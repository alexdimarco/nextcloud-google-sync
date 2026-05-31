<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\CalendarBridge\Service\RecurrenceKey;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the canonical recurrence-instance key. The whole differ's
 * correctness rests on NC, Google-live, and stored-token values reducing to the
 * SAME token for the same instant.
 *
 * The NC side is exercised via canonicalizeValues() with raw DateTimeImmutable
 * (which resolves a real IANA TZID identically to Sabre's getDateTimes), so
 * these stay in the pure harness; the thin Sabre Property extraction in
 * fromIcsDateProps() is lab-verified.
 */
class RecurrenceKeyTest extends TestCase {

	/** Resolves real IANA zones (what the differ passes in production). */
	private \Closure $resolver;

	protected function setUp(): void {
		$this->resolver = static fn (string $tz): bool => in_array($tz, DateTimeZone::listIdentifiers(), true);
	}

	/** A timed NC value as Sabre would resolve it: wall time in its TZID. */
	private function ny(string $iso): DateTimeImmutable {
		return new DateTimeImmutable($iso, new DateTimeZone('America/New_York'));
	}

	// ---- the LOCKED equivalence: NC ics == Google live == stored token ----

	public function testLockedTimedEquivalenceAcrossAllThreeSources(): void {
		$ncKey = RecurrenceKey::canonicalizeValues(false, 'America/New_York', [$this->ny('2026-06-08 10:00:00')], $this->resolver)[0];
		$liveKey = RecurrenceKey::fromGoogleInstance(['originalStartTime' => ['dateTime' => '2026-06-08T10:00:00-04:00', 'timeZone' => 'America/New_York']]);
		$tokenKey = RecurrenceKey::fromGoogleToken('2026-06-08T10:00:00-04:00');
		$this->assertSame('T:2026-06-08T14:00:00Z', $ncKey);
		$this->assertSame('T:2026-06-08T14:00:00Z', $liveKey);
		$this->assertSame('T:2026-06-08T14:00:00Z', $tokenKey);
	}

	public function testLockedAllDayEquivalence(): void {
		$ncKey = RecurrenceKey::canonicalizeValues(true, null, [new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC'))], $this->resolver)[0];
		$liveKey = RecurrenceKey::fromGoogleInstance(['originalStartTime' => ['date' => '2026-06-01']]);
		$tokenKey = RecurrenceKey::fromGoogleToken('2026-06-01');
		$this->assertSame('D:2026-06-01', $ncKey);
		$this->assertSame('D:2026-06-01', $liveKey);
		$this->assertSame('D:2026-06-01', $tokenKey);
	}

	// ---- DST correctness (US DST starts 2026-03-08; NY is EST before, EDT after) ----

	public function testTimedDstBeforeTransitionIsEst(): void {
		// 2026-03-02 10:00 America/New_York = EST (-05:00) = 15:00Z.
		$this->assertSame('T:2026-03-02T15:00:00Z', RecurrenceKey::keyForDateTime($this->ny('2026-03-02 10:00:00'), false));
	}

	public function testTimedDstAfterTransitionIsEdt(): void {
		// 2026-03-09 10:00 America/New_York = EDT (-04:00) = 14:00Z.
		$this->assertSame('T:2026-03-09T14:00:00Z', RecurrenceKey::keyForDateTime($this->ny('2026-03-09 10:00:00'), false));
	}

	// ---- Z/UTC value is UTC ----

	public function testUtcValueCanonicalizes(): void {
		$this->assertSame('T:2026-06-08T14:00:00Z', RecurrenceKey::keyForDateTime(new DateTimeImmutable('2026-06-08T14:00:00Z'), false));
	}

	// ---- unresolvable TZID -> null (do not fabricate an instant) ----

	public function testUnresolvableTzidYieldsNull(): void {
		$reject = static fn (string $tz): bool => false; // simulate Windows/X-LIC zone
		$this->assertSame([null], RecurrenceKey::canonicalizeValues(false, 'Pacific Standard Time', [$this->ny('2026-06-08 10:00:00')], $reject));
	}

	public function testEmptyTzidIsNotGated(): void {
		// floating (no TZID): canonicalize against the supplied (master) zone.
		$this->assertSame('T:2026-06-08T14:00:00Z', RecurrenceKey::canonicalizeValues(false, '', [$this->ny('2026-06-08 10:00:00')], $this->resolver)[0]);
	}

	public function testEmptyDateTimesYieldsNull(): void {
		$this->assertSame([null], RecurrenceKey::canonicalizeValues(false, 'America/New_York', [], $this->resolver));
	}

	// ---- multi-value (EXDATE) ----

	public function testMultiValueYieldsAllKeys(): void {
		$keys = RecurrenceKey::canonicalizeValues(false, 'America/New_York', [$this->ny('2026-06-08 10:00:00'), $this->ny('2026-06-15 10:00:00')], $this->resolver);
		$this->assertSame(['T:2026-06-08T14:00:00Z', 'T:2026-06-15T14:00:00Z'], $keys);
	}

	// ---- kinds never cross ----

	public function testTimedAndAllDayNeverCollide(): void {
		$timed = RecurrenceKey::fromGoogleInstance(['originalStartTime' => ['dateTime' => '2026-06-01T00:00:00Z']]);
		$allDay = RecurrenceKey::fromGoogleInstance(['originalStartTime' => ['date' => '2026-06-01']]);
		$this->assertNotSame($timed, $allDay);
		$this->assertSame('T:2026-06-01T00:00:00Z', $timed);
		$this->assertSame('D:2026-06-01', $allDay);
	}

	// ---- Google-side edge handling ----

	public function testGoogleInstanceMissingOriginalStartIsNull(): void {
		$this->assertNull(RecurrenceKey::fromGoogleInstance(['id' => 'x']));
	}

	public function testGoogleTokenEmptyOrGarbageIsNull(): void {
		$this->assertNull(RecurrenceKey::fromGoogleToken(''));
		$this->assertNull(RecurrenceKey::fromGoogleToken('not-a-date'));
	}

	public function testGoogleTokenZuluNormalizesToUtc(): void {
		$this->assertSame('T:2026-06-08T14:00:00Z', RecurrenceKey::fromGoogleToken('2026-06-08T14:00:00Z'));
	}

	public function testGoogleTokenOffsetNormalizesToUtc(): void {
		$this->assertSame('T:2026-06-08T14:00:00Z', RecurrenceKey::fromGoogleToken('2026-06-08T10:00:00-04:00'));
	}
}
