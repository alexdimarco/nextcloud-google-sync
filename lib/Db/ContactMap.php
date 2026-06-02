<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One mapping row between a Nextcloud address-book card and a Google People
 * contact (`resourceName`). See docs/CONTACTS_SYNC.md and the Track 2 migration
 * for the model. The 1:1 contacts analog of {@see EventMap}.
 *
 * @method int getNcAddressbookId()
 * @method void setNcAddressbookId(int $ncAddressbookId)
 * @method string getNcCardUri()
 * @method void setNcCardUri(string $ncCardUri)
 * @method string getGoogleResourceName()
 * @method void setGoogleResourceName(string $googleResourceName)
 * @method string getOrigin()
 * @method void setOrigin(string $origin)
 * @method string|null getBaselineEtag()
 * @method void setBaselineEtag(?string $baselineEtag)
 * @method string|null getNcEtag()
 * @method void setNcEtag(?string $ncEtag)
 * @method string|null getGoogleUpdated()
 * @method void setGoogleUpdated(?string $googleUpdated)
 * @method int|null getNcLastmodified()
 * @method void setNcLastmodified(?int $ncLastmodified)
 * @method string getState()
 * @method void setState(string $state)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 */
class ContactMap extends Entity {
	protected int $ncAddressbookId = 0;
	protected string $ncCardUri = '';
	protected string $googleResourceName = '';
	protected string $origin = 'google';
	protected ?string $baselineEtag = null;
	protected ?string $ncEtag = null;
	protected ?string $googleUpdated = null;
	protected ?int $ncLastmodified = null;
	protected string $state = 'synced';
	protected ?string $lastError = null;

	public function __construct() {
		$this->addType('ncAddressbookId', 'integer');
		$this->addType('ncLastmodified', 'integer');
	}
}
