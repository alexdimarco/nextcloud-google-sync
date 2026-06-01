<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * MANUAL lab test (NOT in CI) for calendar trash pause/resume.
 *
 * Part A — lifecycle STATE:
 *   TRASH (soft-delete -> CalendarMovedToTrashEvent) PAUSES: import job
 *   unregistered + two-way off, but the calbridge_calendar_map row AND the
 *   calbridge_event_map rows are KEPT, and the change token is PRESERVED (a
 *   pause, not a rebaseline). RESTORE resumes (job on, two-way on, token still
 *   preserved). PURGE (permanent delete -> CalendarDeletedEvent) UNLINKS: the
 *   calendar map row is dropped AND the event-map rows are pruned.
 *
 * Part B — resume CORRECTNESS (the claim the old test never exercised):
 *   a LOCAL_DELETE queued before trashing still FLUSHES to Google on resume
 *   (because the token is preserved, the pending 'deleted' delta survives), and
 *   an unchanged synced event is NOT re-pushed (no spurious insert). Driven with
 *   a fault Google layer that records the DELETE / POST calls.
 *
 * Run: docker exec -u www-data <app> php .../tests/manual/trash-resume.php
 */

require_once '/var/www/html/lib/base.php';

use OCA\CalendarBridge\Db\EventMap;
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
$cms = $c->get(CalendarMapService::class);
$config = $c->get(\OCP\IConfig::class);
$svc = $c->get(GoogleCalendarAPIService::class);
$realG = $c->get(GoogleAPIService::class);
$ems = $c->get(EventMapService::class);
$mapper = $c->get(EventMapMapper::class);
$logger = $c->get(\Psr\Log\LoggerInterface::class);

$pass = true;
$check = static function (string $label, bool $ok) use (&$pass): void {
	echo '  ' . ($ok ? 'OK  ' : 'FAIL') . ' ' . $label . "\n";
	$pass = $pass && $ok;
};
$tokKey = static fn (string $g): string => 'nc_change_token_' . md5($g);

// ===================== Part A: lifecycle state =====================
echo "Part A — trash=PAUSE / restore=RESUME / purge=UNLINK (state + token + event-map)\n";
$gid = "tr-$run@group.calendar.google.com";
$twoWayKey = 'two_way_' . md5($gid);
$uri = "tr-$run";
$calId = $be->createCalendar($principal, $uri, ['{DAV:}displayname' => 'trash-resume ' . $run]);
$cms->recordNcOriginPairing($calId, $uri, $gid, 1780000000);
$svc->registerSyncCalendar($USER, $gid, 'trash-resume', null);
$config->setUserValue($USER, $APP, $twoWayKey, '1');
// One event-map row so the keep-on-trash / prune-on-purge behaviour is observable.
$seed = new EventMap();
$seed->setNcCalId($calId);
$seed->setNcUri("seed-$run.ics");
$seed->setRecurrenceId('');
$seed->setGoogleId("gseed-$run");
$seed->setOrigin('nc');
$seed->setNcEtag('etag-' . $run);
$seed->setState('synced');
$mapper->insert($seed);
// Baseline the change token at the current head (steady state).
$head = (string)(($be->getChangesForCalendar($calId, '', 1)['syncToken'] ?? '') ?: '');
$config->setUserValue($USER, $APP, $tokKey($gid), $head);

$mapped = static fn (): bool => $cms->getGoogleCalIdForNcCalId($calId) !== null;
$jobOn = static fn (): bool => $svc->isJobRegisteredForCalendar($USER, $gid);
$twoWay = static fn (): string => $config->getUserValue($USER, $APP, $twoWayKey, '0');
$tok = static fn (): string => $config->getUserValue($USER, $APP, $tokKey($gid), '');
$evRows = static fn (): int => $mapper->countForCalendar($calId);

$check('setup: live linked pairing with a token + event-map row',
	$mapped() && $jobOn() && $twoWay() === '1' && $head !== '' && $tok() === $head && $evRows() > 0);

echo "\n  TRASH (soft delete)\n";
$be->deleteCalendar($calId, false);
$check('map row KEPT', $mapped());
$check('import job UNregistered', !$jobOn());
$check('two-way cleared', $twoWay() === '0');
$check('change token PRESERVED (pause, not rebaseline)', $tok() === $head && $head !== '');
$check('event-map rows KEPT (clean resume later)', $evRows() > 0);

echo "\n  RESTORE (un-trash)\n";
$be->restoreCalendar($calId);
$check('map row still present', $mapped());
$check('import job RE-registered', $jobOn());
$check('two-way RE-enabled', $twoWay() === '1');
$check('change token STILL preserved', $tok() === $head && $head !== '');

echo "\n  PURGE (permanent delete)\n";
$be->deleteCalendar($calId, true);
$check('map row DROPPED', !$mapped());
$check('event-map rows PRUNED', $evRows() === 0);
$check('two-way cleared', $twoWay() === '0');

// defensive cleanup
$cms->removeByGoogleCalId($gid);
$svc->unregisterSyncCalendar($USER, $gid);
$config->deleteUserValue($USER, $APP, $twoWayKey);
$config->deleteUserValue($USER, $APP, $tokKey($gid));
$ems->removeForCalendar($calId);

// ===================== Part B: pending-delete flush on resume =====================
echo "\nPart B — a delete queued before trashing FLUSHES to Google on resume\n";
$gidB = "trb-$run@group.calendar.google.com";
$twoWayKeyB = 'two_way_' . md5($gidB);
$uriB = "trb-$run";
$calIdB = $be->createCalendar($principal, $uriB, ['{DAV:}displayname' => 'trash-resume-B ' . $run]);
$ev = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:t\nBEGIN:VEVENT\nUID:trb-$run\nSUMMARY:trb\nDTSTART;TZID=America/New_York:20260901T100000\nDTEND;TZID=America/New_York:20260901T103000\nEND:VEVENT\nEND:VCALENDAR";
$xUri = "x-$run.ics";
$etagB = (string)$be->createCalendarObject($calIdB, $xUri, $ev);
// A 'synced' NC-origin master row for X: nc_etag matches so an unchanged X reads
// ECHO, google_id is what the outbound DELETE must target.
$gDel = "gdel-$run";
$rowB = new EventMap();
$rowB->setNcCalId($calIdB);
$rowB->setNcUri($xUri);
$rowB->setRecurrenceId('');
$rowB->setGoogleId($gDel);
$rowB->setOrigin('nc');
$rowB->setNcEtag($etagB);
$rowB->setBaselineEtag('base-' . $run);
$rowB->setState('synced');
$mapper->insert($rowB);
$cms->recordNcOriginPairing($calIdB, $uriB, $gidB, 1780000000);
$config->setUserValue($USER, $APP, $twoWayKeyB, '1');
$headB = (string)(($be->getChangesForCalendar($calIdB, '', 1)['syncToken'] ?? '') ?: '');
$config->setUserValue($USER, $APP, $tokKey($gidB), $headB);

// User deletes X in NC -> a pending LOCAL_DELETE delta relative to $headB.
$be->deleteCalendarObject($calIdB, $xUri, $be::CALENDAR_TYPE_CALENDAR);
$preDel = $be->getChangesForCalendar($calIdB, $headB, 1);
$check('precondition: pending delete is visible in the change feed',
	in_array($xUri, $preDel['deleted'] ?? [], true));

// TRASH then RESTORE — the real listeners pause/resume; the token must survive.
$be->deleteCalendar($calIdB, false);
$check('token preserved through TRASH', $config->getUserValue($USER, $APP, $tokKey($gidB), '') === $headB && $headB !== '');
$be->restoreCalendar($calIdB);
$check('token preserved through RESTORE', $config->getUserValue($USER, $APP, $tokKey($gidB), '') === $headB && $headB !== '');
$check('two-way re-enabled on restore', $config->getUserValue($USER, $APP, $twoWayKeyB, '0') === '1');

// Drive the reconcile with a fault Google layer that records writes.
$fault = new class($realG) extends GoogleAPIService {
	/** @var list<string> */
	public array $deletes = [];
	/** @var list<string> */
	public array $eventPosts = [];
	private $real;
	public function __construct($real) {
		$this->real = $real;
	}
	public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET', ?string $baseUrl = null, array $headers = []): array {
		if ($method === 'DELETE' && str_contains($endPoint, '/events/')) {
			$this->deletes[] = $endPoint;
			return [];
		}
		if ($method === 'POST' && str_contains($endPoint, '/events')) {
			$this->eventPosts[] = $endPoint;
			return ['id' => 'spurious-' . count($this->eventPosts)];
		}
		return $this->real->request($userId, $endPoint, $params, $method, $baseUrl, $headers);
	}
};
$ws = new OutboundWriteService($be, $fault, $ems, $logger);
$ors = new OutboundRecurrenceService($be, $fault, $ems, $logger);
// Force the write gate ON in the reconcile subclass so the test exercises the
// outbound delete regardless of the ambient user_scopes pref — the test must not
// depend on (or mutate) that shared OAuth-grant state.
$recon = new class($be, $mapper, $config, $logger, $ws, $ors, $cms) extends OutboundReconcileService {
	public function hasWriteScope(string $userId): bool {
		return true;
	}
};
$recon->reconcile($USER, $gidB, $calIdB);

$flushed = false;
foreach ($fault->deletes as $d) {
	if (str_contains($d, rawurlencode($gDel)) || str_contains($d, $gDel)) {
		$flushed = true;
	}
}
$check('the queued delete FLUSHED to Google (resume did not drop it)', $flushed);
$check('no spurious event insert on resume', count($fault->eventPosts) === 0);

// defensive cleanup
$cms->removeByGoogleCalId($gidB);
$svc->unregisterSyncCalendar($USER, $gidB);
$config->deleteUserValue($USER, $APP, $twoWayKeyB);
$config->deleteUserValue($USER, $APP, $tokKey($gidB));
$ems->removeForCalendar($calIdB);
$be->deleteCalendar($calIdB, true);

echo "\n" . ($pass ? 'ALL PASS' : 'SOME FAILED') . "\n";
exit($pass ? 0 : 1);
