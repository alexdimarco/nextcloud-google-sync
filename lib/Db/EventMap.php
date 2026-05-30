<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One mapping row between a Nextcloud calendar object (at a given recurrence
 * slot) and a Google Calendar event. See the Phase 0 migration for the 1:N
 * recurrence model.
 *
 * @method int getNcCalId()
 * @method void setNcCalId(int $ncCalId)
 * @method string getNcUri()
 * @method void setNcUri(string $ncUri)
 * @method string getRecurrenceId()
 * @method void setRecurrenceId(string $recurrenceId)
 * @method string|null getGoogleId()
 * @method void setGoogleId(?string $googleId)
 * @method string|null getIcalUid()
 * @method void setIcalUid(?string $icalUid)
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
class EventMap extends Entity {
	protected int $ncCalId = 0;
	protected string $ncUri = '';
	protected string $recurrenceId = '';
	protected ?string $googleId = null;
	protected ?string $icalUid = null;
	protected string $origin = 'google';
	protected ?string $baselineEtag = null;
	protected ?string $ncEtag = null;
	protected ?string $googleUpdated = null;
	protected ?int $ncLastmodified = null;
	protected string $state = 'synced';
	protected ?string $lastError = null;

	public function __construct() {
		$this->addType('ncCalId', 'integer');
		$this->addType('ncLastmodified', 'integer');
	}
}
