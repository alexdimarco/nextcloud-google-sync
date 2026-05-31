<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Service;

use DateTimeInterface;
use OCA\CalendarBridge\AppInfo\Application;
use OCA\DAV\CalDAV\CalDavBackend;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Phase 2b: the first outbound write. Creates a Nextcloud-originated,
 * NON-RECURRING event in Google via events.insert, idempotently, tagged so its
 * inbound echo is recognized and never duplicated.
 *
 * Scope of 2b (deliberately minimal — first write to a user's real calendar):
 * - CREATE only (LOCAL_NEW). Edits/deletes stay dry-run until 2c.
 * - Non-recurring only. A recurring local event is skipped (stays one-way).
 * - No attendees in the body + sendUpdates=none, so we can never send a real
 *   invitation email from the user's account.
 */
class OutboundWriteService {

	public const CREATED = 'created';
	public const UPDATED = 'updated';
	public const DUPLICATE_ADOPTED = 'duplicate_adopted';
	public const SKIPPED_RECURRING = 'skipped_recurring';
	public const SKIPPED_GONE = 'skipped_gone';
	public const SKIPPED_INDETERMINATE = 'skipped_indeterminate';
	public const CONFLICT = 'conflict';
	public const ERROR = 'error';

	public function __construct(
		private CalDavBackend $caldavBackend,
		private GoogleAPIService $googleApiService,
		private EventMapService $eventMapService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Create the Nextcloud object at $ncUri as a new Google event. Returns one
	 * of the status constants. Never throws — failures are logged and reported.
	 */
	public function createLocalEventInGoogle(string $userId, string $calId, int $ncCalId, string $ncUri): string {
		try {
			$obj = $this->caldavBackend->getCalendarObject($ncCalId, $ncUri);
			if (!is_array($obj) || !isset($obj['calendardata'])) {
				return self::SKIPPED_GONE;
			}
			$vevent = $this->firstVEvent((string)$obj['calendardata']);
			if ($vevent === null) {
				return self::SKIPPED_GONE;
			}
			if ($this->isRecurring($vevent)) {
				$this->logger->info(
					'Calendar Bridge: local event ' . $ncUri . ' is recurring; staying one-way (no outbound) in this phase',
					['app' => Application::APP_ID],
				);
				return self::SKIPPED_RECURRING;
			}

			$uid = isset($vevent->UID) ? (string)$vevent->UID : '';
			if ($uid === '') {
				return self::SKIPPED_GONE;
			}
			$clientId = self::deriveClientId($uid);
			$body = $this->buildInsertBody($vevent, $ncUri, $clientId);
			$ncEtag = isset($obj['etag']) ? (string)$obj['etag'] : null;

			// sendUpdates MUST be a query param: request() puts non-GET $params
			// into the JSON body, so it cannot carry query params.
			$endpoint = 'calendar/v3/calendars/' . urlencode($calId) . '/events?sendUpdates=none';
			$result = $this->googleApiService->request($userId, $endpoint, $body, 'POST');

			if (isset($result['error'])) {
				$status = $result['statusCode'] ?? null;
				if ($status === 409) {
					return $this->adoptDuplicate($userId, $calId, $ncCalId, $ncUri, $uid, $clientId, $ncEtag);
				}
				$this->logger->warning(
					'Calendar Bridge: outbound create failed for ' . $ncUri . ' (status ' . (string)($status ?? '?') . '): ' . (string)$result['error'],
					['app' => Application::APP_ID],
				);
				return self::ERROR;
			}

			$googleId = isset($result['id']) ? (string)$result['id'] : $clientId;
			$this->eventMapService->recordLocalNew(
				$ncCalId, $ncUri, $uid, $ncEtag, $googleId,
				isset($result['updated']) ? (string)$result['updated'] : null,
				isset($result['etag']) ? (string)$result['etag'] : null,
			);
			$this->logger->info(
				'Calendar Bridge: created Google event ' . $googleId . ' from local ' . $ncUri . ' on calendar ' . $ncCalId,
				['app' => Application::APP_ID],
			);
			return self::CREATED;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: outbound create threw for ' . $ncUri . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return self::ERROR;
		}
	}

	/**
	 * A duplicate client id (HTTP 409) means we already created this event in a
	 * prior run that crashed before recording the map. Adopt it as success ONLY
	 * after confirming the colliding event is ours (its ncOrigin matches);
	 * otherwise it is an unrelated collision and we leave it for a human.
	 */
	private function adoptDuplicate(string $userId, string $calId, int $ncCalId, string $ncUri, string $uid, string $clientId, ?string $ncEtag): string {
		$existing = $this->googleApiService->request(
			$userId, 'calendar/v3/calendars/' . urlencode($calId) . '/events/' . urlencode($clientId),
		);
		if (isset($existing['error'])) {
			// Couldn't verify ownership — do NOT bind blindly. Leave it; the
			// next run retries (the deterministic id makes this safe to replay).
			$this->logger->warning(
				'Calendar Bridge: could not verify the 409-duplicate Google event ' . $clientId . ' for ' . $ncUri . '; not binding',
				['app' => Application::APP_ID],
			);
			return self::ERROR;
		}
		$origin = $existing['extendedProperties']['private']['ncOrigin'] ?? null;
		if ($origin === $ncUri) {
			$this->eventMapService->recordLocalNew(
				$ncCalId, $ncUri, $uid, $ncEtag, $clientId,
				isset($existing['updated']) ? (string)$existing['updated'] : null,
				isset($existing['etag']) ? (string)$existing['etag'] : null,
			);
			$this->logger->info(
				'Calendar Bridge: adopted existing Google event ' . $clientId . ' for local ' . $ncUri . ' (idempotent replay)',
				['app' => Application::APP_ID],
			);
			return self::DUPLICATE_ADOPTED;
		}
		$this->logger->warning(
			'Calendar Bridge: client id ' . $clientId . ' for local ' . $ncUri
				. ' collides with a Google event that is not ours (ncOrigin mismatch); not binding',
			['app' => Application::APP_ID],
		);
		return self::CONFLICT;
	}

	/**
	 * Push a Nextcloud-side edit (LOCAL_EDIT) to the mapped Google event via
	 * events.patch (a partial merge — leaves Google-side fields we don't manage,
	 * e.g. attendees, untouched) with If-Match on the stored baseline etag for
	 * optimistic concurrency. Never throws. Returns a status constant.
	 */
	public function updateLocalEventInGoogle(string $userId, string $calId, int $ncCalId, string $ncUri): string {
		try {
			$row = $this->eventMapService->getMasterRow($ncCalId, $ncUri);
			$googleId = $row?->getGoogleId();
			if ($row === null || $googleId === null || $googleId === '') {
				return self::SKIPPED_GONE;
			}
			$baselineEtag = $row->getBaselineEtag();
			if ($baselineEtag === null || $baselineEtag === '') {
				// No If-Match baseline -> a write would be a blind clobber of any
				// concurrent Google change. Refuse; an inbound sync backfills it.
				$this->logger->info(
					'Calendar Bridge: skipping outbound update of ' . $ncUri . ' (no etag baseline yet)',
					['app' => Application::APP_ID],
				);
				return self::SKIPPED_INDETERMINATE;
			}
			$obj = $this->caldavBackend->getCalendarObject($ncCalId, $ncUri);
			if (!is_array($obj) || !isset($obj['calendardata'])) {
				return self::SKIPPED_GONE;
			}
			$vevent = $this->firstVEvent((string)$obj['calendardata']);
			if ($vevent === null) {
				return self::SKIPPED_GONE;
			}
			if ($this->isRecurring($vevent)) {
				return self::SKIPPED_RECURRING;
			}

			$body = $this->buildEventFields($vevent, $ncUri);
			$result = $this->patchGoogleEvent($userId, $calId, $googleId, $body, $baselineEtag);

			if (isset($result['error'])) {
				$status = $result['statusCode'] ?? null;
				if ($status === 412) {
					return $this->resolveUpdateConflict($userId, $calId, $ncCalId, $ncUri, $googleId, $obj, $body);
				}
				if ($status === 404 || $status === 410) {
					// Event vanished on Google — drop the mapping; nothing to update.
					$this->eventMapService->removeForNcUri($ncCalId, $ncUri);
					$this->logger->info(
						'Calendar Bridge: update target ' . $googleId . ' gone (status ' . (string)$status . '); removed mapping',
						['app' => Application::APP_ID],
					);
					return self::SKIPPED_GONE;
				}
				$this->logger->warning(
					'Calendar Bridge: outbound update failed for ' . $ncUri . ' (status ' . (string)($status ?? '?') . '): ' . (string)$result['error'],
					['app' => Application::APP_ID],
				);
				return self::ERROR;
			}

			$this->recordUpdateResult($ncCalId, $ncUri, $obj, $result);
			$this->logger->info(
				'Calendar Bridge: updated Google event ' . $googleId . ' from local ' . $ncUri,
				['app' => Application::APP_ID],
			);
			return self::UPDATED;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: outbound update threw for ' . $ncUri . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return self::ERROR;
		}
	}

	/**
	 * events.patch via POST + X-HTTP-Method-Override (NC's IClient has no
	 * patch()), with If-Match for optimistic concurrency and sendUpdates=none.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function patchGoogleEvent(string $userId, string $calId, string $googleId, array $body, string $ifMatchEtag): array {
		$endpoint = 'calendar/v3/calendars/' . urlencode($calId) . '/events/' . urlencode($googleId) . '?sendUpdates=none';
		return $this->googleApiService->request($userId, $endpoint, $body, 'POST', null, [
			'If-Match' => $ifMatchEtag,
			'X-HTTP-Method-Override' => 'PATCH',
		]);
	}

	/**
	 * Resolve a 412 on update. Re-GET the live Google event for its true current
	 * 'updated' + etag (our stored google_updated is stale — it predates the
	 * concurrent Google change). Apply last-writer-wins: NC newer (or tie) ->
	 * single re-patch with the fresh etag; Google newer (or any ambiguity) ->
	 * abandon and let inbound re-pull Google's version. Never blind-clobbers.
	 *
	 * @param array<string, mixed> $obj
	 * @param array<string, mixed> $body
	 */
	private function resolveUpdateConflict(string $userId, string $calId, int $ncCalId, string $ncUri, string $googleId, array $obj, array $body): string {
		$live = $this->googleApiService->request($userId, 'calendar/v3/calendars/' . urlencode($calId) . '/events/' . urlencode($googleId));
		if (isset($live['error']) || !isset($live['etag'])) {
			$this->logger->warning(
				'Calendar Bridge: 412 on update of ' . $ncUri . ' and could not re-read the live event; abandoning (will retry)',
				['app' => Application::APP_ID],
			);
			return self::CONFLICT;
		}
		$ncLastMod = isset($obj['lastmodified']) ? (int)$obj['lastmodified'] : null;
		$googleUpdated = isset($live['updated']) && is_string($live['updated']) ? strtotime($live['updated']) : false;
		$winner = self::resolveConflict($ncLastMod, $googleUpdated === false ? null : $googleUpdated);
		if ($winner !== 'nc_wins') {
			$this->logger->info(
				'Calendar Bridge: 412 conflict on ' . $ncUri . ' resolved Google-wins (LWW); abandoning outbound, inbound will reconcile',
				['app' => Application::APP_ID],
			);
			return self::CONFLICT;
		}
		// NC wins: a single re-patch with the FRESH etag.
		$retry = $this->patchGoogleEvent($userId, $calId, $googleId, $body, (string)$live['etag']);
		if (isset($retry['error'])) {
			$this->logger->warning(
				'Calendar Bridge: 412 conflict on ' . $ncUri . ' NC-wins re-patch failed (tight race); will retry next tick',
				['app' => Application::APP_ID],
			);
			return self::CONFLICT;
		}
		$this->recordUpdateResult($ncCalId, $ncUri, $obj, $retry);
		$this->logger->info(
			'Calendar Bridge: 412 conflict on ' . $ncUri . ' resolved NC-wins (LWW); re-patched',
			['app' => Application::APP_ID],
		);
		return self::UPDATED;
	}

	/**
	 * @param array<string, mixed> $obj
	 * @param array<string, mixed> $result the patch response
	 */
	private function recordUpdateResult(int $ncCalId, string $ncUri, array $obj, array $result): void {
		$this->eventMapService->recordOutboundUpdate(
			$ncCalId, $ncUri,
			isset($obj['etag']) ? (string)$obj['etag'] : null,
			isset($result['updated']) ? (string)$result['updated'] : null,
			isset($result['etag']) ? (string)$result['etag'] : null,
		);
	}

	private function firstVEvent(string $calData): ?VEvent {
		$vcal = Reader::read($calData);
		$vevent = $vcal->{'VEVENT'} ?? null;
		if ($vevent instanceof VEvent) {
			return $vevent;
		}
		return null;
	}

	private function isRecurring(VEvent $vevent): bool {
		return isset($vevent->RRULE) || isset($vevent->RDATE) || isset($vevent->{'RECURRENCE-ID'});
	}

	/**
	 * The NC-derived fields we write to Google (summary/description/location/
	 * start/end) plus the ncOrigin ownership tag. Shared by insert (which adds
	 * the client id) and patch (which doesn't). Attendees are deliberately
	 * omitted (see class docblock) — and because a patch only touches the
	 * fields present here, omitting attendees on an UPDATE LEAVES any Google-
	 * side attendees/reminders untouched rather than wiping them.
	 *
	 * @return array<string, mixed>
	 */
	private function buildEventFields(VEvent $vevent, string $ncUri): array {
		$body = [
			'extendedProperties' => ['private' => ['ncOrigin' => $ncUri]],
		];
		foreach (['summary' => 'SUMMARY', 'description' => 'DESCRIPTION', 'location' => 'LOCATION'] as $key => $prop) {
			if (isset($vevent->{$prop}) && (string)$vevent->{$prop} !== '') {
				$body[$key] = (string)$vevent->{$prop};
			}
		}
		$dtstart = $vevent->DTSTART;
		$body['start'] = self::mapIcalDateToGoogle(
			$dtstart->getDateTime(),
			isset($dtstart['TZID']) ? (string)$dtstart['TZID'] : null,
			!$dtstart->hasTime(),
		);
		if (isset($vevent->DTEND)) {
			$dtend = $vevent->DTEND;
			$body['end'] = self::mapIcalDateToGoogle(
				$dtend->getDateTime(),
				isset($dtend['TZID']) ? (string)$dtend['TZID'] : null,
				!$dtend->hasTime(),
			);
		} else {
			$body['end'] = self::deriveMissingEnd($body['start'], !$dtstart->hasTime());
		}
		return $body;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildInsertBody(VEvent $vevent, string $ncUri, string $clientId): array {
		$body = $this->buildEventFields($vevent, $ncUri);
		$body['id'] = $clientId;
		return $body;
	}

	/**
	 * Last-writer-wins decision on a 412 conflict. Returns 'nc_wins' or
	 * 'google_wins'. Ties resolve to NC (mirrors the inbound rule). Any
	 * ambiguity (a timestamp we can't read) resolves to Google — the safe,
	 * non-clobbering direction. Pure.
	 *
	 * @param ?int $ncLastModified NC object lastmodified (unix seconds).
	 * @param ?int $googleUpdated Google event 'updated' as a unix timestamp.
	 */
	public static function resolveConflict(?int $ncLastModified, ?int $googleUpdated): string {
		if ($ncLastModified === null || $googleUpdated === null) {
			return 'google_wins';
		}
		return $ncLastModified >= $googleUpdated ? 'nc_wins' : 'google_wins';
	}

	/**
	 * Derive the Google end when the VEVENT has no DTEND. Pure.
	 *
	 * For an ALL-DAY event RFC 5545 implies a one-day duration, and Google's
	 * all-day end.date is EXCLUSIVE — so end.date == start.date is rejected
	 * (HTTP 400). Advance the end by one day. For a TIMED event a zero-duration
	 * (end == start) point-in-time event is accepted by Google.
	 *
	 * @param array<string, string> $start The already-mapped start object.
	 * @return array<string, string>
	 */
	public static function deriveMissingEnd(array $start, bool $isAllDay): array {
		if ($isAllDay && isset($start['date'])) {
			$end = (new \DateTimeImmutable($start['date']))->modify('+1 day');
			return ['date' => $end->format('Y-m-d')];
		}
		return $start;
	}

	/**
	 * Map an iCal start/end to a Google event date object. Pure.
	 * - all-day  -> {date: Y-m-d}   (end is exclusive on both sides; no off-by-one)
	 * - has TZID -> {dateTime: local wall time, timeZone: TZID}
	 * - else     -> {dateTime: RFC3339 with offset (Z for UTC)}
	 *
	 * @return array<string, string>
	 */
	public static function mapIcalDateToGoogle(DateTimeInterface $dt, ?string $tzid, bool $isAllDay): array {
		if ($isAllDay) {
			return ['date' => $dt->format('Y-m-d')];
		}
		if ($tzid !== null && $tzid !== '') {
			return ['dateTime' => $dt->format('Y-m-d\TH:i:s'), 'timeZone' => $tzid];
		}
		return ['dateTime' => $dt->format(DateTimeInterface::RFC3339)];
	}

	/**
	 * Deterministic Google event id from the iCal UID. sha1's hex output
	 * (0-9a-f, length 40) is a valid Google event id (base32hex alphabet is
	 * 0-9a-v, of which 0-9a-f is a subset; length must be 5-1024). Deterministic
	 * so a crash-replay re-POSTs the same id and hits 409 instead of
	 * duplicating. Pure.
	 */
	public static function deriveClientId(string $uid): string {
		return sha1($uid);
	}
}
