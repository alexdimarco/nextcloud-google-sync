<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Listener;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Service\CalendarMapService;
use OCA\CalendarBridge\Service\GoogleCalendarAPIService;
use OCA\CalendarBridge\Service\OutboundReconcileService;
use OCA\DAV\Events\CalendarDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @template-implements IEventListener<Event>
 *
 * Tears down a calendar-level NC<->Google pairing when the Nextcloud calendar is
 * deleted out-of-band (Calendar app / occ / account removal). Without this, the
 * calbridge_calendar_map row + the ImportCalendarJob for that pairing would
 * dangle and the job would keep ticking against a gone calendar.
 *
 * It only UNLINKS — drops the map row, unregisters the import job, clears the
 * two-way flag — and deliberately does NOT delete the remote Google calendar
 * (an NC-side deletion must never silently destroy the user's Google copy).
 * Fully defensive: it runs inside CalDAV's delete transaction, so a throw here
 * must never break the deletion.
 */
class CalendarDeletedListener implements IEventListener {

	public function __construct(
		private CalendarMapService $calendarMapService,
		private GoogleCalendarAPIService $googleCalendarAPIService,
		private OutboundReconcileService $outboundReconcileService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof CalendarDeletedEvent)) {
			return;
		}
		try {
			$data = $event->getCalendarData();
			$userId = self::userIdFromPrincipal(isset($data['principaluri']) ? (string)$data['principaluri'] : null);
			if ($userId === null) {
				return; // not a user calendar (group / resource / room principal)
			}
			$ncCalId = $event->getCalendarId();
			$googleCalId = $this->calendarMapService->getGoogleCalIdForNcCalId($ncCalId);
			if ($googleCalId === null) {
				return; // not a linked NC-origin calendar — nothing to clean up
			}
			// Resolve the Google id BEFORE dropping the row (the job + two-way
			// state are keyed by the Google id). Unlink only; keep the Google calendar.
			$this->googleCalendarAPIService->unregisterSyncCalendar($userId, $googleCalId);
			$this->outboundReconcileService->setTwoWayEnabled($userId, $googleCalId, false);
			$this->calendarMapService->removeByNcCalId($ncCalId);
			$this->logger->info(
				'Calendar Bridge: NC calendar ' . $ncCalId . ' deleted; unlinked its Google pairing ' . $googleCalId
					. ' (Google calendar kept)',
				['app' => Application::APP_ID],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: calendar-deleted cleanup failed: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
		}
	}

	/** The user id from a 'principals/users/<uid>' principal, else null. Pure. */
	public static function userIdFromPrincipal(?string $principalUri): ?string {
		$prefix = 'principals/users/';
		if ($principalUri === null || !str_starts_with($principalUri, $prefix)) {
			return null;
		}
		$uid = substr($principalUri, strlen($prefix));
		return $uid !== '' ? $uid : null;
	}
}
