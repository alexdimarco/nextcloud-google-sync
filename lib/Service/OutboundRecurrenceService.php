<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Service;

use DateTimeImmutable;
use DateTimeInterface;
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

	/**
	 * Max per-series instance writes per reconcile tick (circuit breaker). A
	 * method (not a const) so a lab/manual test can subclass + lower it without
	 * editing the source; production is the default.
	 */
	protected function instanceOpBudget(): int {
		return 100;
	}

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
				} elseif (OutboundWriteService::isPermanentBodyRejection(is_int($result['statusCode'] ?? null) ? $result['statusCode'] : null)) {
					// Malformed master Google will always reject — terminal
					// (advances the token), so it can't wedge the calendar.
					$this->logger->warning(
						'Calendar Bridge: create series master PERMANENTLY rejected for ' . $ncUri . ' (status ' . (string)($result['statusCode'] ?? '?') . ') — leaving one-way',
						['app' => Application::APP_ID],
					);
					return OutboundWriteService::SKIPPED_REJECTED;
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
			$diffStatus = $this->runInstanceDiff($userId, $calId, $ncCalId, $ncUri, $masterId, (string)$obj['calendardata']);
			if ($diffStatus === OutboundWriteService::ERROR) {
				// Master created, but an initial override/EXDATE failed transiently.
				// recordLocalNew set nc_etag = the current NC etag (-> ECHO), which
				// would strand them; reset it so the object re-classifies LOCAL_EDIT
				// next tick (the held token keeps it in the delta) and the differ
				// resumes idempotently. (A DEFERRED diff converges as CREATED and
				// advances the token — see the budget breaker for its limitation.)
				$this->eventMapService->recordOutboundUpdate($ncCalId, $ncUri, '', null, null);
				return OutboundWriteService::ERROR;
			}
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

			// 1) MASTER PATCH (recurrence[] = RRULE only). Refresh the master's
			// Google baseline immediately (NOT nc_etag) so a resumed run's re-PATCH
			// matches and does not false-412.
			$body = $this->buildMasterBody($master, $ncUri);
			$result = $this->patchGoogleEvent($userId, $calId, $masterId, $body, $row->getBaselineEtag());
			if (!isset($result['error'])) {
				$this->recordMasterBaseline($ncCalId, $ncUri, $result);
			} else {
				$status = $result['statusCode'] ?? null;
				if ($status === 404 || $status === 410) {
					$this->eventMapService->removeForNcUri($ncCalId, $ncUri);
					return OutboundWriteService::SKIPPED_GONE;
				}
				if ($status === 412) {
					$masterStatus = $this->resolveMasterConflict($userId, $calId, $ncCalId, $ncUri, $masterId, $body, $ncLastMod);
					if ($masterStatus !== null) {
						return $masterStatus; // CONFLICT_PARKED (Google won) / ERROR — stop
					}
					// NC won + re-patched (baseline recorded inside) — continue.
				} elseif (OutboundWriteService::isPermanentBodyRejection(is_int($status) ? $status : null)) {
					// Malformed master edit Google will always reject - terminal
					// (advances the token), so it can't wedge the calendar.
					$this->logger->warning(
						'Calendar Bridge: series master PATCH PERMANENTLY rejected for ' . $ncUri . ' (status ' . (string)$status . ') - leaving one-way',
						['app' => Application::APP_ID],
					);
					return OutboundWriteService::SKIPPED_REJECTED;
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

			// 3) Set nc_etag (so the series reads ECHO) + the guard baselines on
			// every outcome EXCEPT a transient ERROR. ERROR holds the calendar token
			// (reconciler), so leaving nc_etag at its pre-edit value re-classifies the
			// object as LOCAL_EDIT next tick and the differ resumes idempotently.
			// DEFERRED_INSTANCE ADVANCES the token (anti-wedge) and converges; a
			// far-future instance re-syncs on a later edit once Google materializes
			// it, but the budget OVERFLOW stays one-way (see the budget breaker).
			if ($diffStatus !== OutboundWriteService::ERROR) {
				$this->eventMapService->recordOutboundUpdate($ncCalId, $ncUri, isset($obj['etag']) ? (string)$obj['etag'] : null, null, null);
				$this->recordSeriesBaseline($ncCalId, $ncUri, $master);
			}
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
		$this->recordMasterBaseline($ncCalId, $ncUri, $retry);
		return null; // NC won, re-patched
	}

	/** Refresh the master row's Google baseline (etag + updated) — NOT nc_etag. */
	private function recordMasterBaseline(int $ncCalId, string $ncUri, array $result): void {
		$this->eventMapService->recordOutboundUpdate(
			$ncCalId, $ncUri, null,
			isset($result['updated']) && is_string($result['updated']) ? $result['updated'] : null,
			isset($result['etag']) && is_string($result['etag']) ? $result['etag'] : null,
		);
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
	 * against the LIVE Google instances (identity = canonical key on the live
	 * originalStartTime; we never reconstruct a Google id from NC strings).
	 *
	 * Returns UPDATED on full success, DEFERRED_INSTANCE if some instance was not
	 * materialized in the window (retried next tick), CONFLICT_PARKED if a
	 * Google-side instance edit won LWW (left in place), or ERROR on a transient
	 * failure (holds the token). NC object lastmodified is the single (coarse) NC
	 * timestamp for every per-instance LWW.
	 */
	private function runInstanceDiff(string $userId, string $calId, int $ncCalId, string $ncUri, string $masterId, string $calData): string {
		$intent = $this->buildNcIntent($calData);
		// The keys we have CANCELLED — a cancelled slot no longer EXDATE'd/
		// overridden in NC means the user REMOVED the EXDATE, so the occurrence
		// must be RESTORED.
		$cancelledTimes = [];
		$restoreKeys = [];
		foreach ($this->eventMapService->findSiblings($ncCalId, $ncUri) as $row) {
			$k = RecurrenceKey::fromGoogleToken($row->getRecurrenceId());
			if ($k === null) {
				continue;
			}
			if ($row->getState() === 'cancelled' && !isset($intent['exdates'][$k]) && !isset($intent['overrides'][$k])) {
				$restoreKeys[$k] = true;
				$t = $this->tokenTime($row->getRecurrenceId());
				if ($t !== null) {
					$cancelledTimes[] = $t;
				}
			}
		}
		if ($intent['overrides'] === [] && $intent['exdates'] === [] && $restoreKeys === []) {
			return OutboundWriteService::UPDATED; // nothing per-instance to do
		}
		[$timeMin, $timeMax] = $this->window([...$intent['times'], ...$cancelledTimes]);
		$live = $this->listLiveInstances($userId, $calId, $masterId, $timeMin, $timeMax);
		if ($live['error']) {
			return OutboundWriteService::ERROR; // transient; hold + retry
		}
		$byKey = $live['byKey'];
		$collisions = $live['collisions'];

		$deferred = false;
		$parked = false;
		$ops = 0;
		$budgetDeferred = 0;
		$windowDeferred = 0;
		$workKeys = [...array_keys($intent['exdates']), ...array_keys($intent['overrides']), ...array_keys($restoreKeys)];
		$total = count($workKeys);
		foreach ($workKeys as $i => $key) {
			if (isset($collisions[$key])) {
				$this->logger->warning(
					'Calendar Bridge: series ' . $ncUri . ' instance ' . $key . ' is ambiguous (multiple live instances); skipping',
					['app' => Application::APP_ID],
				);
				$parked = true;
				continue;
			}
			$inst = $byKey[$key] ?? null;
			if ($inst === null) {
				// Not materialized in the Google window — a SEPARATE one-way
				// deferral from the budget breaker (far-future / sparse
				// customization); retried once Google materializes it / on a
				// full pull. Counted and logged distinctly below.
				$windowDeferred++;
				$deferred = true;
				continue;
			}
			// Per-tick circuit breaker: cap the per-series Google WRITES so a
			// pathological series (hundreds of customized occurrences) cannot make
			// unbounded API calls in one cron tick. Only REAL writes count toward
			// the budget — an already-cancelled EXDATE / already-confirmed restore
			// short-circuits to a no-op (see $outcome['wrote']), so re-asserting a
			// long-accumulated EXDATE set is free and does not starve genuine edits.
			// DEFERRED advances the token (no wedge). LAB-VERIFIED LIMITATION: there
			// is no resume cursor, so each run re-processes the SAME first N writes
			// (stable order) — the first N sync (and keep updating), but the OVERFLOW
			// beyond N stays one-way (at the base expansion) until a cursor lands.
			if ($ops >= $this->instanceOpBudget()) {
				$budgetDeferred = $total - $i; // remaining incl. this key — upper bound
				$deferred = true;
				break;
			}
			$rawToken = $this->rawOriginalStart($inst['ost']);
			if (isset($intent['exdates'][$key])) {
				$outcome = $this->cancelInstance($userId, $calId, $ncCalId, $ncUri, $inst, $rawToken);
			} elseif (isset($intent['overrides'][$key])) {
				$outcome = $this->overrideInstance($userId, $calId, $ncCalId, $ncUri, $inst, $intent['overrides'][$key], $rawToken);
			} else {
				$outcome = $this->restoreInstance($userId, $calId, $ncCalId, $ncUri, $inst, $rawToken);
			}
			if ($outcome['status'] === OutboundWriteService::ERROR) {
				return OutboundWriteService::ERROR;
			}
			if ($outcome['wrote']) {
				$ops++;
			}
		}
		// Counted + attributed deferral signal (the gap was previously silent — a
		// one-shot warning with no count). NOTE: this fires at deferral time (once
		// per series edit), NOT every cron tick: a deferred series records nc_etag
		// (ECHO) and the reconciler only revisits CHANGED objects, so re-logging
		// every tick would require either re-processing (a Google-quota regression)
		// or a persistent deferred-series registry (the resume-cursor work).
		if ($budgetDeferred > 0) {
			$this->logger->warning(
				'Calendar Bridge: series ' . $ncUri . ' (user ' . $userId . ') hit the per-tick instance-write budget ('
				. $this->instanceOpBudget() . '); ~' . $budgetDeferred . ' customized occurrence(s) deferred and remain '
				. 'ONE-WAY until the series is re-synced (no resume cursor)',
				['app' => Application::APP_ID],
			);
		}
		if ($windowDeferred > 0) {
			$this->logger->info(
				'Calendar Bridge: series ' . $ncUri . ' (user ' . $userId . ') has ' . $windowDeferred
				. ' customized occurrence(s) not yet materialized in the Google window; will retry on a later tick',
				['app' => Application::APP_ID],
			);
		}
		if ($deferred) {
			return OutboundWriteService::DEFERRED_INSTANCE;
		}
		return $parked ? OutboundWriteService::CONFLICT_PARKED : OutboundWriteService::UPDATED;
	}

	/**
	 * Restore an occurrence the user un-EXDATE'd: if the live instance is still
	 * cancelled (our cancel), patch it back to confirmed and record the sibling
	 * synced. Google then re-expands it from the master RRULE.
	 *
	 * @param array{instanceId:string, status:string, etag:?string, updated:?string, ost:array} $inst
	 * @return array{status: string, wrote: bool} wrote=false when the instance was
	 *   already confirmed (a free no-op that must NOT consume the write budget).
	 */
	private function restoreInstance(string $userId, string $calId, int $ncCalId, string $ncUri, array $inst, string $rawToken): array {
		if ($inst['status'] !== 'cancelled') {
			$this->eventMapService->recordOutboundSibling($ncCalId, $ncUri, $rawToken, $inst['instanceId'], is_string($inst['updated']) ? $inst['updated'] : null, is_string($inst['etag']) ? $inst['etag'] : null);
			return ['status' => OutboundWriteService::UPDATED, 'wrote' => false];
		}
		$out = $this->patchInstanceResilient($userId, $calId, (string)$inst['instanceId'], ['status' => 'confirmed'], $inst['etag']);
		$res = $out['result'];
		if (isset($res['error'])) {
			$status = $res['statusCode'] ?? null;
			if ($status === 404 || $status === 410) {
				// Gone on Google — can't restore. Clear the 'cancelled' marker
				// (-> 'synced') so it is not re-selected for restore every tick.
				$this->eventMapService->recordOutboundSibling($ncCalId, $ncUri, $rawToken, (string)$inst['instanceId'], null, null);
				return ['status' => OutboundWriteService::UPDATED, 'wrote' => true];
			}
			if (OutboundWriteService::isPermanentBodyRejection(is_int($status) ? $status : null)) {
				// Permanently rejected — terminal (must NOT bubble ERROR and wedge
				// the series/calendar). Clear the marker; leave one-way.
				$this->logger->warning('Calendar Bridge: instance restore permanently rejected (status ' . (string)$status . ') for ' . $ncUri . ' — leaving one-way', ['app' => Application::APP_ID]);
				$this->eventMapService->recordOutboundSibling($ncCalId, $ncUri, $rawToken, (string)$inst['instanceId'], null, null);
				return ['status' => OutboundWriteService::UPDATED, 'wrote' => true];
			}
			return ['status' => OutboundWriteService::ERROR, 'wrote' => true];
		}
		$this->eventMapService->recordOutboundSibling(
			$ncCalId, $ncUri, $rawToken, (string)$inst['instanceId'],
			isset($res['updated']) ? (string)$res['updated'] : $out['updated'],
			isset($res['etag']) ? (string)$res['etag'] : $out['etag'],
		);
		return ['status' => OutboundWriteService::UPDATED, 'wrote' => true];
	}

	/** Parse a raw Google originalStartTime token into a DateTime (for the window). */
	private function tokenTime(string $token): ?DateTimeInterface {
		if ($token === '') {
			return null;
		}
		try {
			return new DateTimeImmutable($token);
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * Ensure one occurrence is CANCELLED on Google (an NC EXDATE). NC-wins: the
	 * user EXDATE'd it. Idempotent on an already-cancelled instance or a 404/410.
	 * Records the sibling 'cancelled' (kept, so a later EXDATE-removal restores it).
	 *
	 * @param array{instanceId:string, status:string, etag:?string, updated:?string, ost:array} $inst
	 * @return array{status: string, wrote: bool} wrote=false when the instance was
	 *   already cancelled (a free no-op that must NOT consume the write budget — this
	 *   is what makes re-asserting a long-accumulated EXDATE set cost nothing).
	 */
	private function cancelInstance(string $userId, string $calId, int $ncCalId, string $ncUri, array $inst, string $rawToken): array {
		if ($inst['status'] === 'cancelled') {
			$this->eventMapService->markSiblingCancelled($ncCalId, $ncUri, $rawToken);
			return ['status' => OutboundWriteService::UPDATED, 'wrote' => false];
		}
		$out = $this->patchInstanceResilient($userId, $calId, (string)$inst['instanceId'], ['status' => 'cancelled'], $inst['etag']);
		$res = $out['result'];
		if (isset($res['error'])) {
			$status = $res['statusCode'] ?? null;
			if ($status === 404 || $status === 410) {
				$this->eventMapService->markSiblingCancelled($ncCalId, $ncUri, $rawToken);
				return ['status' => OutboundWriteService::UPDATED, 'wrote' => true];
			}
			if (OutboundWriteService::isPermanentBodyRejection(is_int($status) ? $status : null)) {
				// Permanently rejected — terminal (don't wedge). Mark cancelled in
				// the map so it is not re-selected; leave one-way.
				$this->logger->warning('Calendar Bridge: instance cancel permanently rejected (status ' . (string)$status . ') for ' . $ncUri . ' — leaving one-way', ['app' => Application::APP_ID]);
				$this->eventMapService->markSiblingCancelled($ncCalId, $ncUri, $rawToken);
				return ['status' => OutboundWriteService::UPDATED, 'wrote' => true];
			}
			return ['status' => OutboundWriteService::ERROR, 'wrote' => true];
		}
		$this->eventMapService->markSiblingCancelled($ncCalId, $ncUri, $rawToken);
		return ['status' => OutboundWriteService::UPDATED, 'wrote' => true];
	}

	/**
	 * Ensure one occurrence carries the NC OVERRIDE's content on Google (a
	 * RECURRENCE-ID VEVENT). NC-wins: an outbound series edit always re-asserts
	 * NC's overrides — the master PATCH propagates master fields onto instances,
	 * resetting overrides, so a per-instance LWW would mistake our own reset for a
	 * Google edit and drop the override. Google-side instance edits made while NC
	 * is quiescent are captured INBOUND (the sibling-aware echo gate). PATCH is
	 * partial so Google-side attendees survive; status='confirmed' un-cancels.
	 *
	 * @param array{instanceId:string, status:string, etag:?string, updated:?string, ost:array} $inst
	 * @return array{status: string, wrote: bool} always wrote=true — an override has
	 *   no cheap idempotency check (content can differ), so it always patches.
	 */
	private function overrideInstance(string $userId, string $calId, int $ncCalId, string $ncUri, array $inst, VEvent $ov, string $rawToken): array {
		$body = OutboundWriteService::buildEventFields($ov, $ncUri, true);
		$body['status'] = 'confirmed';
		$out = $this->patchInstanceResilient($userId, $calId, (string)$inst['instanceId'], $body, $inst['etag']);
		$res = $out['result'];
		if (isset($res['error'])) {
			$status = $res['statusCode'] ?? null;
			if ($status === 404 || $status === 410) {
				return ['status' => OutboundWriteService::UPDATED, 'wrote' => true]; // instance vanished; nothing to override
			}
			if (OutboundWriteService::isPermanentBodyRejection(is_int($status) ? $status : null)) {
				// Malformed override body Google will always reject — terminal
				// (don't wedge the series/calendar); the instance stays one-way.
				$this->logger->warning('Calendar Bridge: instance override permanently rejected (status ' . (string)$status . ') for ' . $ncUri . ' — leaving one-way', ['app' => Application::APP_ID]);
				return ['status' => OutboundWriteService::UPDATED, 'wrote' => true];
			}
			return ['status' => OutboundWriteService::ERROR, 'wrote' => true];
		}
		$this->eventMapService->recordOutboundSibling(
			$ncCalId, $ncUri, $rawToken, (string)$inst['instanceId'],
			isset($res['updated']) ? (string)$res['updated'] : $out['updated'],
			isset($res['etag']) ? (string)$res['etag'] : $out['etag'],
		);
		return ['status' => OutboundWriteService::UPDATED, 'wrote' => true];
	}

	/**
	 * Patch an instance with If-Match; on a 412 — which a sibling instance's own
	 * mutation routinely triggers, since all instances of a series share an etag
	 * lineage — re-GET the live instance and retry once with the FRESH etag
	 * (NC-wins). Returns the final response + the fresh etag/updated.
	 *
	 * @param array<string, mixed> $body
	 * @return array{result: array<string, mixed>, etag: ?string, updated: ?string}
	 */
	private function patchInstanceResilient(string $userId, string $calId, string $instanceId, array $body, ?string $etag): array {
		$res = $this->patchGoogleEvent($userId, $calId, $instanceId, $body, $etag);
		if (!isset($res['error']) || ($res['statusCode'] ?? null) !== 412) {
			return ['result' => $res, 'etag' => $etag, 'updated' => null];
		}
		$live = $this->googleApiService->request($userId, 'calendar/v3/calendars/' . urlencode($calId) . '/events/' . urlencode($instanceId));
		if (isset($live['error']) || !isset($live['etag'])) {
			return ['result' => $res, 'etag' => $etag, 'updated' => null];
		}
		$liveEtag = (string)$live['etag'];
		$liveUpdated = isset($live['updated']) && is_string($live['updated']) ? $live['updated'] : null;
		$retry = $this->patchGoogleEvent($userId, $calId, $instanceId, $body, $liveEtag);
		return ['result' => $retry, 'etag' => $liveEtag, 'updated' => $liveUpdated];
	}

	/**
	 * List the live Google instances of a series in a window, keyed canonically
	 * (showDeleted so cancelled instances are visible for idempotency + restore).
	 * Detects >1 instance at one key (DST-fold) as a collision.
	 *
	 * @return array{byKey: array<string, array{instanceId:string, status:string, etag:?string, updated:?string, ost:array}>, collisions: array<string, true>, error: bool}
	 */
	private function listLiveInstances(string $userId, string $calId, string $masterId, ?string $timeMin, ?string $timeMax): array {
		$byKey = [];
		$collisions = [];
		/** @var string $pageToken Google pagination cursor; '' once exhausted. */
		$pageToken = '';
		do {
			$endpoint = 'calendar/v3/calendars/' . urlencode($calId) . '/events/' . urlencode($masterId)
				. '/instances?showDeleted=true&maxResults=2500';
			if ($timeMin !== null) {
				$endpoint .= '&timeMin=' . urlencode($timeMin);
			}
			if ($timeMax !== null) {
				$endpoint .= '&timeMax=' . urlencode($timeMax);
			}
			if ($pageToken !== '') {
				$endpoint .= '&pageToken=' . urlencode($pageToken);
			}
			$resp = $this->googleApiService->request($userId, $endpoint);
			if (isset($resp['error'])) {
				return ['byKey' => $byKey, 'collisions' => $collisions, 'error' => true];
			}
			foreach (($resp['items'] ?? []) as $inst) {
				if (!is_array($inst)) {
					continue;
				}
				$key = RecurrenceKey::fromGoogleInstance($inst);
				if ($key === null) {
					continue;
				}
				if (isset($byKey[$key])) {
					$collisions[$key] = true;
					continue;
				}
				$byKey[$key] = [
					'instanceId' => (string)($inst['id'] ?? ''),
					'status' => (string)($inst['status'] ?? 'confirmed'),
					'etag' => isset($inst['etag']) ? (string)$inst['etag'] : null,
					'updated' => isset($inst['updated']) ? (string)$inst['updated'] : null,
					'ost' => is_array($inst['originalStartTime'] ?? null) ? $inst['originalStartTime'] : [],
				];
			}
			$pageToken = isset($resp['nextPageToken']) ? (string)$resp['nextPageToken'] : '';
		} while ($pageToken !== '');
		return ['byKey' => $byKey, 'collisions' => $collisions, 'error' => false];
	}

	/**
	 * Parse the NC series for per-instance INTENT (content + which slots are
	 * EXDATE'd), keyed canonically. EXDATE WINS over a co-located override.
	 * 'times' collects every relevant instant (override RECURRENCE-IDs + override
	 * DTSTARTs + EXDATE values) so the live-instance window covers moved overrides.
	 *
	 * @return array{overrides: array<string, VEvent>, exdates: array<string, true>, times: list<DateTimeInterface>}
	 */
	private function buildNcIntent(string $calData): array {
		$overrides = [];
		$exdates = [];
		$times = [];
		$resolver = [self::class, 'isResolvableTzid'];
		try {
			$vcal = Reader::read($calData);
		} catch (Throwable) {
			return ['overrides' => $overrides, 'exdates' => $exdates, 'times' => $times];
		}
		$master = self::parseMaster($calData);
		$refZone = $this->masterRefZone($master);

		foreach ($vcal->select('VEVENT') as $ev) {
			if (!($ev instanceof VEvent) || !isset($ev->{'RECURRENCE-ID'})) {
				continue;
			}
			$key = RecurrenceKey::fromIcsDateProp($ev->{'RECURRENCE-ID'}, $refZone, $resolver);
			if ($key === null) {
				continue;
			}
			$overrides[$key] = $ev;
			foreach ($this->propDateTimes($ev->{'RECURRENCE-ID'}, $refZone) as $dt) {
				$times[] = $dt;
			}
			if (isset($ev->DTSTART)) {
				foreach ($this->propDateTimes($ev->DTSTART, $refZone) as $dt) {
					$times[] = $dt;
				}
			}
		}
		if ($master !== null) {
			foreach ($master->select('EXDATE') as $exProp) {
				$keys = RecurrenceKey::fromIcsDateProps($exProp, $refZone, $resolver);
				$dts = $this->propDateTimes($exProp, $refZone);
				foreach ($keys as $i => $k) {
					if ($k === null) {
						continue;
					}
					$exdates[$k] = true;
					if (isset($dts[$i])) {
						$times[] = $dts[$i];
					}
				}
			}
		}
		// EXDATE wins over a co-located override.
		foreach (array_keys($exdates) as $k) {
			unset($overrides[$k]);
		}
		return ['overrides' => $overrides, 'exdates' => $exdates, 'times' => $times];
	}

	/** The master's reference zone for floating values (DTSTART TZID, else UTC). */
	private function masterRefZone(?VEvent $master): DateTimeZone {
		$dtstart = $master?->DTSTART ?? null;
		if ($dtstart !== null && isset($dtstart['TZID']) && self::isResolvableTzid((string)$dtstart['TZID'])) {
			try {
				return new DateTimeZone((string)$dtstart['TZID']);
			} catch (Throwable) {
				// fall through
			}
		}
		return new DateTimeZone('UTC');
	}

	/** @return list<DateTimeInterface> */
	private function propDateTimes(\Sabre\VObject\Property $p, DateTimeZone $refZone): array {
		try {
			return array_values($p->getDateTimes($refZone));
		} catch (Throwable) {
			return [];
		}
	}

	/**
	 * A generous [min-1d, max+1d] RFC3339 window over the relevant instants, so
	 * the instances list covers both original slots and moved overrides. Null
	 * bounds when there are no times (caller lists unbounded).
	 *
	 * @param list<DateTimeInterface> $times
	 * @return array{0: ?string, 1: ?string}
	 */
	private function window(array $times): array {
		if ($times === []) {
			return [null, null];
		}
		$min = null;
		$max = null;
		foreach ($times as $dt) {
			$ts = $dt->getTimestamp();
			$min = ($min === null || $ts < $min) ? $ts : $min;
			$max = ($max === null || $ts > $max) ? $ts : $max;
		}
		$fmt = static fn (int $ts): string => (new DateTimeImmutable('@' . $ts))->format(DateTimeInterface::RFC3339);
		return [$fmt($min - 86400), $fmt($max + 86400)];
	}

	/** The raw originalStartTime string (matching the inbound recordFromImport token). */
	private function rawOriginalStart(array $ost): string {
		if (isset($ost['dateTime']) && is_string($ost['dateTime'])) {
			return $ost['dateTime'];
		}
		if (isset($ost['date']) && is_string($ost['date'])) {
			return $ost['date'];
		}
		return '';
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
		$this->eventMapService->recordSeriesBaseline($ncCalId, $ncUri, $shape, self::extractRrule($master), self::masterDtstartSignature($master));
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
			self::masterDtstartSignature($master),
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
	/**
	 * The refusal-guard baselines for a recurring NC series .ics, or null if it
	 * has no master VEVENT. Lets the inbound importer seed them so the FIRST NC
	 * edit of an imported series is diffed against its pre-edit shape. Pure (Sabre).
	 *
	 * @return array{shape: string, rrule: string, dtstartSig: string}|null
	 */
	public static function seriesBaselineFromCalData(string $calData): ?array {
		$master = self::parseMaster($calData);
		if ($master === null) {
			return null;
		}
		return [
			'shape' => (isset($master->RRULE) || isset($master->RDATE)) ? 'recurring' : 'single',
			'rrule' => self::extractRrule($master),
			'dtstartSig' => self::masterDtstartSignature($master),
		];
	}

	private static function masterDtstartSignature(VEvent $master): string {
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
