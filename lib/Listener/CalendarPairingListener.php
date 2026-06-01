<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Listener;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Service\CalendarMapService;
use OCA\CalendarBridge\Service\EventMapService;
use OCA\CalendarBridge\Service\GoogleCalendarAPIService;
use OCA\CalendarBridge\Service\OutboundReconcileService;
use OCA\DAV\Events\CalendarDeletedEvent;
use OCA\DAV\Events\CalendarMovedToTrashEvent;
use OCA\DAV\Events\CalendarRestoredEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @template-implements IEventListener<Event>
 *
 * Keeps a calendar-level NC<->Google pairing in step with the lifecycle of its
 * Nextcloud calendar when that calendar is acted on out-of-band (Calendar app /
 * occ / account removal), for three events:
 *
 *  - CalendarDeletedEvent (permanent purge / force-delete): UNLINK — drop the
 *    map row, unregister the import job, clear two-way. The remote Google
 *    calendar is deliberately KEPT (an NC-side deletion must never silently
 *    destroy the user's Google copy).
 *  - CalendarMovedToTrashEvent (the normal soft-delete): PAUSE — unregister the
 *    import job + clear two-way, but KEEP the map row. Otherwise the job would
 *    keep ticking for the whole trash-retention window (~30 days), importing
 *    into an invisible calendar. The map row is the link; keeping it lets a
 *    restore resume cleanly.
 *  - CalendarRestoredEvent (un-trash): RESUME — re-register the import job +
 *    re-enable two-way. The map rows survive trashing, so the re-baseline sees
 *    every event as an ECHO (already paired) rather than re-pushing them.
 *
 * Fully defensive: these run inside CalDAV transactions, so a throw here must
 * never break the delete/trash/restore.
 */
class CalendarPairingListener implements IEventListener {

	public function __construct(
		private CalendarMapService $calendarMapService,
		private GoogleCalendarAPIService $googleCalendarAPIService,
		private OutboundReconcileService $outboundReconcileService,
		private EventMapService $eventMapService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof CalendarDeletedEvent)
			&& !($event instanceof CalendarMovedToTrashEvent)
			&& !($event instanceof CalendarRestoredEvent)) {
			return;
		}
		try {
			$data = $event->getCalendarData();
			$userId = self::userIdFromPrincipal(isset($data['principaluri']) ? (string)$data['principaluri'] : null);
			if ($userId === null) {
				return; // not a user calendar (group / resource / room principal)
			}
			$ncCalId = $event->getCalendarId();
			// The Google id (job + two-way state are keyed by it) AND the link
			// itself live in the map row. A null means this is not a linked
			// NC-origin calendar — nothing to do for any of the three events.
			$googleCalId = $this->calendarMapService->getGoogleCalIdForNcCalId($ncCalId);
			if ($googleCalId === null) {
				return;
			}

			if ($event instanceof CalendarRestoredEvent) {
				$this->resume($userId, $googleCalId, $data);
				return;
			}

			// Delete + Trash both stop the import job and pause outbound; only a
			// permanent delete also drops the map row (severing the link).
			// On TRASH we preserve the change token (a pause, not a rebaseline) so a
			// LOCAL_DELETE queued before trashing still flushes on restore; on a
			// permanent delete the link is gone so the token is reset.
			$isDelete = $event instanceof CalendarDeletedEvent;
			$this->googleCalendarAPIService->unregisterSyncCalendar($userId, $googleCalId);
			$this->outboundReconcileService->setTwoWayEnabled($userId, $googleCalId, false, $isDelete);

			if ($isDelete) {
				$this->calendarMapService->removeByNcCalId($ncCalId);
				$this->eventMapService->removeForCalendar($ncCalId);
				$this->logger->info(
					'Calendar Bridge: NC calendar ' . $ncCalId . ' deleted; unlinked its Google pairing ' . $googleCalId
						. ' (Google calendar kept)',
					['app' => Application::APP_ID],
				);
			} else {
				$this->logger->info(
					'Calendar Bridge: NC calendar ' . $ncCalId . ' trashed; paused its Google pairing ' . $googleCalId
						. ' (link kept for restore)',
					['app' => Application::APP_ID],
				);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: calendar-pairing lifecycle cleanup failed: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
		}
	}

	/**
	 * Re-arm a paused pairing after the NC calendar is restored from trash.
	 *
	 * @param array<string,mixed> $data the restored calendar's properties
	 */
	private function resume(string $userId, string $googleCalId, array $data): void {
		$name = isset($data['{DAV:}displayname']) ? (string)$data['{DAV:}displayname'] : $googleCalId;
		$color = isset($data['{http://apple.com/ns/ical/}calendar-color'])
			? (string)$data['{http://apple.com/ns/ical/}calendar-color']
			: null;
		$this->googleCalendarAPIService->registerSyncCalendar($userId, $googleCalId, $name, $color);
		// Preserve the change token (resetToken=false): the pairing was only paused
		// by the trash, so resume from the same baseline and let any delete queued
		// before trashing flush, instead of rebaselining and dropping it.
		$this->outboundReconcileService->setTwoWayEnabled($userId, $googleCalId, true, false);
		$this->logger->info(
			'Calendar Bridge: NC calendar restored; resumed its Google pairing ' . $googleCalId,
			['app' => Application::APP_ID],
		);
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
