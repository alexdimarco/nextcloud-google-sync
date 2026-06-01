<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Service;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Db\CalendarMap;
use OCA\CalendarBridge\Db\CalendarMapMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Calendar-level identity for two-way sync: the NC calendar <-> Google calendar
 * pairing. Today the inbound importer assumes "NC calendar URI = Google calendar
 * id", which holds for Google-originated calendars. An NC-originated pairing
 * links a PRE-EXISTING NC calendar (its own URI) to a freshly-created Google
 * calendar id, which this table records and the importer resolves MAP-FIRST.
 *
 * Every public method is defensive (a mapping failure must never break sync):
 * all DB work is wrapped and only logged.
 */
class CalendarMapService {

	public function __construct(
		private CalendarMapMapper $mapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The Nextcloud calendar id linked to this Google calendar id, or null when
	 * there is no NC-originated pairing (the caller then uses the legacy
	 * URI=urlencode(googleCalId) resolution). This is the importer's resolution
	 * seam. Defensive: null on any lookup failure.
	 */
	public function getNcCalIdForGoogleId(string $googleCalId): ?int {
		try {
			return $this->mapper->findByGoogleCalId($googleCalId)->getNcCalId();
		} catch (DoesNotExistException) {
			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: calendar-map lookup failed for google calendar ' . $googleCalId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return null;
		}
	}

	/**
	 * The Google calendar id linked to this NC calendar, or null. Used by the UI
	 * to show whether an NC calendar is already paired. Defensive.
	 */
	public function getGoogleCalIdForNcCalId(int $ncCalId): ?string {
		try {
			return $this->mapper->findByNcCalId($ncCalId)->getGoogleCalId();
		} catch (DoesNotExistException) {
			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: calendar-map lookup failed for NC calendar ' . $ncCalId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return null;
		}
	}

	/**
	 * Record an NC-originated pairing (the NC -> Google create flow, P-c). Idempotent:
	 * an existing row for this NC calendar is updated, otherwise inserted.
	 * Defensive: never throws.
	 *
	 * P-c TODO: this currently swallows a UNIQUE(google_cal_id) collision (the same
	 * Google calendar already bound to a DIFFERENT NC calendar) and returns void, so
	 * a caller gets no signal. When P-c wires the create flow it must either return a
	 * success signal or handle the collision intentionally (the steal-vs-reject
	 * semantics is a P-c product decision) — do NOT silently leave the old binding.
	 */
	public function recordNcOriginPairing(int $ncCalId, string $ncCalUri, string $googleCalId, int $createdAt): void {
		try {
			try {
				$row = $this->mapper->findByNcCalId($ncCalId);
				$row->setNcCalUri($ncCalUri);
				$row->setGoogleCalId($googleCalId);
				$row->setOrigin('nc');
				$this->mapper->update($row);
			} catch (DoesNotExistException) {
				$row = new CalendarMap();
				$row->setNcCalId($ncCalId);
				$row->setNcCalUri($ncCalUri);
				$row->setGoogleCalId($googleCalId);
				$row->setOrigin('nc');
				$row->setCreatedAt($createdAt);
				$this->mapper->insert($row);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: failed to record calendar pairing ' . $ncCalId . ' <-> ' . $googleCalId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
		}
	}

	/**
	 * Remove the pairing for a Google calendar id (un-sync / disconnect, P-c).
	 * Defensive: never throws.
	 */
	public function removeByGoogleCalId(string $googleCalId): void {
		try {
			$this->mapper->deleteByGoogleCalId($googleCalId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: failed to remove calendar pairing for google calendar ' . $googleCalId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
		}
	}
}
