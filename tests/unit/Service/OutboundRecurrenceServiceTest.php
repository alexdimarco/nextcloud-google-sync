<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Tests\Unit\Service;

use OCA\CalendarBridge\Service\OutboundRecurrenceService;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the recurrence-outbound REFUSAL GUARDS. Every "unsupported"
 * transition must be detected so the differ refuses (one-way) rather than
 * corrupting a series.
 */
class OutboundRecurrenceServiceTest extends TestCase {

	private function reason(array $o): ?string {
		return OutboundRecurrenceService::unsupportedReason(
			$o['recurring'] ?? true,
			$o['shape'] ?? null,
			$o['rrule'] ?? 'FREQ=WEEKLY;BYDAY=MO',
			$o['baseRrule'] ?? null,
			$o['dtSig'] ?? 'T|America/New_York|20260608T100000',
			$o['baseDtSig'] ?? null,
			$o['rdate'] ?? false,
			$o['tzOk'] ?? true,
		);
	}

	public function testFirstSyncWithNoBaselineIsSafe(): void {
		// All baselines null -> establish, never refuse on a missing baseline.
		$this->assertNull($this->reason([]));
	}

	public function testUnresolvableTzidRefused(): void {
		$this->assertSame('unresolvable_master_tzid', $this->reason(['tzOk' => false]));
	}

	public function testRdateRefused(): void {
		$this->assertSame('rdate_unsupported', $this->reason(['rdate' => true]));
	}

	public function testShapeTransitionRecurringToSingleRefused(): void {
		$this->assertSame('shape_transition', $this->reason(['recurring' => false, 'shape' => 'recurring']));
	}

	public function testShapeTransitionSingleToRecurringRefused(): void {
		$this->assertSame('shape_transition', $this->reason(['recurring' => true, 'shape' => 'single']));
	}

	public function testShapeUnchangedIsSafe(): void {
		$this->assertNull($this->reason(['recurring' => true, 'shape' => 'recurring']));
	}

	public function testDtstartMovedRefused(): void {
		$this->assertSame('dtstart_moved', $this->reason([
			'dtSig' => 'T|America/New_York|20260608T110000',
			'baseDtSig' => 'T|America/New_York|20260608T100000',
		]));
	}

	public function testDtstartZoneChangeRefused(): void {
		$this->assertSame('dtstart_moved', $this->reason([
			'dtSig' => 'T|America/Los_Angeles|20260608T100000',
			'baseDtSig' => 'T|America/New_York|20260608T100000',
		]));
	}

	public function testAllDayToTimedFlipRefused(): void {
		$this->assertSame('dtstart_moved', $this->reason([
			'dtSig' => 'T|America/New_York|20260608T100000',
			'baseDtSig' => 'D||20260608',
		]));
	}

	public function testDtstartUnchangedIsSafe(): void {
		$this->assertNull($this->reason([
			'dtSig' => 'T|America/New_York|20260608T100000',
			'baseDtSig' => 'T|America/New_York|20260608T100000',
		]));
	}

	public function testThisAndFutureGainedUntilRefused(): void {
		$this->assertSame('thisandfuture_split', $this->reason([
			'rrule' => 'FREQ=WEEKLY;UNTIL=20260901T000000Z',
			'baseRrule' => 'FREQ=WEEKLY',
		]));
	}

	public function testPlainRruleChangeWithoutGainingBoundIsSafe(): void {
		// FREQ change with no new UNTIL/COUNT -> a normal master edit, allowed.
		$this->assertNull($this->reason(['rrule' => 'FREQ=DAILY', 'baseRrule' => 'FREQ=WEEKLY']));
	}

	// ---- rruleGainedBound ----

	public function testGainedBoundDetectsNewUntil(): void {
		$this->assertTrue(OutboundRecurrenceService::rruleGainedBound('FREQ=WEEKLY', 'FREQ=WEEKLY;UNTIL=20260901T000000Z'));
	}

	public function testGainedBoundDetectsNewCount(): void {
		$this->assertTrue(OutboundRecurrenceService::rruleGainedBound('FREQ=WEEKLY', 'FREQ=WEEKLY;COUNT=5'));
	}

	public function testGainedBoundFalseWhenAlreadyBounded(): void {
		$this->assertFalse(OutboundRecurrenceService::rruleGainedBound('FREQ=WEEKLY;COUNT=10', 'FREQ=WEEKLY;COUNT=5'));
	}

	public function testGainedBoundFalseWhenLosingBound(): void {
		$this->assertFalse(OutboundRecurrenceService::rruleGainedBound('FREQ=WEEKLY;UNTIL=20260901T000000Z', 'FREQ=WEEKLY'));
	}

	// ---- isResolvableTzid ----

	public function testResolvableTzid(): void {
		$this->assertTrue(OutboundRecurrenceService::isResolvableTzid('America/New_York'));
		$this->assertTrue(OutboundRecurrenceService::isResolvableTzid('UTC'));
		$this->assertTrue(OutboundRecurrenceService::isResolvableTzid(''));
		$this->assertFalse(OutboundRecurrenceService::isResolvableTzid('Pacific Standard Time'));
	}
}
