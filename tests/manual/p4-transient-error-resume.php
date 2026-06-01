<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * MANUAL lab test (NOT run in CI — needs a live Nextcloud + a connected Google
 * account). Fault-injects a transient 503 on ONE recurrence override PATCH and
 * verifies the differ RESUMES: the change token is held, nc_etag is left at its
 * pre-edit value, and the next reconcile re-runs and converges.
 *
 * Run inside the app container, e.g.:
 *   docker exec -u www-data <app-container> \
 *     php /var/www/html/custom_apps/outside_provider_calendar_bridge/tests/manual/p4-transient-error-resume.php
 *
 * Requires: the calendar two-way toggle ON + the calendar.events write scope for
 * CB_LAB_CAL (a sacrificial Google calendar, default below). It creates a
 * throwaway recurring event and deletes it on the way out.
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

// Unique per run: the Google master id is deterministic (sha1(uid)) and Google
// retains a just-deleted event id as status=cancelled, so a fixed uid would make
// a second run ADOPT the prior (stale) master instead of creating fresh. A fresh
// uid each run sidesteps that; the trade-off is repeated runs leave cancelled
// Google events behind (harmless litter on the sacrificial lab account).
$run = getenv('CB_LAB_RUN') ?: substr(md5(uniqid('', true)), 0, 8);
$uid = "p4-fault-err-$run";
$cid = sha1($uid);

// Fault-injecting Google: fail the first override-instance PATCH (a POST with the
// PATCH override whose body sets status=confirmed and carries no recurrence[]).
$fault = new class($realG) extends GoogleAPIService {
	private $real;
	public int $failOverridePatch = 0;
	public array $log = [];
	public function __construct($real) {
		$this->real = $real;
	}
	public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET', ?string $baseUrl = null, array $headers = []): array {
		$isInstancePatch = $method === 'POST'
			&& isset($headers['X-HTTP-Method-Override'])
			&& isset($params['status']) && $params['status'] === 'confirmed'
			&& !isset($params['recurrence']);
		if ($this->failOverridePatch > 0 && $isInstancePatch) {
			$this->failOverridePatch--;
			$this->log[] = 'INJECTED-503 ' . substr($endPoint, -28);
			return ['error' => 'injected transient', 'statusCode' => 503];
		}
		return $this->real->request($userId, $endPoint, $params, $method, $baseUrl, $headers);
	}
};

$ors = new OutboundRecurrenceService($be, $fault, $ems, $logger);
$cms = $c->get(\OCA\CalendarBridge\Service\CalendarMapService::class);
$recon = new OutboundReconcileService($be, $mapper, $config, $logger, $ws, $ors, $cms);

$tok = static fn (): string => $config->getUserValue($USER, 'outside_provider_calendar_bridge', 'nc_change_token_' . md5($CAL), '-');
$ncEtag = static function () use ($ems, $NCCAL, $uid): string {
	$m = $ems->getMasterRow($NCCAL, "$uid.ics");
	return $m ? (string)$m->getNcEtag() : '(none)';
};
$ovSummary = static function () use ($realG, $USER, $CAL, $cid): string {
	foreach ($realG->request($USER, 'calendar/v3/calendars/' . urlencode($CAL) . '/events/' . $cid . '/instances?maxResults=20')['items'] as $i) {
		// Match by date prefix — an overridden instance's originalStartTime offset
		// string can vary, so don't pin the exact -04:00 form.
		if (str_starts_with((string)($i['originalStartTime']['dateTime'] ?? ''), '2026-07-20')) {
			return (string)($i['summary'] ?? '?');
		}
	}
	return '?';
};
$mk = static fn (string $ov): string => "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:t\nBEGIN:VEVENT\nUID:$uid\nSUMMARY:fault series\nDTSTART;TZID=America/New_York:20260706T100000\nDTEND;TZID=America/New_York:20260706T103000\nRRULE:FREQ=WEEKLY;COUNT=4\nEND:VEVENT\nBEGIN:VEVENT\nUID:$uid\nRECURRENCE-ID;TZID=America/New_York:20260720T100000\nSUMMARY:$ov\nDTSTART;TZID=America/New_York:20260720T100000\nDTEND;TZID=America/New_York:20260720T103000\nEND:VEVENT\nEND:VCALENDAR";

$be->createCalendarObject($NCCAL, "$uid.ics", $mk('ov v1'));
$recon->reconcile($USER, $CAL, $NCCAL);
echo '  setup: override=' . $ovSummary() . ' nc_etag=' . substr($ncEtag(), 0, 8) . "\n";

$be->updateCalendarObject($NCCAL, "$uid.ics", $mk('ov v2'));
$tokBefore = $tok();
$etagBefore = $ncEtag();
$fault->failOverridePatch = 1;
$recon->reconcile($USER, $CAL, $NCCAL);
echo '  reconcile#1 (fault armed): token ' . ($tokBefore === $tok() ? 'HELD' : 'ADVANCED')
	. ' | nc_etag ' . ($etagBefore === $ncEtag() ? 'unchanged (stays LOCAL_EDIT)' : 'CHANGED')
	. ' | injected=' . json_encode($fault->log) . "\n";
echo '    Google override after the failed patch: ' . $ovSummary() . " (expect ov v1)\n";

$recon->reconcile($USER, $CAL, $NCCAL);
echo '  reconcile#2 (disarmed): token ' . ($tokBefore === $tok() ? 'STILL HELD (BAD)' : 'ADVANCED (converged)')
	. ' | Google override=' . $ovSummary() . " (expect ov v2 = RESUMED)\n";
echo '  echo: nbUpdated=' . $svc->importCalendar($USER, $CAL, $CAL)['nbUpdated'] . " (expect 0)\n";

$realG->request($USER, 'calendar/v3/calendars/' . urlencode($CAL) . '/events/' . $cid . '?sendUpdates=none', [], 'DELETE');
try {
	// forceDeletePermanently: skip the calendar trashbin, else the UID index keeps
	// the trashed row and a re-run collides on calobjects_by_uid_index.
	$be->deleteCalendarObject($NCCAL, "$uid.ics", \OCA\DAV\CalDAV\CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
} catch (\Throwable $e) {
}
$mapper->deleteForNcUri($NCCAL, "$uid.ics");
echo "  cleaned up\n";
