<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * MANUAL lab test (NOT in CI) for Track 2 C2b — outbound (NC -> Google) contacts
 * UPDATE + DELETE + etag-conflict. The real People WRITE API can't be exercised
 * without an interactive re-consent to the read-write `contacts` scope, so
 * people:updateContact / people:deleteContact / the live-GET (people.get) are
 * FAULT-INJECTED (scripted responses; captured request bodies + headers). All NC
 * writes are to a throwaway address book, cleaned up at the end. Verifies:
 *   - UPDATE happy path: PATCH-via-POST + X-HTTP-Method-Override, etag in body,
 *     the updatePersonFields mask, and BOTH baselines refreshed from the response;
 *   - stale-etag (400 failedPrecondition) conflict -> NC wins (re-PATCH w/ fresh
 *     etag) and -> Google wins (abandon, baseline NOT refreshed, token held);
 *   - 404-on-update -> drop mapping (SKIPPED_GONE);
 *   - permanent 400 (NOT failedPrecondition) -> SKIPPED_REJECTED, NO conflict GET
 *     (proves the disambiguation);
 *   - transient 503 -> ERROR, token held, then a retry succeeds;
 *   - DELETE happy path (unconditional, no If-Match) + idempotent 404 + map row
 *     removed; DELETE transient hold keeps the row then a retry deletes;
 *   - missing-baseline update routes through the conflict resolver;
 *   - an inbound-origin echo (current etag == nc_etag) is NOT pushed;
 *   - the write budget caps-and-drains.
 *
 * Run: docker exec -u www-data <app> php .../tests/manual/contacts-outbound-c2b.php
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

// Fault People transport: capture + script updateContact / deleteContact / live-GET.
$fault = new class($realG) extends GoogleAPIService {
	/** @var list<array<string,mixed>> */
	public array $updateQueue = [];
	/** @var list<array<string,mixed>> */
	public array $deleteQueue = [];
	/** @var list<array<string,mixed>> */
	public array $getQueue = [];
	/** @var list<array{type:string,endpoint:string,method:string,params:array,headers:array}> */
	public array $calls = [];
	private $real;
	public function __construct($real) {
		$this->real = $real;
	}
	public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET', ?string $baseUrl = null, array $headers = []): array {
		if (str_contains($endPoint, ':updateContact')) {
			$this->calls[] = ['type' => 'update', 'endpoint' => $endPoint, 'method' => $method, 'params' => $params, 'headers' => $headers];
			return array_shift($this->updateQueue) ?? ['error' => 'injected: no update response', 'statusCode' => 500];
		}
		if (str_contains($endPoint, ':deleteContact')) {
			$this->calls[] = ['type' => 'delete', 'endpoint' => $endPoint, 'method' => $method, 'params' => $params, 'headers' => $headers];
			return array_shift($this->deleteQueue) ?? ['error' => 'injected: no delete response', 'statusCode' => 500];
		}
		if ($method === 'GET' && str_contains($endPoint, 'people/') && str_contains($endPoint, '?personFields')) {
			$this->calls[] = ['type' => 'get', 'endpoint' => $endPoint, 'method' => $method, 'params' => $params, 'headers' => $headers];
			return array_shift($this->getQueue) ?? ['error' => 'injected: no get response', 'statusCode' => 500];
		}
		if (str_contains($endPoint, 'v1/contactGroups')) {
			return ['contactGroups' => []];
		}
		if (str_contains($endPoint, 'v1/people/me/connections')) {
			return ['connections' => []];
		}
		return $this->real->request($userId, $endPoint, $params, $method, $baseUrl, $headers);
	}
	/** @return list<array{type:string,endpoint:string,method:string,params:array,headers:array}> */
	public function callsOf(string $type): array {
		return array_values(array_filter($this->calls, static fn ($x) => $x['type'] === $type));
	}
	public function reset(): void {
		$this->calls = [];
		$this->updateQueue = [];
		$this->deleteQueue = [];
		$this->getQueue = [];
	}
};

$mkSvc = static function (int $writeBudget) use ($logger, $cm, $cd, $fault, $config, $cms, $jobList): GoogleContactsAPIService {
	return new class('outside_provider_calendar_bridge', $logger, $cm, $cd, $fault, $config, $cms, $jobList, $writeBudget) extends GoogleContactsAPIService {
		private int $wb;
		public function __construct($a, $b, $c2, $d, $e, $f, $g, $h, int $wb) {
			parent::__construct($a, $b, $c2, $d, $e, $f, $g, $h);
			$this->wb = $wb;
		}
		public function hasContactsWriteScope(string $userId): bool {
			return true;
		}
		protected function contactsWriteBudget(): int {
			return $this->wb;
		}
	};
};
$writeSvc = $mkSvc(200);

$ab = $cd->createAddressBook($principal, 'cb-ob2-' . $run, ['{DAV:}displayname' => 'CB-outbound2-' . $run]);
$tokKey = 'contacts_nc_token_' . $ab;
$head = static fn (): string => (string)(($cd->getChangesForAddressBook($ab, '', 1)['syncToken'] ?? '') ?: '');
$etagOf = static function (string $uri) use ($cd, $ab): string {
	$card = $cd->getCard($ab, $uri);
	return is_array($card) ? (string)($card['etag'] ?? '') : '';
};

// Set up a card that surfaces as a LOCAL_EDIT: map it (nc_etag = pre-edit etag,
// the given baseline_etag + origin), then edit it after the captured token.
$setupEdit = static function (string $uri, string $fn, string $email, string $resourceName, string $baselineEtag, string $origin) use ($cd, $cms, $ab, $vcard, $config, $USER, $APP, $tokKey, $etagOf): void {
	$cd->createCard($ab, $uri, $vcard($uri, $fn, $email));
	$tokenMid = (string)(($cd->getChangesForAddressBook($ab, '', 1)['syncToken'] ?? '') ?: '');
	// An empty baselineEtag -> null, so recordMapping leaves baseline_etag NULL
	// (exercises the missing-baseline conflict path).
	$cms->recordMapping($ab, $uri, $resourceName, $baselineEtag === '' ? null : $baselineEtag, null, (int)time(), $etagOf($uri), $origin);
	$cd->updateCard($ab, $uri, $vcard($uri, $fn . ' EDITED', 'edited-' . $email));
	$config->setUserValue($USER, $APP, $tokKey, $tokenMid);
};
// Set up a card that surfaces as a LOCAL_DELETE: map it, capture token, delete it.
$setupDelete = static function (string $uri, string $fn, string $resourceName, string $origin) use ($cd, $cms, $ab, $vcard, $config, $USER, $APP, $tokKey, $etagOf): void {
	$cd->createCard($ab, $uri, $vcard($uri, $fn, 'd@example.com'));
	$cms->recordMapping($ab, $uri, $resourceName, 'E1', null, (int)time(), $etagOf($uri), $origin);
	$tokenMid = (string)(($cd->getChangesForAddressBook($ab, '', 1)['syncToken'] ?? '') ?: '');
	$cd->deleteCard($ab, $uri);
	$config->setUserValue($USER, $APP, $tokKey, $tokenMid);
};

try {
	// ===== T1: UPDATE happy path + baseline refresh + request shape =====
	echo "T1 UPDATE happy path\n";
	$fault->reset();
	$setupEdit('u1', 'One', 'one@example.com', 'people/cU1' . $run, 'E1', 'nc');
	$fault->updateQueue = [['resourceName' => 'people/cU1' . $run, 'etag' => 'E2', 'metadata' => ['sources' => [['updateTime' => '2026-06-02T10:00:00Z']]]]];
	$r1 = $writeSvc->reconcileOutbound($USER, $ab);
	$uc = $fault->callsOf('update');
	$check('1 update call, POST + X-HTTP-Method-Override: PATCH', count($uc) === 1 && $uc[0]['method'] === 'POST' && ($uc[0]['headers']['X-HTTP-Method-Override'] ?? '') === 'PATCH');
	$check('endpoint has :updateContact + updatePersonFields mask', str_contains($uc[0]['endpoint'], ':updateContact') && str_contains($uc[0]['endpoint'], 'updatePersonFields=names%2CemailAddresses%2CphoneNumbers%2Caddresses%2Corganizations%2Cbiographies%2Curls'));
	$check('body carried baseline etag E1 + the edited email', ($uc[0]['params']['etag'] ?? '') === 'E1' && ($uc[0]['params']['emailAddresses'][0]['value'] ?? '') === 'edited-one@example.com');
	$row1 = $cms->getRowForCard($ab, 'u1');
	$check('baselines refreshed: baseline_etag=E2, nc_etag=current card etag', $row1 !== null && $row1->getBaselineEtag() === 'E2' && $row1->getNcEtag() === $etagOf('u1'));
	$check('counted as written, token advanced', ($r1['written'] ?? 0) === 1 && ($r1['advanced'] ?? false) === true);

	// ===== T2: stale-etag conflict -> NC wins (re-PATCH with fresh etag) =====
	echo "\nT2 stale-etag -> NC wins\n";
	$fault->reset();
	$setupEdit('u2', 'Two', 'two@example.com', 'people/cU2' . $run, 'E1', 'nc');
	$fault->updateQueue = [
		['error' => 'ServerException, status code: 400', 'statusCode' => 400, 'body' => '{"error":{"code":400,"status":"FAILED_PRECONDITION"}}'],
		['resourceName' => 'people/cU2' . $run, 'etag' => 'E10', 'metadata' => ['sources' => [['updateTime' => '2026-06-02T11:00:00Z']]]],
	];
	$fault->getQueue = [['resourceName' => 'people/cU2' . $run, 'etag' => 'E9', 'metadata' => ['sources' => [['updateTime' => '2000-01-01T00:00:00Z']]]]]; // Google old -> NC wins
	$r2 = $writeSvc->reconcileOutbound($USER, $ab);
	$uc2 = $fault->callsOf('update');
	$check('2 update calls (initial + re-PATCH), 1 live GET', count($uc2) === 2 && count($fault->callsOf('get')) === 1);
	$check('re-PATCH carried the FRESH live etag E9', ($uc2[1]['params']['etag'] ?? '') === 'E9');
	$row2 = $cms->getRowForCard($ab, 'u2');
	$check('NC-wins resolved -> baseline_etag=E10, token advanced', $row2 !== null && $row2->getBaselineEtag() === 'E10' && ($r2['advanced'] ?? false) === true);

	// ===== T3: stale-etag conflict -> Google wins (abandon, baseline NOT refreshed) =====
	echo "\nT3 stale-etag -> Google wins\n";
	$fault->reset();
	$setupEdit('u3', 'Three', 'three@example.com', 'people/cU3' . $run, 'E1', 'nc');
	$fault->updateQueue = [['error' => 'ServerException, status code: 400', 'statusCode' => 400, 'body' => '{"error":{"status":"FAILED_PRECONDITION"}}']];
	$fault->getQueue = [['resourceName' => 'people/cU3' . $run, 'etag' => 'E9', 'metadata' => ['sources' => [['updateTime' => '2999-01-01T00:00:00Z']]]]]; // Google far-future -> Google wins
	$r3 = $writeSvc->reconcileOutbound($USER, $ab);
	$check('1 update call, 1 GET, NO re-PATCH', count($fault->callsOf('update')) === 1 && count($fault->callsOf('get')) === 1);
	$row3 = $cms->getRowForCard($ab, 'u3');
	$check('baseline NOT refreshed (still E1, so inbound can pull Google), token HELD', $row3 !== null && $row3->getBaselineEtag() === 'E1' && ($r3['advanced'] ?? true) === false);

	// ===== T4: 404 on UPDATE -> drop mapping, SKIPPED_GONE =====
	echo "\nT4 404 on UPDATE\n";
	$fault->reset();
	$setupEdit('u4', 'Four', 'four@example.com', 'people/cU4' . $run, 'E1', 'nc');
	$fault->updateQueue = [['error' => 'ClientException, status code: 404', 'statusCode' => 404]];
	$r4 = $writeSvc->reconcileOutbound($USER, $ab);
	$check('1 update call, map row removed, token advanced', count($fault->callsOf('update')) === 1 && $cms->getRowForCard($ab, 'u4') === null && ($r4['advanced'] ?? false) === true);

	// ===== T5: permanent 400 (NOT failedPrecondition) -> SKIPPED_REJECTED, no conflict GET =====
	echo "\nT5 permanent 400 reject (disambiguation)\n";
	$fault->reset();
	$setupEdit('u5', 'Five', 'five@example.com', 'people/cU5' . $run, 'E1', 'nc');
	$fault->updateQueue = [['error' => 'ClientException, status code: 400', 'statusCode' => 400, 'body' => '{"error":{"code":400,"status":"INVALID_ARGUMENT","message":"bad body"}}']];
	$r5 = $writeSvc->reconcileOutbound($USER, $ab);
	$row5 = $cms->getRowForCard($ab, 'u5');
	$check('1 update call, NO conflict GET, map unchanged (baseline still E1), token advanced',
		count($fault->callsOf('update')) === 1 && count($fault->callsOf('get')) === 0
		&& $row5 !== null && $row5->getBaselineEtag() === 'E1' && ($r5['advanced'] ?? false) === true);

	// ===== T6: transient 503 -> ERROR, token held, then retry succeeds =====
	echo "\nT6 transient hold then retry\n";
	$fault->reset();
	$setupEdit('u6', 'Six', 'six@example.com', 'people/cU6' . $run, 'E1', 'nc');
	$fault->updateQueue = [['error' => 'ServerException, status code: 503', 'statusCode' => 503]];
	$r6a = $writeSvc->reconcileOutbound($USER, $ab);
	$row6a = $cms->getRowForCard($ab, 'u6');
	$check('transient: token HELD, map unchanged (baseline E1)', ($r6a['advanced'] ?? true) === false && $row6a !== null && $row6a->getBaselineEtag() === 'E1');
	$fault->calls = [];
	$fault->updateQueue = [['resourceName' => 'people/cU6' . $run, 'etag' => 'E12', 'metadata' => ['sources' => [['updateTime' => '2026-06-02T12:00:00Z']]]]];
	$r6b = $writeSvc->reconcileOutbound($USER, $ab); // token still at tokenMid -> change resurfaces
	$row6b = $cms->getRowForCard($ab, 'u6');
	$check('retry updates -> baseline_etag=E12, token advances', count($fault->callsOf('update')) === 1 && $row6b !== null && $row6b->getBaselineEtag() === 'E12' && ($r6b['advanced'] ?? false) === true);

	// ===== T7: DELETE happy path + row removed + request shape =====
	echo "\nT7 DELETE happy path\n";
	$fault->reset();
	$setupDelete('d7', 'Del7', 'people/cD7' . $run, 'nc');
	$fault->deleteQueue = [[]]; // empty success body
	$r7 = $writeSvc->reconcileOutbound($USER, $ab);
	$dc = $fault->callsOf('delete');
	$check('1 DELETE call, method DELETE, endpoint :deleteContact, empty body, NO If-Match',
		count($dc) === 1 && $dc[0]['method'] === 'DELETE' && str_contains($dc[0]['endpoint'], 'people/cD7' . $run . ':deleteContact')
		&& $dc[0]['params'] === [] && !isset($dc[0]['headers']['If-Match']));
	$check('map row removed, token advanced', $cms->getRowForCard($ab, 'd7') === null && ($r7['advanced'] ?? false) === true);

	// ===== T8: DELETE idempotent 404 =====
	echo "\nT8 DELETE idempotent 404\n";
	$fault->reset();
	$setupDelete('d8', 'Del8', 'people/cD8' . $run, 'nc');
	$fault->deleteQueue = [['error' => 'ClientException, status code: 404', 'statusCode' => 404]];
	$r8 = $writeSvc->reconcileOutbound($USER, $ab);
	$check('404-on-delete is idempotent success: row removed, token advanced', count($fault->callsOf('delete')) === 1 && $cms->getRowForCard($ab, 'd8') === null && ($r8['advanced'] ?? false) === true);

	// ===== T9: DELETE transient hold keeps row, then retry deletes =====
	echo "\nT9 DELETE transient hold then retry\n";
	$fault->reset();
	$setupDelete('d9', 'Del9', 'people/cD9' . $run, 'nc');
	$fault->deleteQueue = [['error' => 'ServerException, status code: 503', 'statusCode' => 503]];
	$r9a = $writeSvc->reconcileOutbound($USER, $ab);
	$check('transient delete: row KEPT (retry needs resourceName), token held', $cms->getRowForCard($ab, 'd9') !== null && ($r9a['advanced'] ?? true) === false);
	$fault->calls = [];
	$fault->deleteQueue = [[]];
	$r9b = $writeSvc->reconcileOutbound($USER, $ab);
	$check('retry deletes -> row removed, token advances', count($fault->callsOf('delete')) === 1 && $cms->getRowForCard($ab, 'd9') === null && ($r9b['advanced'] ?? false) === true);

	// ===== T10: missing baseline -> conflict resolver (NC wins) =====
	echo "\nT10 missing baseline -> conflict resolver\n";
	$fault->reset();
	$setupEdit('u10', 'Ten', 'ten@example.com', 'people/cU10' . $run, '', 'nc'); // empty baseline -> null
	$fault->getQueue = [['resourceName' => 'people/cU10' . $run, 'etag' => 'E9', 'metadata' => ['sources' => [['updateTime' => '2000-01-01T00:00:00Z']]]]];
	$fault->updateQueue = [['resourceName' => 'people/cU10' . $run, 'etag' => 'E11', 'metadata' => ['sources' => [['updateTime' => '2026-06-02T13:00:00Z']]]]];
	$r10 = $writeSvc->reconcileOutbound($USER, $ab);
	$uc10 = $fault->callsOf('update');
	$check('no initial patch; 1 GET then 1 re-PATCH with live etag E9; baseline_etag=E11',
		count($fault->callsOf('get')) === 1 && count($uc10) === 1 && ($uc10[0]['params']['etag'] ?? '') === 'E9'
		&& ($cms->getRowForCard($ab, 'u10')?->getBaselineEtag()) === 'E11');

	// ===== T11: inbound-origin echo (current etag == nc_etag) is NOT pushed =====
	echo "\nT11 echo not pushed\n";
	$fault->reset();
	$cd->createCard($ab, 'e11', $vcard('e11', 'Echo', 'echo@example.com'));
	$tokenMid = $head();
	$cd->updateCard($ab, 'e11', $vcard('e11', 'Echo2', 'echo2@example.com'));
	$cms->recordMapping($ab, 'e11', 'people/cE11' . $run, 'E1', null, (int)time(), $etagOf('e11'), 'google'); // nc_etag = POST-edit etag
	$config->setUserValue($USER, $APP, $tokKey, $tokenMid);
	$r11 = $writeSvc->reconcileOutbound($USER, $ab);
	$check('classified ECHO: no update call, map unchanged, token advanced',
		count($fault->callsOf('update')) === 0 && ($cms->getRowForCard($ab, 'e11')?->getBaselineEtag()) === 'E1' && ($r11['advanced'] ?? false) === true);

	// ===== T12: write budget caps-and-drains =====
	echo "\nT12 write budget cap-and-drain\n";
	$fault->reset();
	$budgetSvc = $mkSvc(1);
	$setupEdit('b1', 'B1', 'b1@example.com', 'people/cB1' . $run, 'E1', 'nc');
	// second edit card sharing the same token window
	$cd->createCard($ab, 'b2', $vcard('b2', 'B2', 'b2@example.com'));
	$cms->recordMapping($ab, 'b2', 'people/cB2' . $run, 'E1', null, (int)time(), $etagOf('b2'), 'nc');
	$cd->updateCard($ab, 'b2', $vcard('b2', 'B2 EDITED', 'edited-b2@example.com'));
	$fault->updateQueue = [['resourceName' => 'people/cB1' . $run, 'etag' => 'E2', 'metadata' => ['sources' => [['updateTime' => '2026-06-02T14:00:00Z']]]]];
	$r12 = $budgetSvc->reconcileOutbound($USER, $ab);
	$check('budget=1: exactly one update call, token HELD (drain next run)', count($fault->callsOf('update')) === 1 && ($r12['advanced'] ?? true) === false);
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
