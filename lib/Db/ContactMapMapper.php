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
 * @extends QBMapper<ContactMap>
 */
class ContactMapMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'calbridge_contacts_map', ContactMap::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function findByGoogleResourceName(int $ncAddressbookId, string $googleResourceName): ContactMap {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('nc_addressbook_id', $qb->createNamedParameter($ncAddressbookId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('google_resource_name', $qb->createNamedParameter($googleResourceName)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function findByNcCard(int $ncAddressbookId, string $ncCardUri): ContactMap {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('nc_addressbook_id', $qb->createNamedParameter($ncAddressbookId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nc_card_uri', $qb->createNamedParameter($ncCardUri)));
		return $this->findEntity($qb);
	}

	/**
	 * @return ContactMap[]
	 */
	public function findForAddressBook(int $ncAddressbookId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('nc_addressbook_id', $qb->createNamedParameter($ncAddressbookId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	public function countForAddressBook(int $ncAddressbookId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('nc_addressbook_id', $qb->createNamedParameter($ncAddressbookId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	/** Returns the number of rows removed. */
	public function deleteByNcCard(int $ncAddressbookId, string $ncCardUri): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('nc_addressbook_id', $qb->createNamedParameter($ncAddressbookId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nc_card_uri', $qb->createNamedParameter($ncCardUri)));
		return $qb->executeStatement();
	}

	/**
	 * Drop every row for an address book (teardown when sync is turned off or the
	 * address book is gone). Returns the number of rows removed.
	 */
	public function deleteForAddressBook(int $ncAddressbookId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('nc_addressbook_id', $qb->createNamedParameter($ncAddressbookId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
