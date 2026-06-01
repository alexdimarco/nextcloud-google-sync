<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One linked pair: a Nextcloud calendar <-> a Google calendar. See the
 * calendar-level-sync migration for the model.
 *
 * @method int getNcCalId()
 * @method void setNcCalId(int $ncCalId)
 * @method string getNcCalUri()
 * @method void setNcCalUri(string $ncCalUri)
 * @method string getGoogleCalId()
 * @method void setGoogleCalId(string $googleCalId)
 * @method string getOrigin()
 * @method void setOrigin(string $origin)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class CalendarMap extends Entity {
	protected int $ncCalId = 0;
	protected string $ncCalUri = '';
	protected string $googleCalId = '';
	protected string $origin = 'nc';
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('ncCalId', 'integer');
		$this->addType('createdAt', 'integer');
	}
}
