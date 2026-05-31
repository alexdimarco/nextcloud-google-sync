<?php

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Tests\Unit\Service;

use OCA\CalendarBridge\Service\EventMapService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for EventMapService. recurrenceIdToken() is static and
 * touches no dependencies, so it is exercised directly.
 */
class EventMapServiceTest extends TestCase {

	public function testRecurrenceIdTokenEmptyForMasterWithoutOriginalStartTime(): void {
		$this->assertSame('', EventMapService::recurrenceIdToken(['id' => 'abc']));
	}

	public function testRecurrenceIdTokenEmptyWhenOriginalStartTimeNotArray(): void {
		$this->assertSame('', EventMapService::recurrenceIdToken(['originalStartTime' => 'nope']));
	}

	public function testRecurrenceIdTokenUsesDateTimeWhenPresent(): void {
		$this->assertSame(
			'2026-05-24T15:00:00-04:00',
			EventMapService::recurrenceIdToken([
				'originalStartTime' => ['dateTime' => '2026-05-24T15:00:00-04:00', 'timeZone' => 'America/New_York'],
			])
		);
	}

	public function testRecurrenceIdTokenFallsBackToDateForAllDay(): void {
		$this->assertSame(
			'2026-05-24',
			EventMapService::recurrenceIdToken([
				'originalStartTime' => ['date' => '2026-05-24'],
			])
		);
	}

	public function testRecurrenceIdTokenPrefersDateTimeOverDate(): void {
		$this->assertSame(
			'2026-05-24T15:00:00Z',
			EventMapService::recurrenceIdToken([
				'originalStartTime' => ['dateTime' => '2026-05-24T15:00:00Z', 'date' => '2026-05-24'],
			])
		);
	}

	public function testRecurrenceIdTokenEmptyWhenBothBlank(): void {
		$this->assertSame('', EventMapService::recurrenceIdToken([
			'originalStartTime' => ['dateTime' => '', 'date' => ''],
		]));
	}
}
