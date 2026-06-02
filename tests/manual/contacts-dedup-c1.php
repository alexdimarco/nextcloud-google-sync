<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * MANUAL lab test (NOT in CI) for Track 2 C1 — contacts de-duplication
 * (GoogleContactsAPIService::dedupeAddressBook). All-local (no Google calls);
 * uses a throwaway address book, cleaned up at the end.
 *
 * Verifies the conservative keep-one rule: an UNMAPPED stray that
 * high-confidence-matches exactly ONE mapped (synced) card (same name AND a
 * shared email) is removed; everything else is kept — a different person, a
 * same-name-but-no-shared-email card, a name-only card (no email), the mapped
 * card itself; an ambiguous stray (matches two mapped cards) is NOT removed.
 * Idempotent: a second run removes nothing.
 *
 * Run: docker exec -u www-data <app> php .../tests/manual/contacts-dedup-c1.php
 */

require_once '/var/www/html/lib/base.php';

use OCA\CalendarBridge\Service\ContactMapService;
use OCA\CalendarBridge\Service\GoogleContactsAPIService;

$USER = getenv('CB_LAB_USER') ?: 'admin';
$run = getenv('CB_LAB_RUN') ?: substr(md5(uniqid('', true)), 0, 6);
$principal = 'principals/users/' . $USER;

$c = \OC::$server;
$cd = $c->get(\OCA\DAV\CardDAV\CardDavBackend::class);
$cms = $c->get(ContactMapService::class);
$svc = $c->get(GoogleContactsAPIService::class);

$pass = true;
$check = static function (string $label, bool $ok) use (&$pass): void {
	echo '  ' . ($ok ? 'OK  ' : 'FAIL') . ' ' . $label . "\n";
	$pass = $pass && $ok;
};
$vcard = static function (string $uri, string $fn, string $email): string {
	return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:$uri\r\nFN:$fn\r\n" . ($email !== '' ? "EMAIL:$email\r\n" : '') . "END:VCARD\r\n";
};
$has = static function (int $ab, string $uri) use ($cd): bool {
	return is_array($cd->getCard($ab, $uri));
};

$ab = $cd->createAddressBook($principal, 'cb-dd-' . $run, ['{DAV:}displayname' => 'CB-dedup-' . $run]);

try {
	// A mapped (Google-synced) canonical + assorted other cards.
	$cd->createCard($ab, 'people_dd1', $vcard('people_dd1', 'Alice Example', 'alice@example.com'));
	$cms->recordMapping($ab, 'people_dd1', 'people/dd1', 'etag1', null, null, null, 'google');     // MAPPED canonical
	$cd->createCard($ab, 'manual-stray', $vcard('manual-stray', 'Alice Example', 'alice@example.com')); // stray dup -> REMOVE
	$cd->createCard($ab, 'manual-other', $vcard('manual-other', 'Zoe Other', 'zoe@example.com'));   // different person -> keep
	$cd->createCard($ab, 'manual-noemailmatch', $vcard('manual-noemailmatch', 'Alice Example', 'other@y.com')); // same name, no shared email -> keep
	$cd->createCard($ab, 'manual-noemail', $vcard('manual-noemail', 'Alice Example', ''));           // name only, no email -> keep

	echo "Dedupe run 1\n";
	$r1 = $svc->dedupeAddressBook($USER, $ab);
	$check('scanned 5, removed 1, ambiguous 0', ($r1['scanned'] ?? 0) === 5 && ($r1['removed'] ?? -1) === 1 && ($r1['ambiguous'] ?? -1) === 0);
	$check('the stray was removed', !$has($ab, 'manual-stray'));
	$check('mapped canonical kept', $has($ab, 'people_dd1'));
	$check('different person kept', $has($ab, 'manual-other'));
	$check('same-name/no-shared-email kept', $has($ab, 'manual-noemailmatch'));
	$check('name-only (no email) kept', $has($ab, 'manual-noemail'));
	$check('4 cards remain', count($cd->getCards($ab)) === 4);

	echo "\nDedupe run 2 (idempotent)\n";
	$r2 = $svc->dedupeAddressBook($USER, $ab);
	$check('removed 0 on re-run', ($r2['removed'] ?? -1) === 0);

	echo "\nAmbiguous match is NOT removed\n";
	// A second DISTINCT synced contact with the same name + email, then a stray
	// matching both -> ambiguous -> must be left alone.
	$cd->createCard($ab, 'people_dd2', $vcard('people_dd2', 'Alice Example', 'alice@example.com'));
	$cms->recordMapping($ab, 'people_dd2', 'people/dd2', 'etag2', null, null, null, 'google');
	$cd->createCard($ab, 'manual-amb', $vcard('manual-amb', 'Alice Example', 'alice@example.com'));
	$r3 = $svc->dedupeAddressBook($USER, $ab);
	$check('ambiguous stray NOT removed (ambiguous=1, removed=0)', ($r3['ambiguous'] ?? -1) === 1 && ($r3['removed'] ?? -1) === 0);
	$check('ambiguous stray still present', $has($ab, 'manual-amb'));
} finally {
	$cms->removeForAddressBook($ab);
	try {
		$cd->deleteAddressBook($ab);
	} catch (\Throwable $e) {
	}
}

echo "\n" . ($pass ? 'ALL PASS' : 'SOME FAILED') . "\n";
exit($pass ? 0 : 1);
