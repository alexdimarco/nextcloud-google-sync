<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * MANUAL lab test (NOT in CI) for P-b (calendar-map + resolution seam). Proves:
 *  1. the calendar-map round-trips (record -> resolve -> remove);
 *  2. REGRESSION: with no pairing for a real synced Google calendar, the importer
 *     still resolves it via the legacy URI=id path and an import still succeeds
 *     without creating a new calendar — i.e. the new map-first seam is inert for
 *     existing installs.
 *
 * Run inside the app container, e.g.:
 *   docker exec -u www-data <app-container> \
 *     php /var/www/html/custom_apps/outside_provider_calendar_bridge/tests/manual/calendar-map-pb.php
 */

require_once '/var/www/html/lib/base.php';

use OCA\CalendarBridge\Service\CalendarMapService;
use OCA\CalendarBridge\Service\GoogleCalendarAPIService;

$CAL = getenv('CB_LAB_CAL') ?: 'dimarcotech@gmail.com';
$USER = getenv('CB_LAB_USER') ?: 'admin';
$NCCAL = (int)(getenv('CB_LAB_NCCAL') ?: '3');
$run = getenv('CB_LAB_RUN') ?: substr(md5(uniqid('', true)), 0, 8);

$c = \OC::$server;
$be = $c->get(\OCA\DAV\CalDAV\CalDavBackend::class);
$maps = $c->get(CalendarMapService::class);
$svc = $c->get(GoogleCalendarAPIService::class);

$ncCalCount = static function () use ($be, $USER): int {
	return count($be->getCalendarsForUser('principals/users/' . $USER));
};

// ---- 1. round-trip ----------------------------------------------------
$fakeGid = "cbmap-test-$run@group.calendar.google.com";
echo "1) round-trip\n";
echo '   before: getNcCalIdForGoogleId = ' . var_export($maps->getNcCalIdForGoogleId($fakeGid), true) . " (expect NULL)\n";
$maps->recordNcOriginPairing($NCCAL, 'some-nc-uri', $fakeGid, 1780000000);
echo '   after record: ncCalId = ' . var_export($maps->getNcCalIdForGoogleId($fakeGid), true) . " (expect $NCCAL)\n";
echo '   reverse: googleCalId for NC ' . $NCCAL . ' = ' . var_export($maps->getGoogleCalIdForNcCalId($NCCAL), true) . " (expect $fakeGid)\n";
$maps->removeByGoogleCalId($fakeGid);
echo '   after remove: ncCalId = ' . var_export($maps->getNcCalIdForGoogleId($fakeGid), true) . " (expect NULL)\n";

// ---- 2. REGRESSION: real synced calendar still resolves legacy ---------
echo "\n2) regression — existing Google-origin calendar unaffected\n";
echo '   pairing for real cal? ' . var_export($maps->getNcCalIdForGoogleId($CAL), true) . " (expect NULL = legacy path / map-first seam inert)\n";

// Pass the calendar's real name (= its Google summary; for a primary calendar
// that is the calId) so the legacy resolution matches the existing NC calendar
// instead of minting a new one (a mismatched name would, exactly as before P-b).
$before = $ncCalCount();
$result = $svc->importCalendar($USER, $CAL, $CAL, null);
$after = $ncCalCount();
echo '   import result: ' . (isset($result['error']) ? 'ERROR ' . $result['error'] : 'ok (added ' . $result['nbAdded'] . ', updated ' . $result['nbUpdated'] . ', deleted ' . $result['nbDeleted'] . ')') . "\n";
echo '   NC calendar count: ' . $before . ' -> ' . $after . " (expect UNCHANGED = no duplicate calendar)\n";

echo "\n   leftover calendar_map rows for this run: ";
$pdo = new PDO('mysql:host=db;dbname=nextcloud', 'nextcloud', 'localncpass');
echo $pdo->query('SELECT COUNT(*) c FROM oc_calbridge_calendar_map')->fetch(PDO::FETCH_ASSOC)['c'] . " (expect 0)\n";
echo "done\n";
