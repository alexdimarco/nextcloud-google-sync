<?php

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Tests\Unit\Service;

use DateTime;
use DateTimeZone;
use OCA\CalendarBridge\Service\OutboundWriteService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the outbound write mapping helpers.
 */
class OutboundWriteServiceTest extends TestCase {

	// ----- deriveClientId -----

	public function testDeriveClientIdIsDeterministic(): void {
		$a = OutboundWriteService::deriveClientId('event-uid-123@example.com');
		$b = OutboundWriteService::deriveClientId('event-uid-123@example.com');
		$this->assertSame($a, $b);
	}

	public function testDeriveClientIdDiffersPerUid(): void {
		$this->assertNotSame(
			OutboundWriteService::deriveClientId('a@x'),
			OutboundWriteService::deriveClientId('b@x')
		);
	}

	public function testDeriveClientIdUsesValidGoogleIdAlphabetAndLength(): void {
		// Google event id: base32hex alphabet (0-9 a-v), length 5-1024.
		// sha1 hex is 0-9a-f (a subset), length 40.
		$id = OutboundWriteService::deriveClientId('whatever-uid');
		$this->assertSame(40, strlen($id));
		$this->assertMatchesRegularExpression('/^[0-9a-v]+$/', $id);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $id);
	}

	// ----- mapIcalDateToGoogle -----

	public function testMapAllDayUsesDateOnly(): void {
		$dt = new DateTime('2026-06-01 00:00:00', new DateTimeZone('UTC'));
		$this->assertSame(
			['date' => '2026-06-01'],
			OutboundWriteService::mapIcalDateToGoogle($dt, null, true)
		);
	}

	public function testMapTimedWithTzidUsesLocalWallTimePlusTimeZone(): void {
		// Sabre returns the DateTime already in the event's zone for a TZID.
		$dt = new DateTime('2026-06-01 10:00:00', new DateTimeZone('America/New_York'));
		$this->assertSame(
			['dateTime' => '2026-06-01T10:00:00', 'timeZone' => 'America/New_York'],
			OutboundWriteService::mapIcalDateToGoogle($dt, 'America/New_York', false)
		);
	}

	public function testMapTimedWithoutTzidUsesRfc3339Offset(): void {
		$dt = new DateTime('2026-06-01 15:00:00', new DateTimeZone('UTC'));
		$this->assertSame(
			['dateTime' => '2026-06-01T15:00:00+00:00'],
			OutboundWriteService::mapIcalDateToGoogle($dt, null, false)
		);
	}

	public function testMapTimedEmptyTzidTreatedAsNoTzid(): void {
		$dt = new DateTime('2026-06-01 15:00:00', new DateTimeZone('UTC'));
		$result = OutboundWriteService::mapIcalDateToGoogle($dt, '', false);
		$this->assertArrayNotHasKey('timeZone', $result);
		$this->assertSame('2026-06-01T15:00:00+00:00', $result['dateTime']);
	}

	// ----- deriveMissingEnd (no-DTEND) -----

	public function testDeriveMissingEndAllDayAdvancesByOneDay(): void {
		// Google all-day end.date is EXCLUSIVE; equal start/end is rejected (400).
		$this->assertSame(
			['date' => '2026-06-02'],
			OutboundWriteService::deriveMissingEnd(['date' => '2026-06-01'], true)
		);
	}

	public function testDeriveMissingEndAllDayMonthBoundary(): void {
		$this->assertSame(
			['date' => '2026-07-01'],
			OutboundWriteService::deriveMissingEnd(['date' => '2026-06-30'], true)
		);
	}

	public function testDeriveMissingEndTimedIsPointInTime(): void {
		$start = ['dateTime' => '2026-06-01T15:00:00+00:00'];
		$this->assertSame($start, OutboundWriteService::deriveMissingEnd($start, false));
	}

	// ----- resolveConflict (LWW on a 412) -----

	public function testResolveConflictNcNewerWins(): void {
		$this->assertSame('nc_wins', OutboundWriteService::resolveConflict(2000, 1000));
	}

	public function testResolveConflictGoogleNewerWins(): void {
		$this->assertSame('google_wins', OutboundWriteService::resolveConflict(1000, 2000));
	}

	public function testResolveConflictTieGoesToNc(): void {
		$this->assertSame('nc_wins', OutboundWriteService::resolveConflict(1500, 1500));
	}

	public function testResolveConflictUnknownNcTimestampIsSafeGoogleWins(): void {
		$this->assertSame('google_wins', OutboundWriteService::resolveConflict(null, 1000));
	}

	public function testResolveConflictUnknownGoogleTimestampIsSafeGoogleWins(): void {
		$this->assertSame('google_wins', OutboundWriteService::resolveConflict(2000, null));
	}

	// ----- isForeignDelete (origin-aware 412-delete ownership guard) -----

	public function testForeignDeleteNcOriginWithStrippedTagIsForeign(): void {
		// An event WE authored whose ncOrigin tag is gone -> no longer ours.
		$this->assertTrue(OutboundWriteService::isForeignDelete('nc', null, 'evt.ics'));
	}

	public function testForeignDeleteNcOriginWithRepointedTagIsForeign(): void {
		$this->assertTrue(OutboundWriteService::isForeignDelete('nc', 'other.ics', 'evt.ics'));
	}

	public function testForeignDeleteNcOriginWithMatchingTagIsOurs(): void {
		$this->assertFalse(OutboundWriteService::isForeignDelete('nc', 'evt.ics', 'evt.ics'));
	}

	public function testForeignDeleteGoogleOriginIsNeverForeign(): void {
		// A google-origin (imported) event has no tag but is ours by google_id;
		// it must be deletable under NC-delete-wins (else it resurrects).
		$this->assertFalse(OutboundWriteService::isForeignDelete('google', null, 'google-master-id'));
		$this->assertFalse(OutboundWriteService::isForeignDelete('google', 'anything', 'google-master-id'));
	}

	// ---- isPermanentBodyRejection (P-d: terminal SKIPPED_REJECTED vs transient hold) ----

	public function testPermanentCreateFailureOnlyForMalformedBodyStatuses(): void {
		$this->assertTrue(OutboundWriteService::isPermanentBodyRejection(400));
		$this->assertTrue(OutboundWriteService::isPermanentBodyRejection(422));
	}

	public function testTransientCreateFailuresAreNotPermanent(): void {
		// 403 is left transient (Google uses it for rate/quota limits); 404/410/5xx/
		// 429/409/unknown are transient too -> hold + retry, never silently dropped.
		foreach ([403, 404, 410, 429, 500, 502, 503, 409, null] as $status) {
			$this->assertFalse(
				OutboundWriteService::isPermanentBodyRejection($status),
				'status ' . var_export($status, true) . ' must be transient',
			);
		}
	}
}
