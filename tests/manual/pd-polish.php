<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * MANUAL lab test (NOT in CI) for P-d polish:
 *  #5 — a PERMANENT Google rejection (400) of a bootstrap create is terminal
 *       (SKIPPED_REJECTED) so the change token ADVANCES (no wedge); a TRANSIENT
 *       failure (500) HOLDS the token (retry). Verified with a fault Google layer.
 *  #7 — deleting the Nextcloud calendar out-of-band fires CalendarDeletedEvent,
 *       whose listener unlinks the pairing (drops the map row + unregisters the
 *       job + clears two-way) WITHOUT deleting the Google calendar.
 *
 * Run: docker exec -u www-data <app> php .../tests/manual/pd-polish.php
 */

require_once '/var/www/html/lib/base.php';

use OCA\CalendarBridge\Db\CalendarMapMapper;
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
$realG = $c->get(GoogleAPIService::class);
$ems = $c->get(EventMapService::class);
$mapper = $c->get(EventMapMapper::class);
$cms = $c->get(CalendarMapService::class);
$cmm = $c->get(CalendarMapMapper::class);
$config = $c->get(\OCP\IConfig::class);
$logger = $c->get(\Psr\Log\LoggerInterface::class);
$svc = $c->get(GoogleCalendarAPIService::class);

// A fault Google layer whose events.insert returns a controllable status.
$fault = new class($realG) extends GoogleAPIService {
	public int $insertStatus = 400;
	private $real;
	public function __construct($real) {
		$this->real = $real;
	}
	public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET', ?string $baseUrl = null, array $headers = []): array {
		// Fault any events write (insert OR patch-via-override-POST).
		if ($method === 'POST' && str_contains($endPoint, '/events')) {
			return ['error' => 'injected', 'statusCode' => $this->insertStatus];
		}
		return $this->real->request($userId, $endPoint, $params, $method, $baseUrl, $headers);
	}
};
$ws = new OutboundWriteService($be, $fault, $ems, $logger);
$ors = new OutboundRecurrenceService($be, $fault, $ems, $logger);
$recon = new OutboundReconcileService($be, $mapper, $config, $logger, $ws, $ors, $cms);

$mkCal = static function (string $uri, string $name) use ($be, $principal): int {
	return $be->createCalendar($principal, $uri, ['{DAV:}displayname' => $name]);
};
$ev = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:t\nBEGIN:VEVENT\nUID:pd-$run\nSUMMARY:pd\nDTSTART;TZID=America/New_York:20260901T100000\nDTEND;TZID=America/New_York:20260901T103000\nEND:VEVENT\nEND:VCALENDAR";

// ===== #5: permanent (400) -> token advances; transient (500) -> token held =====
echo "#5 permanent-vs-transient create failure (wedge fix)\n";
$tokKey = static fn (string $g): string => 'nc_change_token_' . md5($g);
foreach ([['status' => 400, 'expect' => 'ADVANCED'], ['status' => 500, 'expect' => 'HELD']] as $case) {
	$gid = "pd5-{$case['status']}-$run@group.calendar.google.com";
	$xUri = "pd5-{$case['status']}-$run";
	$xId = $mkCal($xUri, "pd5 {$case['status']}");
	$be->createCalendarObject($xId, "e-$run.ics", $ev);
	$cms->recordNcOriginPairing($xId, $xUri, $gid, 1780000000);
	$config->setUserValue($USER, $APP, 'two_way_' . md5($gid), '1');
	$config->deleteUserValue($USER, $APP, $tokKey($gid));
	$fault->insertStatus = $case['status'];
	$recon->reconcile($USER, $gid, $xId);
	$tok = $config->getUserValue($USER, $APP, $tokKey($gid), '');
	$got = ($tok === '') ? 'HELD' : 'ADVANCED';
	echo "  status {$case['status']}: token $got (expect {$case['expect']})" . ($got === $case['expect'] ? ' OK' : ' <-- MISMATCH') . "\n";
	// cleanup
	$cms->removeByGoogleCalId($gid);
	$config->deleteUserValue($USER, $APP, 'two_way_' . md5($gid));
	$config->deleteUserValue($USER, $APP, $tokKey($gid));
	foreach ($mapper->findForCalendar($xId) as $r) {
		$mapper->delete($r);
	}
	$be->deleteCalendar($xId, true);
}

// ===== #5b: the UPDATE/PATCH path is also unwedged (permanent 400 -> advance) =====
echo "\n#5b permanent failure on the UPDATE path also advances (was the review gap)\n";
$gidU = "pd5u-$run@group.calendar.google.com";
$xUriU = "pd5u-$run";
$xIdU = $mkCal($xUriU, 'pd5u');
$etagU = (string)$be->createCalendarObject($xIdU, "eu-$run.ics", $ev);
// Seed a 'synced' master row with a WRONG nc_etag so the object reads LOCAL_EDIT
// (not a fresh LOCAL_NEW) -> exercises updateLocalEventInGoogle (the PATCH path).
$row = new \OCA\CalendarBridge\Db\EventMap();
$row->setNcCalId($xIdU);
$row->setNcUri("eu-$run.ics");
$row->setRecurrenceId('');
$row->setGoogleId("gfake-$run");
$row->setOrigin('nc');
$row->setNcEtag('stale-etag-not-' . $etagU);
// A real synced event has a baseline_etag; without it the update path re-GETs
// (resolveUpdateConflict) instead of patching, which would not exercise the fix.
$row->setBaselineEtag('base-etag-' . $run);
$row->setState('synced');
$mapper->insert($row);
$cms->recordNcOriginPairing($xIdU, $xUriU, $gidU, 1780000000);
$config->setUserValue($USER, $APP, 'two_way_' . md5($gidU), '1');
$config->deleteUserValue($USER, $APP, $tokKey($gidU));
$fault->insertStatus = 400; // the fault now also covers the patch-via-override POST
$recon->reconcile($USER, $gidU, $xIdU);
$tokU = $config->getUserValue($USER, $APP, $tokKey($gidU), '');
echo '  update 400: token ' . ($tokU === '' ? 'HELD <-- MISMATCH' : 'ADVANCED OK') . "\n";
$cms->removeByGoogleCalId($gidU);
$config->deleteUserValue($USER, $APP, 'two_way_' . md5($gidU));
$config->deleteUserValue($USER, $APP, $tokKey($gidU));
foreach ($mapper->findForCalendar($xIdU) as $r) {
	$mapper->delete($r);
}
$be->deleteCalendar($xIdU, true);

// ===== #7: out-of-band NC calendar deletion -> listener unlinks the pairing =====
echo "\n#7 out-of-band NC calendar deletion -> CalendarDeletedEvent listener cleanup\n";
$gid7 = "pd7-$run@group.calendar.google.com";
$yUri = "pd7-$run";
$yId = $mkCal($yUri, "pd7");
$cms->recordNcOriginPairing($yId, $yUri, $gid7, 1780000000);
$svc->registerSyncCalendar($USER, $gid7, 'pd7', null);
$config->setUserValue($USER, $APP, 'two_way_' . md5($gid7), '1');
echo '  before: mapped=' . var_export($cms->getGoogleCalIdForNcCalId($yId), true)
	. ' jobRegistered=' . var_export($svc->isJobRegisteredForCalendar($USER, $gid7), true)
	. ' twoWay=' . $config->getUserValue($USER, $APP, 'two_way_' . md5($gid7), '0') . "\n";

$be->deleteCalendar($yId, true); // permanent delete -> fires CalendarDeletedEvent

echo '  after:  mapped=' . var_export($cms->getGoogleCalIdForNcCalId($yId), true) . ' (expect NULL)'
	. ' jobRegistered=' . var_export($svc->isJobRegisteredForCalendar($USER, $gid7), true) . ' (expect false)'
	. ' twoWay=' . $config->getUserValue($USER, $APP, 'two_way_' . md5($gid7), '0') . " (expect 0)\n";

// cleanup (in case the listener did not fire)
$cms->removeByGoogleCalId($gid7);
$svc->unregisterSyncCalendar($USER, $gid7);
$config->deleteUserValue($USER, $APP, 'two_way_' . md5($gid7));
$config->deleteUserValue($USER, $APP, 'nc_change_token_' . md5($gid7));
echo "\ndone\n";
