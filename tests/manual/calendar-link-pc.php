<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * MANUAL lab test (NOT in CI) for P-c (calendar-level NC -> Google sync):
 *  1. getOwnNcCalendars lists the user's own calendars, excluding imports/birthdays.
 *  2. linkNcCalendarToGoogle errors cleanly when the Google calendar create FAILS
 *     (no partial state / no orphan map row).
 *  3. THE BOOTSTRAP (cap-and-drain): an NC-originated calendar's existing events are
 *     pushed as LOCAL_NEW, capped per tick and drained over ticks.
 *  4. delete-both ABORTS cleanly when the Google calendar delete FAILS (no orphan).
 *  5. disconnect unlinks but keeps both calendars.
 *
 * DETERMINISTIC + LEAK-FREE regardless of token scopes: every Google-side failure
 * (#2 create, #4 delete) and success (#3 event insert) is FAULT-INJECTED, so NO
 * real Google calendar is ever created or deleted. (This test originally relied on
 * the lab token LACKING calendar.app.created to force a real 403; that token has
 * since been re-consented WITH the full scope set, so #2/#4 would otherwise create
 * a real calendar and leak it — hence the fault injection. The real calendars.insert
 * success path reuses the P2b-proven create path and, now that the scope is present,
 * could be exercised by a separate self-cleaning round-trip test.)
 *
 * Run: docker exec -u www-data <app> php .../tests/manual/calendar-link-pc.php
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

$c = \OC::$server;
$be = $c->get(\OCA\DAV\CalDAV\CalDavBackend::class);
$realG = $c->get(GoogleAPIService::class);
$svc = $c->get(GoogleCalendarAPIService::class);
$cms = $c->get(CalendarMapService::class);
$ems = $c->get(EventMapService::class);
$mapper = $c->get(EventMapMapper::class);
$config = $c->get(\OCP\IConfig::class);
$logger = $c->get(\Psr\Log\LoggerInterface::class);
$APP = 'outside_provider_calendar_bridge';

// Build a GoogleCalendarAPIService whose Google transport is $faultG by cloning
// the DI singleton and swapping the private googleApiService field (its ctor has
// 11 deps; cloning is far less brittle than re-listing them, and only the clone is
// affected). Lets #2/#4 drive a Google-side failure deterministically — the lab
// token now HAS calendar.app.created, so a real 403 can no longer be relied on.
$withFaultGoogle = static function (GoogleAPIService $faultG) use ($svc): GoogleCalendarAPIService {
	$clone = clone $svc;
	$p = new \ReflectionProperty(GoogleCalendarAPIService::class, 'googleApiService');
	$p->setAccessible(true);
	$p->setValue($clone, $faultG);
	return $clone;
};

// ---- 1. getOwnNcCalendars -------------------------------------------------
echo "1) getOwnNcCalendars\n";
$own = $svc->getOwnNcCalendars($USER);
$names = array_map(static fn ($x) => $x['displayname'], $own);
echo '   eligible: ' . json_encode($names) . "\n";
echo '   includes Personal? ' . (in_array('Personal', $names, true) ? 'yes' : 'NO') . "\n";
echo '   excludes imports/birthdays? ' . (count(array_filter($names, static fn ($n) => str_contains($n, 'Google Calendar import') || $n === 'Contact birthdays')) === 0 ? 'yes' : 'NO') . "\n";

// ---- 2. link errors cleanly when the Google create FAILS, no orphan -------
echo "\n2) link with a FAILING Google create -> clean error, no partial state\n";
$xUri = "cbpc-x-$run";
$xName = "CB P-c test $run";
$xId = $be->createCalendar($principal, $xUri, ['{DAV:}displayname' => $xName]);
$faultCreate = new class($realG) extends GoogleAPIService {
	private $real;
	public function __construct($real) {
		$this->real = $real;
	}
	public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET', ?string $baseUrl = null, array $headers = []): array {
		// Fail calendars.insert (the create) with a non-recoverable error.
		if ($method === 'POST' && $endPoint === 'calendar/v3/calendars') {
			return ['error' => 'injected create failure', 'statusCode' => 403];
		}
		return $this->real->request($userId, $endPoint, $params, $method, $baseUrl, $headers);
	}
};
$linkRes = $withFaultGoogle($faultCreate)->linkNcCalendarToGoogle($USER, $xUri);
echo '   link result: ' . json_encode($linkRes) . " (expect error)\n";
echo '   error returned? ' . (isset($linkRes['error']) ? 'yes' : 'NO') . "\n";
echo '   no map row left for X? ' . ($cms->getGoogleCalIdForNcCalId($xId) === null ? 'yes (clean)' : 'NO (orphan!)') . "\n";

// ---- 3. cap-and-drain bootstrap (fault-injected Google) -------------------
echo "\n3) cap-and-drain bootstrap push (budget=2, 5 events)\n";
$ev = static function (string $uid, string $name): string {
	return "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:t\nBEGIN:VEVENT\nUID:$uid\nSUMMARY:$name\nDTSTART;TZID=America/New_York:20260901T100000\nDTEND;TZID=America/New_York:20260901T103000\nEND:VEVENT\nEND:VCALENDAR";
};
for ($i = 1; $i <= 5; $i++) {
	$be->createCalendarObject($xId, "evt-$run-$i.ics", $ev("cbpc-$run-$i", "Event $i"));
}
$fakeGid = "cbpc-fake-$run@group.calendar.google.com";
$cms->recordNcOriginPairing($xId, $xUri, $fakeGid, 1780000000);
$config->setUserValue($USER, $APP, 'two_way_' . md5($fakeGid), '1');
$config->deleteUserValue($USER, $APP, 'nc_change_token_' . md5($fakeGid));

// Fault Google: simulate events.insert success (counted); pass everything else through.
$fault = new class($realG) extends GoogleAPIService {
	public int $inserts = 0;
	private $real;
	public function __construct($real) {
		$this->real = $real;
	}
	public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET', ?string $baseUrl = null, array $headers = []): array {
		if ($method === 'POST' && str_contains($endPoint, '/events?sendUpdates=none')) {
			$this->inserts++;
			return ['id' => 'fakeevt-' . $this->inserts, 'etag' => '"e' . $this->inserts . '"', 'updated' => '2026-06-01T00:00:00Z', 'status' => 'confirmed'];
		}
		return $this->real->request($userId, $endPoint, $params, $method, $baseUrl, $headers);
	}
};
$ws = new OutboundWriteService($be, $fault, $ems, $logger);
$ors = new OutboundRecurrenceService($be, $fault, $ems, $logger);
$recon = new class($be, $mapper, $config, $logger, $ws, $ors, $cms) extends OutboundReconcileService {
	protected function createBudget(): int {
		return 2;
	}
	// Force the write gate ON so the bootstrap-push control flow is exercised
	// regardless of the ambient user_scopes pref — the test must not depend on
	// (or mutate) that shared OAuth-grant state.
	public function hasWriteScope(string $userId): bool {
		return true;
	}
};
$tok = static fn (): string => $config->getUserValue($USER, $APP, 'nc_change_token_' . md5($fakeGid), '');
for ($t = 1; $t <= 3; $t++) {
	$recon->reconcile($USER, $fakeGid, $xId);
	$held = $tok() === '';
	echo "   tick $t: inserts=$fault->inserts | token " . ($held ? 'HELD' : 'ADVANCED (' . $tok() . ')') . "\n";
}
$mapRows = count(array_filter($mapper->findForCalendar($xId), static fn ($r) => $r->getRecurrenceId() === ''));
echo "   => total inserts=$fault->inserts (expect 5), master map rows=$mapRows (expect 5), drained+token advanced\n";

// ---- 4. delete-both ABORTS cleanly on a Google-delete failure (no orphan) --
echo "\n4) deleteLinkedCalendars aborts when the Google delete FAILS -> nothing changed\n";
// A non-404/410 failure of the Google calendar delete must abort with NOTHING
// changed (never trash the NC calendar + drop the mapping while the Google
// calendar survives). X is still linked to $fakeGid from #3.
$faultDelete = new class($realG) extends GoogleAPIService {
	private $real;
	public function __construct($real) {
		$this->real = $real;
	}
	public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET', ?string $baseUrl = null, array $headers = []): array {
		// Fail calendars.delete with a non-404/410 error -> delete-both must abort.
		if ($method === 'DELETE' && str_starts_with($endPoint, 'calendar/v3/calendars/')) {
			return ['error' => 'injected delete failure', 'statusCode' => 500];
		}
		return $this->real->request($userId, $endPoint, $params, $method, $baseUrl, $headers);
	}
};
$delRes = $withFaultGoogle($faultDelete)->deleteLinkedCalendars($USER, $xUri);
echo '   result: ' . json_encode($delRes) . " (expect error, not deleted)\n";
$xPresent = $be->getCalendarByUri($principal, $xUri) !== null;
$mapPresent = $cms->getGoogleCalIdForNcCalId($xId) !== null;
echo '   nothing changed (X present + map present)? ' . (($xPresent && $mapPresent) ? 'yes (no orphan)' : 'NO') . "\n";

// ---- 5. disconnect — stop syncing, KEEP both calendars --------------------
echo "\n5) disconnectNcCalendar (shared teardown: unlink, keep both)\n";
$svc->disconnectNcCalendar($USER, $fakeGid);
echo '   map row gone? ' . ($cms->getGoogleCalIdForNcCalId($xId) === null ? 'yes' : 'NO') . "\n";
echo '   two-way disabled? ' . ($config->getUserValue($USER, $APP, 'two_way_' . md5($fakeGid), '0') !== '1' ? 'yes' : 'NO') . "\n";
echo '   NC calendar X kept (not deleted)? ' . ($be->getCalendarByUri($principal, $xUri) !== null ? 'yes' : 'NO') . "\n";

// ---- cleanup --------------------------------------------------------------
$mapper->deleteForNcUri($xId, ''); // any stragglers
foreach ($mapper->findForCalendar($xId) as $r) {
	$mapper->delete($r);
}
$cms->removeByGoogleCalId($fakeGid);
$config->deleteUserValue($USER, $APP, 'two_way_' . md5($fakeGid));
$config->deleteUserValue($USER, $APP, 'nc_change_token_' . md5($fakeGid));
try {
	$be->deleteCalendar($xId, true); // X is live (step 4 aborted before its soft-delete); purge it permanently
} catch (\Throwable $e) {
}
echo "\ncleaned up\n";
