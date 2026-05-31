<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Tests\Unit\Service;

use OCA\CalendarBridge\Service\MapVerifyService;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the verify pass's two decision functions. The whole safety
 * argument rests on classifyRowDrift never returning a REPAIR verdict for a
 * divergence that could lose data — so the decision table is exhaustively pinned
 * here, and a guard test pins the verdict ENUM (only the 9 known verdicts can be
 * returned). The complementary "only the two REPAIR verdicts mutate the map"
 * contract lives in applyVerdict and is exercised end-to-end by the non-destructive
 * assertions in tests/manual/verify-pass.php.
 */
class MapVerifyServiceTest extends TestCase {

	/** @param array<string,mixed> $over */
	private function row(array $over = []): array {
		return array_merge([
			'ncUri' => 'u1',
			'googleId' => 'g1',
			'origin' => 'google',
			'ncEtag' => 'nc-etag-1',
			'baselineEtag' => 'b-etag-1',
		], $over);
	}

	/** A live Google projection entry. */
	private function gev(?string $etag, ?string $ncOrigin = null): array {
		return ['etag' => $etag, 'updated' => '2026-05-31T00:00:00Z', 'ncOrigin' => $ncOrigin];
	}

	// ---------------- shouldVerify (cadence gate) ----------------

	public function testShouldVerifyDueAtAndPastInterval(): void {
		$this->assertTrue(MapVerifyService::shouldVerify(0, 21600, 21600), 'exactly interval since epoch-0 is due');
		$this->assertTrue(MapVerifyService::shouldVerify(1000, 1000 + 21600, 21600), 'exactly interval elapsed is due');
		$this->assertTrue(MapVerifyService::shouldVerify(1000, 1000 + 99999, 21600), 'long past is due');
	}

	public function testShouldVerifyNotDueWithinInterval(): void {
		$this->assertFalse(MapVerifyService::shouldVerify(1000, 1000 + 21599, 21600), 'one second short is not due');
		$this->assertFalse(MapVerifyService::shouldVerify(1000, 1000, 21600), 'same instant is not due');
	}

	// ---------------- classifyRowDrift: healthy ----------------

	public function testOkGoogleOriginInSync(): void {
		$v = MapVerifyService::classifyRowDrift(
			$this->row(),
			['g1' => $this->gev('b-etag-1')],
			['u1' => 'x'],
			[],
		);
		$this->assertSame(MapVerifyService::OK, $v);
	}

	public function testOkNcOriginWithMatchingTag(): void {
		$v = MapVerifyService::classifyRowDrift(
			$this->row(['origin' => 'nc', 'ncUri' => 'myuri', 'googleId' => 'g9']),
			['g9' => $this->gev('b-etag-1', 'myuri')],
			['myuri' => 'x'],
			['myuri' => ['g9']],
		);
		$this->assertSame(MapVerifyService::OK, $v);
	}

	// ---------------- classifyRowDrift: LOG-only (never repaired) ----------------

	public function testStaleBaselineIsLoggedNotRepaired(): void {
		$v = MapVerifyService::classifyRowDrift(
			$this->row(['baselineEtag' => 'OLD']),
			['g1' => $this->gev('NEW')],
			['u1' => 'x'],
			[],
		);
		$this->assertSame(MapVerifyService::LOG_STALE_BASELINE, $v);
	}

	public function testNullNcEtagIsIndeterminate(): void {
		$v = MapVerifyService::classifyRowDrift(
			$this->row(['ncEtag' => null]),
			['g1' => $this->gev('b-etag-1')],
			['u1' => 'x'],
			[],
		);
		$this->assertSame(MapVerifyService::LOG_INDETERMINATE, $v);
	}

	public function testGooglePresentNcGoneIsAmbiguous(): void {
		$v = MapVerifyService::classifyRowDrift(
			$this->row(),
			['g1' => $this->gev('b-etag-1')],
			[], // no NC object
			[],
		);
		$this->assertSame(MapVerifyService::LOG_AMBIGUOUS_GOOGLE_ONLY, $v);
	}

	public function testStrippedOrRepointedTagIsForeign(): void {
		$v = MapVerifyService::classifyRowDrift(
			$this->row(['origin' => 'nc', 'ncUri' => 'mine', 'googleId' => 'g5']),
			['g5' => $this->gev('b-etag-1', 'someone-else')],
			['mine' => 'x'],
			['someone-else' => ['g5']],
		);
		$this->assertSame(MapVerifyService::LOG_FOREIGN_TAG, $v);
	}

	public function testNcPresentGoogleGoneNoCandidateIsAmbiguous(): void {
		$v = MapVerifyService::classifyRowDrift(
			$this->row(['ncUri' => 'here', 'googleId' => 'gmissing']),
			[], // google gone
			['here' => 'e'],
			[],
		);
		$this->assertSame(MapVerifyService::LOG_AMBIGUOUS_NC_ONLY, $v);
	}

	public function testMultipleTagCandidatesIsAmbiguous(): void {
		$v = MapVerifyService::classifyRowDrift(
			$this->row(['origin' => 'nc', 'ncUri' => 'myuri', 'googleId' => 'gold']),
			['gnew1' => $this->gev('e', 'myuri'), 'gnew2' => $this->gev('e', 'myuri')],
			['myuri' => 'x'],
			['myuri' => ['gnew1', 'gnew2']],
		);
		$this->assertSame(MapVerifyService::LOG_REBIND_AMBIGUOUS, $v);
	}

	// ---------------- classifyRowDrift: the two safe REPAIRs ----------------

	public function testRebindWhenExactlyOneTagCandidate(): void {
		$v = MapVerifyService::classifyRowDrift(
			$this->row(['origin' => 'nc', 'ncUri' => 'myuri', 'googleId' => 'gold']),
			['gnew' => $this->gev('e', 'myuri')],
			['myuri' => 'x'],
			['myuri' => ['gnew']],
		);
		$this->assertSame(MapVerifyService::REPAIR_REBIND_GID, $v);
	}

	public function testDropWhenBothSidesGone(): void {
		$v = MapVerifyService::classifyRowDrift(
			$this->row(['ncUri' => 'dead', 'googleId' => 'dead']),
			[],
			[],
			[],
		);
		$this->assertSame(MapVerifyService::REPAIR_DROP_ORPHAN, $v);
	}

	public function testRebindCandidateEqualToDeadGidIsNotARebind(): void {
		// The only tag candidate is the (gone) current google_id itself -> excluded,
		// so there is nothing to rebind to. NC object still present => ambiguous.
		$v = MapVerifyService::classifyRowDrift(
			$this->row(['origin' => 'nc', 'ncUri' => 'myuri', 'googleId' => 'gcur']),
			[],
			['myuri' => 'x'],
			['myuri' => ['gcur']],
		);
		$this->assertSame(MapVerifyService::LOG_AMBIGUOUS_NC_ONLY, $v);
	}

	public function testGoogleOriginNeverRebindsEvenWithTagCandidate(): void {
		// Rebind-by-tag is an nc-origin-only repair; a google-origin row with a
		// stray tag candidate must not rebind.
		$v = MapVerifyService::classifyRowDrift(
			$this->row(['origin' => 'google', 'ncUri' => 'here', 'googleId' => 'gmissing']),
			['gother' => $this->gev('e', 'here')],
			['here' => 'x'],
			['here' => ['gother']],
		);
		$this->assertSame(MapVerifyService::LOG_AMBIGUOUS_NC_ONLY, $v);
	}

	// ---------------- guard: only two REPAIR verdicts can ever mutate ----------------

	public function testEveryVerdictIsInTheKnownSafeSet(): void {
		$allowed = [
			MapVerifyService::OK,
			MapVerifyService::REPAIR_DROP_ORPHAN,
			MapVerifyService::REPAIR_REBIND_GID,
			MapVerifyService::LOG_INDETERMINATE,
			MapVerifyService::LOG_FOREIGN_TAG,
			MapVerifyService::LOG_STALE_BASELINE,
			MapVerifyService::LOG_AMBIGUOUS_NC_ONLY,
			MapVerifyService::LOG_AMBIGUOUS_GOOGLE_ONLY,
			MapVerifyService::LOG_REBIND_AMBIGUOUS,
		];
		// Exercise a cross-product of conditions; every outcome must be one of the
		// known verdicts (the two REPAIR_* ones are the only verdicts applyVerdict
		// ever acts on — see tests/manual/verify-pass.php for the non-destructive
		// end-to-end proof).
		foreach (['google', 'nc'] as $origin) {
			foreach ([null, 'nc-etag-1'] as $ncEtag) {
				foreach ([true, false] as $gLive) {
					foreach ([true, false] as $ncLive) {
						foreach ([[], ['u1' => ['gX']], ['u1' => ['gX', 'gY']]] as $byTag) {
							$row = $this->row(['origin' => $origin, 'ncEtag' => $ncEtag]);
							$byId = $gLive ? ['g1' => $this->gev('b-etag-1', $origin === 'nc' ? 'u1' : null)] : [];
							$ncEtags = $ncLive ? ['u1' => 'x'] : [];
							$v = MapVerifyService::classifyRowDrift($row, $byId, $ncEtags, $byTag);
							$this->assertContains($v, $allowed, "unexpected verdict $v");
						}
					}
				}
			}
		}
	}
}
