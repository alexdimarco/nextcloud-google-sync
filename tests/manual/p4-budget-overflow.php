<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * MANUAL lab test (NOT run in CI — needs a live Nextcloud + a connected Google
 * account). Drives the per-tick instance-op budget circuit breaker to its limit
 * and documents the v1 limitation: the first N instance ops sync (and the token
 * ADVANCES — no wedge), but the overflow beyond N stays ONE-WAY. It does NOT
 * resume on the next tick (the differ re-walks the same first N each time — there
 * is no overflow cursor), so it is NOT "syncs the remainder on a later edit".
 *
 * The breaker is OutboundRecurrenceService::instanceOpBudget() (a method so this
 * test can subclass + lower it without touching production, which stays at 100).
 *
 * Run inside the app container, e.g.:
 *   docker exec -u www-data <app-container> \
 *     php /var/www/html/custom_apps/outside_provider_calendar_bridge/tests/manual/p4-budget-overflow.php
 */

require_once '/var/www/html/lib/base.php';

use OCA\CalendarBridge\Db\EventMapMapper;
use OCA\CalendarBridge\Service\EventMapService;
use OCA\CalendarBridge\Service\GoogleAPIService;
use OCA\CalendarBridge\Service\GoogleCalendarAPIService;
use OCA\CalendarBridge\Service\OutboundReconcileService;
use OCA\CalendarBridge\Service\OutboundRecurrenceService;
use OCA\CalendarBridge\Service\OutboundWriteService;

$CAL = getenv('CB_LAB_CAL') ?: 'dimarcotech@gmail.com';
$USER = getenv('CB_LAB_USER') ?: 'admin';
$NCCAL = (int)(getenv('CB_LAB_NCCAL') ?: '3');

$c = \OC::$server;
$be = $c->get(\OCA\DAV\CalDAV\CalDavBackend::class);
$realG = $c->get(GoogleAPIService::class);
$ems = $c->get(EventMapService::class);
$logger = $c->get(\Psr\Log\LoggerInterface::class);
$mapper = $c->get(EventMapMapper::class);
$config = $c->get(\OCP\IConfig::class);
$ws = $c->get(OutboundWriteService::class);
$svc = $c->get(GoogleCalendarAPIService::class);

// Unique per run — see the note in p4-transient-error-resume.php: the deterministic
// Google master id (sha1(uid)) + Google's cancelled-id retention would make a
// repeat run adopt the prior master. A fresh uid each run keeps it independent.
$run = getenv('CB_LAB_RUN') ?: substr(md5(uniqid('', true)), 0, 8);
$uid = "p4-budget-$run";
$cid = sha1($uid);

// Same differ, but the circuit breaker is lowered to 2 ops per tick.
$ors = new class($be, $realG, $ems, $logger) extends OutboundRecurrenceService {
	protected function instanceOpBudget(): int {
		return 2;
	}
};
$recon = new OutboundReconcileService($be, $mapper, $config, $logger, $ws, $ors);

$tok = static fn (): string => $config->getUserValue($USER, 'outside_provider_calendar_bridge', 'nc_change_token_' . md5($CAL), '-');
$ovSummaries = static function () use ($realG, $USER, $CAL, $cid): array {
	$out = [];
	foreach ($realG->request($USER, 'calendar/v3/calendars/' . urlencode($CAL) . '/events/' . $cid . '/instances?maxResults=20')['items'] as $i) {
		$k = substr((string)($i['originalStartTime']['dateTime'] ?? ''), 0, 10);
		if ($k !== '' && ($i['summary'] ?? '') !== 'budget series') {
			$out[$k] = (string)($i['summary'] ?? '?');
		}
	}
	ksort($out);
	return $out;
};
// Three overrides on weeks 2, 3, 4 — with budget=2 only two can land per tick.
$mk = static function (string $tag) use ($uid): string {
	$ov = static fn (string $d): string => "BEGIN:VEVENT\nUID:$uid\nRECURRENCE-ID;TZID=America/New_York:{$d}T100000\nSUMMARY:$tag $d\nDTSTART;TZID=America/New_York:{$d}T100000\nDTEND;TZID=America/New_York:{$d}T103000\nEND:VEVENT\n";
	return "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:t\nBEGIN:VEVENT\nUID:$uid\nSUMMARY:budget series\nDTSTART;TZID=America/New_York:20260706T100000\nDTEND;TZID=America/New_York:20260706T103000\nRRULE:FREQ=WEEKLY;COUNT=4\nEND:VEVENT\n" . $ov('20260713') . $ov('20260720') . $ov('20260727') . 'END:VCALENDAR';
};

$be->createCalendarObject($NCCAL, "$uid.ics", $mk('v1'));
$tokBefore = $tok();
$recon->reconcile($USER, $CAL, $NCCAL);
$after = $ovSummaries();
$synced = count(array_filter($after, static fn ($s) => str_starts_with($s, 'v1')));
echo '  reconcile#1 (budget=2, 3 overrides): synced ' . $synced . '/3 -> ' . json_encode($after) . "\n";
echo '    token ' . ($tokBefore === $tok() ? 'HELD (BAD — would wedge)' : 'ADVANCED (no wedge)') . "\n";

// Second tick with NO new NC edit: the overflow does NOT get picked up (no cursor).
$recon->reconcile($USER, $CAL, $NCCAL);
$after2 = $ovSummaries();
$synced2 = count(array_filter($after2, static fn ($s) => str_starts_with($s, 'v1')));
echo '  reconcile#2 (no new edit): synced ' . $synced2 . '/3 -> ' . json_encode($after2) . "\n";
echo '    => overflow is ONE-WAY: ' . (($synced2 < 3) ? 'CONFIRMED (third never syncs without a fresh edit)' : 'unexpected') . "\n";

$realG->request($USER, 'calendar/v3/calendars/' . urlencode($CAL) . '/events/' . $cid . '?sendUpdates=none', [], 'DELETE');
try {
	// forceDeletePermanently: skip the calendar trashbin, else the UID index keeps
	// the trashed row and a re-run collides on calobjects_by_uid_index.
	$be->deleteCalendarObject($NCCAL, "$uid.ics", \OCA\DAV\CalDAV\CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
} catch (\Throwable $e) {
}
$mapper->deleteForNcUri($NCCAL, "$uid.ics");
echo "  cleaned up\n";
