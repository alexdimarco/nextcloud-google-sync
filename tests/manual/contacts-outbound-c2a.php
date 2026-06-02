<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * MANUAL lab test (NOT in CI) for Track 2 C2a — outbound (NC -> Google) contacts
 * CREATE. The real People WRITE API can't be exercised without an interactive
 * re-consent to the read-write `contacts` scope, so people:createContact is
 * FAULT-INJECTED (scripted responses; captured request bodies). All NC writes are
 * to a throwaway address book, cleaned up at the end. Verifies:
 *   - the write-scope GATE (no can_write_contacts -> no create);
 *   - first-run BASELINE (no bulk push of existing cards);
 *   - CREATE: an NC-origin unmapped card -> people:createContact with a correctly
 *     shaped Person body (buildPersonFromVCard) -> map row recorded (origin=nc);
 *   - NO DUPLICATE on a held-token replay (mapped card -> ECHO, no 2nd POST);
 *   - token HOLD on a transient failure (retried next run);
 *   - permanent rejection of an unmappable card is terminal (token advances).
 *
 * Run: docker exec -u www-data <app> php .../tests/manual/contacts-outbound-c2a.php
 */

require_once '/var/www/html/lib/base.php';

use OCA\CalendarBridge\Service\ContactMapService;
use OCA\CalendarBridge\Service\GoogleAPIService;
use OCA\CalendarBridge\Service\GoogleContactsAPIService;

$USER = getenv('CB_LAB_USER') ?: 'admin';
$run = getenv('CB_LAB_RUN') ?: substr(md5(uniqid('', true)), 0, 6);
$principal = 'principals/users/' . $USER;
$APP = 'outside_provider_calendar_bridge';

$c = \OC::$server;
$cd = $c->get(\OCA\DAV\CardDAV\CardDavBackend::class);
$cms = $c->get(ContactMapService::class);
$config = $c->get(\OCP\IConfig::class);
$svc = $c->get(GoogleContactsAPIService::class);
$realG = $c->get(GoogleAPIService::class);
$logger = $c->get(\Psr\Log\LoggerInterface::class);
$cm = $c->get(\OCP\Contacts\IManager::class);
$jobList = $c->get(\OCP\BackgroundJob\IJobList::class);

$pass = true;
$check = static function (string $label, bool $ok) use (&$pass): void {
	echo '  ' . ($ok ? 'OK  ' : 'FAIL') . ' ' . $label . "\n";
	$pass = $pass && $ok;
};
$vcard = static function (string $uid, string $fn, string $email): string {
	return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:$uid\r\nFN:$fn\r\n" . ($email !== '' ? "EMAIL;TYPE=WORK:$email\r\n" : '') . "END:VCARD\r\n";
};

// Fault People transport: capture + script people:createContact; empty groups;
// empty connections (so the inbound pass is a no-op); pass everything else through.
$fault = new class($realG) extends GoogleAPIService {
	/** @var list<array<string,mixed>> */
	public array $createQueue = [];
	/** @var list<array<string,mixed>> captured Person bodies */
	public array $created = [];
	private $real;
	public function __construct($real) {
		$this->real = $real;
	}
	public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET', ?string $baseUrl = null, array $headers = []): array {
		if (str_contains($endPoint, 'people:createContact')) {
			$this->created[] = $params;
			return array_shift($this->createQueue) ?? ['error' => 'injected: no scripted response', 'statusCode' => 500];
		}
		if (str_contains($endPoint, 'v1/contactGroups')) {
			return ['contactGroups' => []];
		}
		if (str_contains($endPoint, 'v1/people/me/connections')) {
			return ['connections' => []];
		}
		return $this->real->request($userId, $endPoint, $params, $method, $baseUrl, $headers);
	}
};

// gateSvc: real hasContactsWriteScope (admin lacks can_write_contacts -> false).
$gateSvc = clone $svc;
$p = new \ReflectionProperty(GoogleContactsAPIService::class, 'googleApiService');
$p->setAccessible(true);
$p->setValue($gateSvc, $fault);
// writeSvc: scope forced on (avoids mutating the shared user_scopes pref).
$writeSvc = new class('outside_provider_calendar_bridge', $logger, $cm, $cd, $fault, $config, $cms, $jobList) extends GoogleContactsAPIService {
	public function hasContactsWriteScope(string $userId): bool {
		return true;
	}
};

$ab = $cd->createAddressBook($principal, 'cb-ob-' . $run, ['{DAV:}displayname' => 'CB-outbound-' . $run]);
$tokKey = 'contacts_nc_token_' . $ab;
$config->deleteUserValue($USER, $APP, $tokKey);
$ncCards = static fn (): int => count($cd->getCards($ab));
$mapRows = static fn (): int => $cms->countForAddressBook($ab);

try {
	// ===== Gate: no write scope -> no create =====
	echo "Gate (no write scope)\n";
	$cd->createCard($ab, 'gate-card', $vcard('gate-card', 'Gate Person', 'gate@example.com'));
	$g = $gateSvc->reconcileOutbound($USER, $ab);
	$check('skipped (no_write_scope), 0 creates', ($g['skipped'] ?? '') === 'no_write_scope' && count($fault->created) === 0);

	// ===== First-run baseline: no bulk push =====
	echo "\nFirst-run baseline\n";
	$config->deleteUserValue($USER, $APP, $tokKey);
	$b = $writeSvc->reconcileOutbound($USER, $ab);
	$baseToken = $config->getUserValue($USER, $APP, $tokKey, '');
	$check('baselined, token set, 0 creates (existing card not pushed)', isset($b['baselined']) && $baseToken !== '' && count($fault->created) === 0);

	// ===== CREATE: new NC-origin unmapped card -> people:createContact =====
	echo "\nCREATE (NC-origin card)\n";
	$cd->createCard($ab, 'nc-1', $vcard('nc-1', 'Newby McCard', 'newby@example.com'));
	$fault->createQueue = [['resourceName' => 'people/cNEW' . $run, 'etag' => 'getag1', 'metadata' => ['sources' => [['updateTime' => '2026-06-02T00:00:00Z']]]]];
	$r = $writeSvc->reconcileOutbound($USER, $ab);
	$check('1 create POST fired, advanced', count($fault->created) === 1 && ($r['created'] ?? 0) === 1 && ($r['advanced'] ?? false) === true);
	$body = $fault->created[0] ?? [];
	$check('Person body has unstructuredName + email (buildPersonFromVCard)', ($body['names'][0]['unstructuredName'] ?? '') === 'Newby McCard' && ($body['emailAddresses'][0]['value'] ?? '') === 'newby@example.com');
	$row = $cms->getRowForCard($ab, 'nc-1');
	$check('map row recorded: origin=nc, resourceName + baseline_etag', $row !== null && $row->getOrigin() === 'nc' && $row->getGoogleResourceName() === 'people/cNEW' . $run && $row->getBaselineEtag() === 'getag1');

	// ===== No duplicate on a held-token replay (mapped card -> ECHO) =====
	echo "\nNo-duplicate on replay\n";
	$config->setUserValue($USER, $APP, $tokKey, $baseToken); // rewind token -> card re-surfaces as 'added'
	$fault->created = [];
	$fault->createQueue = [];
	$r2 = $writeSvc->reconcileOutbound($USER, $ab);
	$check('no 2nd create POST (mapped card -> echo/edit, not re-created)', count($fault->created) === 0 && ($r2['created'] ?? 0) === 0);
	$check('still exactly one map row for nc-1', $mapRows() === ($cms->getRowForCard($ab, 'nc-1') !== null ? $mapRows() : -1) && $cms->getRowForResourceName($ab, 'people/cNEW' . $run) !== null);

	// ===== Token HOLD on transient failure, then retry succeeds =====
	echo "\nToken hold on transient failure\n";
	$cd->createCard($ab, 'nc-2', $vcard('nc-2', 'Retry Person', 'retry@example.com'));
	$fault->created = [];
	$fault->createQueue = [['error' => 'injected transient', 'statusCode' => 503]];
	$tokBefore = $config->getUserValue($USER, $APP, $tokKey, '');
	$rf = $writeSvc->reconcileOutbound($USER, $ab);
	$check('create attempted but token HELD (not advanced)', count($fault->created) === 1 && ($rf['advanced'] ?? true) === false && $config->getUserValue($USER, $APP, $tokKey, '') === $tokBefore);
	$fault->created = [];
	$fault->createQueue = [['resourceName' => 'people/cR' . $run, 'etag' => 'getag2', 'metadata' => ['sources' => [['updateTime' => '2026-06-02T00:00:00Z']]]]];
	$rr = $writeSvc->reconcileOutbound($USER, $ab);
	$check('retry creates + token advances', ($rr['created'] ?? 0) === 1 && ($rr['advanced'] ?? false) === true && $cms->getRowForCard($ab, 'nc-2') !== null);

	// ===== Permanent rejection of an unmappable card is terminal =====
	echo "\nPermanent rejection (unmappable card)\n";
	$cd->createCard($ab, 'nc-empty', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:nc-empty\r\nEND:VCARD\r\n");
	$fault->created = [];
	$re = $writeSvc->reconcileOutbound($USER, $ab);
	$check('no POST (empty Person), token advanced (not wedged)', count($fault->created) === 0 && ($re['advanced'] ?? false) === true && $cms->getRowForCard($ab, 'nc-empty') === null);
} finally {
	$cms->removeForAddressBook($ab);
	$config->deleteUserValue($USER, $APP, $tokKey);
	try {
		$cd->deleteAddressBook($ab);
	} catch (\Throwable $e) {
	}
}

echo "\n" . ($pass ? 'ALL PASS' : 'SOME FAILED') . "\n";
exit($pass ? 0 : 1);
