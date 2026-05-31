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
	 * @param ?string $ncEtag The NC object etag returned by the create/update
	 *   we just performed — the NC-side echo baseline, stored on the master row.
	 */
	public function recordFromImport(int $ncCalId, array $masterEvent, array $allExceptions, bool $isFullPull, ?string $ncEtag = null): void {
		$masterId = (string)($masterEvent['id'] ?? '');
		if ($masterId === '') {
			return;
		}
		// The NC object URI the importer uses for the whole series is the
		// master Google id (see importCalendar: $objectUri = $e['id']).
		$ncUri = $masterId;

		$this->upsert($ncCalId, $ncUri, '', static function (EventMap $row) use ($masterEvent, $masterId, $ncEtag): void {
			$row->setGoogleId($masterId);
			$row->setIcalUid(isset($masterEvent['iCalUID']) ? (string)$masterEvent['iCalUID'] : null);
			$row->setOrigin('google');
			$row->setGoogleUpdated(isset($masterEvent['updated']) ? (string)$masterEvent['updated'] : null);
			// Keep the Google etag fresh on every inbound import so a later
			// outbound edit's If-Match uses the current baseline (a stale one
			// would 412 against our own prior import — a false conflict).
			$row->setBaselineEtag(isset($masterEvent['etag']) ? (string)$masterEvent['etag'] : null);
			if ($ncEtag !== null) {
				$row->setNcEtag($ncEtag);
			}
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
				$row->setBaselineEtag(isset($ex['etag']) ? (string)$ex['etag'] : null);
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
	 * Record an NC-ORIGINATED event after it was created in Google (Phase 2b
	 * outbound). origin='nc'; google_id is the id we just created; nc_etag is
	 * the NC object's current etag (the echo baseline — our write touched
	 * Google, not the NC object, so the next inbound reconcile must see this
	 * unchanged object as ECHO, not LOCAL_EDIT). Master row only.
	 *
	 * Distinct from recordFromImport, which hardcodes origin='google' and
	 * nc_uri = the Google master id — both wrong for an NC-origin row.
	 */
	public function recordLocalNew(int $ncCalId, string $ncUri, string $icalUid, ?string $ncEtag, string $googleId, ?string $googleUpdated, ?string $baselineEtag = null): void {
		$this->upsert($ncCalId, $ncUri, '', static function (EventMap $row) use ($icalUid, $ncEtag, $googleId, $googleUpdated, $baselineEtag): void {
			$row->setGoogleId($googleId);
			$row->setIcalUid($icalUid !== '' ? $icalUid : null);
			$row->setOrigin('nc');
			if ($ncEtag !== null) {
				$row->setNcEtag($ncEtag);
			}
			$row->setGoogleUpdated($googleUpdated);
			if ($baselineEtag !== null) {
				$row->setBaselineEtag($baselineEtag);
			}
			$row->setState('synced');
		});
	}

	/**
	 * Bind the Google id onto an NC-origin master row when that event echoes
	 * back inbound (carrying extendedProperties.private.ncOrigin = nc_uri). Does
	 * NOT touch nc_etag — the NC object did not change, so its echo baseline
	 * must be preserved. origin is set to 'nc' so a row created here (if the
	 * outbound record was lost to a crash) is still correctly typed.
	 */
	public function bindGoogleIdForNcUri(int $ncCalId, string $ncUri, string $googleId, ?string $googleUpdated, ?string $baselineEtag = null): void {
		$this->upsert($ncCalId, $ncUri, '', static function (EventMap $row) use ($googleId, $googleUpdated, $baselineEtag): void {
			$row->setGoogleId($googleId);
			if ($googleUpdated !== null) {
				$row->setGoogleUpdated($googleUpdated);
			}
			if ($baselineEtag !== null) {
				$row->setBaselineEtag($baselineEtag);
			}
			$row->setOrigin('nc');
			$row->setState('synced');
		});
	}

	/**
	 * The master map row for an NC object, or null if none / on error.
	 */
	public function getMasterRow(int $ncCalId, string $ncUri): ?EventMap {
		try {
			return $this->mapper->findByNcObject($ncCalId, $ncUri, '');
		} catch (DoesNotExistException) {
			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: master-row lookup failed for ' . $ncUri . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return null;
		}
	}

	/**
	 * Re-record after a successful outbound UPDATE (Phase 2c). Refreshes the
	 * Google baseline_etag + google_updated (so the next edit's If-Match is
	 * current) and nc_etag (= the NC object's current etag — our PUT touched
	 * Google, not the NC object, so the next reconcile must read it as ECHO,
	 * not a second edit). Does NOT change origin or google_id.
	 */
	public function recordOutboundUpdate(int $ncCalId, string $ncUri, ?string $ncEtag, ?string $googleUpdated, ?string $baselineEtag): void {
		$this->upsert($ncCalId, $ncUri, '', static function (EventMap $row) use ($ncEtag, $googleUpdated, $baselineEtag): void {
			if ($ncEtag !== null) {
				$row->setNcEtag($ncEtag);
			}
			if ($googleUpdated !== null) {
				$row->setGoogleUpdated($googleUpdated);
			}
			if ($baselineEtag !== null) {
				$row->setBaselineEtag($baselineEtag);
			}
			$row->setState('synced');
		});
	}

	/**
	 * The NC-ORIGIN master row whose google_id is $googleId (a series master we
	 * pushed), or null. Used by inbound to recognise an orphan exception (a
	 * Google-side single-instance edit arriving without its master).
	 */
	public function findNcOriginMasterByGoogleId(int $ncCalId, string $googleId): ?EventMap {
		try {
			$row = $this->mapper->findByGoogleId($ncCalId, $googleId);
			return ($row->getOrigin() === 'nc' && $row->getRecurrenceId() === '') ? $row : null;
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * The recurrence-instance sibling rows of one NC object (origin-agnostic),
	 * or [] on error. recurrence_id is the RAW Google originalStartTime token;
	 * canonical matching is the caller's job (via RecurrenceKey::fromGoogleToken).
	 *
	 * @return EventMap[]
	 */
	public function findSiblings(int $ncCalId, string $ncUri): array {
		try {
			return $this->mapper->findSiblingsForNcUri($ncCalId, $ncUri);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: sibling lookup failed for ' . $ncUri . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return [];
		}
	}

	/**
	 * Upsert ONE recurrence-instance sibling row after an outbound instance
	 * write. $recurrenceId is the RAW Google originalStartTime of the live
	 * instance (kept identical to the inbound recordFromImport format so a row
	 * is shared, never duplicated). state defaults to 'synced'.
	 */
	public function recordOutboundSibling(int $ncCalId, string $ncUri, string $recurrenceId, string $googleId, ?string $googleUpdated, ?string $baselineEtag, string $state = 'synced'): void {
		$this->upsert($ncCalId, $ncUri, $recurrenceId, static function (EventMap $row) use ($googleId, $googleUpdated, $baselineEtag, $state): void {
			$row->setGoogleId($googleId);
			$row->setOrigin('nc');
			if ($googleUpdated !== null) {
				$row->setGoogleUpdated($googleUpdated);
			}
			if ($baselineEtag !== null) {
				$row->setBaselineEtag($baselineEtag);
			}
			$row->setState($state);
		});
	}

	/**
	 * Mark a sibling row 'cancelled' (an occurrence the user EXDATE'd). The row
	 * is KEPT (not deleted) so a later restore can target it. No-op if absent.
	 */
	public function markSiblingCancelled(int $ncCalId, string $ncUri, string $recurrenceId): void {
		$this->upsert($ncCalId, $ncUri, $recurrenceId, static function (EventMap $row): void {
			$row->setState('cancelled');
		});
	}

	/**
	 * Record the master-row recurrence baselines used by the outbound differ's
	 * refusal guards (shape / RRULE / DTSTART as of the last successful sync).
	 * Only overwrites a baseline when a non-null value is supplied.
	 */
	public function recordSeriesBaseline(int $ncCalId, string $ncUri, ?string $shape, ?string $baselineRrule, ?string $masterDtstart): void {
		$this->upsert($ncCalId, $ncUri, '', static function (EventMap $row) use ($shape, $baselineRrule, $masterDtstart): void {
			if ($shape !== null) {
				$row->setShape($shape);
			}
			if ($baselineRrule !== null) {
				$row->setBaselineRrule($baselineRrule);
			}
			if ($masterDtstart !== null) {
				$row->setMasterDtstart($masterDtstart);
			}
		});
	}

	/**
	 * Whether an NC-ORIGIN map row exists for this Google id. Used by the
	 * inbound echo de-dup to recognize "the user deleted an event we pushed,
	 * before its echo arrived" — in which case the echo must NOT resurrect the
	 * deleted object as a new import. Defensive: false on any lookup failure.
	 */
	public function hasNcOriginRowForGoogleId(int $ncCalId, string $googleId): bool {
		try {
			return $this->mapper->findByGoogleId($ncCalId, $googleId)->getOrigin() === 'nc';
		} catch (DoesNotExistException) {
			return false;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: provenance lookup failed for google id ' . $googleId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return false;
		}
	}

	/**
	 * Annotate a master row's last_error with a verify-pass finding (or null to
	 * clear it). Pure bookkeeping — surfaces drift to an admin querying the table
	 * without changing any sync behavior. Defensive: never throws.
	 */
	public function recordLastError(int $ncCalId, string $ncUri, ?string $error): void {
		$this->upsert($ncCalId, $ncUri, '', static function (EventMap $row) use ($error): void {
			$row->setLastError($error);
		});
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
			$etag = isset($obj['etag']) ? (string)$obj['etag'] : null;
			$this->upsert($ncCalId, $uri, '', static function (EventMap $row) use ($uri, $lastmod, $etag): void {
				$row->setGoogleId($uri);
				$row->setOrigin('google');
				$row->setNcLastmodified($lastmod);
				$row->setNcEtag($etag);
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
