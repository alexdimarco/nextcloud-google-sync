<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<EventMap>
 */
class EventMapMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'calbridge_event_map', EventMap::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function findByNcObject(int $ncCalId, string $ncUri, string $recurrenceId): EventMap {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('nc_cal_id', $qb->createNamedParameter($ncCalId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nc_uri', $qb->createNamedParameter($ncUri)))
			->andWhere($qb->expr()->eq('recurrence_id', $qb->createNamedParameter($recurrenceId)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function findByGoogleId(int $ncCalId, string $googleId): EventMap {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('nc_cal_id', $qb->createNamedParameter($ncCalId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('google_id', $qb->createNamedParameter($googleId)));
		return $this->findEntity($qb);
	}

	public function countForCalendar(int $ncCalId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('nc_cal_id', $qb->createNamedParameter($ncCalId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	/**
	 * @return EventMap[]
	 */
	public function findForCalendar(int $ncCalId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('nc_cal_id', $qb->createNamedParameter($ncCalId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * The recurrence-instance SIBLING rows (recurrence_id <> '') of one NC
	 * object — the per-instance overrides/cancellations of a recurring series.
	 * The master row (recurrence_id = '') is excluded.
	 *
	 * @return EventMap[]
	 */
	public function findSiblingsForNcUri(int $ncCalId, string $ncUri): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('nc_cal_id', $qb->createNamedParameter($ncCalId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nc_uri', $qb->createNamedParameter($ncUri)))
			->andWhere($qb->expr()->neq('recurrence_id', $qb->createNamedParameter('')));
		return $this->findEntities($qb);
	}

	/**
	 * Delete every row (master + all recurrence-instance siblings) for one
	 * NC calendar object. Returns the number of rows removed.
	 */
	public function deleteForNcUri(int $ncCalId, string $ncUri): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('nc_cal_id', $qb->createNamedParameter($ncCalId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nc_uri', $qb->createNamedParameter($ncUri)));
		return $qb->executeStatement();
	}

	/**
	 * Prune stale recurrence-instance siblings of one NC object: delete every
	 * sibling row (recurrence_id <> '') whose token is NOT in $keepTokens. The
	 * master row (recurrence_id = '') is never touched. An empty $keepTokens
	 * removes all siblings (the series has no live exceptions any more).
	 *
	 * Caller must only invoke this on a FULL pull, where $keepTokens is the
	 * complete live-exception set; on an incremental pull the exception list
	 * is only a delta and pruning would wrongly delete still-live siblings.
	 *
	 * Returns the number of rows removed.
	 */
	public function deleteSiblingsNotIn(int $ncCalId, string $ncUri, array $keepTokens): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('nc_cal_id', $qb->createNamedParameter($ncCalId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nc_uri', $qb->createNamedParameter($ncUri)))
			->andWhere($qb->expr()->neq('recurrence_id', $qb->createNamedParameter('')));
		if (count($keepTokens) > 0) {
			$qb->andWhere($qb->expr()->notIn(
				'recurrence_id',
				$qb->createNamedParameter($keepTokens, IQueryBuilder::PARAM_STR_ARRAY)
			));
		}
		return $qb->executeStatement();
	}
}
