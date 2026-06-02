<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * MANUAL lab test (NOT in CI) for Track 2 C0 part 2 — continuous inbound
 * contacts sync (GoogleContactsAPIService::syncAddressBook).
 *
 * Uses a FAULT-INJECTED People layer (scripted connections.list responses) for
 * deterministic classification coverage, plus one REAL-API smoke of the live
 * incremental pull. All writes are to a throwaway NC address book (inbound only
 * — Google is never written), cleaned up at the end.
 *
 *  Run 1 — full pull: 3 new Google contacts -> 3 cards + 3 map rows + token saved.
 *  Run 2 — delta: 1 ECHO (etag==baseline -> skip), 1 EDIT (newer -> update card),
 *          1 DELETE (metadata.deleted -> remove card + map row).
 *  Run 3 — EXPIRED_SYNC_TOKEN -> full resync via the map (no duplicates).
 *  Smoke — the real People connections.list delta pull returns persons + a token.
 *
 * Run: docker exec -u www-data <app> php .../tests/manual/contacts-sync-c0b.php
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
$realSvc = $c->get(GoogleContactsAPIService::class);
$realG = $c->get(GoogleAPIService::class);
$logger = $c->get(\Psr\Log\LoggerInterface::class);
$cm = $c->get(\OCP\Contacts\IManager::class);
$jobList = $c->get(\OCP\BackgroundJob\IJobList::class);

$pass = true;
$check = static function (string $label, bool $ok) use (&$pass): void {
	echo '  ' . ($ok ? 'OK  ' : 'FAIL') . ' ' . $label . "\n";
	$pass = $pass && $ok;
};
$person = static function (string $id, string $etag, string $name, string $updateTime): array {
	return [
		'resourceName' => $id,
		'etag' => $etag,
		'names' => [['displayName' => $name, 'givenName' => $name, 'familyName' => 'Test']],
		'emailAddresses' => [['value' => strtolower($name) . '@example.com']],
		'metadata' => ['sources' => [['updateTime' => $updateTime]]],
	];
};

// A fault People transport: scripted connections.list responses (queue), empty
// contactGroups, pass-through otherwise.
$fault = new class($realG) extends GoogleAPIService {
	/** @var list<array<string,mixed>> queue of connections.list responses */
	public array $connectionsQueue = [];
	private $real;
	public function __construct($real) {
		$this->real = $real;
	}
	public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET', ?string $baseUrl = null, array $headers = []): array {
		if (str_contains($endPoint, 'v1/contactGroups')) {
			return ['contactGroups' => []];
		}
		if (str_contains($endPoint, 'v1/people/me/connections')) {
			return array_shift($this->connectionsQueue) ?? ['connections' => []];
		}
		return $this->real->request($userId, $endPoint, $params, $method, $baseUrl, $headers);
	}
};

// Build a GoogleContactsAPIService whose People transport is the fault (clone the
// DI singleton + swap the private googleApiService).
$faultSvc = clone $realSvc;
$p = new \ReflectionProperty(GoogleContactsAPIService::class, 'googleApiService');
$p->setAccessible(true);
$p->setValue($faultSvc, $fault);

$abUri = 'cb-ct-' . $run;
$abId = $cd->createAddressBook($principal, $abUri, ['{DAV:}displayname' => 'CB-contacts-' . $run]);
$tokKey = 'contacts_sync_token_' . $abId;
$config->deleteUserValue($USER, $APP, $tokKey);

$cardCount = static function () use ($cd, $abId): int {
	return count($cd->getCards($abId));
};

try {
	// ===== Run 1 — full pull, 3 new contacts =====
	echo "Run 1 — full pull (3 new contacts)\n";
	$fault->connectionsQueue = [[
		'connections' => [
			$person('people/ct1-' . $run, 'etagA', 'Alice', '2026-06-01T10:00:00Z'),
			$person('people/ct2-' . $run, 'etagB', 'Bob', '2026-06-01T10:00:00Z'),
			$person('people/ct3-' . $run, 'etagC', 'Carol', '2026-06-01T10:00:00Z'),
		],
		'nextSyncToken' => 'TOK1',
	]];
	$r1 = $faultSvc->syncAddressBook($USER, $abId);
	$check('created 3', ($r1['nbCreated'] ?? 0) === 3);
	$check('3 cards + 3 map rows', $cardCount() === 3 && $cms->countForAddressBook($abId) === 3);
	$check('sync token saved (TOK1)', $config->getUserValue($USER, $APP, $tokKey, '') === 'TOK1');

	// ===== Run 2 — delta: echo / edit / delete =====
	echo "\nRun 2 — delta (1 echo, 1 edit, 1 delete)\n";
	$fault->connectionsQueue = [[
		'connections' => [
			$person('people/ct1-' . $run, 'etagA', 'Alice', '2026-06-01T10:00:00Z'),        // ECHO (etag unchanged)
			$person('people/ct2-' . $run, 'etagB2', 'Bobby', '2027-01-01T00:00:00Z'),        // EDIT (new etag, newer)
			['resourceName' => 'people/ct3-' . $run, 'metadata' => ['deleted' => true]],     // DELETE
		],
		'nextSyncToken' => 'TOK2',
	]];
	$r2 = $faultSvc->syncAddressBook($USER, $abId);
	$check('echo=1 updated=1 deleted=1 created=0', ($r2['nbEcho'] ?? 0) === 1 && ($r2['nbUpdated'] ?? 0) === 1 && ($r2['nbDeleted'] ?? 0) === 1 && ($r2['nbCreated'] ?? 0) === 0);
	$check('now 2 cards + 2 map rows (Carol gone)', $cardCount() === 2 && $cms->countForAddressBook($abId) === 2);
	$bob = $cd->getCard($abId, 'people_ct2-' . $run);
	$check('Bob card updated (FN now Bobby)', is_array($bob) && str_contains((string)$bob['carddata'], 'Bobby'));
	$check('Carol map row removed', $cms->getRowForResourceName($abId, 'people/ct3-' . $run) === null);
	$check('token advanced (TOK2)', $config->getUserValue($USER, $APP, $tokKey, '') === 'TOK2');

	// ===== Run 3 — expired token -> full resync, no dupes =====
	echo "\nRun 3 — EXPIRED_SYNC_TOKEN -> full resync (no duplicates)\n";
	$fault->connectionsQueue = [
		['error' => 'injected: EXPIRED_SYNC_TOKEN', 'statusCode' => 400],                 // token'd call fails
		['connections' => [                                                               // full resync (no token)
			$person('people/ct1-' . $run, 'etagA', 'Alice', '2026-06-01T10:00:00Z'),
			$person('people/ct2-' . $run, 'etagB2', 'Bobby', '2027-01-01T00:00:00Z'),
		], 'nextSyncToken' => 'TOK3'],
	];
	$r3 = $faultSvc->syncAddressBook($USER, $abId);
	$check('no error; resynced', !isset($r3['error']));
	$check('still 2 cards + 2 map rows (no dupes)', $cardCount() === 2 && $cms->countForAddressBook($abId) === 2);
	$check('token re-established (TOK3)', $config->getUserValue($USER, $APP, $tokKey, '') === 'TOK3');

	// ===== Run 4 — cap-and-drain budget (token held until drained) =====
	echo "\nRun 4 — cap-and-drain budget=2 (token held, drains next run)\n";
	$ab2 = $cd->createAddressBook($principal, 'cb-ctb-' . $run, ['{DAV:}displayname' => 'CB-contacts-budget-' . $run]);
	$tok2Key = 'contacts_sync_token_' . $ab2;
	$config->deleteUserValue($USER, $APP, $tok2Key);
	$budgetSvc = new class('outside_provider_calendar_bridge', $logger, $cm, $cd, $fault, $config, $cms, $jobList) extends GoogleContactsAPIService {
		protected function contactsSyncBudget(): int {
			return 2;
		}
	};
	$three = [
		$person('people/cb1-' . $run, 'e1', 'Dee One', '2026-06-01T10:00:00Z'),
		$person('people/cb2-' . $run, 'e2', 'Dee Two', '2026-06-01T10:00:00Z'),
		$person('people/cb3-' . $run, 'e3', 'Dee Three', '2026-06-01T10:00:00Z'),
	];
	$fault->connectionsQueue = [['connections' => $three, 'nextSyncToken' => 'BTOK']];
	$b1 = $budgetSvc->syncAddressBook($USER, $ab2);
	$check('run 1: applied 2 (cap), token HELD', ($b1['nbCreated'] ?? 0) === 2 && ($b1['advanced'] ?? true) === false && $config->getUserValue($USER, $APP, $tok2Key, '') === '');
	$fault->connectionsQueue = [['connections' => $three, 'nextSyncToken' => 'BTOK2']]; // re-fetch (token not advanced): first 2 echo, 3rd new
	$b2 = $budgetSvc->syncAddressBook($USER, $ab2);
	$check('run 2: drained (3 cards, token advanced)', $cms->countForAddressBook($ab2) === 3 && ($b2['advanced'] ?? false) === true && $config->getUserValue($USER, $APP, $tok2Key, '') === 'BTOK2');
	$cms->removeForAddressBook($ab2);
	$config->deleteUserValue($USER, $APP, $tok2Key);
	try {
		$cd->deleteAddressBook($ab2);
	} catch (\Throwable $e) {
	}

	// ===== Smoke — the REAL People delta pull works (live) =====
	echo "\nSmoke — real People connections.list pull\n";
	$realChanges = $realSvc->getContactChanges($USER, null);
	$check('real pull returned persons + a syncToken', isset($realChanges['persons']) && count($realChanges['persons']) > 0 && !empty($realChanges['syncToken']));
} finally {
	$cms->removeForAddressBook($abId);
	$config->deleteUserValue($USER, $APP, $tokKey);
	try {
		$cd->deleteAddressBook($abId);
	} catch (\Throwable $e) {
	}
}

echo "\n" . ($pass ? 'ALL PASS' : 'SOME FAILED') . "\n";
exit($pass ? 0 : 1);
