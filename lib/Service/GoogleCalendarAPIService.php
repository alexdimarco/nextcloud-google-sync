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

namespace OCA\Google\Service;

use DateTime;
use DateTimeZone;
use Ds\Set;
use Exception;
use Generator;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Google\AppInfo\Application;
use OCA\Google\BackgroundJob\ImportCalendarJob;
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
 * @phpstan-type Event array{id: string, iCalUID: string, start?: array{date?: string, dateTime?: string, timeZone?: string}, end?: array{date?: string, dateTime?: string, timeZone?: string}, originalStartTime?: array{date?: string, dateTime?: string, timeZone?: string}, recurringEventId?: string, colorId?: string, summary?: string, visibility?: string, sequence?: string, location?: string, description?: string, status?: string, created?: string, updated?: string, reminders?: array{useDefault?: bool, overrides?: list{array{minutes?: string, hours?: string, days?: string, weeks?: string}}}, recurrence?: list<string>, organizer?: array{email?: string, displayName?: string}, attendees?: list<array{email?: string, displayName?: string, responseStatus?: string, optional?: bool, resource?: bool}>}
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
		return $result['items'];
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

		$lockFile = sys_get_temp_dir() .
			"/nextcloud_google_synchronization_calendar_import_$calId.lock";

		if (file_exists($lockFile)) {
			throw new Exception('Could not acquire lock');
		}

		touch($lockFile);

		try {
			return $this->importCalendar($userId, $calId, $calName, $color);
		} finally {
			$this->logger->debug('Elapsed time is: ' . (microtime(true) - $startTime) . ' seconds', ['app' => $this->appName]);
			try {
				unlink($lockFile);
			} catch (Exception) {
			}
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

		/** @var Set<string> $unseenURIs */
		$unseenURIs = new Set();
		/** @var array{uri: string} $e */
		foreach ($this->caldavBackend->getCalendarObjects($ncCalId) as $e) {
			$unseenURIs->add($e['uri']);
		}

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

		/** @var Event $e */
		foreach ($events as $e) {
			$objectUri = $e['id'];

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
					$this->caldavBackend->updateCalendarObject($ncCalId, $objectUri, $calData);
					$nbUpdated++;
				} catch (Exception|Throwable $ex) {
					$this->logger->warning('Error when updating calendar event ' . $ex->getMessage(), ['app' => Application::APP_ID]);
				}
			} else {
				try {
					$this->caldavBackend->createCalendarObject($ncCalId, $objectUri, $calData);
					$nbAdded++;
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
			}
		}

		// Persist the new sync token. On incremental, if any cancelled
		// recurring-instance arrived, clear the token instead — the existing
		// EXDATE generation in generateEventData() needs the full exception
		// list to fire correctly, so we force the next tick to be a full
		// pull rather than patch the master inline here. On a full pull the
		// EXDATE path already ran, so save normally.
		if ($useSyncToken) {
			$forceFullNext = false;
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
