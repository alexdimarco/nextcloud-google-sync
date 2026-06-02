<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\BackgroundJob;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Service\GoogleContactsAPIService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Continuous inbound contacts sync (Track 2, C0): one job per address book that
 * a user has turned "Sync contacts" on for. Each tick pulls the People-API delta
 * (incrementally via the stored syncToken) and applies creates, edits, and
 * Google-side deletions to the Nextcloud cards. The contacts analog of
 * {@see ImportCalendarJob}.
 */
class SyncContactsJob extends TimedJob {

	public function __construct(
		ITimeFactory $timeFactory,
		private GoogleContactsAPIService $service,
		private LoggerInterface $logger,
	) {
		parent::__construct($timeFactory);
		parent::setInterval(1);
	}

	/**
	 * @param array{user_id: string, addressbook_id: int} $argument
	 */
	#[\Override]
	protected function run($argument): void {
		$userId = (string)($argument['user_id'] ?? '');
		$addressBookId = (int)($argument['addressbook_id'] ?? 0);
		if ($userId === '' || $addressBookId === 0) {
			return;
		}
		echo date('Y-m-d H:i:s') . ' Syncing contacts (address book ' . $addressBookId . ')...';
		$result = $this->service->syncAddressBook($userId, $addressBookId);
		if (isset($result['error'])) {
			$error = is_string($result['error']) ? $result['error'] : (string)json_encode($result['error']);
			echo ' error: ' . $error . PHP_EOL;
			$this->logger->warning(
				'Calendar Bridge: contacts sync failed for address book ' . $addressBookId . ' (user ' . $userId . '): ' . $error,
				['app' => Application::APP_ID],
			);
		} else {
			echo ' done. +' . ($result['nbCreated'] ?? 0) . ' ~' . ($result['nbUpdated'] ?? 0)
				. ' -' . ($result['nbDeleted'] ?? 0) . PHP_EOL;
		}
	}
}
