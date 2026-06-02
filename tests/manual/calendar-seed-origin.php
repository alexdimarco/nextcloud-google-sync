<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * MANUAL lab test (NOT in CI) for the seedFromExistingIfEmpty origin-aware fix.
 *
 * Bug: the lazy event-map backfill seeded EVERY existing NC object as
 * origin='google' (an already-synced import echo). For an NC-ORIGIN pairing
 * (a pre-existing NC calendar linked to a fresh empty Google calendar) those
 * objects are LOCAL events the reconciler must PUSH — seeding them as google
 * made them classify ECHO and silently never sync out (the symptom: "added an
 * event in NC, it never reached Google").
 *
 * Fix: gate the seed on the calendar's origin (hasNcOriginPairing 3-state):
 *   - false (Google-origin)  -> SEED (existing objects are imports).
 *   - true  (NC-origin)      -> SKIP (reconciler pushes them as LOCAL_NEW).
 *   - null  (undetermined)   -> SKIP (defer to a later tick).
 *
 * Run: docker exec -u www-data <app> php .../tests/manual/calendar-seed-origin.php
 */

require_once '/var/www/html/lib/base.php';

use OCA\CalendarBridge\Service\CalendarMapService;
use OCA\CalendarBridge\Service\EventMapService;

$USER = getenv('CB_LAB_USER') ?: 'admin';
$run = getenv('CB_LAB_RUN') ?: substr(md5(uniqid('', true)), 0, 6);
$principal = 'principals/users/' . $USER;

$c = \OC::$server;
$cal = $c->get(\OCA\DAV\CalDAV\CalDavBackend::class);
$ems = $c->get(EventMapService::class);
$cms = $c->get(CalendarMapService::class);
$db = $c->get(\OCP\IDBConnection::class);

$pass = true;
$check = static function (string $label, bool $ok) use (&$pass): void {
	echo '  ' . ($ok ? 'OK  ' : 'FAIL') . ' ' . $label . "\n";
	$pass = $pass && $ok;
};
$vevent = static function (string $uid, string $summary): string {
	return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//cb//test//EN\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTART;VALUE=DATE:20260610\r\nDTEND;VALUE=DATE:20260611\r\nSUMMARY:$summary\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
};

$count = static fn (): int => (int)$db->executeQuery('SELECT COUNT(*) c FROM oc_calbridge_event_map WHERE nc_cal_id = ?', [$GLOBALS['ncCalId']])->fetch()['c'];

$calUri = 'cb-seed-' . $run;
$googleCalId = 'seedtest-' . $run . '@group.calendar.google.com';
$ncCalId = $cal->createCalendar($principal, $calUri, ['{DAV:}displayname' => 'CB seed ' . $run]);
$cal->createCalendarObject($ncCalId, 'ev1-' . $run . '.ics', $vevent('uid1-' . $run, 'Seed One'));
$cal->createCalendarObject($ncCalId, 'ev2-' . $run . '.ics', $vevent('uid2-' . $run, 'Seed Two'));
$objs = static fn (): array => iterator_to_array((function () use ($cal, $ncCalId) {
	foreach ($cal->getCalendarObjects($ncCalId) as $o) {
		yield $o;
	}
})());

try {
	// ===== NC-origin pairing (hasNcOriginPairing -> true): the bug case =====
	echo "NC-origin pairing -> must NOT seed (events are local, reconciler pushes them)\n";
	$cms->recordNcOriginPairing($ncCalId, $calUri, $googleCalId, time());
	$origin = $cms->hasNcOriginPairing($googleCalId);
	$check('hasNcOriginPairing == true', $origin === true);
	$ems->removeForCalendar($ncCalId); // clean slate
	$ems->seedFromExistingIfEmpty($ncCalId, $objs(), $origin);
	$check('NC-origin: 0 rows seeded (was the bug: 2 origin=google echoes)', $count() === 0);

	// ===== Undetermined origin (null): conservative skip =====
	echo "\nUndetermined origin (null) -> must NOT seed (defer)\n";
	$ems->removeForCalendar($ncCalId);
	$ems->seedFromExistingIfEmpty($ncCalId, $objs(), null);
	$check('null origin: 0 rows seeded', $count() === 0);

	// ===== Google-origin (false): the legitimate baseline still works =====
	echo "\nGoogle-origin (false) -> seeds existing imports as origin=google\n";
	$cms->removeByGoogleCalId($googleCalId); // drop the pairing -> hasNcOriginPairing == false
	$origin2 = $cms->hasNcOriginPairing($googleCalId);
	$check('hasNcOriginPairing == false', $origin2 === false);
	$ems->removeForCalendar($ncCalId);
	$ems->seedFromExistingIfEmpty($ncCalId, $objs(), $origin2);
	$check('Google-origin: 2 rows seeded', $count() === 2);
	$origins = $db->executeQuery('SELECT DISTINCT origin FROM oc_calbridge_event_map WHERE nc_cal_id = ?', [$ncCalId])->fetchAll();
	$check('seeded rows are origin=google', count($origins) === 1 && ($origins[0]['origin'] ?? '') === 'google');

	// ===== Idempotent: a second seed on a non-empty map is a no-op =====
	echo "\nSecond seed on a non-empty map -> no-op (count guard)\n";
	$ems->seedFromExistingIfEmpty($ncCalId, $objs(), false);
	$check('still exactly 2 rows (no duplicate seeding)', $count() === 2);
} finally {
	$ems->removeForCalendar($ncCalId);
	try {
		$cms->removeByGoogleCalId($googleCalId);
	} catch (\Throwable $e) {
	}
	try {
		$cal->deleteCalendar($ncCalId, true);
	} catch (\Throwable $e) {
	}
}

echo "\n" . ($pass ? 'ALL PASS' : 'SOME FAILED') . "\n";
exit($pass ? 0 : 1);
