<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * REAL round-trip lab test (NOT in CI) for calendar-level NC -> Google create.
 * Unlike calendar-link-pc.php (which FAULT-INJECTS the Google layer), this makes
 * REAL Google API calls end-to-end:
 *   1. calendars.insert  — linkNcCalendarToGoogle creates a real Google calendar
 *   2. events.insert     — the reconcile bootstrap pushes the NC events up
 *   3. events.list       — confirm the events really landed in Google
 *   4. calendars.delete  — deleteLinkedCalendars tears the Google calendar down
 *      (gone confirmed via calendars.get -> 404, which is lag-immune unlike a
 *       calendarList re-scan)
 *
 * Requires the calendar.app.created + calendar.events scopes on the lab token
 * (full re-consent). It CREATES AND DELETES a real Google calendar on the
 * (sacrificial) account. Leak-proofing: a try/finally tears down on any failure,
 * and a comprehensive PREFIX purge ('CB-roundtrip-') runs at BOTH start and end —
 * it removes the Google calendar AND its background job / map / prefs AND the NC
 * calendar, so even a hard-killed (SIGKILL) prior run is fully healed on the next
 * run. The prefix is the test's own constant; it can never match a real calendar.
 *
 * Run: docker exec -u www-data <app> php .../tests/manual/calendar-create-roundtrip.php
 */

require_once '/var/www/html/lib/base.php';

use OCA\CalendarBridge\Db\EventMapMapper;
use OCA\CalendarBridge\Service\CalendarMapService;
use OCA\CalendarBridge\Service\EventMapService;
use OCA\CalendarBridge\Service\GoogleAPIService;
use OCA\CalendarBridge\Service\GoogleCalendarAPIService;
use OCA\CalendarBridge\Service\OutboundReconcileService;
use OCA\CalendarBridge\Service\OutboundRecurrenceService;
use OCA\CalendarBridge\Service\OutboundWriteService;

$USER = getenv('CB_LAB_USER') ?: 'admin';
$run = getenv('CB_LAB_RUN') ?: substr(md5(uniqid('', true)), 0, 8);
$principal = 'principals/users/' . $USER;
$APP = 'outside_provider_calendar_bridge';

$c = \OC::$server;
$be = $c->get(\OCA\DAV\CalDAV\CalDavBackend::class);
$svc = $c->get(GoogleCalendarAPIService::class);
$cms = $c->get(CalendarMapService::class);
$ems = $c->get(EventMapService::class);
$mapper = $c->get(EventMapMapper::class);
$config = $c->get(\OCP\IConfig::class);
$logger = $c->get(\Psr\Log\LoggerInterface::class);
$realG = $c->get(GoogleAPIService::class);

$pass = true;
$check = static function (string $label, bool $ok) use (&$pass): void {
	echo '  ' . ($ok ? 'OK  ' : 'FAIL') . ' ' . $label . "\n";
	$pass = $pass && $ok;
};

$NAME_PREFIX = 'CB-roundtrip-';
$xName = $NAME_PREFIX . $run;
$xUri = 'cb-rt-' . $run;
$xId = null;
$googleCalId = null;

// Comprehensive, idempotent purge of ALL test leftovers (this run AND any
// SIGKILLed prior run), matched purely by the test's name prefix:
//  - every Google calendar whose summary starts with the prefix, plus its
//    background import job + calendar-map row + two-way/token prefs;
//  - every NC calendar whose displayname starts with the prefix (incl. trashed,
//    which getCalendarsForUser still returns), plus its event-map rows.
// Each delete is individually guarded so one failure never abandons the rest.
$purgeLeftovers = static function () use ($realG, $svc, $cms, $ems, $config, $be, $principal, $USER, $APP, $NAME_PREFIX): int {
	$n = 0;
	$items = [];
	try {
		$r = $realG->request($USER, 'calendar/v3/users/me/calendarList', ['maxResults' => 250]);
		$items = is_array($r['items'] ?? null) ? $r['items'] : [];
	} catch (\Throwable $e) {
	}
	foreach ($items as $it) {
		$gid = (string)($it['id'] ?? '');
		if ($gid !== '' && str_starts_with((string)($it['summary'] ?? ''), $NAME_PREFIX)) {
			try {
				$svc->deleteGoogleCalendar($USER, $gid);
				$svc->unregisterSyncCalendar($USER, $gid);
				$cms->removeByGoogleCalId($gid);
				$config->deleteUserValue($USER, $APP, 'two_way_' . md5($gid));
				$config->deleteUserValue($USER, $APP, 'nc_change_token_' . md5($gid));
				$n++;
			} catch (\Throwable $e) {
			}
		}
	}
	try {
		foreach ($be->getCalendarsForUser($principal) as $cal) {
			if (str_starts_with((string)($cal['{DAV:}displayname'] ?? ''), $NAME_PREFIX)) {
				try {
					$ncId = (int)($cal['id'] ?? 0);
					$ems->removeForCalendar($ncId);
					$be->deleteCalendar($ncId, true);
				} catch (\Throwable $e) {
				}
			}
		}
	} catch (\Throwable $e) {
	}
	return $n;
};

// Is the Google calendar present in the owner's calendarList? Retries to ride out
// read-after-write lag on the owner's own create. Returns the list item or null.
$calListItem = static function (string $calId) use ($realG, $USER): ?array {
	for ($i = 0; $i < 6; $i++) {
		try {
			$r = $realG->request($USER, 'calendar/v3/users/me/calendarList', ['maxResults' => 250]);
			foreach (($r['items'] ?? []) as $it) {
				if ((string)($it['id'] ?? '') === $calId) {
					return $it;
				}
			}
		} catch (\Throwable $e) {
		}
		usleep(700000);
	}
	return null;
};

$swept = $purgeLeftovers();
if ($swept > 0) {
	echo "(startup: purged $swept leftover Google test calendar(s) from a prior run)\n";
}

try {
	// ---- setup: an NC calendar with two events --------------------------------
	$xId = $be->createCalendar($principal, $xUri, ['{DAV:}displayname' => $xName]);
	$ev = static function (string $uid, string $sum): string {
		return "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:t\nBEGIN:VEVENT\nUID:$uid\nSUMMARY:$sum\nDTSTART;TZID=America/New_York:20260901T100000\nDTEND;TZID=America/New_York:20260901T103000\nEND:VEVENT\nEND:VCALENDAR";
	};
	$be->createCalendarObject($xId, "a-$run.ics", $ev("rt-$run-a", 'Roundtrip A'));
	$be->createCalendarObject($xId, "b-$run.ics", $ev("rt-$run-b", 'Roundtrip B'));

	// ---- 1. REAL calendars.insert (linkNcCalendarToGoogle) --------------------
	echo "1) link -> real calendars.insert\n";
	$res = $svc->linkNcCalendarToGoogle($USER, $xUri);
	$googleCalId = (isset($res['googleCalId']) && is_string($res['googleCalId']) && $res['googleCalId'] !== '') ? $res['googleCalId'] : null;
	$check('link returned a googleCalId (no error)', $googleCalId !== null && !isset($res['error']));
	$check('calendar_map row recorded (X -> google)', $googleCalId !== null && $cms->getGoogleCalIdForNcCalId($xId) === $googleCalId);
	$item = $googleCalId !== null ? $calListItem($googleCalId) : null;
	$check('new calendar present in Google calendarList', is_array($item));
	$check('  with our name + owner access', is_array($item) && ($item['summary'] ?? '') === $xName && ($item['accessRole'] ?? '') === 'owner');

	// ---- 2. REAL events.insert (reconcile bootstrap push) ---------------------
	echo "\n2) reconcile -> real events.insert (bootstrap push)\n";
	if ($googleCalId !== null) {
		$ws = new OutboundWriteService($be, $realG, $ems, $logger);
		$ors = new OutboundRecurrenceService($be, $realG, $ems, $logger);
		// Force the write gate ON so the test does not depend on the ambient
		// user_scopes pref (the Google calls themselves are real).
		$recon = new class($be, $mapper, $config, $logger, $ws, $ors, $cms) extends OutboundReconcileService {
			public function hasWriteScope(string $userId): bool {
				return true;
			}
		};
		$recon->reconcile($USER, $googleCalId, $xId);
	}
	$masters = array_filter($mapper->findForCalendar($xId), static fn ($r) => $r->getRecurrenceId() === '');
	$pushed = array_filter($masters, static fn ($r) => (string)($r->getGoogleId() ?? '') !== '' && $r->getOrigin() === 'nc');
	$check('both events now carry a real Google id (events.insert returned ids)', count($pushed) === 2);
	$cnt = -1;
	for ($i = 0; $i < 6 && $googleCalId !== null; $i++) {
		try {
			$lr = $realG->request($USER, 'calendar/v3/calendars/' . urlencode($googleCalId) . '/events', ['maxResults' => 250]);
			$cnt = is_array($lr['items'] ?? null) ? count($lr['items']) : -1;
		} catch (\Throwable $e) {
		}
		if ($cnt >= 2) {
			break;
		}
		usleep(700000);
	}
	$check('Google events.list returns the 2 pushed events', $cnt >= 2);

	// ---- 3. REAL calendars.delete (deleteLinkedCalendars) ---------------------
	echo "\n3) delete-both -> real calendars.delete\n";
	$del = $svc->deleteLinkedCalendars($USER, $xUri);
	$check('delete-both succeeded', ($del['deleted'] ?? false) === true && !isset($del['error']));
	// Confirm the calendar is really gone via calendars.get (404/410) — lag-immune,
	// unlike a calendarList re-scan.
	$gone = false;
	for ($i = 0; $i < 6 && $googleCalId !== null; $i++) {
		try {
			$g = $realG->request($USER, 'calendar/v3/calendars/' . urlencode($googleCalId));
			$st = $g['statusCode'] ?? null;
			if (isset($g['error']) && ($st === 404 || $st === 410)) {
				$gone = true;
				break;
			}
		} catch (\Throwable $e) {
		}
		usleep(600000);
	}
	$check('Google calendar GONE (calendars.get -> 404)', $gone);
	$check('calendar_map row dropped', $cms->getGoogleCalIdForNcCalId($xId) === null);
	$googleCalId = null; // deleted on the happy path -> the finally must not re-delete
} finally {
	// Leak-proof teardown — runs on any assertion failure / exception above.
	// Current run's Google calendar: delete by id (lag-immune; a just-created
	// calendar may not be in calendarList yet, so the prefix purge could miss it).
	if ($googleCalId !== null) {
		try {
			$svc->deleteGoogleCalendar($USER, $googleCalId);
			$svc->unregisterSyncCalendar($USER, $googleCalId);
			$cms->removeByGoogleCalId($googleCalId);
			$config->deleteUserValue($USER, $APP, 'two_way_' . md5($googleCalId));
			$config->deleteUserValue($USER, $APP, 'nc_change_token_' . md5($googleCalId));
		} catch (\Throwable $e) {
		}
	}
	// Comprehensive prefix purge: any other leftover Google calendar (+ its job /
	// map / prefs) and the NC calendar (incl. if step 3 trashed it) — and heals a
	// SIGKILLed prior run.
	$leftover = $purgeLeftovers();
	if ($leftover > 0) {
		echo "   (teardown: purged $leftover residual Google test calendar(s))\n";
	}
}

echo "\n" . ($pass ? 'ALL PASS' : 'SOME FAILED') . "\n";
exit($pass ? 0 : 1);
