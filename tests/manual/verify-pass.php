<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * MANUAL lab test (NOT in CI — needs a live Nextcloud + a connected Google
 * account). Exercises the trust-but-verify map pass end-to-end against ground
 * truth and, crucially, asserts it is NON-DESTRUCTIVE: it repairs the two
 * provably-safe map drifts (drop a both-sides-gone orphan row, rebind a dangling
 * google_id to our ncOrigin-tagged event) and changes NOTHING on Google or in NC.
 *
 * Run inside the app container, e.g.:
 *   docker exec -u www-data <app-container> \
 *     php /var/www/html/custom_apps/outside_provider_calendar_bridge/tests/manual/verify-pass.php
 */

require_once '/var/www/html/lib/base.php';

use OCA\CalendarBridge\Db\EventMap;
use OCA\CalendarBridge\Db\EventMapMapper;
use OCA\CalendarBridge\Service\GoogleAPIService;
use OCA\CalendarBridge\Service\MapVerifyService;
use OCP\AppFramework\Db\DoesNotExistException;

$CAL = getenv('CB_LAB_CAL') ?: 'dimarcotech@gmail.com';
$USER = getenv('CB_LAB_USER') ?: 'admin';
$NCCAL = (int)(getenv('CB_LAB_NCCAL') ?: '3');
$run = getenv('CB_LAB_RUN') ?: substr(md5(uniqid('', true)), 0, 8);

$c = \OC::$server;
$be = $c->get(\OCA\DAV\CalDAV\CalDavBackend::class);
$g = $c->get(GoogleAPIService::class);
$mapper = $c->get(EventMapMapper::class);
$config = $c->get(\OCP\IConfig::class);
$verify = $c->get(MapVerifyService::class);

$orphanUri = "verify-orphan-$run.ics";
$rebindUri = "verify-rebind-$run.ics";
// Google event ids must be base32hex ([a-v0-9]); sha1 is valid hex (the app uses
// the same scheme). A non-conforming id would 400 and never be created.
$rebindGid = sha1("cbverify-rebind-$run");

$insertRow = static function (string $ncUri, string $googleId, string $origin, ?string $ncEtag) use ($mapper, $NCCAL): void {
	$row = new EventMap();
	$row->setNcCalId($NCCAL);
	$row->setNcUri($ncUri);
	$row->setRecurrenceId('');
	$row->setGoogleId($googleId);
	$row->setOrigin($origin);
	$row->setNcEtag($ncEtag);
	$row->setState('synced');
	$mapper->insert($row);
};
$rowGid = static function (string $ncUri) use ($mapper, $NCCAL): string {
	try {
		return (string)$mapper->findByNcObject($NCCAL, $ncUri, '')->getGoogleId();
	} catch (DoesNotExistException) {
		return '(row gone)';
	}
};
$liveGoogleCount = static function () use ($g, $USER, $CAL): int {
	$n = 0;
	$pageToken = '';
	do {
		$ep = 'calendar/v3/calendars/' . urlencode($CAL) . '/events?maxResults=2500&singleEvents=false&showDeleted=false';
		if ($pageToken !== '') {
			$ep .= '&pageToken=' . urlencode($pageToken);
		}
		$r = $g->request($USER, $ep);
		$n += count($r['items'] ?? []);
		$pageToken = (string)($r['nextPageToken'] ?? '');
	} while ($pageToken !== '');
	return $n;
};
$ncCount = static function () use ($be, $NCCAL): int {
	return count($be->getCalendarObjects($NCCAL));
};

// --- Setup ground truth -------------------------------------------------
// (1) ORPHAN: a map row whose NC object AND Google event are both absent.
$insertRow($orphanUri, "cbverify-orphan-$run", 'google', 'etag-x');

// (2) REBIND: an nc-origin row with a STALE google_id, an existing NC object,
//     and a live Google event carrying our ncOrigin tag.
$ics = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:t\nBEGIN:VEVENT\nUID:$rebindUri\nSUMMARY:verify rebind\nDTSTART;TZID=America/New_York:20260901T100000\nDTEND;TZID=America/New_York:20260901T103000\nEND:VEVENT\nEND:VCALENDAR";
$ncEtag = (string)$be->createCalendarObject($NCCAL, $rebindUri, $ics);
$created = $g->request($USER, 'calendar/v3/calendars/' . urlencode($CAL) . '/events?sendUpdates=none', [
	'id' => $rebindGid,
	'summary' => 'verify rebind',
	'start' => ['dateTime' => '2026-09-01T10:00:00-04:00'],
	'end' => ['dateTime' => '2026-09-01T10:30:00-04:00'],
	'extendedProperties' => ['private' => ['ncOrigin' => $rebindUri]],
], 'POST');
if (isset($created['error'])) {
	echo 'SETUP FAILED: could not create tagged Google event: ' . json_encode($created) . "\n";
}
$insertRow($rebindUri, "stale$run", 'nc', $ncEtag);

// --- Run the verify pass (cadence reset so it actually fires) ------------
$gBefore = $liveGoogleCount();
$ncBefore = $ncCount();
$pkey = 'last_verify_' . md5($CAL);
$config->setUserValue($USER, 'outside_provider_calendar_bridge', $pkey, '0');
$verify->verify($USER, $CAL, $NCCAL, true);

$gAfter = $liveGoogleCount();
$ncAfter = $ncCount();
$prefAfter = $config->getUserValue($USER, 'outside_provider_calendar_bridge', $pkey, '0');

echo "orphan row -> " . $rowGid($orphanUri) . " (expect 'row gone' = dropped)\n";
echo "rebind row google_id -> " . $rowGid($rebindUri) . " (expect $rebindGid)\n";
echo "Google event count: $gBefore -> $gAfter (expect UNCHANGED = non-destructive)\n";
echo "NC object count:    $ncBefore -> $ncAfter (expect UNCHANGED = non-destructive)\n";
echo "cadence pref advanced: " . ($prefAfter !== '0' ? "yes ($prefAfter)" : 'NO (bad)') . "\n";

// --- Cadence gate: a second call within the interval must be a no-op -----
// Re-stale the rebind row; if verify wrongly re-ran it would re-repair it.
$mapper->update((static function (EventMap $r) use ($run) {
	$r->setGoogleId("STALE2-$run");
	return $r;
})($mapper->findByNcObject($NCCAL, $rebindUri, '')));
$verify->verify($USER, $CAL, $NCCAL, true);
echo "after 2nd verify within interval, rebind row -> " . $rowGid($rebindUri) . " (expect STALE2-$run = gate held, no re-run)\n";

// --- Cleanup ------------------------------------------------------------
$g->request($USER, 'calendar/v3/calendars/' . urlencode($CAL) . '/events/' . $rebindGid . '?sendUpdates=none', [], 'DELETE');
try {
	$be->deleteCalendarObject($NCCAL, $rebindUri, \OCA\DAV\CalDAV\CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
} catch (\Throwable $e) {
}
$mapper->deleteForNcUri($NCCAL, $orphanUri);
$mapper->deleteForNcUri($NCCAL, $rebindUri);
$config->deleteUserValue($USER, 'outside_provider_calendar_bridge', $pkey);
echo "cleaned up\n";
