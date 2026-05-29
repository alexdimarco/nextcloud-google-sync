<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Migration;

use Closure;
use OCA\CalendarBridge\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Moves data from the pre-rename app identifier ("google_synchronization",
 * namespace "OCA\Google") to the new one ("outside_provider_calendar_bridge",
 * namespace "OCA\CalendarBridge").
 *
 * Touches three tables:
 * - oc_appconfig: only the app-specific keys (client_id, client_secret,
 *   use_popup). NC-managed keys (enabled, installed_version, types) are left
 *   alone — they're populated for the new app by `app:enable` and would
 *   collide on the (appid, configkey) uniqueness constraint if we tried to
 *   UPDATE them across.
 * - oc_preferences: every row. Per-user state (token, refresh_token,
 *   oauth_state, user_name, user_scopes, sync_token_*, etc.) has no
 *   conflicting rows for the new app on a clean upgrade.
 * - oc_jobs: rewrite the class column from OCA\Google\... to
 *   OCA\CalendarBridge\... so the registered ImportCalendarJob entries keep
 *   firing.
 *
 * Fresh installs that never had the old app run this as a zero-row no-op.
 */
class Version04000000Date20260529000001 extends SimpleMigrationStep {

	private const OLD_APP_ID = 'google_synchronization';
	private const OLD_NAMESPACE_PREFIX = 'OCA\\Google\\';
	private const NEW_NAMESPACE_PREFIX = 'OCA\\CalendarBridge\\';

	private const MOVED_APP_KEYS = ['client_id', 'client_secret', 'use_popup'];

	public function __construct(private IDBConnection $db) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$movedAppConfig = $this->moveAppConfig();
		$movedPrefs = $this->movePreferences();
		$movedJobs = $this->moveJobs();

		$total = $movedAppConfig + $movedPrefs + $movedJobs;
		if ($total > 0) {
			$output->info(sprintf(
				'Calendar Bridge: migrated %d appconfig + %d preference + %d job row(s) from %s to %s',
				$movedAppConfig, $movedPrefs, $movedJobs,
				self::OLD_APP_ID, Application::APP_ID,
			));
		}
	}

	private function moveAppConfig(): int {
		$moved = 0;
		foreach (self::MOVED_APP_KEYS as $key) {
			$qb = $this->db->getQueryBuilder();
			$moved += $qb->update('appconfig')
				->set('appid', $qb->createNamedParameter(Application::APP_ID, IQueryBuilder::PARAM_STR))
				->where($qb->expr()->eq('appid', $qb->createNamedParameter(self::OLD_APP_ID, IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key, IQueryBuilder::PARAM_STR)))
				->executeStatement();
		}
		return $moved;
	}

	private function movePreferences(): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update('preferences')
			->set('appid', $qb->createNamedParameter(Application::APP_ID, IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(self::OLD_APP_ID, IQueryBuilder::PARAM_STR)))
			->executeStatement();
	}

	private function moveJobs(): int {
		// REPLACE() is supported across mysql/mariadb/postgres/sqlite. NC's
		// QueryBuilder can't express a column-side string substitution
		// portably, so a small literal-prefixed raw statement is cleaner here.
		$sql = 'UPDATE `*PREFIX*jobs` SET `class` = REPLACE(`class`, ?, ?) WHERE `class` LIKE ?';
		return $this->db->executeStatement($sql, [
			self::OLD_NAMESPACE_PREFIX,
			self::NEW_NAMESPACE_PREFIX,
			self::OLD_NAMESPACE_PREFIX . '%',
		]);
	}
}
