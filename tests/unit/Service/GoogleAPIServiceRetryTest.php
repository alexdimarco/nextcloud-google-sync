<?php

namespace OCA\Google\Tests\Unit\Service;

use OCA\Google\Service\GoogleAPIService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure-helper tests for GoogleAPIService's retry policy.
 *
 * shouldRetry() and computeBackoff() don't touch the constructor deps,
 * so we instantiate the service via newInstanceWithoutConstructor() and
 * reach the private methods via reflection.
 */
class GoogleAPIServiceRetryTest extends TestCase {
	private GoogleAPIService $svc;
	private ReflectionClass $ref;

	protected function setUp(): void {
		$this->ref = new ReflectionClass(GoogleAPIService::class);
		$this->svc = $this->ref->newInstanceWithoutConstructor();
	}

	private function invoke(string $method, array $args = []): mixed {
		$m = $this->ref->getMethod($method);
		$m->setAccessible(true);
		return $m->invoke($this->svc, ...$args);
	}

	// ---------- shouldRetry ----------

	public function testShouldRetryTrueForConnectionError(): void {
		$this->assertTrue($this->invoke('shouldRetry', [null]));
	}

	public function testShouldRetryTrueFor429(): void {
		$this->assertTrue($this->invoke('shouldRetry', [429]));
	}

	public function testShouldRetryTrueFor5xx(): void {
		$this->assertTrue($this->invoke('shouldRetry', [500]));
		$this->assertTrue($this->invoke('shouldRetry', [502]));
		$this->assertTrue($this->invoke('shouldRetry', [503]));
		$this->assertTrue($this->invoke('shouldRetry', [599]));
	}

	public function testShouldRetryFalseFor4xxOtherThan429(): void {
		foreach ([400, 401, 403, 404, 410, 418, 422] as $code) {
			$this->assertFalse($this->invoke('shouldRetry', [$code]), "Should not retry on $code");
		}
	}

	public function testShouldRetryFalseFor2xx(): void {
		// We never call shouldRetry on success, but defensively…
		$this->assertFalse($this->invoke('shouldRetry', [200]));
	}

	// ---------- computeBackoff ----------

	public function testComputeBackoffHonorsRetryAfterWhenProvided(): void {
		$this->assertSame(7, $this->invoke('computeBackoff', [1, 7]));
		$this->assertSame(12, $this->invoke('computeBackoff', [2, 12]));
	}

	public function testComputeBackoffCapsRetryAfterAt60s(): void {
		$this->assertSame(60, $this->invoke('computeBackoff', [1, 1200]));
	}

	public function testComputeBackoffIgnoresZeroOrNegativeRetryAfter(): void {
		// Fall back to exponential.
		$this->assertSame(1, $this->invoke('computeBackoff', [1, 0]));
		$this->assertSame(1, $this->invoke('computeBackoff', [1, -5]));
	}

	public function testComputeBackoffExponentialSequenceWhenNoRetryAfter(): void {
		$this->assertSame(1, $this->invoke('computeBackoff', [1, null]));
		$this->assertSame(2, $this->invoke('computeBackoff', [2, null]));
		$this->assertSame(4, $this->invoke('computeBackoff', [3, null]));
		$this->assertSame(8, $this->invoke('computeBackoff', [4, null]));
	}

	public function testComputeBackoffExponentialCapsAt30s(): void {
		$this->assertSame(30, $this->invoke('computeBackoff', [10, null]));
		$this->assertSame(30, $this->invoke('computeBackoff', [20, null]));
	}
}
