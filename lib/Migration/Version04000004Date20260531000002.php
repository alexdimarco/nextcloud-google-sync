<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Calendar-level sync: the calendar-mapping table.
 *
 * One row per linked pair (Nextcloud calendar <-> Google calendar). Today the
 * inbound importer assumes the NC calendar URI equals the Google calendar id;
 * that implicit identity is fine for Google-ORIGINATED calendars (and they keep
 * working via the legacy URI=id resolution — no row needed here). This table
 * exists for NC-ORIGINATED pairs, where a PRE-EXISTING Nextcloud calendar (its
 * own URI) is linked to a freshly-created Google calendar id: the importer
 * resolves the NC calendar map-first, then falls back to the legacy scheme.
 *
 * P-b adds the table + the resolution seam only; nothing writes rows yet (the
 * NC -> Google create flow that populates it lands in P-c). So with an empty
 * table behavior is identical to today.
 */
class Version04000004Date20260531000002 extends SimpleMigrationStep {

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('calbridge_calendar_map')) {
			return null;
		}

		$table = $schema->createTable('calbridge_calendar_map');

		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
		]);
		// The Nextcloud calendar id (oc_calendars.id) of the linked calendar.
		$table->addColumn('nc_cal_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
		]);
		// The NC CalDAV calendar URI (for resolution/debugging; the calendar id
		// is the authoritative key).
		$table->addColumn('nc_cal_uri', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		// The Google calendar id this NC calendar is linked to.
		$table->addColumn('google_cal_id', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		// Provenance: 'nc' (we created the Google calendar from an NC one) or
		// 'google' (imported — optional backfill; not written in P-b).
		$table->addColumn('origin', Types::STRING, [
			'notnull' => true,
			'length' => 16,
			'default' => 'nc',
		]);
		// Unix timestamp the pairing was recorded.
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
			'default' => 0,
		]);

		$table->setPrimaryKey(['id']);
		// One Google calendar per NC calendar, and vice-versa. Both columns are
		// NOT NULL, so (unlike the event map's nullable google_id) there is no
		// cross-DB NULL-uniqueness caveat here.
		$table->addUniqueIndex(['nc_cal_id'], 'oc_cbcalmap_nc');
		$table->addUniqueIndex(['google_cal_id'], 'oc_cbcalmap_g');

		return $schema;
	}
}
