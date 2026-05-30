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
 * Phase 2a: add the NC-side echo baseline to the event map.
 *
 * nc_etag stores the Nextcloud CalDAV object etag as it was the moment THIS
 * app last wrote the object. The outbound reconciler compares a changed
 * object's current etag against this baseline to tell our own inbound write
 * (etag unchanged -> echo, skip) from a genuine user edit (etag changed ->
 * push). It is the NC-direction counterpart to the Google-direction
 * extendedProperties.private.ncOrigin gate.
 */
class Version04000002Date20260530000002 extends SimpleMigrationStep {

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('calbridge_event_map')) {
			return null;
		}
		$table = $schema->getTable('calbridge_event_map');
		if ($table->hasColumn('nc_etag')) {
			return null;
		}
		$table->addColumn('nc_etag', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);

		return $schema;
	}
}
