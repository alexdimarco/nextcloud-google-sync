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
	 * Create a new NC recurring series in Google. (Mutation in a later slice;
	 * for now the refusal guards run and the safe path is a no-op one-way.)
	 */
	public function createLocalSeriesInGoogle(string $userId, string $calId, int $ncCalId, string $ncUri): string {
		return $this->guardThenRun($userId, $calId, $ncCalId, $ncUri, true);
	}

	/**
	 * Push a change to an existing NC recurring series (the differ). (Mutation in
	 * a later slice; for now the refusal guards run and the safe path is a no-op.)
	 */
	public function updateLocalSeriesInGoogle(string $userId, string $calId, int $ncCalId, string $ncUri): string {
		return $this->guardThenRun($userId, $calId, $ncCalId, $ncUri, false);
	}

	private function guardThenRun(string $userId, string $calId, int $ncCalId, string $ncUri, bool $isCreate): string {
		try {
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
			// Mutation (master PATCH + instance diff) lands in a later slice.
			$this->logger->info(
				'Calendar Bridge: recurring ' . ($isCreate ? 'create' : 'update') . ' ' . $ncUri . ' passed guards (push not yet implemented; one-way)',
				['app' => Application::APP_ID],
			);
			return OutboundWriteService::SKIPPED_UNSUPPORTED;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: recurring ' . ($isCreate ? 'create' : 'update') . ' threw for ' . $ncUri . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return OutboundWriteService::ERROR;
		}
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
