<?php

namespace OCA\Google\Tests\Unit\Service;

use DateTimeZone;
use OCA\Google\Service\GoogleCalendarAPIService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure-helper tests for GoogleCalendarAPIService.
 *
 * These helpers don't touch the Nextcloud framework, so the service is
 * instantiated via newInstanceWithoutConstructor() — only utcTimezone is
 * seeded (mapTime needs it). Tests use reflection to reach the private
 * helpers without source changes.
 */
class GoogleCalendarAPIServiceHelpersTest extends TestCase {
	private GoogleCalendarAPIService $svc;
	private ReflectionClass $ref;

	protected function setUp(): void {
		$this->ref = new ReflectionClass(GoogleCalendarAPIService::class);
		$this->svc = $this->ref->newInstanceWithoutConstructor();
		$utc = $this->ref->getProperty('utcTimezone');
		$utc->setAccessible(true);
		$utc->setValue($this->svc, new DateTimeZone('-0000'));
	}

	private function invoke(string $method, array $args = []): mixed {
		$m = $this->ref->getMethod($method);
		$m->setAccessible(true);
		return $m->invoke($this->svc, ...$args);
	}

	// ---------- quoteIcalParam ----------

	public function testQuoteIcalParamWrapsPlainStringInDoubleQuotes(): void {
		$this->assertSame('"Jane Doe"', $this->invoke('quoteIcalParam', ['Jane Doe']));
	}

	public function testQuoteIcalParamReplacesEmbeddedDoubleQuotesWithSingleQuotes(): void {
		$this->assertSame("\"the 'best' team\"", $this->invoke('quoteIcalParam', ['the "best" team']));
	}

	public function testQuoteIcalParamStripsControlCharacters(): void {
		$this->assertSame('"line1line2"', $this->invoke('quoteIcalParam', ["line1\r\nline2"]));
	}

	// ---------- mapTime ----------

	public function testMapTimeReturnsEmptyStringForObjectWithoutDate(): void {
		$this->assertSame('', $this->invoke('mapTime', [[]]));
	}

	public function testMapTimeFormatsAllDayDate(): void {
		$this->assertSame('VALUE=DATE:20260601', $this->invoke('mapTime', [['date' => '2026-06-01']]));
	}

	public function testMapTimeFormatsDateTimeWithoutTimezoneAsUtc(): void {
		$this->assertSame(
			'VALUE=DATE-TIME:20260601T150000Z',
			$this->invoke('mapTime', [['dateTime' => '2026-06-01T15:00:00Z']])
		);
	}

	public function testMapTimeFormatsDateTimeWithTimezoneAsTzidPrefix(): void {
		$this->assertSame(
			'TZID=America/New_York:20260601T100000',
			$this->invoke('mapTime', [[
				'dateTime' => '2026-06-01T14:00:00Z',
				'timeZone' => 'America/New_York',
			]])
		);
	}

	// ---------- buildOrganizerLine ----------

	public function testBuildOrganizerLineReturnsEmptyStringWhenEmailMissing(): void {
		$this->assertSame('', $this->invoke('buildOrganizerLine', [['displayName' => 'No Email']]));
	}

	public function testBuildOrganizerLineWithoutDisplayName(): void {
		$this->assertSame(
			"ORGANIZER:mailto:alice@example.com\n",
			$this->invoke('buildOrganizerLine', [['email' => 'alice@example.com']])
		);
	}

	public function testBuildOrganizerLineWithDisplayName(): void {
		$this->assertSame(
			"ORGANIZER;CN=\"Alice Cooper\":mailto:alice@example.com\n",
			$this->invoke('buildOrganizerLine', [[
				'email' => 'alice@example.com',
				'displayName' => 'Alice Cooper',
			]])
		);
	}

	// ---------- buildAttendeeLine ----------

	public function testBuildAttendeeLineReturnsEmptyStringWhenEmailMissing(): void {
		$this->assertSame('', $this->invoke('buildAttendeeLine', [['displayName' => 'No Email']]));
	}

	public function testBuildAttendeeLineDefaultsRoleToReqParticipant(): void {
		$this->assertSame(
			"ATTENDEE;ROLE=REQ-PARTICIPANT:mailto:bob@example.com\n",
			$this->invoke('buildAttendeeLine', [['email' => 'bob@example.com']])
		);
	}

	public function testBuildAttendeeLineOptionalProducesOptParticipant(): void {
		$this->assertSame(
			"ATTENDEE;ROLE=OPT-PARTICIPANT:mailto:bob@example.com\n",
			$this->invoke('buildAttendeeLine', [['email' => 'bob@example.com', 'optional' => true]])
		);
	}

	public function testBuildAttendeeLineResourceAddsCutype(): void {
		$this->assertSame(
			"ATTENDEE;CUTYPE=RESOURCE;ROLE=REQ-PARTICIPANT:mailto:room@example.com\n",
			$this->invoke('buildAttendeeLine', [['email' => 'room@example.com', 'resource' => true]])
		);
	}

	public function testBuildAttendeeLineDisplayNameAddsQuotedCn(): void {
		$this->assertSame(
			"ATTENDEE;CN=\"Bob Smith\";ROLE=REQ-PARTICIPANT:mailto:bob@example.com\n",
			$this->invoke('buildAttendeeLine', [[
				'email' => 'bob@example.com',
				'displayName' => 'Bob Smith',
			]])
		);
	}

	public static function partstatProvider(): array {
		return [
			'accepted' => ['accepted', 'ACCEPTED'],
			'declined' => ['declined', 'DECLINED'],
			'tentative' => ['tentative', 'TENTATIVE'],
			'needsAction' => ['needsAction', 'NEEDS-ACTION'],
		];
	}

	/**
	 * @dataProvider partstatProvider
	 */
	public function testBuildAttendeeLineMapsResponseStatusToPartstat(string $google, string $ical): void {
		$line = $this->invoke('buildAttendeeLine', [[
			'email' => 'bob@example.com',
			'responseStatus' => $google,
		]]);
		$this->assertStringContainsString('PARTSTAT=' . $ical, $line);
	}

	public function testBuildAttendeeLineOmitsPartstatForUnknownResponseStatus(): void {
		$line = $this->invoke('buildAttendeeLine', [[
			'email' => 'bob@example.com',
			'responseStatus' => 'somethingWeird',
		]]);
		$this->assertStringNotContainsString('PARTSTAT', $line);
	}

	public function testBuildAttendeeLineFullExample(): void {
		$this->assertSame(
			"ATTENDEE;CN=\"Carol\";CUTYPE=RESOURCE;ROLE=OPT-PARTICIPANT;PARTSTAT=TENTATIVE:mailto:carol@example.com\n",
			$this->invoke('buildAttendeeLine', [[
				'email' => 'carol@example.com',
				'displayName' => 'Carol',
				'optional' => true,
				'resource' => true,
				'responseStatus' => 'tentative',
			]])
		);
	}
}
