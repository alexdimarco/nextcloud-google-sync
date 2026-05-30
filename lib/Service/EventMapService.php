<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Service;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Db\EventMap;
use OCA\CalendarBridge\Db\EventMapMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Phase 0 of bidirectional sync. Mirrors the inbound importer's writes into
 * the calbridge_event_map table so the mapping between NC calendar objects
 * (and their recurrence instances) and Google event ids is observable and
 * provably consistent — with no outbound writes and no behavior change.
 *
 * Every public method is defensive: a mapping failure must never break the
 * one-way sync it is shadowing, so all DB work is wrapped and only logged.
 */
class EventMapService {

	public function __construct(
		private EventMapMapper $mapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The recurrence-slot token for an event: '' for a master/standalone
	 * event, otherwise the exception's originalStartTime (dateTime, else
	 * date). This is the same value space the importer emits as RECURRENCE-ID.
	 *
	 * @param array $event A Google event (possibly an exception).
	 */
	public static function recurrenceIdToken(array $event): string {
		$ost = $event['originalStartTime'] ?? null;
		if (!is_array($ost)) {
			return '';
		}
		if (isset($ost['dateTime']) && $ost['dateTime'] !== '') {
			return (string)$ost['dateTime'];
		}
		if (isset($ost['date']) && $ost['date'] !== '') {
			return (string)$ost['date'];
		}
		return '';
	}

	/**
	 * Record the master/standalone event plus every live (non-cancelled)
	 * exception that belongs to it. Called after the importer has
	 * successfully created or updated the single NC object for this series.
	 *
	 * Sibling pruning (removing instance rows for exceptions that are no
	 * longer live — cancelled, reverted, or retimed) runs ONLY on a full
	 * pull, because on an incremental pull $allExceptions is just the delta
	 * and a blanket prune would wrongly delete still-live unchanged siblings.
	 * This is safe: the importer forces a full pull on the very next tick
	 * after any cancellation (see importCalendar's forceFullNext), so the
	 * staleness window for a phantom sibling row is bounded to one tick.
	 *
	 * @param int $ncCalId The NC calendar id.
	 * @param array $masterEvent The Google master/standalone event ($e in the importer).
	 * @param array $allExceptions The importer's full $exceptions list.
	 * @param bool $isFullPull True when this tick is a full pull (not incremental).
	 */
	public function recordFromImport(int $ncCalId, array $masterEvent, array $allExceptions, bool $isFullPull): void {
		$masterId = (string)($masterEvent['id'] ?? '');
		if ($masterId === '') {
			return;
		}
		// The NC object URI the importer uses for the whole series is the
		// master Google id (see importCalendar: $objectUri = $e['id']).
		$ncUri = $masterId;

		$this->upsert($ncCalId, $ncUri, '', static function (EventMap $row) use ($masterEvent, $masterId): void {
			$row->setGoogleId($masterId);
			$row->setIcalUid(isset($masterEvent['iCalUID']) ? (string)$masterEvent['iCalUID'] : null);
			$row->setOrigin('google');
			$row->setGoogleUpdated(isset($masterEvent['updated']) ? (string)$masterEvent['updated'] : null);
			$row->setState('synced');
		});

		$liveTokens = [];
		foreach ($allExceptions as $ex) {
			if ((string)($ex['recurringEventId'] ?? '') !== $masterId) {
				continue;
			}
			// Cancelled instances become EXDATE on the master, not a writable
			// instance — do not map them as live rows.
			if (($ex['status'] ?? '') === 'cancelled') {
				continue;
			}
			$token = self::recurrenceIdToken($ex);
			$exId = (string)($ex['id'] ?? '');
			if ($token === '' || $exId === '') {
				continue;
			}
			$liveTokens[] = $token;
			$this->upsert($ncCalId, $ncUri, $token, static function (EventMap $row) use ($ex, $exId): void {
				$row->setGoogleId($exId);
				$row->setIcalUid(isset($ex['iCalUID']) ? (string)$ex['iCalUID'] : null);
				$row->setOrigin('google');
				$row->setGoogleUpdated(isset($ex['updated']) ? (string)$ex['updated'] : null);
				$row->setState('synced');
			});
		}

		// Reap sibling rows for exceptions that are no longer live. Full-pull
		// only (see method docblock); defensive — failure only logs.
		if ($isFullPull) {
			try {
				$this->mapper->deleteSiblingsNotIn($ncCalId, $ncUri, $liveTokens);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Calendar Bridge: failed to prune stale event map siblings for ' . $ncUri . ': ' . $e->getMessage(),
					['app' => Application::APP_ID],
				);
			}
		}
	}

	/**
	 * Remove every mapping row (master + recurrence siblings) for one NC
	 * calendar object. Called when the importer deletes that object.
	 */
	public function removeForNcUri(int $ncCalId, string $ncUri): void {
		try {
			$this->mapper->deleteForNcUri($ncCalId, $ncUri);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: failed to remove event map rows for ' . $ncUri . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
		}
	}

	/**
	 * One-time lazy backfill: if a calendar has no mapping rows yet, seed a
	 * master row for each existing imported NC object so the steady-state
	 * sync (which skips unchanged events and would never record them) starts
	 * from a complete master-level baseline. Recurrence-instance rows are
	 * filled in by recordFromImport on subsequent syncs, which have the real
	 * per-exception Google ids (those are not recoverable from NC data alone).
	 *
	 * @param int $ncCalId The NC calendar id.
	 * @param iterable $ncObjects The CalDavBackend::getCalendarObjects() rows (uri + lastmodified).
	 */
	public function seedFromExistingIfEmpty(int $ncCalId, iterable $ncObjects): void {
		try {
			if ($this->mapper->countForCalendar($ncCalId) > 0) {
				return;
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: failed to count event map rows for calendar ' . $ncCalId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return;
		}

		$seeded = 0;
		foreach ($ncObjects as $obj) {
			$uri = (string)($obj['uri'] ?? '');
			if ($uri === '') {
				continue;
			}
			$lastmod = isset($obj['lastmodified']) ? (int)$obj['lastmodified'] : null;
			$this->upsert($ncCalId, $uri, '', static function (EventMap $row) use ($uri, $lastmod): void {
				$row->setGoogleId($uri);
				$row->setOrigin('google');
				$row->setNcLastmodified($lastmod);
				$row->setState('synced');
			});
			$seeded++;
		}
		if ($seeded > 0) {
			$this->logger->info(
				'Calendar Bridge: seeded ' . $seeded . ' event map master rows for calendar ' . $ncCalId,
				['app' => Application::APP_ID],
			);
		}
	}

	/**
	 * Find-or-create the row for (ncCalId, ncUri, recurrenceId), apply the
	 * mutator, and persist. Defensive: any failure is logged, never thrown.
	 *
	 * @param callable(EventMap):void $apply
	 */
	private function upsert(int $ncCalId, string $ncUri, string $recurrenceId, callable $apply): void {
		try {
			try {
				$row = $this->mapper->findByNcObject($ncCalId, $ncUri, $recurrenceId);
				$apply($row);
				$this->mapper->update($row);
			} catch (DoesNotExistException) {
				$row = new EventMap();
				$row->setNcCalId($ncCalId);
				$row->setNcUri($ncUri);
				$row->setRecurrenceId($recurrenceId);
				$apply($row);
				$this->mapper->insert($row);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: event map upsert failed for ' . $ncUri . '/' . $recurrenceId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
		}
	}
}
