<?php

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Tests\Unit\Service;

use DateTimeZone;
use OCA\CalendarBridge\Service\GoogleCalendarAPIService;
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

	// ---------- extractTzids ----------

	public function testExtractTzidsReturnsEmptyArrayForUtcOnlyEventData(): void {
		$ical = "DTSTART;VALUE=DATE-TIME:20260601T150000Z\nDTEND;VALUE=DATE-TIME:20260601T160000Z\n";
		$this->assertSame([], $this->invoke('extractTzids', [$ical]));
	}

	public function testExtractTzidsCapturesUniqueTzidsFromDtstartAndDtend(): void {
		$ical = "DTSTART;TZID=America/New_York:20260601T100000\n"
			. "DTEND;TZID=America/New_York:20260601T110000\n"
			. "EXDATE;TZID=Europe/Berlin:20260615T100000\n";
		$this->assertSame(
			['America/New_York', 'Europe/Berlin'],
			$this->invoke('extractTzids', [$ical])
		);
	}

	// ---------- formatTzOffset ----------

	public function testFormatTzOffsetPositive(): void {
		$this->assertSame('+0530', $this->invoke('formatTzOffset', [5 * 3600 + 30 * 60]));
	}

	public function testFormatTzOffsetNegative(): void {
		$this->assertSame('-0500', $this->invoke('formatTzOffset', [-5 * 3600]));
	}

	public function testFormatTzOffsetZero(): void {
		$this->assertSame('+0000', $this->invoke('formatTzOffset', [0]));
	}

	// ---------- buildVTimezoneBlock ----------

	public function testBuildVTimezoneBlockReturnsEmptyForUnknownTzid(): void {
		$this->assertSame('', $this->invoke('buildVTimezoneBlock', ['Not/AReal_Zone']));
	}

	public function testBuildVTimezoneBlockForDstZoneContainsStandardAndDaylight(): void {
		$block = $this->invoke('buildVTimezoneBlock', ['America/New_York']);
		$this->assertStringStartsWith("BEGIN:VTIMEZONE\nTZID:America/New_York\n", $block);
		$this->assertStringEndsWith("END:VTIMEZONE\n", $block);
		$this->assertStringContainsString("BEGIN:STANDARD", $block);
		$this->assertStringContainsString("BEGIN:DAYLIGHT", $block);
		$this->assertStringContainsString("TZOFFSETTO:-0500", $block);
		$this->assertStringContainsString("TZOFFSETTO:-0400", $block);
	}

	public function testBuildVTimezoneBlockForUtcContainsOnlyStandard(): void {
		$block = $this->invoke('buildVTimezoneBlock', ['UTC']);
		$this->assertStringContainsString("BEGIN:STANDARD", $block);
		$this->assertStringNotContainsString("BEGIN:DAYLIGHT", $block);
		$this->assertStringContainsString("TZOFFSETTO:+0000", $block);
	}

	// ---------- isSyncTokenExpiredError ----------

	public function testIsSyncTokenExpiredErrorTrueOn410(): void {
		$ret = ['error' => 'ServerException|ClientException, message:... status code: 410'];
		$this->assertTrue($this->invoke('isSyncTokenExpiredError', [$ret]));
	}

	public function testIsSyncTokenExpiredErrorFalseOn401(): void {
		$ret = ['error' => 'ServerException|ClientException, message:... status code: 401'];
		$this->assertFalse($this->invoke('isSyncTokenExpiredError', [$ret]));
	}

	public function testIsSyncTokenExpiredErrorFalseOnSuccessReturn(): void {
		$this->assertFalse($this->invoke('isSyncTokenExpiredError', [['nextSyncToken' => 'abc']]));
	}

	public function testIsSyncTokenExpiredErrorFalseOnNull(): void {
		$this->assertFalse($this->invoke('isSyncTokenExpiredError', [null]));
	}

	// ---------- syncTokenConfigKey ----------

	public function testSyncTokenConfigKeyIsDeterministicAndCalIdScoped(): void {
		$a = $this->invoke('syncTokenConfigKey', ['cal-a@group.calendar.google.com']);
		$b = $this->invoke('syncTokenConfigKey', ['cal-b@group.calendar.google.com']);
		$this->assertNotSame($a, $b);
		$this->assertStringStartsWith('sync_token_', $a);
		$this->assertSame($a, $this->invoke('syncTokenConfigKey', ['cal-a@group.calendar.google.com']));
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

	// ---------- extractCalendarTimezone (P-c: name+timezone on calendars.insert) ----------

	public function testExtractCalendarTimezonePullsTzidFromVtimezone(): void {
		$vt = "BEGIN:VCALENDAR\r\nBEGIN:VTIMEZONE\r\nTZID:America/New_York\r\nEND:VTIMEZONE\r\nEND:VCALENDAR";
		$this->assertSame('America/New_York', GoogleCalendarAPIService::extractCalendarTimezone($vt));
	}

	public function testExtractCalendarTimezoneNullAndEmptyReturnNull(): void {
		$this->assertNull(GoogleCalendarAPIService::extractCalendarTimezone(null));
		$this->assertNull(GoogleCalendarAPIService::extractCalendarTimezone(''));
	}

	public function testExtractCalendarTimezoneNoTzidLineReturnsNull(): void {
		$this->assertNull(GoogleCalendarAPIService::extractCalendarTimezone("BEGIN:VCALENDAR\nEND:VCALENDAR"));
	}
}
