<?php

/**
 * Nextcloud - google
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\CalendarBridge\Service;

use DateTime;
use DateTimeZone;
use Ds\Set;
use Exception;
use Generator;
use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\BackgroundJob\ImportCalendarJob;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IL10N;

use Ortic\ColorConverter\Color;
use Ortic\ColorConverter\Colors\Named;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\PropPatch;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Throwable;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Service to make requests to Google v3 (JSON) API
 *
 * @phpstan-type Event array{id: string, iCalUID: string, etag?: string, start?: array{date?: string, dateTime?: string, timeZone?: string}, end?: array{date?: string, dateTime?: string, timeZone?: string}, originalStartTime?: array{date?: string, dateTime?: string, timeZone?: string}, recurringEventId?: string, colorId?: string, summary?: string, visibility?: string, sequence?: string, location?: string, description?: string, status?: string, created?: string, updated?: string, reminders?: array{useDefault?: bool, overrides?: list{array{minutes?: string, hours?: string, days?: string, weeks?: string}}}, recurrence?: list<string>, organizer?: array{email?: string, displayName?: string}, attendees?: list<array{email?: string, displayName?: string, responseStatus?: string, optional?: bool, resource?: bool}>, extendedProperties?: array{private?: array<string, string>, shared?: array<string, string>}}
 */
class GoogleCalendarAPIService {
	private DateTimeZone $utcTimezone;

	public function __construct(
		protected string $appName,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private CalDavBackend $caldavBackend,
		private IJobList $jobList,
		private GoogleAPIService $googleApiService,
		private IConfig $config,
		private EventMapService $eventMapService,
		private OutboundReconcileService $outboundReconcileService,
		private MapVerifyService $mapVerifyService,
		private CalendarMapService $calendarMapService,
	) {
		$this->utcTimezone = new DateTimeZone('-0000');
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function getCalendarList(string $userId): array {
		$result = $this->googleApiService->request($userId, 'calendar/v3/users/me/calendarList');
		if (isset($result['error']) || !isset($result['items'])) {
			return $result;
		}
		// Hide NC-ORIGIN calendars (created from a Nextcloud calendar): they are
		// managed from the "Your Nextcloud calendars" section, and showing them
		// here too would let the Sync/Two-way toggles silently desync the pair.
		return array_values(array_filter(
			$result['items'],
			fn (array $cal): bool => $this->calendarMapService->getNcCalIdForGoogleId((string)($cal['id'] ?? '')) === null,
		));
	}

	/**
	 * @param array{date?: string, dateTime?: string, timeZone?: string} $obj The datetime object to map.
	 * @return string The date time mapped to the best representation from the available data.
	 */
	private function mapTime(array $obj): string {
		if (isset($obj['dateTime'])) {
			$dateTime = new DateTime($obj['dateTime']);

			if (isset($obj['timeZone'])) {
				$timezone = $obj['timeZone'];
				$dateTime->setTimezone(new DateTimeZone($timezone));
				return "TZID=$timezone:" . $dateTime->format('Ymd\THis');
			} else {
				$dateTime->setTimezone($this->utcTimezone);
				return 'VALUE=DATE-TIME:' . $dateTime->format('Ymd\THis\Z');
			}
		} elseif (isset($obj['date'])) {
			// whole days
			$date = new DateTime($obj['date']);
			return 'VALUE=DATE:' . $date->format('Ymd');
		} else {
			// skip entries without any date
			return '';
		}
	}

	/**
	 * @param Event $e The event from which to generate the data.
	 * @param array<Event> $exceptions The events that represent recurring exceptions.
	 * @param int $ncCalId The id of the event's calendar.
	 * @param array $eventColors The event colors mapping.
	 */
	private function generateEventData(array $e, array $exceptions, int $ncCalId, array $eventColors): string {
		$eventData = 'BEGIN:VEVENT' . "\n";

		$eventData .= 'UID:' . strval($ncCalId) . '-' . $e['iCalUID'] . "\n";
		if (isset($e['colorId'], $eventColors[$e['colorId']], $eventColors[$e['colorId']]['background'])) {
			$closestCssColor = $this->getClosestCssColor($eventColors[$e['colorId']]['background']);
			$eventData .= 'COLOR:' . $closestCssColor . "\n";
		}
		$eventData .= isset($e['summary'])
			? ('SUMMARY:' . substr(str_replace("\n", '\n', $e['summary']), 0, 250) . "\n")
			: (($e['visibility'] ?? '') === 'private'
				? ('SUMMARY:' . $this->l10n->t('Private event') . "\n")
				: '');
		$eventData .= isset($e['sequence']) ? ('SEQUENCE:' . $e['sequence'] . "\n") : '';
		$eventData .= isset($e['location'])
			? ('LOCATION:' . substr(str_replace("\n", '\n', $e['location']), 0, 250) . "\n")
			: '';
		$eventData .= isset($e['description'])
			? ('DESCRIPTION:' . substr(str_replace("\n", '\n', $e['description']), 0, 250) . "\n")
			: '';
		$eventData .= isset($e['status']) ? ('STATUS:' . strtoupper(str_replace("\n", '\n', $e['status'])) . "\n") : '';

		if (isset($e['created'])) {
			$created = new DateTime($e['created']);
			$created->setTimezone($this->utcTimezone);
			$eventData .= 'CREATED:' . $created->format('Ymd\THis\Z') . "\n";
		}

		if (isset($e['updated'])) {
			$updated = new DateTime($e['updated']);
			$updated->setTimezone($this->utcTimezone);
			$eventData .= 'LAST-MODIFIED:' . $updated->format('Ymd\THis\Z') . "\n";
		}

		if (isset($e['organizer']['email'])) {
			$eventData .= $this->buildOrganizerLine($e['organizer']);
		}
		if (isset($e['attendees']) && is_array($e['attendees'])) {
			foreach ($e['attendees'] as $a) {
				$eventData .= $this->buildAttendeeLine($a);
			}
		}

		if (isset($e['reminders'], $e['reminders']['useDefault']) && $e['reminders']['useDefault']) {
			// 15 min before, default alarm
			$eventData .= 'BEGIN:VALARM' . "\n"
				. 'ACTION:DISPLAY' . "\n"
				. 'TRIGGER;RELATED=START:-PT15M' . "\n"
				. 'END:VALARM' . "\n";
		}
		if (isset($e['reminders'], $e['reminders']['overrides'])) {
			foreach ($e['reminders']['overrides'] as $o) {
				$nbMin = 0;
				if (isset($o['minutes'])) {
					$nbMin += (int)$o['minutes'];
				}
				if (isset($o['hours'])) {
					$nbMin += ((int)$o['hours']) * 60;
				}
				if (isset($o['days'])) {
					$nbMin += ((int)$o['days']) * 60 * 24;
				}
				if (isset($o['weeks'])) {
					$nbMin += ((int)$o['weeks']) * 60 * 24 * 7;
				}
				$eventData .= 'BEGIN:VALARM' . "\n"
					. 'ACTION:DISPLAY' . "\n"
					. 'TRIGGER;RELATED=START:-PT' . $nbMin . 'M' . "\n"
					. 'END:VALARM' . "\n";
			}
		}

		if (isset($e['recurrence']) && is_array($e['recurrence'])) {
			foreach ($e['recurrence'] as $r) {
				$eventData .= $r . "\n";
			}
		}

		// Cancelled instances of a recurring series come back from Google as
		// separate events with status:cancelled, recurringEventId, and
		// originalStartTime — but no start/end. Emit them as EXDATE lines on
		// the master so the instance disappears from the recurrence.
		$isMaster = !isset($e['recurringEventId']);
		if ($isMaster) {
			foreach ($exceptions as $candidateException) {
				if (
					($candidateException['recurringEventId'] ?? null) === $e['id']
					&& ($candidateException['status'] ?? null) === 'cancelled'
					&& isset($candidateException['originalStartTime'])
				) {
					$exdate = $this->mapTime($candidateException['originalStartTime']);
					if ($exdate !== '') {
						$eventData .= "EXDATE;$exdate\n";
					}
				}
			}
		}

		// skip entries without any date
		if (!isset($e['start']) || !isset($e['end'])) {
			return '';
		}

		$start = $this->mapTime($e['start']);
		$end = $this->mapTime($e['end']);

		// skip entries without any date
		if ($start == '' || $end == '') {
			return '';
		}

		$eventData .= "DTSTART;$start\n";
		$eventData .= "DTEND;$end\n";

		if (isset($e['recurringEventId'], $e['originalStartTime'])) {
			$recurrenceId = $this->mapTime($e['originalStartTime']);
			$eventData .= "RECURRENCE-ID;$recurrenceId\n";
		}

		$eventData .= 'CLASS:PUBLIC' . "\n"
			. 'END:VEVENT' . "\n";

		foreach ($exceptions as $candidateException) {
			if (
				$candidateException['recurringEventId'] == $e['id']
				&& $candidateException['id'] != $e['id']
				&& ($candidateException['status'] ?? null) !== 'cancelled'
			) {
				$eventData .= $this->generateEventData($candidateException, $exceptions, $ncCalId, $eventColors);
			}
		}

		return $eventData;
	}

	/**
	 * @param string $hexColor
	 * @return string closest CSS color name
	 */
	private function getClosestCssColor(string $hexColor): string {
		/** @var Color $color */
		$color = Color::fromString($hexColor);
		$rbgColor = [
			'r' => $color->getRed(),
			'g' => $color->getGreen(),
			'b' => $color->getBlue(),
		];
		// init
		$closestColor = 'black';
		$black = Color::fromString(Named::CSS_COLORS['black']);
		$rgbBlack = [
			'r' => $black->getRed(),
			'g' => $black->getGreen(),
			'b' => $black->getBlue(),
		];
		$closestDiff = $this->colorDiff($rbgColor, $rgbBlack);

		foreach (Named::CSS_COLORS as $name => $hex) {
			$c = Color::fromString($hex);
			$rgb = [
				'r' => $c->getRed(),
				'g' => $c->getGreen(),
				'b' => $c->getBlue(),
			];
			$diff = $this->colorDiff($rbgColor, $rgb);
			if ($diff < $closestDiff) {
				$closestDiff = $diff;
				$closestColor = $name;
			}
		}

		return $closestColor;
	}

	/**
	 * @param array{r:int, g:int, b:int} $rgb1 first color
	 * @param array{r:int, g:int, b:int} $rgb2 second color
	 *
	 * @return int the distance between colors
	 */
	private function colorDiff(array $rgb1, array $rgb2): int|float {
		return abs($rgb1['r'] - $rgb2['r']) + abs($rgb1['g'] - $rgb2['g']) + abs($rgb1['b'] - $rgb2['b']);
	}

	/**
	 * @param array{email?: string, displayName?: string} $organizer
	 */
	private function buildOrganizerLine(array $organizer): string {
		$email = (string)($organizer['email'] ?? '');
		if ($email === '') {
			return '';
		}
		$line = 'ORGANIZER';
		if (isset($organizer['displayName']) && $organizer['displayName'] !== '') {
			$line .= ';CN=' . $this->quoteIcalParam((string)$organizer['displayName']);
		}
		return $line . ':mailto:' . $email . "\n";
	}

	/**
	 * @param array{email?: string, displayName?: string, responseStatus?: string, optional?: bool, resource?: bool} $attendee
	 */
	private function buildAttendeeLine(array $attendee): string {
		$email = (string)($attendee['email'] ?? '');
		if ($email === '') {
			return '';
		}
		$params = [];
		if (isset($attendee['displayName']) && $attendee['displayName'] !== '') {
			$params[] = 'CN=' . $this->quoteIcalParam((string)$attendee['displayName']);
		}
		if (isset($attendee['resource']) && $attendee['resource']) {
			$params[] = 'CUTYPE=RESOURCE';
		}
		$params[] = 'ROLE=' . ((isset($attendee['optional']) && $attendee['optional']) ? 'OPT-PARTICIPANT' : 'REQ-PARTICIPANT');
		if (isset($attendee['responseStatus'])) {
			$partstat = match ($attendee['responseStatus']) {
				'accepted' => 'ACCEPTED',
				'declined' => 'DECLINED',
				'tentative' => 'TENTATIVE',
				'needsAction' => 'NEEDS-ACTION',
				default => null,
			};
			if ($partstat !== null) {
				$params[] = 'PARTSTAT=' . $partstat;
			}
		}
		return 'ATTENDEE;' . implode(';', $params) . ':mailto:' . $email . "\n";
	}

	/**
	 * Wrap an iCal parameter value in double quotes (always-quote is always
	 * valid per RFC 5545 §3.1). Strips CR/LF (forbidden in params) and
	 * substitutes single quotes for embedded double quotes.
	 */
	private function quoteIcalParam(string $value): string {
		return '"' . str_replace(["\r", "\n", '"'], ['', '', "'"], $value) . '"';
	}

	/**
	 * Return the unique TZID values referenced by a chunk of iCal text.
	 *
	 * Matches the `TZID=...` form that mapTime() emits on DTSTART, DTEND,
	 * EXDATE, RECURRENCE-ID. Case-insensitive on the keyword; preserves the
	 * TZID value as written.
	 */
	private function extractTzids(string $icalText): array {
		preg_match_all('/(?:^|;)TZID=([^:;\r\n]+)/i', $icalText, $matches);
		return array_values(array_unique($matches[1] ?? []));
	}

	/**
	 * Emit a VTIMEZONE block for an IANA TZID, with one STANDARD and (if the
	 * zone observes DST) one DAYLIGHT subcomponent populated from the most
	 * recent transition of each kind. No RRULE is emitted — clients with a
	 * tz database (all modern ones) resolve future dates from the TZID
	 * itself; the subcomponents anchor the offset for the current era.
	 *
	 * Returns an empty string if the TZID isn't resolvable.
	 */
	private function buildVTimezoneBlock(string $tzid): string {
		try {
			$tz = new DateTimeZone($tzid);
		} catch (Exception $e) {
			return '';
		}

		$now = time();
		$transitions = $tz->getTransitions($now - 60 * 60 * 24 * 400, $now + 60 * 60 * 24 * 400);
		if (!$transitions) {
			return '';
		}

		$standard = null;
		$daylight = null;
		foreach ($transitions as $i => $t) {
			$prev = $i > 0 ? $transitions[$i - 1] : $t;
			if ($t['isdst']) {
				$daylight = ['t' => $t, 'prev' => $prev];
			} else {
				$standard = ['t' => $t, 'prev' => $prev];
			}
		}

		$block = "BEGIN:VTIMEZONE\nTZID:" . $tzid . "\n";
		if ($standard !== null) {
			$block .= $this->buildVTimezoneSubcomponent('STANDARD', $standard['t'], $standard['prev']);
		}
		if ($daylight !== null) {
			$block .= $this->buildVTimezoneSubcomponent('DAYLIGHT', $daylight['t'], $daylight['prev']);
		}
		return $block . "END:VTIMEZONE\n";
	}

	/**
	 * @param array{ts: int, offset: int, isdst: bool, abbr: string, time: string} $t
	 * @param array{ts: int, offset: int, isdst: bool, abbr: string, time: string} $prev
	 */
	private function buildVTimezoneSubcomponent(string $kind, array $t, array $prev): string {
		// DTSTART in a VTIMEZONE subcomponent is local clock time at the
		// moment of transition, expressed using the previous offset.
		$localTs = $t['ts'] + $prev['offset'];
		$dtstart = gmdate('Ymd\THis', $localTs);
		return 'BEGIN:' . $kind . "\n"
			. 'DTSTART:' . $dtstart . "\n"
			. 'TZOFFSETFROM:' . $this->formatTzOffset($prev['offset']) . "\n"
			. 'TZOFFSETTO:' . $this->formatTzOffset($t['offset']) . "\n"
			. 'END:' . $kind . "\n";
	}

	private function formatTzOffset(int $seconds): string {
		$sign = $seconds < 0 ? '-' : '+';
		$abs = abs($seconds);
		return sprintf('%s%02d%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60));
	}

	/**
	 * Get last modified timestamp from the calendar data of a calendar object
	 *
	 * @param string $calData
	 * @return int|null
	 * @throws Exception
	 */
	private function getEventLastModifiedTimestamp(string $calData): ?int {
		/** @var VCalendar $vCalendar */
		$vCalendar = Reader::read($calData);
		/** @var VEvent $vEvent */
		$vEvent = $vCalendar->{'VEVENT'};
		$iCalEvents = $vEvent->getIterator();
		foreach ($iCalEvents as $event) {
			if (isset($event->{'LAST-MODIFIED'})) {
				$lastMod = $event->{'LAST-MODIFIED'};
				if (is_string($lastMod)) {
					return (new DateTime($lastMod))->getTimestamp();
				} elseif ($lastMod instanceof \Sabre\VObject\Property\ICalendar\DateTime) {
					return $lastMod->getDateTime()->getTimestamp();
				}
			}
		}
		return null;
	}

	/**
	 * Apply a genuine Google-side change to an NC-OWNED event (one we pushed,
	 * carrying ncOrigin) back into its Nextcloud object — the inbound half of
	 * bidirectional LWW when Google wins. Writes to URI=$ncOrigin (NOT the
	 * google id) and PRESERVES the NC object's original UID, since
	 * generateEventData would otherwise stamp the Google-derived UID and change
	 * the event's identity for CalDAV clients. Records the new baseline so the
	 * outbound reconcile classifies the write as an ECHO. Never throws.
	 *
	 * Returns one of:
	 *   'applied'    - written to NC successfully.
	 *   'unmappable' - the Google event cannot be represented in NC (no mappable
	 *                  start/end). PERMANENT — the caller parks it instead of
	 *                  retrying, since a re-pull yields the same unmappable data.
	 *   'error'      - a (possibly transient) write failure; the caller may retry.
	 *
	 * @param Event $e
	 * @param array<int, Event> $exceptions
	 * @param array<string, array{background?: string}> $eventColors
	 */
	private function applyRemoteToNcOrigin(array $e, array $exceptions, int $ncCalId, string $ncOrigin, array $eventColors, string $originalCalData): string {
		try {
			$eventData = $this->generateEventData($e, $exceptions, $ncCalId, $eventColors);
			if ($eventData === '') {
				$this->logger->warning(
					'Calendar Bridge: cannot pull Google change into NC-owned event ' . $ncOrigin . ' (no mappable event data)',
					['app' => Application::APP_ID],
				);
				return 'unmappable';
			}
			// Preserve the NC object's original UID (generateEventData stamps the
			// Google-derived UID, which would change the event's identity for
			// CalDAV clients). Read it via Sabre so a long, RFC5545 line-FOLDED UID
			// is unfolded correctly, and rewrite EVERY VEVENT's UID (master + any
			// recurrence overrides) so the override and master stay consistent.
			// A parse failure of the stored original is NON-fatal: degrade to the
			// generated UID rather than failing (and then looping) the whole apply.
			try {
				$origVcal = Reader::read($originalCalData);
				$origVevent = $origVcal->{'VEVENT'} ?? null;
				if ($origVevent instanceof VEvent && isset($origVevent->UID)) {
					$origUid = (string)$origVevent->UID;
					$eventData = (string)preg_replace_callback('/^UID:.*$/m', static fn (): string => 'UID:' . $origUid, $eventData);
				}
			} catch (Throwable $ex) {
				$this->logger->warning(
					'Calendar Bridge: could not parse stored original for ' . $ncOrigin . ' to preserve its UID; applying with the generated UID',
					['app' => Application::APP_ID],
				);
			}
			$vtimezones = '';
			foreach ($this->extractTzids($eventData) as $tzid) {
				$vtimezones .= $this->buildVTimezoneBlock($tzid);
			}
			$calData = 'BEGIN:VCALENDAR' . "\n"
				. 'VERSION:2.0' . "\n"
				. 'PRODID:NextCloud Calendar' . "\n"
				. $vtimezones
				. $eventData
				. 'END:VCALENDAR';
			$ncEtag = $this->caldavBackend->updateCalendarObject($ncCalId, $ncOrigin, $calData);
			// nc_etag = our write's etag (so the reconcile reads ECHO, not edit);
			// baseline = Google's etag we just applied; google_updated likewise.
			$this->eventMapService->recordOutboundUpdate(
				$ncCalId, $ncOrigin, $ncEtag,
				isset($e['updated']) ? (string)$e['updated'] : null,
				isset($e['etag']) ? (string)$e['etag'] : null,
			);
			// Phase 4: a recurring NC-owned series re-renders its overrides inline
			// (generateEventData consumed $exceptions). Refresh the sibling baselines
			// to the live exception etags so the same instances read as a pure echo
			// next tick instead of looking like fresh Google-side drift.
			$this->refreshSeriesSiblings($ncCalId, $ncOrigin, (string)$e['id'], $exceptions);
			return 'applied';
		} catch (Exception|Throwable $ex) {
			$this->logger->warning(
				'Calendar Bridge: failed to pull Google change into NC-owned event ' . $ncOrigin . ': ' . $ex->getMessage(),
				['app' => Application::APP_ID],
			);
			return 'error';
		}
	}

	/**
	 * Whether any live OVERRIDE of an NC-owned recurring series differs from our
	 * recorded sibling baseline — a Google-side single-instance edit that the
	 * master etag may not reflect. Used to flip a pure-echo master to a genuine
	 * change so the series re-renders into NC. (Google-side cancellations are not
	 * detected here; they propagate on the periodic full pull.)
	 *
	 * @param array<int, Event> $exceptions
	 */
	private function seriesHasGoogleDrift(int $ncCalId, string $ncOrigin, string $masterId, array $exceptions): bool {
		$baselineByKey = [];
		foreach ($this->eventMapService->findSiblings($ncCalId, $ncOrigin) as $row) {
			$k = RecurrenceKey::fromGoogleToken($row->getRecurrenceId());
			if ($k !== null) {
				$baselineByKey[$k] = $row->getBaselineEtag();
			}
		}
		foreach ($exceptions as $ex) {
			if ((string)($ex['recurringEventId'] ?? '') !== $masterId || ($ex['status'] ?? '') === 'cancelled') {
				continue;
			}
			$k = RecurrenceKey::fromGoogleInstance($ex);
			if ($k === null) {
				continue;
			}
			$base = $baselineByKey[$k] ?? null;
			$exEtag = isset($ex['etag']) ? (string)$ex['etag'] : null;
			if ($base === null || ($exEtag !== null && $exEtag !== $base)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * After applying a Google series into an NC-owned object, record each live
	 * override's current etag as the sibling baseline (origin='nc'), so its echo
	 * is recognised next tick.
	 *
	 * @param array<int, Event> $exceptions
	 */
	private function refreshSeriesSiblings(int $ncCalId, string $ncOrigin, string $masterId, array $exceptions): void {
		foreach ($exceptions as $ex) {
			if ((string)($ex['recurringEventId'] ?? '') !== $masterId || ($ex['status'] ?? '') === 'cancelled') {
				continue;
			}
			$token = EventMapService::recurrenceIdToken($ex);
			$exId = (string)($ex['id'] ?? '');
			if ($token === '' || $exId === '') {
				continue;
			}
			$this->eventMapService->recordOutboundSibling(
				$ncCalId, $ncOrigin, $token, $exId,
				isset($ex['updated']) ? (string)$ex['updated'] : null,
				isset($ex['etag']) ? (string)$ex['etag'] : null,
			);
		}
	}

	/**
	 * Seed the outbound differ's refusal-guard baselines (shape/RRULE/DTSTART)
	 * for an imported RECURRING series, so the FIRST NC edit is diffed against the
	 * pre-edit shape (a DTSTART move / shape flip / this-and-following split on an
	 * imported series would otherwise bypass the guards, which only fire on a
	 * non-null baseline). Only for recurring events; cheap; defensive.
	 *
	 * @param Event $e
	 */
	private function seedImportedSeriesBaseline(int $ncCalId, string $objectUri, array $e, string $calData): void {
		if (!isset($e['recurrence'])) {
			return;
		}
		$b = OutboundRecurrenceService::seriesBaselineFromCalData($calData);
		if ($b !== null) {
			$this->eventMapService->recordSeriesBaseline($ncCalId, $objectUri, $b['shape'], $b['rrule'], $b['dtstartSig']);
		}
	}

	/**
	 * get the most recent event update date in a calendar
	 *
	 * @param int $calendarId
	 * @return int
	 */
	private function getCalendarLastEventModificationTimestamp(int $calendarId): int {
		$objects = $this->caldavBackend->getCalendarObjects($calendarId);
		$lastModifieds = array_map(static function (array $object) {
			return $object['lastmodified'] ?? 0;
		}, $objects);
		return max($lastModifieds);
	}

	/**
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return array{error: string}|array{nbAdded: int, nbUpdated: int, nbDeleted: int, calName: string}
	 */
	public function safeImportCalendar(string $userId, string $calId, string $calName, ?string $color = null): array {
		$startTime = microtime(true);
		$this->logger->debug("Starting calendar import of $calId", ['app' => $this->appName]);

		$lockFile = sys_get_temp_dir()
			. '/nextcloud_outside_provider_calendar_bridge_calendar_import_'
			. md5($calId) . '.lock';

		// Atomic, crash-safe locking: flock(LOCK_EX|LOCK_NB) either grabs the
		// lock or fails immediately if another sync of this calendar is in
		// progress. Unlike the previous file_exists()+touch() check (a TOCTOU
		// race that also left a permanent stale lock if a process died
		// mid-sync), the kernel releases an flock when the handle closes or
		// the process exits. This matters now that an outbound writer will
		// contend for the same calendar.
		$handle = fopen($lockFile, 'c');
		if ($handle === false) {
			throw new Exception('Could not open calendar import lock file');
		}
		if (!flock($handle, LOCK_EX | LOCK_NB)) {
			fclose($handle);
			throw new Exception('Could not acquire lock');
		}

		try {
			return $this->importCalendar($userId, $calId, $calName, $color);
		} finally {
			$this->logger->debug('Elapsed time is: ' . (microtime(true) - $startTime) . ' seconds', ['app' => $this->appName]);
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/**
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return array{error: string}|array{nbAdded: int, nbUpdated: int, nbDeleted: int, calName: string}
	 */
	public function importCalendar(string $userId, string $calId, string $calName, ?string $color = null): array {
		$params = [];
		if ($color) {
			$params['{http://apple.com/ns/ical/}calendar-color'] = $color;
		}

		$newCalName = trim($calName) . ' (' . $this->l10n->t('Google Calendar import') . ')';
		$params['{DAV:}displayname'] = $newCalName;

		// Map-first resolution: an NC-ORIGINATED pairing links a PRE-EXISTING
		// Nextcloud calendar (its own URI) to this Google calendar id. Use it
		// directly and NEVER create or rename it — it is the user's own calendar.
		// Falls through to the legacy "URI = urlencode(Google id)" scheme for
		// Google-originated imports. The calendar map is empty until the
		// NC -> Google create flow (P-c) populates it, so existing installs are
		// unaffected.
		$mappedNcCalId = $this->calendarMapService->getNcCalIdForGoogleId($calId);
		if ($mappedNcCalId !== null) {
			$ncCalId = $mappedNcCalId;
			$calendarIsNew = false;
		} else {
			// Use the Google calendar ID (stable across renames) as the CalDAV
			// URI rather than the display name. Pre-Phase-2 calendars were
			// created under urlencode(displayName) — fall back to that on lookup
			// so existing installs keep working without a rename/migration.
			$newCalUri = urlencode($calId);

			$existing = $this->caldavBackend->getCalendarByUri('principals/users/' . $userId, $newCalUri);
			if ($existing === null) {
				$legacyUri = urlencode($newCalName);
				$existing = $this->caldavBackend->getCalendarByUri('principals/users/' . $userId, $legacyUri);
			}
			$ncCalId = $existing['id'] ?? null;
			$calendarIsNew = is_null($ncCalId);
			if (is_null($ncCalId)) {
				$ncCalId = $this->caldavBackend->createCalendar('principals/users/' . $userId, $newCalUri, $params);
			} elseif (($existing['{DAV:}displayname'] ?? null) !== $newCalName) {
				// Propagate a Google-side rename to the existing NC calendar.
				// Skipped when the value hasn't changed so we don't churn
				// CalDAV sync state on every tick.
				$propPatch = new PropPatch(['{DAV:}displayname' => $newCalName]);
				$this->caldavBackend->updateCalendar($ncCalId, $propPatch);
				$propPatch->commit();
			}
		}

		/** @var Set<string> $unseenURIs */
		$unseenURIs = new Set();
		$existingObjects = $this->caldavBackend->getCalendarObjects($ncCalId);
		/** @var array{uri: string} $e */
		foreach ($existingObjects as $e) {
			$unseenURIs->add($e['uri']);
		}

		// Phase-0 bidirectional-sync observability: lazily seed the event map
		// from existing imported objects the first time (steady-state sync
		// skips unchanged events and would never record them). Purely
		// additive — no behavior change to the one-way import.
		$this->eventMapService->seedFromExistingIfEmpty($ncCalId, $existingObjects);

		// get color list
		$eventColors = [];
		/** @type array{error: string}|array{event: array} $colors */
		$colors = $this->googleApiService->request($userId, 'calendar/v3/colors');
		if (!isset($colors['error']) && isset($colors['event'])) {
			$eventColors = $colors['event'];
		}

		date_default_timezone_set('UTC');
		$allEvents = $this->config->getUserValue($userId, Application::APP_ID, 'consider_all_events', '1') === '1';

		// syncToken is incompatible with eventTypes filtering, so only use
		// incremental sync when the user wants all event types (the default).
		$useSyncToken = $allEvents;
		$syncTokenKey = $this->syncTokenConfigKey($calId);
		$syncToken = $useSyncToken
			? $this->config->getUserValue($userId, Application::APP_ID, $syncTokenKey, '')
			: '';
		$isIncremental = $syncToken !== '';

		$eventsGenerator = $this->getCalendarEvents($userId, $calId, $allEvents, $syncToken);

		// Normal events
		$events = [];
		// Exceptions to recurring events (recurringEventId set).
		$exceptions = [];

		foreach ($eventsGenerator as $e) {
			if (isset($e['recurringEventId'])) {
				array_push($exceptions, $e);
			} else {
				array_push($events, $e);
			}
		}
		$genReturn = $eventsGenerator->getReturn();

		// 410 GONE means Google invalidated the syncToken (too old, or the
		// calendar shape changed). Drop the token and fall back to a full pull
		// on the same tick.
		if ($isIncremental && $this->isSyncTokenExpiredError($genReturn)) {
			$this->logger->info('Sync token expired for ' . $calId . ', falling back to full pull', ['app' => Application::APP_ID]);
			$this->config->deleteUserValue($userId, Application::APP_ID, $syncTokenKey);
			$syncToken = '';
			$isIncremental = false;
			$eventsGenerator = $this->getCalendarEvents($userId, $calId, $allEvents, '');
			$events = [];
			$exceptions = [];
			foreach ($eventsGenerator as $e) {
				if (isset($e['recurringEventId'])) {
					array_push($exceptions, $e);
				} else {
					array_push($events, $e);
				}
			}
			$genReturn = $eventsGenerator->getReturn();
		}

		$nbAdded = 0;
		$nbUpdated = 0;
		$nbDeleted = 0;
		// Set when a google-wins apply of an NC-owned event fails transiently:
		// forces the next pull to be FULL so Google re-delivers the (otherwise
		// unchanged) event and we retry — an incremental pull would not.
		$forceFullPullNext = false;
		/** @var array<string, true> master google-ids seen in $events this pull */
		$seenMasterIds = [];

		/** @var Event $e */
		foreach ($events as $e) {
			$objectUri = $e['id'];
			$seenMasterIds[$objectUri] = true;

			// Bidirectional-sync echo indirection: an event WE wrote to Google
			// (Phase 2b) carries extendedProperties.private.ncOrigin = the NC
			// object URI. When it echoes back here we must never mint a duplicate
			// NC object under URI=$e['id']; we also drop $ncOrigin from
			// $unseenURIs (the real object lives under URI=$ncOrigin, not the
			// google id, so without this the full-pull deletion sweep would
			// delete the user's own event).
			$ncOrigin = $e['extendedProperties']['private']['ncOrigin'] ?? null;
			if ($ncOrigin !== null && $ncOrigin !== '') {
				$original = $this->caldavBackend->getCalendarObject($ncCalId, $ncOrigin);
				if ($original !== null) {
					// An event we own is echoing back. Three states, decided by the
					// recorded baseline etag AND the NC object's own etag:
					//  - incoming etag == baseline: Google is unchanged since our
					//    last write — a pure echo. Bind google_id and skip.
					//  - Google changed (etag != baseline) but the NC object is
					//    UNCHANGED since our last write (current etag == nc_etag):
					//    the Google change is unambiguously newer than anything on
					//    our side, so apply it — no cross-clock timestamp guess.
					//  - BOTH sides changed (Google etag != baseline AND NC object
					//    etag != nc_etag): a real conflict — resolve by the SAME
					//    pure last-writer-wins rule the outbound path uses, so the
					//    two paths can never disagree and wedge.
					// google-wins pulls Google's version into the NC object (UID
					// preserved) so the same-tick reconcile sees an ECHO; nc-wins
					// refreshes the baseline so the reconcile's outbound patch (the
					// NC edit IS in this tick's delta) applies over Google's older
					// edit. Comparing the NC etag first avoids letting a re-stamped
					// NC lastmodified (set by our own apply) masquerade as a user
					// edit on a clock that may differ from Google's.
					$row = $this->eventMapService->getMasterRow($ncCalId, $ncOrigin);
					$baseline = $row?->getBaselineEtag();
					$incomingEtag = isset($e['etag']) ? (string)$e['etag'] : null;
					$pureEcho = $baseline !== null && $incomingEtag !== null && $incomingEtag === $baseline;
					// Phase 4: a Google-side edit to a single OCCURRENCE often leaves
					// the master etag unchanged, so a "pure echo" master can still hide
					// a drifted override — re-render the series in that case too.
					if ($pureEcho && $this->seriesHasGoogleDrift($ncCalId, $ncOrigin, $objectUri, $exceptions)) {
						$pureEcho = false;
					}
					if ($pureEcho) {
						$this->eventMapService->bindGoogleIdForNcUri($ncCalId, $ncOrigin, $objectUri, $e['updated'] ?? null, $incomingEtag);
					} else {
						$mapNcEtag = $row?->getNcEtag();
						$currentNcEtag = isset($original['etag']) ? (string)$original['etag'] : null;
						$ncEdited = $mapNcEtag === null || $currentNcEtag === null || $currentNcEtag !== $mapNcEtag;
						if ($ncEdited) {
							$ncLastMod = isset($original['lastmodified']) ? (int)$original['lastmodified'] : null;
							$googleUpdated = isset($e['updated']) ? strtotime((string)$e['updated']) : false;
							$winner = OutboundWriteService::resolveConflict($ncLastMod, $googleUpdated === false ? null : $googleUpdated);
						} else {
							$winner = 'google_wins';
						}
						if ($winner === 'google_wins') {
							$applied = $this->applyRemoteToNcOrigin($e, $exceptions, $ncCalId, $ncOrigin, $eventColors, (string)$original['calendardata']);
							if ($applied === 'applied') {
								$nbUpdated++;
								// Also (re-)bind google_id/origin: applyRemoteToNcOrigin
								// preserves an existing row but would not set them if
								// the row were somehow absent (a crashed create).
								$this->eventMapService->bindGoogleIdForNcUri($ncCalId, $ncOrigin, $objectUri, $e['updated'] ?? null, $incomingEtag);
								$this->logger->info(
									'Calendar Bridge: pulled Google-side change into NC-owned event ' . $ncOrigin . ' (LWW: google wins)',
									['app' => Application::APP_ID],
								);
							} elseif ($applied === 'unmappable') {
								// PERMANENT: the Google version can't be represented in
								// NC. PARK it — bind the incoming etag as the baseline so
								// it reads as a pure echo next tick and we stop retrying
								// (a re-pull yields the same data). Self-heals if Google
								// later makes the event mappable (etag moves again).
								$this->eventMapService->bindGoogleIdForNcUri($ncCalId, $ncOrigin, $objectUri, $e['updated'] ?? null, $incomingEtag);
							} else {
								// Possibly-transient failure. Keep google_id bound, leave
								// the baseline STALE (a clobber is impossible — an outbound
								// patch would 412 -> google-wins -> abandon). Retry via a
								// FULL pull, but ONLY from an incremental tick: if the
								// forced full pull fails again it will NOT re-force (that
								// tick is non-incremental), so a permanent failure parks
								// after one retry instead of looping.
								$this->eventMapService->bindGoogleIdForNcUri($ncCalId, $ncOrigin, $objectUri, null, null);
								if ($isIncremental) {
									$forceFullPullNext = true;
								}
							}
						} else {
							$this->eventMapService->bindGoogleIdForNcUri($ncCalId, $ncOrigin, $objectUri, $e['updated'] ?? null, $incomingEtag);
						}
					}
					if ($unseenURIs->contains($ncOrigin)) {
						$unseenURIs->remove($ncOrigin);
					}
					if ($unseenURIs->contains($objectUri)) {
						$unseenURIs->remove($objectUri);
					}
					continue;
				}
				// The original NC object is gone. If we still hold an nc-origin
				// map row for this Google id, the user DELETED an event we
				// pushed before its echo arrived — do NOT resurrect it as a
				// fresh import (which would also collide on the google_id index
				// and then be re-pushed as a duplicate on the real calendar).
				// Leave the Google-side delete to a later phase.
				if ($this->eventMapService->hasNcOriginRowForGoogleId($ncCalId, $objectUri)) {
					if ($unseenURIs->contains($objectUri)) {
						$unseenURIs->remove($objectUri);
					}
					continue;
				}
				// Otherwise: a genuinely foreign Google event that merely
				// carries a stale ncOrigin tag — fall through to normal import.
			}

			// If this event exists in NC, remove it from the set of events to be
			// deleted. Continue processing it, it could have been updated.
			if ($unseenURIs->contains($objectUri)) {
				$unseenURIs->remove($objectUri);
			}

			// On incremental sync, cancelled master events arrive explicitly
			// rather than via the "absent from list = deleted" inference used
			// for full pulls. Delete them now.
			if ($isIncremental && ($e['status'] ?? null) === 'cancelled') {
				try {
					$this->caldavBackend->deleteCalendarObject($ncCalId, $objectUri, $this->caldavBackend::CALENDAR_TYPE_CALENDAR, true);
					$nbDeleted++;
					$this->eventMapService->removeForNcUri($ncCalId, $objectUri);
				} catch (Exception|Throwable $ex) {
					$this->logger->warning('Error when deleting calendar event ' . $ex->getMessage(), ['app' => Application::APP_ID]);
				}
				continue;
			}

			$existingEvent = null;
			// check if we should update existing events (on existing calendars only :-)
			if (!$calendarIsNew) {
				// check if it already exists and if we should update it
				$existingEvent = $this->caldavBackend->getCalendarObject($ncCalId, $objectUri);
				if ($existingEvent !== null) {
					if (!isset($e['updated'])) {
						continue;
					}
					$remoteEventUpdatedTimestamp = (new DateTime($e['updated']))->getTimestamp();
					$localEventUpdatedTimestamp = $this->getEventLastModifiedTimestamp($existingEvent['calendardata']);
					if ($localEventUpdatedTimestamp !== null && $remoteEventUpdatedTimestamp <= $localEventUpdatedTimestamp) {
						continue;
					}
				}
			}

			$eventData = $this->generateEventData($e, $exceptions, $ncCalId, $eventColors);

			if ($eventData == '') {
				continue;
			}

			$vtimezones = '';
			foreach ($this->extractTzids($eventData) as $tzid) {
				$vtimezones .= $this->buildVTimezoneBlock($tzid);
			}

			$calData = 'BEGIN:VCALENDAR' . "\n"
				. 'VERSION:2.0' . "\n"
				. 'PRODID:NextCloud Calendar' . "\n"
				. $vtimezones
				. $eventData
				. 'END:VCALENDAR';

			if ($existingEvent !== null) {
				try {
					$ncEtag = $this->caldavBackend->updateCalendarObject($ncCalId, $objectUri, $calData);
					$nbUpdated++;
					$this->eventMapService->recordFromImport($ncCalId, $e, $exceptions, !$isIncremental, $ncEtag);
					$this->seedImportedSeriesBaseline($ncCalId, $objectUri, $e, $calData);
				} catch (Exception|Throwable $ex) {
					$this->logger->warning('Error when updating calendar event ' . $ex->getMessage(), ['app' => Application::APP_ID]);
				}
			} else {
				try {
					$ncEtag = $this->caldavBackend->createCalendarObject($ncCalId, $objectUri, $calData);
					$nbAdded++;
					$this->eventMapService->recordFromImport($ncCalId, $e, $exceptions, !$isIncremental, $ncEtag);
					$this->seedImportedSeriesBaseline($ncCalId, $objectUri, $e, $calData);
				} catch (BadRequest $ex) {
					if (strpos($ex->getMessage(), 'uid already exists') !== false) {
						$this->logger->debug('Skip existing event', ['app' => Application::APP_ID]);
					} else {
						$this->logger->warning('Error when creating calendar event "' . '<redacted>' . '" ' . $ex->getMessage(), ['app' => Application::APP_ID]);
					}
				} catch (Exception|Throwable $ex) {
					$this->logger->warning('Error when creating calendar event "' . '<redacted>' . '" ' . $ex->getMessage(), ['app' => Application::APP_ID]);
				}
			}
		}

		// Phase 4: on an INCREMENTAL pull a Google-side single-occurrence edit
		// arrives as an exception WITHOUT its master, so the ncOrigin gate above
		// never sees the series. If such an orphan exception belongs to an NC-owned
		// series and genuinely drifted, force a FULL pull so the master re-renders
		// the override inline next tick (where the sibling-aware gate applies it).
		if ($isIncremental && !$forceFullPullNext) {
			$checkedMasters = [];
			foreach ($exceptions as $ex) {
				$rid = (string)($ex['recurringEventId'] ?? '');
				if ($rid === '' || isset($seenMasterIds[$rid]) || isset($checkedMasters[$rid])) {
					continue;
				}
				$checkedMasters[$rid] = true;
				$masterRow = $this->eventMapService->findNcOriginMasterByGoogleId($ncCalId, $rid);
				if ($masterRow !== null && $this->seriesHasGoogleDrift($ncCalId, $masterRow->getNcUri(), $rid, $exceptions)) {
					$forceFullPullNext = true;
					$this->logger->info(
						'Calendar Bridge: Google-side instance edit on NC-owned series ' . $masterRow->getNcUri() . '; forcing a full pull to apply it',
						['app' => Application::APP_ID],
					);
					break;
				}
			}
		}

		// On a full pull, anything we never saw in Google's response is now
		// deleted there too. On incremental, Google explicitly reports
		// cancellations (handled above), so $unseenURIs is meaningless and
		// must NOT be drained — every untouched event would be wrongly wiped.
		//
		// Also skip the deletion if the generator returned an error: in that
		// case $events may be partial or empty not because Google's calendar
		// is empty but because the request failed (token expiry, 5xx,
		// network blip). Deleting the locally-stored events here would erase
		// the user's data on a transient API failure.
		$apiErrored = is_array($genReturn) && isset($genReturn['error']);
		if (!$isIncremental && !$apiErrored) {
			foreach ($unseenURIs as $uri) {
				$this->caldavBackend->deleteCalendarObject($ncCalId, $uri, $this->caldavBackend::CALENDAR_TYPE_CALENDAR, true);
				$this->eventMapService->removeForNcUri($ncCalId, $uri);
			}
		}

		// Persist the new sync token. On incremental, if any cancelled
		// recurring-instance arrived, clear the token instead — the existing
		// EXDATE generation in generateEventData() needs the full exception
		// list to fire correctly, so we force the next tick to be a full
		// pull rather than patch the master inline here. On a full pull the
		// EXDATE path already ran, so save normally.
		if ($useSyncToken) {
			$forceFullNext = $forceFullPullNext;
			if ($isIncremental) {
				foreach ($exceptions as $ex) {
					if (($ex['status'] ?? null) === 'cancelled') {
						$forceFullNext = true;
						break;
					}
				}
			}
			if ($forceFullNext) {
				$this->config->deleteUserValue($userId, Application::APP_ID, $syncTokenKey);
			} elseif (is_array($genReturn) && !empty($genReturn['nextSyncToken'])) {
				$this->config->setUserValue($userId, Application::APP_ID, $syncTokenKey, $genReturn['nextSyncToken']);
			}
		}

		// Surface API errors to the caller. BackgroundJob and Controller
		// both already check `isset($result['error'])`, but pre-Phase-2 the
		// check was dormant because importCalendar() always returned the
		// success shape — so a fully failed sync (e.g. expired token, 5xx,
		// 404 on a bad calId) silently looked like "Added 0" in the cron
		// output and a 200 in the API. Now the error propagates and those
		// checks fire.
		if (is_array($genReturn) && isset($genReturn['error'])) {
			return ['error' => $genReturn['error']];
		}

		// Phase 2a (bidirectional sync): after a successful inbound import,
		// dry-run the outbound reconcile for calendars the user has opted into
		// two-way. Self-gated (default off), logs only, writes nothing to
		// Google, and is internally defensive — cannot affect the import.
		$this->outboundReconcileService->reconcile($userId, $calId, $ncCalId);

		// Operational hardening: a periodic, conservative "trust-but-verify" pass
		// over the event map vs both live sides (cadence-gated to once per ~6h per
		// two-way calendar). Read + map-only, internally defensive — cannot write
		// to Google or NC and cannot affect the import.
		$this->mapVerifyService->verify(
			$userId, $calId, $ncCalId,
			$this->outboundReconcileService->isTwoWayEnabled($userId, $calId),
		);

		return [
			'nbAdded' => $nbAdded,
			'nbUpdated' => $nbUpdated,
			'nbDeleted' => $nbDeleted,
			'calName' => $newCalName,
		];
	}

	/**
	 * Delete all the registered calendar sync jobs from the database.
	 */
	public function resetRegisteredSyncCalendar(): void {
		$this->jobList->remove(ImportCalendarJob::class);
	}

	/**
	 * Check if a background job is registered.
	 * @param string $userId The user id of the job.
	 * @param string $calId The calendar id of the job.
	 * @return bool Whether the job with the given parameters is registered.
	 */
	public function isJobRegisteredForCalendar(string $userId, string $calId): bool {
		foreach ($this->jobList->getJobsIterator(ImportCalendarJob::class, null, 0) as $job) {
			$args = $job->getArgument();

			if ($args["user_id"] == $userId && $args["cal_id"] == $calId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register a calendar to periodically be synced and kept up to date in the
	 * background
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return void
	 */
	public function registerSyncCalendar(string $userId, string $calId, string $calName, ?string $color = null): void {
		$argument = [
			'user_id' => $userId,
			'cal_id' => $calId,
			'cal_name' => $calName,
			'color' => $color,
		];

		foreach ($this->jobList->getJobsIterator(ImportCalendarJob::class, null, 0) as $job) {
			$args = $job->getArgument();

			if ($args["user_id"] == $argument["user_id"] && $args["cal_id"] == $argument["cal_id"]) {
				$job->setArgument($argument);
				return;
			}
		}

		$this->jobList->add(ImportCalendarJob::class, $argument);
	}

	/**
	 * Unregister a calendar to periodically be synced and kept up to date in the
	 * background
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return void
	 */
	public function unregisterSyncCalendar(string $userId, string $calId): void {

		foreach ($this->jobList->getJobsIterator(ImportCalendarJob::class, null, 0) as $job) {
			/** @var array{user_id: string, cal_id: string} $args */
			$args = $job->getArgument();

			if ($args["user_id"] == $userId && $args["cal_id"] == $calId) {
				$this->jobList->remove($job, $args);
				return;
			}
		}
	}

	// ===================== Calendar-level NC -> Google sync (P-c) =====================

	/**
	 * Create a secondary Google calendar (requires the calendar.app.created scope).
	 * @return array<string, mixed> the created calendar (with 'id') or ['error'=>...].
	 */
	public function createGoogleCalendar(string $userId, string $summary, ?string $timeZone = null): array {
		$body = ['summary' => $summary];
		if ($timeZone !== null && $timeZone !== '') {
			$body['timeZone'] = $timeZone;
		}
		return $this->googleApiService->request($userId, 'calendar/v3/calendars', $body, 'POST');
	}

	/**
	 * Delete a Google calendar (only ones the app created, under calendar.app.created).
	 * @return array<string, mixed>
	 */
	public function deleteGoogleCalendar(string $userId, string $googleCalId): array {
		return $this->googleApiService->request($userId, 'calendar/v3/calendars/' . urlencode($googleCalId), [], 'DELETE');
	}

	/**
	 * The user's OWN Nextcloud calendars eligible for NC -> Google linking:
	 * owned (not shared-in), not the birthday calendar, and not an app-created
	 * Google-import calendar (those are managed in the Google list). Each entry
	 * carries its current pairing status.
	 *
	 * @return list<array{ncCalId:int, uri:string, displayname:string, color:?string, isLinked:bool, googleCalId:?string}>
	 */
	public function getOwnNcCalendars(string $userId): array {
		$principal = 'principals/users/' . $userId;
		$importSuffix = ' (' . $this->l10n->t('Google Calendar import') . ')';
		$out = [];
		foreach ($this->caldavBackend->getCalendarsForUser($principal) as $cal) {
			$owner = (string)($cal['{http://owncloud.org/ns}owner-principal'] ?? ($cal['principaluri'] ?? ''));
			if ($owner !== $principal) {
				continue; // shared-in, not owned
			}
			if (($cal['{http://nextcloud.com/ns}deleted-at'] ?? null) !== null) {
				continue; // soft-deleted (in the calendar trash)
			}
			$uri = (string)($cal['uri'] ?? '');
			$name = (string)($cal['{DAV:}displayname'] ?? $uri);
			if ($uri === '' || $uri === 'contact_birthdays' || str_ends_with($name, $importSuffix)) {
				continue;
			}
			$ncCalId = (int)($cal['id'] ?? 0);
			$googleCalId = $this->calendarMapService->getGoogleCalIdForNcCalId($ncCalId);
			$out[] = [
				'ncCalId' => $ncCalId,
				'uri' => $uri,
				'displayname' => $name,
				'color' => isset($cal['{http://apple.com/ns/ical/}calendar-color']) ? (string)$cal['{http://apple.com/ns/ical/}calendar-color'] : null,
				'isLinked' => $googleCalId !== null,
				'googleCalId' => $googleCalId,
			];
		}
		return $out;
	}

	/**
	 * Link an existing Nextcloud calendar to a NEWLY-created Google calendar and
	 * enable two-way sync. The next reconcile bootstraps the initial outbound push
	 * of this calendar's existing events (cap-and-drain). Idempotent: a calendar
	 * that is already linked returns its existing pairing without creating a duplicate.
	 *
	 * @return array{googleCalId?:string, displayname?:string, alreadyLinked?:bool, error?:string}
	 */
	public function linkNcCalendarToGoogle(string $userId, string $ncCalUri): array {
		$principal = 'principals/users/' . $userId;
		$cal = $this->caldavBackend->getCalendarByUri($principal, $ncCalUri);
		if ($cal === null) {
			return ['error' => 'Calendar not found'];
		}
		$owner = (string)($cal['{http://owncloud.org/ns}owner-principal'] ?? ($cal['principaluri'] ?? ''));
		if ($owner !== $principal) {
			return ['error' => 'You do not own this calendar'];
		}
		if (($cal['{http://nextcloud.com/ns}deleted-at'] ?? null) !== null) {
			return ['error' => 'This calendar is in the trash'];
		}
		$name = (string)($cal['{DAV:}displayname'] ?? $ncCalUri);
		$importSuffix = ' (' . $this->l10n->t('Google Calendar import') . ')';
		if ($ncCalUri === 'contact_birthdays' || str_ends_with($name, $importSuffix)) {
			return ['error' => 'This calendar cannot be synced to Google'];
		}
		$ncCalId = (int)($cal['id'] ?? 0);
		$existing = $this->calendarMapService->getGoogleCalIdForNcCalId($ncCalId);
		if ($existing !== null) {
			return ['googleCalId' => $existing, 'alreadyLinked' => true];
		}

		$timeZone = self::extractCalendarTimezone(
			isset($cal['{urn:ietf:params:xml:ns:caldav}calendar-timezone']) ? (string)$cal['{urn:ietf:params:xml:ns:caldav}calendar-timezone'] : null
		);
		$color = isset($cal['{http://apple.com/ns/ical/}calendar-color']) ? (string)$cal['{http://apple.com/ns/ical/}calendar-color'] : null;

		$created = $this->createGoogleCalendar($userId, $name, $timeZone);
		$googleCalId = isset($created['id']) ? (string)$created['id'] : '';
		if (isset($created['error']) || $googleCalId === '') {
			$this->logger->warning(
				'Calendar Bridge: could not create Google calendar for ' . $ncCalUri . ': ' . (string)($created['error'] ?? 'no id'),
				['app' => Application::APP_ID],
			);
			return ['error' => 'Could not create the Google calendar'];
		}

		// Record the pairing; roll the Google calendar back if it fails (no orphan).
		if (!$this->calendarMapService->recordNcOriginPairing($ncCalId, $ncCalUri, $googleCalId, time())) {
			$this->deleteGoogleCalendar($userId, $googleCalId);
			return ['error' => 'Could not link the calendars'];
		}

		$this->registerSyncCalendar($userId, $googleCalId, $name, $color);
		$this->outboundReconcileService->setTwoWayEnabled($userId, $googleCalId, true);
		$this->logger->info(
			'Calendar Bridge: linked NC calendar ' . $ncCalUri . ' -> Google calendar ' . $googleCalId,
			['app' => Application::APP_ID],
		);
		return ['googleCalId' => $googleCalId, 'displayname' => $name];
	}

	/**
	 * Disconnect a linked pair: stop syncing + clear two-way, but KEEP both
	 * calendars and their events (just unlinked). Re-linking re-creates a fresh
	 * Google calendar.
	 */
	public function disconnectNcCalendar(string $userId, string $googleCalId): void {
		$this->unregisterSyncCalendar($userId, $googleCalId);
		$this->outboundReconcileService->setTwoWayEnabled($userId, $googleCalId, false);
		$this->calendarMapService->removeByGoogleCalId($googleCalId);
	}

	/**
	 * Destructive: delete BOTH the Google calendar (permanently — Google has no
	 * calendar trash) and the Nextcloud calendar (to NC's calendar trash, so it
	 * stays recoverable) of a linked pair. The caller must have confirmed.
	 *
	 * @return array{deleted?:bool, error?:string}
	 */
	public function deleteLinkedCalendars(string $userId, string $ncCalUri): array {
		$principal = 'principals/users/' . $userId;
		$cal = $this->caldavBackend->getCalendarByUri($principal, $ncCalUri);
		if ($cal === null) {
			return ['error' => 'Calendar not found'];
		}
		$owner = (string)($cal['{http://owncloud.org/ns}owner-principal'] ?? ($cal['principaluri'] ?? ''));
		if ($owner !== $principal) {
			return ['error' => 'You do not own this calendar'];
		}
		$ncCalId = (int)($cal['id'] ?? 0);
		$googleCalId = $this->calendarMapService->getGoogleCalIdForNcCalId($ncCalId);
		if ($googleCalId === null) {
			return ['error' => 'This calendar is not linked'];
		}
		// Delete the Google calendar FIRST (the only externally-visible,
		// irreversible step). If it fails for any reason OTHER than already-gone,
		// abort with NOTHING changed — never trash the NC calendar + drop the
		// mapping while the Google calendar survives (that would orphan it,
		// un-deletable and un-relinkable from the UI).
		$del = $this->deleteGoogleCalendar($userId, $googleCalId);
		$status = $del['statusCode'] ?? null;
		if (isset($del['error']) && $status !== 404 && $status !== 410) {
			$this->logger->warning(
				'Calendar Bridge: delete-both aborted — Google calendar delete failed for ' . $googleCalId . ': ' . (string)$del['error'],
				['app' => Application::APP_ID],
			);
			return ['error' => 'Could not delete the Google calendar; nothing was changed. Please try again.'];
		}
		// Google calendar is gone — tear down sync + the NC side + the pairing.
		$this->unregisterSyncCalendar($userId, $googleCalId);
		$this->outboundReconcileService->setTwoWayEnabled($userId, $googleCalId, false);
		// Soft-delete the NC calendar (recoverable from NC's calendar trash);
		// skip if it is already trashed so we don't reset its retention clock.
		if (($cal['{http://nextcloud.com/ns}deleted-at'] ?? null) === null) {
			$this->caldavBackend->deleteCalendar($ncCalId);
		}
		$this->calendarMapService->removeByGoogleCalId($googleCalId);
		$this->logger->info(
			'Calendar Bridge: deleted linked calendars (NC ' . $ncCalUri . ' + Google ' . $googleCalId . ')',
			['app' => Application::APP_ID],
		);
		return ['deleted' => true];
	}

	/** Best-effort IANA TZID from a CalDAV calendar-timezone VTIMEZONE blob. Pure. */
	public static function extractCalendarTimezone(?string $vtimezone): ?string {
		if ($vtimezone === null || $vtimezone === '') {
			return null;
		}
		if (preg_match('/^TZID:(.+)$/m', $vtimezone, $m) === 1) {
			return trim($m[1]);
		}
		return null;
	}

	/**
	 * @param string $userId
	 * @param string $calId
	 * @param bool $allEvents
	 * @param string $syncToken Empty for a full pull; otherwise the value
	 *   previously returned by Google as nextSyncToken. Combining a syncToken
	 *   with eventTypes is unsupported by Google, so callers must only pass a
	 *   token when $allEvents is true.
	 * @return Generator<Event> Generator return is `['nextSyncToken' => string|null]`
	 *   on success or `['error' => string]` on API failure.
	 */
	private function getCalendarEvents(string $userId, string $calId, bool $allEvents, string $syncToken = ''): Generator {
		$params = [
			'maxResults' => 2500,
		];
		if ($syncToken !== '') {
			$params['syncToken'] = $syncToken;
		} elseif (!$allEvents) {
			$params['eventTypes'] = 'default';
		}
		$nextSyncToken = null;
		do {
			$result = $this->googleApiService->request($userId, 'calendar/v3/calendars/' . urlencode($calId) . '/events', $params);
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['items'] ?? [] as $event) {
				yield $event;
			}
			if (isset($result['nextSyncToken'])) {
				$nextSyncToken = $result['nextSyncToken'];
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));
		return ['nextSyncToken' => $nextSyncToken];
	}

	/**
	 * Detect Google's "sync token expired" error (HTTP 410) on a generator
	 * return value. The status code is embedded in the error string by
	 * GoogleAPIService; we match the substring rather than a stricter
	 * structure to avoid coupling to that format too tightly.
	 */
	private function isSyncTokenExpiredError(?array $genReturn): bool {
		if (!is_array($genReturn) || !isset($genReturn['error'])) {
			return false;
		}
		return strpos($genReturn['error'], 'status code: 410') !== false;
	}

	private function syncTokenConfigKey(string $calId): string {
		return 'sync_token_' . md5($calId);
	}
}
