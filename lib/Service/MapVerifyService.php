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
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Operational hardening: a periodic "trust-but-verify" reconcile of the event
 * map against ground truth.
 *
 * THE PROBLEM. The bidirectional sync trusts its OWN recorded history: the
 * nc_etag baseline decides ECHO vs LOCAL_EDIT, and the baseline_etag decides
 * pure-echo vs Google-side-change. The one-way importer self-heals every full
 * pull (it re-derives everything from Google), but the bidirectional map has no
 * equivalent re-derivation — so a single wrong/stale row can mis-classify a real
 * edit forever, and the error compounds. This pass is that missing re-derivation.
 *
 * THE POSTURE — SAFE BY DEFAULT. It is READ + MAP-ONLY. It NEVER calls
 * events.insert/patch/delete and NEVER touches a CalDAV object; it only reads
 * both live sides (Google events.list + NC objects), then mutates the bookkeeping
 * table or a config pref. Of the divergences it finds it REPAIRS only the two
 * that cannot lose data — dropping a row whose NC object AND Google event are
 * both gone, and rebinding a dangling google_id to the one live Google event that
 * still carries our ncOrigin tag — and merely LOGS everything else (including a
 * stale baseline_etag, which is non-corrupting: it self-heals via the next
 * outbound edit's If-Match re-GET, and silently re-baselining it here could mask
 * an unapplied Google-side change).
 *
 * CADENCE. Gated to once per {@see VERIFY_INTERVAL} per calendar via a
 * last_verify_<md5(calId)> pref, and only for calendars with two-way enabled. It
 * piggybacks the inbound import tick (called right after the outbound reconcile),
 * runs under the same per-calendar flock, and is fully defensive — a throw can
 * never fail the import.
 */
class MapVerifyService {

	/** Re-verify a calendar at most once per this many seconds (6h). */
	private const VERIFY_INTERVAL = 21600;

	/**
	 * After a verify that could NOT complete (e.g. a transient Google outage),
	 * retry after this long instead of the full interval — so one blip can't
	 * silence re-derivation for 6h, but a sustained outage still can't hot-loop
	 * the expensive list on every tick (30 min).
	 */
	private const RETRY_BACKOFF = 1800;

	/** Hard cap on events.list pages (a runaway-pagination backstop). */
	private const MAX_PAGES = 50;

	// --- Drift verdicts (classifyRowDrift). REPAIR_* are provably loss-proof; ---
	// --- every LOG_* is surfaced but never auto-fixed. ---
	public const OK = 'ok';
	public const REPAIR_DROP_ORPHAN = 'repair_drop_orphan';
	public const REPAIR_REBIND_GID = 'repair_rebind_gid';
	public const LOG_INDETERMINATE = 'log_indeterminate';
	public const LOG_FOREIGN_TAG = 'log_foreign_tag';
	public const LOG_STALE_BASELINE = 'log_stale_baseline';
	public const LOG_AMBIGUOUS_NC_ONLY = 'log_ambiguous_nc_only';
	public const LOG_AMBIGUOUS_GOOGLE_ONLY = 'log_ambiguous_google_only';
	public const LOG_REBIND_AMBIGUOUS = 'log_rebind_ambiguous';

	public function __construct(
		private CalDavBackend $caldavBackend,
		private EventMapMapper $mapper,
		private EventMapService $eventMapService,
		private GoogleAPIService $googleApiService,
		private IConfig $config,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether enough time has elapsed to re-verify. Pure (for the unit harness).
	 * lastRun=0 means "never run" -> always due.
	 */
	public static function shouldVerify(int $lastRun, int $now, int $interval): bool {
		return ($now - $lastRun) >= $interval;
	}

	/**
	 * Cadence-gated entry point, called from the importer after reconcile().
	 * Defensive: never throws (a failure must not fail the import). Advances the
	 * cadence pref whenever it actually attempts a run, so a transient failure
	 * cannot hot-loop the Google list on every tick.
	 */
	public function verify(string $userId, string $calId, int $ncCalId, bool $twoWayEnabled): void {
		try {
			if (!$twoWayEnabled) {
				return; // map drift only matters where outbound writes trust the baselines
			}
			$now = $this->timeFactory->getTime();
			$key = 'last_verify_' . md5($calId);
			$last = (int)$this->config->getUserValue($userId, Application::APP_ID, $key, '0');
			if (!self::shouldVerify($last, $now, self::VERIFY_INTERVAL)) {
				return;
			}
			// NOTE: this runs under the importer's per-calendar flock and makes a
			// full (non-incremental) events.list, so once per interval it extends
			// the lock hold for that one calendar. Accepted: bounded, ~once per 6h,
			// and contending ticks fail-fast and retry on the next cron run.
			$completed = false;
			try {
				$completed = $this->runVerify($userId, $calId, $ncCalId);
			} finally {
				// A COMPLETED pass holds the full interval. A pass that could not
				// list Google (transient outage) retries after RETRY_BACKOFF — so a
				// blip can't silence re-derivation for 6h, but a sustained outage
				// still can't hot-loop the expensive list every tick.
				$next = $completed ? $now : ($now - self::VERIFY_INTERVAL + self::RETRY_BACKOFF);
				$this->config->setUserValue($userId, Application::APP_ID, $key, (string)$next);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge verify: threw for calendar ' . $calId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
		}
	}

	/**
	 * One verify pass: gather both live sides, classify every MASTER map row,
	 * apply the safe repairs, log the rest. Sibling (per-instance) rows are out
	 * of scope — the recurrence differ already re-resolves them against the live
	 * instances on every series edit.
	 */
	private function runVerify(string $userId, string $calId, int $ncCalId): bool {
		$google = $this->listGoogleEvents($userId, $calId);
		if (!$google['ok']) {
			$this->logger->warning(
				'Calendar Bridge verify: skipped calendar ' . $ncCalId . ' (could not list Google events completely)',
				['app' => Application::APP_ID],
			);
			return false;
		}
		$ncEtags = $this->liveNcEtags($ncCalId);
		$rows = $this->mapper->findForCalendar($ncCalId);

		$stats = [];
		foreach ($rows as $row) {
			if ($row->getRecurrenceId() !== '') {
				continue; // master rows only
			}
			$verdict = self::classifyRowDrift(
				[
					'ncUri' => $row->getNcUri(),
					'googleId' => $row->getGoogleId(),
					'origin' => $row->getOrigin(),
					'ncEtag' => $row->getNcEtag(),
					'baselineEtag' => $row->getBaselineEtag(),
				],
				$google['byId'],
				$ncEtags,
				$google['byNcOrigin'],
			);
			$stats[$verdict] = ($stats[$verdict] ?? 0) + 1;
			$this->applyVerdict($verdict, $ncCalId, $row, $google['byNcOrigin']);
		}

		$this->logger->info(
			'Calendar Bridge verify: calendar ' . $ncCalId . ' checked ' . array_sum($stats)
				. ' master rows: ' . json_encode($stats),
			['app' => Application::APP_ID],
		);
		return true;
	}

	/**
	 * Classify ONE master map row against ground truth. PURE — no I/O — so the
	 * whole decision table is unit-testable. Returns one of the OK / REPAIR / LOG
	 * verdicts; only REPAIR_DROP_ORPHAN and REPAIR_REBIND_GID ever mutate anything,
	 * and both are provably incapable of losing user data.
	 *
	 * @param array{ncUri:string, googleId:?string, origin:string, ncEtag:?string, baselineEtag:?string} $row
	 * @param array<string, array{etag:?string, updated:?string, ncOrigin:?string}> $googleById live Google events keyed by id
	 * @param array<string, ?string> $ncEtags live NC objects: uri => etag
	 * @param array<string, list<string>> $googleByNcOrigin live Google ids carrying each ncOrigin tag
	 */
	public static function classifyRowDrift(array $row, array $googleById, array $ncEtags, array $googleByNcOrigin): string {
		$ncUri = $row['ncUri'];
		$gid = $row['googleId'];
		$origin = $row['origin'];
		$ncEtag = $row['ncEtag'];
		$baseline = $row['baselineEtag'];

		$gExists = ($gid !== null && $gid !== '' && isset($googleById[$gid]));
		$ncExists = array_key_exists($ncUri, $ncEtags);

		if ($gExists) {
			$live = $googleById[$gid];
			// An nc-origin event we pushed must still carry our ncOrigin tag. A
			// present-but-different tag means it was stripped/repointed on Google
			// -> we may no longer own it. LOG, never touch. (A missing tag is not
			// flagged: not every code path guarantees the projection captured it.)
			if ($origin === 'nc') {
				$tag = $live['ncOrigin'] ?? null;
				if ($tag !== null && $tag !== $ncUri) {
					return self::LOG_FOREIGN_TAG;
				}
			}
			if (!$ncExists) {
				// Google event present, but no NC object at this uri: a pending
				// delete, a lost inbound write, or drift. Ambiguous -> LOG.
				return self::LOG_AMBIGUOUS_GOOGLE_ONLY;
			}
			if ($ncEtag === null) {
				// Unbaselined master: the outbound classifier can never decide
				// ECHO vs LOCAL_EDIT, so it will NEVER push edits for this object.
				return self::LOG_INDETERMINATE;
			}
			$liveEtag = $live['etag'] ?? null;
			if ($liveEtag !== null && $baseline !== null && $liveEtag !== $baseline) {
				// Google's copy moved since our baseline: an unapplied Google-side
				// change. NON-corrupting (self-heals on the next outbound If-Match
				// re-GET) and silently re-baselining could MASK it, so LOG only.
				return self::LOG_STALE_BASELINE;
			}
			return self::OK;
		}

		// google_id no longer resolves to a live event.
		if ($origin === 'nc') {
			$cands = [];
			foreach ($googleByNcOrigin[$ncUri] ?? [] as $c) {
				if ($c !== $gid) {
					$cands[$c] = true;
				}
			}
			$cands = array_keys($cands);
			if (count($cands) === 1) {
				// Exactly one live Google event carries OUR tag for this uri: it is
				// provably the event we pushed; rebind the dangling pointer to it.
				return self::REPAIR_REBIND_GID;
			}
			if (count($cands) > 1) {
				return self::LOG_REBIND_AMBIGUOUS; // can't guess which is ours
			}
		}
		if (!$ncExists) {
			// Neither side exists: dead bookkeeping mapping nothing -> safe to drop.
			// (Includes a SOFT-deleted/trashbin NC object: the Google event is
			// provably gone here, so a later restore correctly re-classifies as
			// LOCAL_NEW and re-syncs — dropping the dead row is right, and keeping
			// it would instead risk a restore reading as ECHO against a dead id.)
			return self::REPAIR_DROP_ORPHAN;
		}
		// NC object present, Google event gone, no tagged candidate: a lost push,
		// a Google-side delete, or drift. Ambiguous -> LOG, never delete the row
		// (which could trigger a duplicate re-create) and never resurrect.
		return self::LOG_AMBIGUOUS_NC_ONLY;
	}

	/**
	 * Carry out a verdict. Only the two REPAIR_* verdicts mutate state (map-only,
	 * via the defensive EventMapService); every LOG_* records the divergence on
	 * the row's last_error and warns. An OK row clears any prior last_error.
	 *
	 * @param array<string, list<string>> $googleByNcOrigin
	 */
	private function applyVerdict(string $verdict, int $ncCalId, EventMap $row, array $googleByNcOrigin): void {
		$ncUri = $row->getNcUri();
		switch ($verdict) {
			case self::OK:
				if ($row->getLastError() !== null) {
					$this->eventMapService->recordLastError($ncCalId, $ncUri, null);
				}
				return;
			case self::REPAIR_DROP_ORPHAN:
				$this->logger->info(
					'Calendar Bridge verify: dropping orphan map row ' . $ncUri . ' (no NC object, no Google event)',
					['app' => Application::APP_ID],
				);
				$this->eventMapService->removeForNcUri($ncCalId, $ncUri);
				return;
			case self::REPAIR_REBIND_GID:
				$cands = [];
				foreach ($googleByNcOrigin[$ncUri] ?? [] as $c) {
					if ($c !== $row->getGoogleId()) {
						$cands[$c] = true;
					}
				}
				$cands = array_keys($cands);
				if (count($cands) !== 1) {
					return; // raced since classification; be conservative
				}
				$newGid = $cands[0];
				$this->logger->info(
					'Calendar Bridge verify: rebinding ' . $ncUri . ' google_id ' . (string)$row->getGoogleId()
						. ' -> ' . $newGid . ' (matched by ncOrigin tag)',
					['app' => Application::APP_ID],
				);
				// Rebind the id only; leave baseline_etag so the next outbound edit
				// re-GETs and re-baselines via LWW (never blind-trust the new etag).
				$this->eventMapService->bindGoogleIdForNcUri($ncCalId, $ncUri, $newGid, null, null);
				$this->eventMapService->recordLastError($ncCalId, $ncUri, null);
				return;
			default:
				$this->logger->warning(
					'Calendar Bridge verify: ' . $verdict . ' for ' . $ncUri . ' on calendar ' . $ncCalId
						. ' (origin=' . $row->getOrigin() . ', google_id=' . (string)$row->getGoogleId() . ')',
					['app' => Application::APP_ID],
				);
				if ($row->getLastError() !== $verdict) {
					$this->eventMapService->recordLastError($ncCalId, $ncUri, $verdict);
				}
				return;
		}
	}

	/**
	 * Live NC objects for the calendar: uri => etag. The same authoritative
	 * source seedFromExistingIfEmpty trusts.
	 *
	 * @return array<string, ?string>
	 */
	private function liveNcEtags(int $ncCalId): array {
		$out = [];
		foreach ($this->caldavBackend->getCalendarObjects($ncCalId) as $obj) {
			$uri = (string)($obj['uri'] ?? '');
			if ($uri === '') {
				continue;
			}
			$out[$uri] = isset($obj['etag']) ? (string)$obj['etag'] : null;
		}
		return $out;
	}

	/**
	 * List ALL live Google events for the calendar (paged, masters + exceptions),
	 * projected to the ground truth the classifier needs. ok=false on any API
	 * error (the caller then skips this pass rather than acting on partial data).
	 *
	 * @return array{ok: bool, byId: array<string, array{etag:?string, updated:?string, ncOrigin:?string}>, byNcOrigin: array<string, list<string>>}
	 */
	private function listGoogleEvents(string $userId, string $calId): array {
		$byId = [];
		$byNcOrigin = [];
		/** @var string $pageToken — psalm narrows the '' literal otherwise (NoValue) */
		$pageToken = '';
		$pages = 0;
		do {
			$endpoint = 'calendar/v3/calendars/' . urlencode($calId)
				. '/events?maxResults=2500&singleEvents=false&showDeleted=false';
			if ($pageToken !== '') {
				$endpoint .= '&pageToken=' . urlencode($pageToken);
			}
			$result = $this->googleApiService->request($userId, $endpoint);
			if (isset($result['error'])) {
				return ['ok' => false, 'byId' => [], 'byNcOrigin' => []];
			}
			foreach (($result['items'] ?? []) as $item) {
				$id = (string)($item['id'] ?? '');
				if ($id === '') {
					continue;
				}
				$ncOrigin = $item['extendedProperties']['private']['ncOrigin'] ?? null;
				$byId[$id] = [
					'etag' => isset($item['etag']) ? (string)$item['etag'] : null,
					'updated' => isset($item['updated']) ? (string)$item['updated'] : null,
					'ncOrigin' => is_string($ncOrigin) && $ncOrigin !== '' ? $ncOrigin : null,
				];
				if (is_string($ncOrigin) && $ncOrigin !== '') {
					$byNcOrigin[$ncOrigin][] = $id;
				}
			}
			$pageToken = (string)($result['nextPageToken'] ?? '');
			$pages++;
		} while ($pageToken !== '' && $pages < self::MAX_PAGES);

		if ($pageToken !== '') {
			// Hit the page cap with pages still remaining — a TRUNCATED list.
			// Treat it exactly like an API error: NEVER classify/repair against a
			// provably-incomplete Google snapshot (a live event on an un-fetched
			// page would look deleted and could drive a wrong drop/rebind).
			$this->logger->warning(
				'Calendar Bridge verify: Google event list exceeded ' . self::MAX_PAGES
					. ' pages; treating as incomplete and skipping this pass',
				['app' => Application::APP_ID],
			);
			return ['ok' => false, 'byId' => [], 'byNcOrigin' => []];
		}
		return ['ok' => true, 'byId' => $byId, 'byNcOrigin' => $byNcOrigin];
	}
}
