<?php

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Tests\Unit\Service;

use OCA\CalendarBridge\Service\GoogleContactsAPIService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the outbound (NC -> Google) contacts change classifier
 * (Track 2 C2a). buildPersonFromVCard depends on Sabre\VObject (a Nextcloud
 * runtime dependency absent from this pure harness) and is lab-verified instead.
 */
class GoogleContactsOutboundTest extends TestCase {

	public function testNoMapRowIsLocalNew(): void {
		$this->assertSame(GoogleContactsAPIService::LOCAL_NEW, GoogleContactsAPIService::classifyOutbound('added', false, null, 'etag'));
		$this->assertSame(GoogleContactsAPIService::LOCAL_NEW, GoogleContactsAPIService::classifyOutbound('modified', false, null, 'etag'));
	}

	public function testDeletedNoMapRowIsEchoDelete(): void {
		$this->assertSame(GoogleContactsAPIService::ECHO_DELETE, GoogleContactsAPIService::classifyOutbound('deleted', false, null, null));
	}

	public function testDeletedWithMapRowIsLocalDelete(): void {
		$this->assertSame(GoogleContactsAPIService::LOCAL_DELETE, GoogleContactsAPIService::classifyOutbound('deleted', true, 'etagX', null));
	}

	public function testEtagEqualsBaselineIsEcho(): void {
		$this->assertSame(GoogleContactsAPIService::ECHO, GoogleContactsAPIService::classifyOutbound('modified', true, 'etagX', 'etagX'));
	}

	public function testEtagDiffersIsLocalEdit(): void {
		$this->assertSame(GoogleContactsAPIService::LOCAL_EDIT, GoogleContactsAPIService::classifyOutbound('modified', true, 'etagX', 'etagY'));
	}

	public function testNullBaselineIsIndeterminate(): void {
		$this->assertSame(GoogleContactsAPIService::LOCAL_EDIT_INDETERMINATE, GoogleContactsAPIService::classifyOutbound('modified', true, null, 'etagY'));
	}

	public function testNullCurrentEtagIsLocalEditNotEcho(): void {
		// A mapped card we cannot read the etag for is a (indeterminate) edit, never an echo.
		$this->assertSame(GoogleContactsAPIService::LOCAL_EDIT, GoogleContactsAPIService::classifyOutbound('modified', true, 'etagX', null));
	}
}
