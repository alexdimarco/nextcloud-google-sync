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
 * Phase 0 of bidirectional sync: the event-mapping table.
 *
 * One row per (NC calendar object, recurrence slot) <-> Google event. A
 * non-recurring or master event is recurrence_id='' ; each exception of a
 * recurring series is a sibling row sharing nc_uri but carrying the
 * exception's own originalStartTime token in recurrence_id and the
 * exception's own Google event id in google_id. This 1:N shape is required
 * because the inbound importer stores a whole recurring series (master +
 * inlined exceptions) as ONE NC calendar object whose URI is the master's
 * Google id, while each exception is a separate Google event with its own id.
 *
 * Phase 0 only mirrors reality into this table (observability). No outbound
 * writes and no behavior change yet.
 */
class Version04000001Date20260530000001 extends SimpleMigrationStep {

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('calbridge_event_map')) {
			return null;
		}

		$table = $schema->createTable('calbridge_event_map');

		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
		]);
		// NC calendar id (oc_calendars.id) this row belongs to.
		$table->addColumn('nc_cal_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
		]);
		// The NC CalDAV object URI (for imported events this equals the
		// master Google event id; for NC-originated events it is NC's own URI).
		$table->addColumn('nc_uri', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		// '' for a master/standalone event; otherwise the exception's
		// originalStartTime token (dateTime or date) identifying which
		// instance of the series this row maps.
		$table->addColumn('recurrence_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
			'default' => '',
		]);
		// The Google event id. Nullable so a Phase 2 NC-originated event can
		// exist in the map before events.insert assigns its Google id.
		$table->addColumn('google_id', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		// The iCalUID (cross-reference / debugging).
		$table->addColumn('ical_uid', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		// Provenance: 'google' (imported) or 'nc' (created in Nextcloud).
		$table->addColumn('origin', Types::STRING, [
			'notnull' => true,
			'length' => 16,
			'default' => 'google',
		]);
		// Secondary LWW / change-detection signals captured at last sync.
		// These are NOT the echo gate (that is the ncOrigin extendedProperty
		// in Phase 2) because NC and Google both rewrite the body.
		$table->addColumn('baseline_etag', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		$table->addColumn('google_updated', Types::STRING, [
			'notnull' => false,
			'length' => 32,
		]);
		$table->addColumn('nc_lastmodified', Types::BIGINT, [
			'notnull' => false,
			'length' => 20,
		]);
		// Reconciler lifecycle (Phase 2): synced | pending | error.
		$table->addColumn('state', Types::STRING, [
			'notnull' => true,
			'length' => 16,
			'default' => 'synced',
		]);
		// Last outbound error for per-row observability (Phase 2).
		$table->addColumn('last_error', Types::TEXT, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		// One row per NC object + recurrence slot.
		$table->addUniqueIndex(['nc_cal_id', 'nc_uri', 'recurrence_id'], 'oc_cbevmap_obj');
		// Reverse lookup by Google id. google_id is nullable for Phase 2
		// NC-origin rows that have no Google id until events.insert assigns
		// one. NOTE: on MySQL/MariaDB/PostgreSQL/SQLite multiple NULLs do not
		// collide in this unique index, but on Oracle a composite unique index
		// with a NOT NULL leading column (nc_cal_id) DOES enforce uniqueness
		// across rows whose google_id is NULL — so two not-yet-pushed rows for
		// the same calendar would collide there. Harmless in Phase 0 (every
		// row inserted here has a non-null google_id); Phase 2 must resolve
		// this (e.g. a non-null local-token sentinel, or app-layer uniqueness)
		// before relying on NULL google_id, and can fold the change into its
		// own migration since this table is created fresh here.
		$table->addUniqueIndex(['nc_cal_id', 'google_id'], 'oc_cbevmap_gid');

		return $schema;
	}
}
