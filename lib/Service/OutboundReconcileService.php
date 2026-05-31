<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Service;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Db\EventMapMapper;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Outbound reconciler. Detects local Nextcloud changes for a calendar the user
 * has opted into two-way sync and classifies each against the event map. As of
 * Phase 2b a LOCAL_NEW change is CREATED in Google (when the write scope is
 * granted); all other classifications remain log-only until later phases.
 *
 * The hard problem it validates is the NC-side echo: the inbound importer's own
 * createCalendarObject/updateCalendarObject/deleteCalendarObject calls bump the
 * NC sync token and appear in getChangesForCalendar exactly like user edits,
 * and Nextcloud records no provenance. The discriminator is the event map's
 * nc_etag baseline (the etag at the moment WE last wrote the object):
 *   - added/modified, no map row            -> LOCAL_NEW   (user created it)
 *   - added/modified, current etag == map   -> ECHO        (our inbound write)
 *   - added/modified, current etag != map   -> LOCAL_EDIT  (user edited it)
 *   - deleted, map row still present         -> LOCAL_DELETE (user deleted it)
 *   - deleted, map row already gone          -> ECHO_DELETE (our inbound delete,
 *                                                which already removed the row)
 */
class OutboundReconcileService {

	public const ECHO = 'echo';
	public const LOCAL_NEW = 'local_new';
	public const LOCAL_EDIT = 'local_edit';
	public const LOCAL_EDIT_INDETERMINATE = 'local_edit_indeterminate';
	public const LOCAL_DELETE = 'local_delete';
	public const ECHO_DELETE = 'echo_delete';

	public function __construct(
		private CalDavBackend $caldavBackend,
		private EventMapMapper $mapper,
		private IConfig $config,
		private LoggerInterface $logger,
		private OutboundWriteService $writeService,
	) {
	}

	public function isTwoWayEnabled(string $userId, string $calId): bool {
		return $this->config->getUserValue($userId, Application::APP_ID, $this->twoWayKey($calId), '0') === '1';
	}

	/**
	 * Turn two-way (outbound) sync on/off for one calendar. Enabling resets the
	 * NC change-token baseline so the reconciler re-baselines on its next run
	 * (it must not replay the whole calendar as outbound). Disabling clears both
	 * the flag and the token.
	 *
	 * Disabling intentionally RETAINS the nc-origin event-map rows from prior
	 * outbound writes. This is safe in the current phase: the only writing
	 * classification is LOCAL_NEW, which requires the absence of a map row, so
	 * surviving rows only steer ECHO/LOCAL_EDIT (log-only), and re-enable resets
	 * the token so nothing replays. A future phase that activates LOCAL_EDIT/
	 * LOCAL_DELETE writes must revisit pruning/re-baselining these rows.
	 */
	public function setTwoWayEnabled(string $userId, string $calId, bool $enabled): void {
		if ($enabled) {
			$this->config->setUserValue($userId, Application::APP_ID, $this->twoWayKey($calId), '1');
		} else {
			$this->config->deleteUserValue($userId, Application::APP_ID, $this->twoWayKey($calId));
		}
		// Force a fresh baseline next reconcile either way.
		$this->config->deleteUserValue($userId, Application::APP_ID, $this->changeTokenKey($calId));
	}

	/**
	 * Whether the user granted the read-write calendar.events scope. Gates all
	 * outbound writes (the widen-and-gate model: existing read-only users have
	 * no can_write_calendar key, so writes stay off until they reconnect).
	 */
	public function hasWriteScope(string $userId): bool {
		$scopes = json_decode($this->config->getUserValue($userId, Application::APP_ID, 'user_scopes', '{}'), true);
		return is_array($scopes) && ($scopes['can_write_calendar'] ?? 0) === 1;
	}

	private function twoWayKey(string $calId): string {
		return 'two_way_' . md5($calId);
	}

	private function changeTokenKey(string $calId): string {
		return 'nc_change_token_' . md5($calId);
	}

	/**
	 * Classify one local change against the map baseline. Pure: no I/O.
	 *
	 * @param string $changeType 'added' | 'modified' | 'deleted'
	 * @param bool $mapRowExists Whether a master map row exists for the object.
	 * @param ?string $mapNcEtag The map's recorded nc_etag (our last write), or null.
	 * @param ?string $currentEtag The object's current etag (null for deletes).
	 */
	public static function classifyChange(string $changeType, bool $mapRowExists, ?string $mapNcEtag, ?string $currentEtag): string {
		if ($changeType === 'deleted') {
			// Our own inbound delete also removed the map row (removeForNcUri),
			// so a missing row means this delete is our echo.
			return $mapRowExists ? self::LOCAL_DELETE : self::ECHO_DELETE;
		}
		if (!$mapRowExists) {
			return self::LOCAL_NEW;
		}
		if ($mapNcEtag === null) {
			// We have no baseline to compare against (e.g. a seeded row never
			// re-written). Cannot decide echo vs edit safely.
			return self::LOCAL_EDIT_INDETERMINATE;
		}
		if ($currentEtag !== null && $currentEtag === $mapNcEtag) {
			return self::ECHO;
		}
		return self::LOCAL_EDIT;
	}

	/**
	 * Whether the stored change token is no longer usable and the reconciler
	 * must re-baseline. True when getChangesForCalendar returned null (the
	 * token expired — NC purged oc_calendarchanges — or is unknown), or when
	 * the returned head token is LOWER than the stored one (the calendar was
	 * deleted and re-imported under a fresh, lower synctoken sequence). Pure.
	 *
	 * @param ?array $changes The getChangesForCalendar result, or null.
	 * @param string $storedToken The previously stored token.
	 */
	public static function needsRebaseline(?array $changes, string $storedToken): bool {
		if ($changes === null) {
			return true;
		}
		$head = isset($changes['syncToken']) ? (int)$changes['syncToken'] : -1;
		return $head < (int)$storedToken;
	}

	/**
	 * Reconcile local changes for one calendar (opt-in). Phase 2b: a LOCAL_NEW
	 * change is CREATED in Google when the user also granted the write scope;
	 * every other classification (and LOCAL_NEW without the write scope) stays
	 * log-only. Fully defensive — runs after a committed inbound import, so a
	 * throw here must never fail the import.
	 */
	public function reconcile(string $userId, string $calId, int $ncCalId): void {
		// The opt-in gate is INSIDE the try: isTwoWayEnabled() is a DB read
		// that can throw, and this method runs after a fully-committed inbound
		// import — a throw here must never fail the import (defensive contract).
		try {
			if (!$this->isTwoWayEnabled($userId, $calId)) {
				return;
			}
			$canWrite = $this->hasWriteScope($userId);
			$tokenKey = $this->changeTokenKey($calId);
			$stored = $this->config->getUserValue($userId, Application::APP_ID, $tokenKey, '');

			if ($stored === '') {
				// First run: baseline at the current token without classifying
				// the initial full set (all already-imported events).
				$changes = $this->caldavBackend->getChangesForCalendar($ncCalId, '', 1);
				$token = (string)(($changes['syncToken'] ?? '') ?: '');
				$this->config->setUserValue($userId, Application::APP_ID, $tokenKey, $token);
				$this->logger->info(
					'Calendar Bridge reconcile: baselined calendar ' . $ncCalId . ' at token ' . $token,
					['app' => Application::APP_ID],
				);
				return;
			}

			$changes = $this->caldavBackend->getChangesForCalendar($ncCalId, $stored, 1);

			// getChangesForCalendar returns null when the stored token is
			// expired (NC purged oc_calendarchanges) or unknown; it also yields
			// a head token lower than the stored one if the calendar was deleted
			// and re-imported under a fresh sequence. In both cases the delta is
			// meaningless — re-baseline at the current head so detection resumes
			// rather than re-persisting a dead token forever. The gap's changes
			// are unrecoverable (NC already dropped the records). Config-only.
			if (self::needsRebaseline($changes, $stored)) {
				$fresh = $this->caldavBackend->getChangesForCalendar($ncCalId, '', 1);
				$token = (string)(($fresh['syncToken'] ?? '') ?: '');
				$this->config->setUserValue($userId, Application::APP_ID, $tokenKey, $token);
				$this->logger->warning(
					'Calendar Bridge reconcile: change token expired/stale for calendar ' . $ncCalId
						. ', re-baselined at ' . $token . '; local changes in the gap were not classified',
					['app' => Application::APP_ID],
				);
				return;
			}

			// Only advance the change token if every write in this delta reached
			// a terminal state. A transient failure (ERROR/CONFLICT) leaves the
			// NC object unmodified, so advancing past it would drop it from all
			// future deltas and silently never sync it. Holding the token re-
			// processes the whole delta next tick — safe, because an
			// already-succeeded create re-POSTs under its deterministic id and
			// hits 409 -> DUPLICATE_ADOPTED (no duplicate).
			$advance = true;
			$counts = [];
			foreach (['added', 'modified'] as $type) {
				foreach (($changes[$type] ?? []) as $uri) {
					$uri = (string)$uri;
					$cls = $this->classifyOne($ncCalId, $type, $uri);
					$counts[$cls] = ($counts[$cls] ?? 0) + 1;
					if ($cls === self::LOCAL_NEW && $canWrite) {
						// Phase 2b: create a Nextcloud-originated event in Google.
						$status = $this->writeService->createLocalEventInGoogle($userId, $calId, $ncCalId, $uri);
						if ($status === OutboundWriteService::ERROR || $status === OutboundWriteService::CONFLICT) {
							$advance = false;
						}
						$this->logger->info(
							'Calendar Bridge: outbound create ' . $uri . ' on calendar ' . $ncCalId . ' -> ' . $status,
							['app' => Application::APP_ID],
						);
					} elseif ($cls === self::LOCAL_EDIT && $canWrite) {
						// Phase 2c: push a Nextcloud-side edit to Google. Resolves
						// to a terminal status (UPDATED/SKIPPED_*) or holds the
						// token (ERROR/CONFLICT) for a retry; a missing baseline
						// self-heals via the live-event LWW path, never a silent drop.
						$status = $this->writeService->updateLocalEventInGoogle($userId, $calId, $ncCalId, $uri);
						if ($status === OutboundWriteService::ERROR || $status === OutboundWriteService::CONFLICT) {
							$advance = false;
						}
						$this->logger->info(
							'Calendar Bridge: outbound update ' . $uri . ' on calendar ' . $ncCalId . ' -> ' . $status,
							['app' => Application::APP_ID],
						);
					} elseif ($cls !== self::ECHO) {
						$this->logger->info(
							'Calendar Bridge: would handle ' . $cls . ' (' . $type . ') ' . $uri . ' on calendar ' . $ncCalId
								. ($cls === self::LOCAL_NEW ? ' (write scope not granted)' : ''),
							['app' => Application::APP_ID],
						);
					}
				}
			}
			foreach (($changes['deleted'] ?? []) as $uri) {
				$uri = (string)$uri;
				$cls = $this->classifyOne($ncCalId, 'deleted', $uri);
				$counts[$cls] = ($counts[$cls] ?? 0) + 1;
				if ($cls === self::LOCAL_DELETE && $canWrite) {
					// Phase 2c-ii: delete the mapped Google event for a deleted NC
					// object. NC-delete-wins on a 412 conflict (see write service).
					$status = $this->writeService->deleteLocalEventInGoogle($userId, $calId, $ncCalId, $uri);
					if ($status === OutboundWriteService::ERROR || $status === OutboundWriteService::CONFLICT) {
						$advance = false;
					}
					$this->logger->info(
						'Calendar Bridge: outbound delete ' . $uri . ' on calendar ' . $ncCalId . ' -> ' . $status,
						['app' => Application::APP_ID],
					);
				} elseif ($cls !== self::ECHO_DELETE) {
					$this->logger->info(
						'Calendar Bridge: would handle ' . $cls . ' ' . $uri . ' on calendar ' . $ncCalId
							. ($cls === self::LOCAL_DELETE ? ' (write scope not granted)' : ''),
						['app' => Application::APP_ID],
					);
				}
			}

			if ($advance) {
				$this->config->setUserValue(
					$userId, Application::APP_ID, $tokenKey,
					(string)($changes['syncToken'] ?? $stored),
				);
			} else {
				$this->logger->info(
					'Calendar Bridge reconcile calendar ' . $ncCalId
						. ': holding change token (a write did not reach a terminal state; will retry next tick)',
					['app' => Application::APP_ID],
				);
			}

			if (count($counts) > 0) {
				$summary = [];
				foreach ($counts as $k => $v) {
					$summary[] = $k . '=' . $v;
				}
				$this->logger->info(
					'Calendar Bridge reconcile calendar ' . $ncCalId . ': ' . implode(', ', $summary),
					['app' => Application::APP_ID],
				);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge reconcile failed for calendar ' . $ncCalId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
		}
	}

	private function classifyOne(int $ncCalId, string $type, string $uri): string {
		$mapRowExists = false;
		$mapNcEtag = null;
		try {
			$row = $this->mapper->findByNcObject($ncCalId, $uri, '');
			$mapRowExists = true;
			$mapNcEtag = $row->getNcEtag();
		} catch (DoesNotExistException) {
			$mapRowExists = false;
		}

		$currentEtag = null;
		if ($type !== 'deleted') {
			$obj = $this->caldavBackend->getCalendarObject($ncCalId, $uri);
			if (is_array($obj) && isset($obj['etag'])) {
				$currentEtag = (string)$obj['etag'];
			}
		}

		return self::classifyChange($type, $mapRowExists, $mapNcEtag, $currentEtag);
	}
}
