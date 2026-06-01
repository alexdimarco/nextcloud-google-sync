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
 * @extends QBMapper<CalendarMap>
 */
class CalendarMapMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'calbridge_calendar_map', CalendarMap::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function findByGoogleCalId(string $googleCalId): CalendarMap {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('google_cal_id', $qb->createNamedParameter($googleCalId)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function findByNcCalId(int $ncCalId): CalendarMap {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('nc_cal_id', $qb->createNamedParameter($ncCalId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Delete the pairing for a Google calendar id. Returns rows removed.
	 */
	public function deleteByGoogleCalId(string $googleCalId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('google_cal_id', $qb->createNamedParameter($googleCalId)));
		return $qb->executeStatement();
	}
}
