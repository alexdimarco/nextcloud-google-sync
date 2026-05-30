<?php

namespace OCA\CalendarBridge\Tests\Unit\Service;

use OCA\CalendarBridge\Service\OutboundReconcileService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the outbound change classifier — the NC-side echo gate.
 */
class OutboundReconcileServiceTest extends TestCase {

	// ----- deletes -----

	public function testDeleteWithMapRowIsLocalDelete(): void {
		$this->assertSame(
			OutboundReconcileService::LOCAL_DELETE,
			OutboundReconcileService::classifyChange('deleted', true, '"e1"', null)
		);
	}

	public function testDeleteWithoutMapRowIsEchoDelete(): void {
		// Our own inbound delete already removed the map row.
		$this->assertSame(
			OutboundReconcileService::ECHO_DELETE,
			OutboundReconcileService::classifyChange('deleted', false, null, null)
		);
	}

	// ----- added / modified -----

	public function testAddedWithoutMapRowIsLocalNew(): void {
		$this->assertSame(
			OutboundReconcileService::LOCAL_NEW,
			OutboundReconcileService::classifyChange('added', false, null, '"e1"')
		);
	}

	public function testModifiedEtagMatchesBaselineIsEcho(): void {
		// Current etag == what we last wrote => our own inbound write echoing.
		$this->assertSame(
			OutboundReconcileService::ECHO,
			OutboundReconcileService::classifyChange('modified', true, '"e7"', '"e7"')
		);
	}

	public function testModifiedEtagDiffersFromBaselineIsLocalEdit(): void {
		$this->assertSame(
			OutboundReconcileService::LOCAL_EDIT,
			OutboundReconcileService::classifyChange('modified', true, '"e7"', '"e8"')
		);
	}

	public function testAddedEtagMatchesBaselineIsEcho(): void {
		// An inbound create also reports as 'added' on the NC change feed.
		$this->assertSame(
			OutboundReconcileService::ECHO,
			OutboundReconcileService::classifyChange('added', true, '"e1"', '"e1"')
		);
	}

	public function testNullBaselineIsIndeterminate(): void {
		// Seeded row never re-written: no baseline to compare.
		$this->assertSame(
			OutboundReconcileService::LOCAL_EDIT_INDETERMINATE,
			OutboundReconcileService::classifyChange('modified', true, null, '"e1"')
		);
	}

	public function testMapRowButNullCurrentEtagWithBaselineIsLocalEdit(): void {
		// Baseline exists, current etag unreadable/missing -> not an echo match.
		$this->assertSame(
			OutboundReconcileService::LOCAL_EDIT,
			OutboundReconcileService::classifyChange('modified', true, '"e7"', null)
		);
	}

	// ----- needsRebaseline (token lifecycle) -----

	public function testNeedsRebaselineWhenChangesNull(): void {
		// Expired/unknown token: getChangesForCalendar returns null.
		$this->assertTrue(OutboundReconcileService::needsRebaseline(null, '42'));
	}

	public function testNeedsRebaselineWhenHeadLowerThanStored(): void {
		// Calendar deleted + re-imported under a fresh, lower sequence.
		$this->assertTrue(OutboundReconcileService::needsRebaseline(['syncToken' => 5], '42'));
	}

	public function testNoRebaselineWhenHeadEqualsStored(): void {
		$this->assertFalse(OutboundReconcileService::needsRebaseline(['syncToken' => 42], '42'));
	}

	public function testNoRebaselineWhenHeadAdvancedNormally(): void {
		$this->assertFalse(OutboundReconcileService::needsRebaseline(['syncToken' => 50], '42'));
	}

	public function testNeedsRebaselineWhenSyncTokenMissing(): void {
		// Defensive: a result without a syncToken is unusable.
		$this->assertTrue(OutboundReconcileService::needsRebaseline(['added' => []], '42'));
	}
}
