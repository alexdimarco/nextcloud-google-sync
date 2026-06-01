<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * MANUAL lab test (NOT run in CI — needs a live Nextcloud + a connected Google
 * account). Two scenarios for the per-tick instance-WRITE budget circuit breaker
 * (OutboundRecurrenceService::instanceOpBudget(), a method so this test can
 * subclass + lower it to 2 without touching production, which stays at 100):
 *
 *  A. OVERFLOW IS ONE-WAY: a series with more genuine writes than the budget
 *     syncs the first N and the token ADVANCES (no wedge), but the overflow
 *     beyond N stays one-way — it does NOT resume on a later tick without a fresh
 *     edit (there is no resume cursor). This is the documented v1 limitation.
 *  B. WRITE-ONLY COUNTING (the hardening): an already-cancelled EXDATE re-asserted
 *     on a later edit is a free no-op and must NOT consume the budget. With
 *     budget=2, two already-cancelled EXDATEs + one new override -> the override
 *     STILL syncs (old "count every op" behavior would have starved it).
 *
 * Run inside the app container, e.g.:
 *   docker exec -u www-data <app-container> \
 *     php /var/www/html/custom_apps/outside_provider_calendar_bridge/tests/manual/p4-budget-overflow.php
 */

require_once '/var/www/html/lib/base.php';

use OCA\CalendarBridge\Db\EventMapMapper;
use OCA\CalendarBridge\Service\EventMapService;
use OCA\CalendarBridge\Service\GoogleAPIService;
use OCA\CalendarBridge\Service\OutboundReconcileService;
use OCA\CalendarBridge\Service\OutboundRecurrenceService;
use OCA\CalendarBridge\Service\OutboundWriteService;

$CAL = getenv('CB_LAB_CAL') ?: 'dimarcotech@gmail.com';
$USER = getenv('CB_LAB_USER') ?: 'admin';
$NCCAL = (int)(getenv('CB_LAB_NCCAL') ?: '3');
// Unique per run — see the note in p4-transient-error-resume.php: the deterministic
// Google master id (sha1(uid)) + Google's cancelled-id retention would make a
// repeat run adopt the prior master. A fresh uid each run keeps it independent.
$run = getenv('CB_LAB_RUN') ?: substr(md5(uniqid('', true)), 0, 8);

$c = \OC::$server;
$be = $c->get(\OCA\DAV\CalDAV\CalDavBackend::class);
$realG = $c->get(GoogleAPIService::class);
$ems = $c->get(EventMapService::class);
$logger = $c->get(\Psr\Log\LoggerInterface::class);
$mapper = $c->get(EventMapMapper::class);
$config = $c->get(\OCP\IConfig::class);
$ws = $c->get(OutboundWriteService::class);

// Same differ, but the circuit breaker is lowered to 2 ops per tick.
$ors = new class($be, $realG, $ems, $logger) extends OutboundRecurrenceService {
	protected function instanceOpBudget(): int {
		return 2;
	}
};
$cms = $c->get(\OCA\CalendarBridge\Service\CalendarMapService::class);
$recon = new OutboundReconcileService($be, $mapper, $config, $logger, $ws, $ors, $cms);

$tok = static fn (): string => $config->getUserValue($USER, 'outside_provider_calendar_bridge', 'nc_change_token_' . md5($CAL), '-');

// date(YYYY-MM-DD) => [summary, status] over the live instances (incl. cancelled).
$instByDate = static function (string $uid) use ($realG, $USER, $CAL): array {
	$cid = sha1($uid);
	$ep = 'calendar/v3/calendars/' . urlencode($CAL) . '/events/' . $cid . '/instances?maxResults=50&showDeleted=true';
	$out = [];
	foreach (($realG->request($USER, $ep)['items'] ?? []) as $i) {
		$k = substr((string)($i['originalStartTime']['dateTime'] ?? ($i['originalStartTime']['date'] ?? '')), 0, 10);
		if ($k === '') {
			continue;
		}
		$out[$k] = ['summary' => (string)($i['summary'] ?? ''), 'status' => (string)($i['status'] ?? '')];
	}
	ksort($out);
	return $out;
};
$cleanup = static function (string $uid) use ($be, $mapper, $realG, $USER, $CAL, $NCCAL): void {
	$realG->request($USER, 'calendar/v3/calendars/' . urlencode($CAL) . '/events/' . sha1($uid) . '?sendUpdates=none', [], 'DELETE');
	try {
		// forceDeletePermanently: skip the trashbin, else the UID index keeps the
		// trashed row and a re-run collides on calobjects_by_uid_index.
		$be->deleteCalendarObject($NCCAL, "$uid.ics", \OCA\DAV\CalDAV\CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
	} catch (\Throwable $e) {
	}
	$mapper->deleteForNcUri($NCCAL, "$uid.ics");
};

// =====================================================================
// Scenario A — overflow stays ONE-WAY (3 overrides, budget 2).
// =====================================================================
$uidA = "p4-budget-a-$run";
$ovA = static fn (string $d): string => "BEGIN:VEVENT\nUID:$uidA\nRECURRENCE-ID;TZID=America/New_York:{$d}T100000\nSUMMARY:v1 $d\nDTSTART;TZID=America/New_York:{$d}T100000\nDTEND;TZID=America/New_York:{$d}T103000\nEND:VEVENT\n";
$calA = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:t\nBEGIN:VEVENT\nUID:$uidA\nSUMMARY:budget series\nDTSTART;TZID=America/New_York:20260706T100000\nDTEND;TZID=America/New_York:20260706T103000\nRRULE:FREQ=WEEKLY;COUNT=6\nEND:VEVENT\n" . $ovA('20260713') . $ovA('20260720') . $ovA('20260727') . 'END:VCALENDAR';

echo "Scenario A — overflow is one-way\n";
$be->createCalendarObject($NCCAL, "$uidA.ics", $calA);
$tokBefore = $tok();
$recon->reconcile($USER, $CAL, $NCCAL);
$synced = static fn (array $byDate): int => count(array_filter($byDate, static fn ($v) => str_starts_with($v['summary'], 'v1')));
$a1 = $instByDate($uidA);
echo '  reconcile#1 (budget=2, 3 overrides): synced ' . $synced($a1) . '/3'
	. ' | token ' . ($tokBefore === $tok() ? 'HELD (BAD — would wedge)' : 'ADVANCED (no wedge)') . "\n";
$recon->reconcile($USER, $CAL, $NCCAL);
$a2 = $instByDate($uidA);
echo '  reconcile#2 (no new edit): synced ' . $synced($a2) . '/3'
	. ' => overflow ' . (($synced($a2) < 3) ? 'CONFIRMED one-way (third never syncs without a fresh edit)' : 'unexpectedly synced') . "\n";
$cleanup($uidA);

// =====================================================================
// Scenario B — write-only counting: no-op EXDATEs do not starve a real write.
// =====================================================================
$uidB = "p4-budget-b-$run";
$master = "BEGIN:VEVENT\nUID:$uidB\nSUMMARY:budget-B series\nDTSTART;TZID=America/New_York:20260706T100000\nDTEND;TZID=America/New_York:20260706T103000\nRRULE:FREQ=WEEKLY;COUNT=6\n";
// Step 1: two EXDATEs only (weeks of 0713, 0720). budget=2 -> both cancel cleanly.
$calB1 = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:t\n" . $master
	. "EXDATE;TZID=America/New_York:20260713T100000\nEXDATE;TZID=America/New_York:20260720T100000\nEND:VEVENT\nEND:VCALENDAR";
// Step 2: SAME two EXDATEs (now already-cancelled no-ops) + a NEW override on 0803.
$calB2 = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:t\n" . $master
	. "EXDATE;TZID=America/New_York:20260713T100000\nEXDATE;TZID=America/New_York:20260720T100000\nEND:VEVENT\n"
	. "BEGIN:VEVENT\nUID:$uidB\nRECURRENCE-ID;TZID=America/New_York:20260803T100000\nSUMMARY:ov v1\nDTSTART;TZID=America/New_York:20260803T100000\nDTEND;TZID=America/New_York:20260803T103000\nEND:VEVENT\nEND:VCALENDAR";

echo "\nScenario B — write-only counting (no-op EXDATEs are free)\n";
$be->createCalendarObject($NCCAL, "$uidB.ics", $calB1);
$recon->reconcile($USER, $CAL, $NCCAL);
$b1 = $instByDate($uidB);
$cancelled1 = count(array_filter($b1, static fn ($v) => $v['status'] === 'cancelled'));
echo '  step1 (2 EXDATEs, budget=2): cancelled ' . $cancelled1 . "/2\n";

$be->updateCalendarObject($NCCAL, "$uidB.ics", $calB2);
$recon->reconcile($USER, $CAL, $NCCAL);
$b2 = $instByDate($uidB);
$ovSummary = $b2['2026-08-03']['summary'] ?? '(absent)';
echo '  step2 (+1 override, same 2 EXDATEs, budget=2): override 0803 = "' . $ovSummary . '"'
	. ' => ' . ($ovSummary === 'ov v1'
		? 'SYNCED — no-op EXDATEs did NOT consume the budget (hardening works)'
		: 'NOT synced — old count-every-op behavior would starve it here') . "\n";
$stillCancelled = count(array_filter($b2, static fn ($v) => $v['status'] === 'cancelled'));
echo '    (the 2 EXDATEs are still cancelled: ' . $stillCancelled . "/2)\n";
$cleanup($uidB);

echo "\n  cleaned up\n";
