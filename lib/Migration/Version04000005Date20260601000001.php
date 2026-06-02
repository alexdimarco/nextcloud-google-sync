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
 * Contacts sync (Track 2) — the contact-mapping table.
 *
 * One row per reconciled contact: a Nextcloud address-book card <-> a Google
 * People `resourceName` (people/{id}). It is the identity + echo/conflict
 * baseline for continuous and (later) two-way contacts sync, the direct analog
 * of `calbridge_event_map` for calendars (minus the recurrence fan-out, so 1:1
 * not 1:N). Google People has no `extendedProperties`, so echo suppression
 * rests on `baseline_etag` (Google etag) and `nc_etag`/`nc_lastmodified` (the NC
 * card baselines) — see docs/CONTACTS_SYNC.md §3.
 *
 * C0 (this migration) adds the table + the foundation that populates it; the
 * incremental sync job lands next. With an empty table behavior is unchanged.
 */
class Version04000005Date20260601000001 extends SimpleMigrationStep {

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('calbridge_contacts_map')) {
			return null;
		}

		$table = $schema->createTable('calbridge_contacts_map');

		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
		]);
		// The Nextcloud address book id (oc_addressbooks.id) holding the card.
		$table->addColumn('nc_addressbook_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
		]);
		// The NC card URI within that address book.
		$table->addColumn('nc_card_uri', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		// Google People stable identity: people/{id}. Stored RAW (unsanitized) —
		// the NC card URI may be a slash-sanitized form, but Google calls use this.
		$table->addColumn('google_resource_name', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		// Provenance: 'google' (imported from Google) or 'nc' (created in NC and
		// pushed out — only once outbound C2 exists).
		$table->addColumn('origin', Types::STRING, [
			'notnull' => true,
			'length' => 16,
			'default' => 'google',
		]);
		// Last Google etag we know (from the last read, or our last write's
		// response). Inbound echo gate: incoming etag == baseline_etag => echo.
		$table->addColumn('baseline_etag', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		// NC card etag we last wrote/observed — the outbound echo baseline (C2).
		$table->addColumn('nc_etag', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		// Google `metadata.sources.updateTime` at last sync — the LWW input.
		$table->addColumn('google_updated', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		// NC card `lastmodified` (unix ts) at last sync — the other LWW input.
		$table->addColumn('nc_lastmodified', Types::BIGINT, [
			'notnull' => false,
			'length' => 20,
		]);
		// Reconciliation lifecycle: synced | pending | error.
		$table->addColumn('state', Types::STRING, [
			'notnull' => true,
			'length' => 16,
			'default' => 'synced',
		]);
		// Last failure detail (diagnostics only).
		$table->addColumn('last_error', Types::TEXT, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		// One map row per card, and one per (address book, resourceName) — the
		// latter is the in-address-book de-dup invariant. The SAME Google contact
		// synced into two DIFFERENT address books is two rows (one resourceName,
		// two address book ids) — that is allowed (see docs/CONTACTS_SYNC.md §9).
		$table->addUniqueIndex(['nc_addressbook_id', 'nc_card_uri'], 'oc_cbctmap_card');
		$table->addUniqueIndex(['nc_addressbook_id', 'google_resource_name'], 'oc_cbctmap_res');
		// Non-unique lookup by Google id across address books.
		$table->addIndex(['google_resource_name'], 'oc_cbctmap_rn');

		return $schema;
	}
}
