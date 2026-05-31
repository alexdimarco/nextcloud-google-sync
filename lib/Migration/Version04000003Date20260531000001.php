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
 * Phase 4: advisory baseline columns on the master event-map row, used ONLY by
 * the outbound recurrence differ's refusal guards to detect a destructive
 * recurrence transition and keep that series one-way (SKIPPED_UNSUPPORTED)
 * rather than blindly mutate it:
 *
 *  - shape:          'single' | 'recurring' as of the last sync (single<->recurring flip).
 *  - baseline_rrule: the master RRULE string(s) as of the last sync (THISANDFUTURE /
 *                    UNTIL/COUNT split detection).
 *  - master_dtstart: the master DTSTART canonical key + zone as of the last sync
 *                    (DTSTART instant/zone move + all-day<->timed flip).
 *
 * All nullable; a null baseline means "no prior sync recorded" — the differ
 * establishes it on the next successful write and only refuses on a real
 * mismatch against a populated baseline.
 */
class Version04000003Date20260531000001 extends SimpleMigrationStep {

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('calbridge_event_map')) {
			return null;
		}
		$table = $schema->getTable('calbridge_event_map');
		$changed = false;

		if (!$table->hasColumn('shape')) {
			$table->addColumn('shape', Types::STRING, ['notnull' => false, 'length' => 16]);
			$changed = true;
		}
		if (!$table->hasColumn('baseline_rrule')) {
			$table->addColumn('baseline_rrule', Types::TEXT, ['notnull' => false]);
			$changed = true;
		}
		if (!$table->hasColumn('master_dtstart')) {
			$table->addColumn('master_dtstart', Types::STRING, ['notnull' => false, 'length' => 128]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
