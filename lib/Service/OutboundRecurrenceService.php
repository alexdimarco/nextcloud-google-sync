<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Service;

use DateTimeZone;
use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Db\EventMap;
use OCA\DAV\CalDAV\CalDavBackend;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Phase 4: outbound recurrence. Pushes a Nextcloud recurring SERIES (master +
 * per-instance overrides + EXDATE cancellations) to Google via an
 * "expansion-diff against live Google state" — instances are identified by
 * matching NC values to the LIVE events.instances list by canonical
 * {@see RecurrenceKey}, never by reconstructing a Google id from NC strings.
 *
 * This first slice ships the REFUSAL GUARDS and the skeleton: a recurrence
 * transition we cannot safely apply (this-and-following split, DTSTART move,
 * shape flip, RDATE, unresolvable TZID) is detected and returns
 * SKIPPED_UNSUPPORTED (terminal, one-way, loud) with ZERO Google calls. The
 * mutation flows (master PATCH, per-instance overrides/cancels) land in
 * subsequent slices. Whole-series DELETE is handled by the flat
 * OutboundWriteService::deleteLocalEventInGoogle (events.delete on the master
 * cascades to every instance); a single-instance delete is a LOCAL_EDIT (a new
 * EXDATE), handled here.
 */
class OutboundRecurrenceService {

	public function __construct(
		private CalDavBackend $caldavBackend,
		private GoogleAPIService $googleApiService,
		private EventMapService $eventMapService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Does this calendar object's master VEVENT make it a recurring series?
	 * Pure-ish (Sabre parse, no I/O); used by the reconciler to route.
	 */
	public static function isRecurringCalData(string $calData): bool {
		try {
			$master = self::parseMaster($calData);
			return $master !== null && (isset($master->RRULE) || isset($master->RDATE));
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * Create a new NC recurring series in Google: insert the master (recurrence[]
	 * = RRULE) under a deterministic id (409-adopts a crash-replayed master),
	 * record the master row + baselines, then run the instance diff for any
	 * overrides/EXDATEs.
	 */
	public function createLocalSeriesInGoogle(string $userId, string $calId, int $ncCalId, string $ncUri): string {
		try {
			$g = $this->loadAndGuard($ncCalId, $ncUri, true);
			if (is_string($g)) {
				return $g;
			}
			$master = $g['master'];
			$obj = $g['obj'];
			$uid = isset($master->UID) ? (string)$master->UID : '';
			if ($uid === '') {
				return OutboundWriteService::SKIPPED_GONE;
			}
			$clientId = OutboundWriteService::deriveClientId($uid);
			$body = $this->buildMasterBody($master, $ncUri);
			$body['id'] = $clientId;
			$result = $this->googleApiService->request(
				$userId,
				'calendar/v3/calendars/' . urlencode($calId) . '/events?sendUpdates=none',
				$body, 'POST',
			);
			if (isset($result['error'])) {
				if (($result['statusCode'] ?? null) === 409) {
					$result = $this->adoptMaster($userId, $calId, $clientId, $ncUri);
					if ($result === null) {
						return OutboundWriteService::CONFLICT;
					}
				} else {
					$this->logger->warning(
						'Calendar Bridge: create series master failed for ' . $ncUri . ' (status ' . (string)($result['statusCode'] ?? '?') . ')',
						['app' => Application::APP_ID],
					);
					return OutboundWriteService::ERROR;
				}
			}
			$masterId = isset($result['id']) ? (string)$result['id'] : $clientId;
			$this->eventMapService->recordLocalNew(
				$ncCalId, $ncUri, $uid,
				isset($obj['etag']) ? (string)$obj['etag'] : null,
				$masterId,
				isset($result['updated']) ? (string)$result['updated'] : null,
				isset($result['etag']) ? (string)$result['etag'] : null,
			);
			$this->recordSeriesBaseline($ncCalId, $ncUri, $master);
			$this->logger->info(
				'Calendar Bridge: created Google series ' . $masterId . ' from local ' . $ncUri,
				['app' => Application::APP_ID],
			);
			$this->runInstanceDiff($userId, $calId, $ncCalId, $ncUri, $masterId, (string)$obj['calendardata']);
			return OutboundWriteService::CREATED;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: recurring create threw for ' . $ncUri . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return OutboundWriteService::ERROR;
		}
	}

	/**
	 * Push a change to an existing NC recurring series (the differ): master PATCH
	 * (RRULE-only recurrence) with per-resource LWW on a 412, then the
	 * per-instance overrides/EXDATE reconcile, then an authoritative master
	 * re-GET so the whole series reads ECHO next tick.
	 */
	public function updateLocalSeriesInGoogle(string $userId, string $calId, int $ncCalId, string $ncUri): string {
		try {
			$g = $this->loadAndGuard($ncCalId, $ncUri, false);
			if (is_string($g)) {
				return $g;
			}
			$master = $g['master'];
			$obj = $g['obj'];
			$row = $g['row'];
			$masterId = $row?->getGoogleId();
			if ($row === null || $masterId === null || $masterId === '') {
				return OutboundWriteService::SKIPPED_GONE;
			}
			$ncLastMod = isset($obj['lastmodified']) ? (int)$obj['lastmodified'] : null;

			// 1) MASTER PATCH (recurrence[] = RRULE only).
			$body = $this->buildMasterBody($master, $ncUri);
			$result = $this->patchGoogleEvent($userId, $calId, $masterId, $body, $row->getBaselineEtag());
			if (isset($result['error'])) {
				$status = $result['statusCode'] ?? null;
				if ($status === 404 || $status === 410) {
					$this->eventMapService->removeForNcUri($ncCalId, $ncUri);
					return OutboundWriteService::SKIPPED_GONE;
				}
				if ($status === 412) {
					$masterStatus = $this->resolveMasterConflict($userId, $calId, $ncCalId, $ncUri, $masterId, $body, $ncLastMod);
					if ($masterStatus !== null) {
						return $masterStatus; // CONFLICT_PARKED (Google won) — stop, no instance churn
					}
					// NC won + re-patched: fall through to the instance diff.
				} else {
					$this->logger->warning(
						'Calendar Bridge: series master PATCH failed for ' . $ncUri . ' (status ' . (string)($status ?? '?') . ')',
						['app' => Application::APP_ID],
					);
					return OutboundWriteService::ERROR;
				}
			}

			// 2) per-instance overrides + EXDATE cancellations.
			$diffStatus = $this->runInstanceDiff($userId, $calId, $ncCalId, $ncUri, $masterId, (string)$obj['calendardata']);

			// 3) authoritative master re-GET so inbound reads the whole series as ECHO.
			$this->recordMasterEcho($userId, $calId, $ncCalId, $ncUri, $masterId, $master, $obj);
			$this->logger->info(
				'Calendar Bridge: updated Google series ' . $masterId . ' from local ' . $ncUri . ' (' . $diffStatus . ')',
				['app' => Application::APP_ID],
			);
			return $diffStatus;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: recurring update threw for ' . $ncUri . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return OutboundWriteService::ERROR;
		}
	}

	/**
	 * events.patch via POST + X-HTTP-Method-Override (NC's IClient has no patch())
	 * with If-Match + sendUpdates=none.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function patchGoogleEvent(string $userId, string $calId, string $eventId, array $body, ?string $ifMatchEtag): array {
		$headers = ['X-HTTP-Method-Override' => 'PATCH'];
		if ($ifMatchEtag !== null && $ifMatchEtag !== '') {
			$headers['If-Match'] = $ifMatchEtag;
		}
		return $this->googleApiService->request(
			$userId,
			'calendar/v3/calendars/' . urlencode($calId) . '/events/' . urlencode($eventId) . '?sendUpdates=none',
			$body, 'POST', null, $headers,
		);
	}

	/**
	 * Resolve a 412 on the master PATCH by last-writer-wins against the live
	 * master. Returns null when NC wins and the re-PATCH succeeded (caller
	 * continues), or a terminal status (CONFLICT_PARKED / ERROR) to return.
	 *
	 * @param array<string, mixed> $body
	 */
	private function resolveMasterConflict(string $userId, string $calId, int $ncCalId, string $ncUri, string $masterId, array $body, ?int $ncLastMod): ?string {
		$live = $this->googleApiService->request($userId, 'calendar/v3/calendars/' . urlencode($calId) . '/events/' . urlencode($masterId));
		if (isset($live['error']) || !isset($live['etag'])) {
			return OutboundWriteService::ERROR; // transient; hold + retry
		}
		$googleUpdated = isset($live['updated']) && is_string($live['updated']) ? strtotime($live['updated']) : false;
		if (OutboundWriteService::resolveConflict($ncLastMod, $googleUpdated === false ? null : $googleUpdated) !== 'nc_wins') {
			// Google wins: record its etag as our baseline so the series reads
			// ECHO and STOPS retrying; do NOT hold the calendar token.
			$this->eventMapService->recordOutboundUpdate($ncCalId, $ncUri, null, isset($live['updated']) ? (string)$live['updated'] : null, (string)$live['etag']);
			$this->logger->info(
				'Calendar Bridge: series master conflict on ' . $ncUri . ' resolved Google-wins (LWW); inbound will reconcile',
				['app' => Application::APP_ID],
			);
			return OutboundWriteService::CONFLICT_PARKED;
		}
		$retry = $this->patchGoogleEvent($userId, $calId, $masterId, $body, (string)$live['etag']);
		if (isset($retry['error'])) {
			return OutboundWriteService::ERROR;
		}
		return null; // NC won, re-patched
	}

	/** Authoritative master re-GET; record nc_etag=current NC etag + fresh google baseline. */
	private function recordMasterEcho(string $userId, string $calId, int $ncCalId, string $ncUri, string $masterId, VEvent $master, array $obj): void {
		$live = $this->googleApiService->request($userId, 'calendar/v3/calendars/' . urlencode($calId) . '/events/' . urlencode($masterId));
		$this->eventMapService->recordOutboundUpdate(
			$ncCalId, $ncUri,
			isset($obj['etag']) ? (string)$obj['etag'] : null,
			isset($live['updated']) && is_string($live['updated']) ? (string)$live['updated'] : null,
			isset($live['etag']) && is_string($live['etag']) ? (string)$live['etag'] : null,
		);
		$this->recordSeriesBaseline($ncCalId, $ncUri, $master);
	}

	/**
	 * Load the NC object, parse its master VEVENT, and run the refusal guards.
	 * Returns ['obj'=>..., 'master'=>..., 'row'=>...] when safe to proceed, or a
	 * terminal status string to return as-is.
	 *
	 * @return array{obj: array<string, mixed>, master: VEvent, row: ?EventMap}|string
	 */
	private function loadAndGuard(int $ncCalId, string $ncUri, bool $isCreate): array|string {
		$obj = $this->caldavBackend->getCalendarObject($ncCalId, $ncUri);
		if (!is_array($obj) || !isset($obj['calendardata'])) {
			return OutboundWriteService::SKIPPED_GONE;
		}
		$master = self::parseMaster((string)$obj['calendardata']);
		if ($master === null) {
			return OutboundWriteService::SKIPPED_GONE;
		}
		$row = $isCreate ? null : $this->eventMapService->getMasterRow($ncCalId, $ncUri);
		$reason = $this->refusalReason($master, $row);
		if ($reason !== null) {
			$this->logger->warning(
				'Calendar Bridge: recurring series ' . $ncUri . ' not pushed (' . $reason . '); staying one-way',
				['app' => Application::APP_ID],
			);
			return OutboundWriteService::SKIPPED_UNSUPPORTED;
		}
		return ['obj' => $obj, 'master' => $master, 'row' => $row];
	}

	/**
	 * Reconcile the per-instance overrides + EXDATE cancellations of a series
	 * against the live Google instances. Implemented in the next slice; for now a
	 * logged no-op that surfaces any instances left one-way. Returns a terminal
	 * status (UPDATED while instance work is deferred).
	 */
	private function runInstanceDiff(string $userId, string $calId, int $ncCalId, string $ncUri, string $masterId, string $calData): string {
		$pending = $this->countInstanceWork($calData);
		if ($pending > 0) {
			$this->logger->info(
				'Calendar Bridge: series ' . $ncUri . ' has ' . $pending . ' instance override(s)/EXDATE(s) not yet pushed (one-way)',
				['app' => Application::APP_ID],
			);
		}
		return OutboundWriteService::UPDATED;
	}

	/** @return array<string, mixed>|null the adopted live master, or null if it is not ours */
	private function adoptMaster(string $userId, string $calId, string $clientId, string $ncUri): ?array {
		$live = $this->googleApiService->request($userId, 'calendar/v3/calendars/' . urlencode($calId) . '/events/' . urlencode($clientId));
		$liveOrigin = $live['extendedProperties']['private']['ncOrigin'] ?? null;
		if (isset($live['error']) || $liveOrigin !== $ncUri) {
			$this->logger->warning(
				'Calendar Bridge: series master id ' . $clientId . ' collides with an event that is not ours; not adopting',
				['app' => Application::APP_ID],
			);
			return null;
		}
		$this->logger->info(
			'Calendar Bridge: adopted existing Google series master ' . $clientId . ' for ' . $ncUri . ' (idempotent replay)',
			['app' => Application::APP_ID],
		);
		return $live;
	}

	private function recordSeriesBaseline(int $ncCalId, string $ncUri, VEvent $master): void {
		$shape = (isset($master->RRULE) || isset($master->RDATE)) ? 'recurring' : 'single';
		$this->eventMapService->recordSeriesBaseline($ncCalId, $ncUri, $shape, self::extractRrule($master), $this->masterDtstartSignature($master));
	}

	/** The number of override VEVENTs + EXDATE values in the object (instance work). */
	private static function countInstanceWork(string $calData): int {
		try {
			$vcal = Reader::read($calData);
		} catch (Throwable) {
			return 0;
		}
		$n = 0;
		foreach ($vcal->select('VEVENT') as $ev) {
			if ($ev instanceof VEvent && isset($ev->{'RECURRENCE-ID'})) {
				$n++;
			}
		}
		$master = self::parseMaster($calData);
		if ($master !== null) {
			foreach ($master->select('EXDATE') as $ex) {
				$n += count($ex->getParts());
			}
		}
		return $n;
	}

	/**
	 * Build the Google master body: the shared NC fields + recurrence[] (RRULE
	 * lines only; RDATE is refused by the guards and EXDATE is applied per
	 * instance). A recurring event REQUIRES a timeZone on its timed start/end.
	 *
	 * @return array<string, mixed>
	 */
	private function buildMasterBody(VEvent $master, string $ncUri): array {
		$body = OutboundWriteService::buildEventFields($master, $ncUri);
		$rrules = [];
		foreach ($master->select('RRULE') as $r) {
			$rrules[] = 'RRULE:' . (string)$r->getValue();
		}
		if ($rrules !== []) {
			$body['recurrence'] = $rrules;
		}
		$body['start'] = self::ensureTimeZone($body['start']);
		$body['end'] = self::ensureTimeZone($body['end']);
		return $body;
	}

	/**
	 * @param array<string, mixed> $t
	 * @return array<string, mixed>
	 */
	private static function ensureTimeZone(array $t): array {
		if (isset($t['dateTime']) && !isset($t['timeZone'])) {
			$t['timeZone'] = 'UTC';
		}
		return $t;
	}

	// ----- refusal guards -----

	private function refusalReason(VEvent $master, ?EventMap $row): ?string {
		return self::unsupportedReason(
			isset($master->RRULE) || isset($master->RDATE),
			$row?->getShape(),
			self::extractRrule($master),
			$row?->getBaselineRrule(),
			$this->masterDtstartSignature($master),
			$row?->getMasterDtstart(),
			isset($master->RDATE),
			$this->masterTzidResolvable($master),
		);
	}

	/**
	 * Pure: given current master facts + the stored baselines, the reason this
	 * series cannot be safely pushed, or null if safe. Order: hard-structural
	 * (TZID/RDATE) first, then baseline mismatches (only checked once a baseline
	 * exists, so a first sync establishes rather than refuses).
	 */
	public static function unsupportedReason(
		bool $currentlyRecurring,
		?string $baselineShape,
		string $currentRrule,
		?string $baselineRrule,
		string $currentDtstartSig,
		?string $baselineDtstartSig,
		bool $hasRdate,
		bool $masterTzidResolvable,
	): ?string {
		if (!$masterTzidResolvable) {
			return 'unresolvable_master_tzid';
		}
		if ($hasRdate) {
			return 'rdate_unsupported';
		}
		if ($baselineShape !== null && ($baselineShape === 'recurring') !== $currentlyRecurring) {
			return 'shape_transition';
		}
		if ($baselineDtstartSig !== null && $currentDtstartSig !== $baselineDtstartSig) {
			return 'dtstart_moved';
		}
		if ($baselineRrule !== null && self::rruleGainedBound($baselineRrule, $currentRrule)) {
			return 'thisandfuture_split';
		}
		return null;
	}

	/** A this-and-following truncation shows up as a newly-gained UNTIL/COUNT. */
	public static function rruleGainedBound(string $baselineRrule, string $currentRrule): bool {
		return self::rruleHasBound($currentRrule) && !self::rruleHasBound($baselineRrule);
	}

	private static function rruleHasBound(string $rrule): bool {
		$u = strtoupper($rrule);
		return str_contains($u, 'UNTIL=') || str_contains($u, 'COUNT=');
	}

	// ----- master parsing helpers -----

	private static function parseMaster(string $calData): ?VEvent {
		$vcal = Reader::read($calData);
		foreach ($vcal->select('VEVENT') as $ev) {
			if ($ev instanceof VEvent && !isset($ev->{'RECURRENCE-ID'})) {
				return $ev;
			}
		}
		return null;
	}

	/** All RRULE lines of the master, sorted+joined (order-insensitive baseline). */
	private static function extractRrule(VEvent $master): string {
		$parts = [];
		foreach ($master->select('RRULE') as $r) {
			$parts[] = (string)$r->getValue();
		}
		sort($parts);
		return implode("\n", $parts);
	}

	/**
	 * A change-detecting signature of the master DTSTART: kind | TZID | raw wall
	 * value. Stable across DST (the wall value is fixed); changes on a real
	 * DTSTART move, a zone change, or an all-day<->timed flip.
	 */
	private function masterDtstartSignature(VEvent $master): string {
		$dtstart = $master->DTSTART ?? null;
		if ($dtstart === null) {
			return '';
		}
		$kind = ($dtstart instanceof \Sabre\VObject\Property\ICalendar\DateTime && $dtstart->hasTime()) ? 'T' : 'D';
		$tzid = isset($dtstart['TZID']) ? (string)$dtstart['TZID'] : '';
		return $kind . '|' . $tzid . '|' . (string)$dtstart->getValue();
	}

	private function masterTzidResolvable(VEvent $master): bool {
		$dtstart = $master->DTSTART ?? null;
		if ($dtstart === null || !isset($dtstart['TZID'])) {
			return true;
		}
		return self::isResolvableTzid((string)$dtstart['TZID']);
	}

	public static function isResolvableTzid(string $tzid): bool {
		if ($tzid === '') {
			return true;
		}
		try {
			new DateTimeZone($tzid);
			return true;
		} catch (Throwable) {
			return false;
		}
	}
}
