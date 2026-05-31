<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Sabre\VObject\Property;
use Throwable;

/**
 * Canonical recurrence-instance keys for the outbound recurrence differ.
 *
 * An "instance" of a recurring series is identified by its ORIGINAL start (the
 * slot in the expansion it overrides or cancels), which three subsystems each
 * spell differently:
 *   - Nextcloud .ics:  a RECURRENCE-ID / EXDATE property (TZID + wall time, or a
 *                      VALUE=DATE for all-day);
 *   - Google live API: an event instance's originalStartTime ({dateTime} or {date});
 *   - the event map:   recurrence_id = the raw Google originalStartTime string.
 *
 * This reduces all three to ONE canonical token so they compare by equality.
 * Timed instants collapse to UTC; all-day to a plain date. The two kinds are
 * namespaced ('T:' vs 'D:') so a timed key can NEVER accidentally equal an
 * all-day key. Pure: no I/O.
 *
 * Google's live originalStartTime is the AUTHORITATIVE identity — the differ
 * matches NC values and stored tokens TO the live instance list. A timed NC
 * value with an UNRESOLVABLE TZID yields null rather than a fabricated instant;
 * the caller then resolves it only via the live match (never by guessing).
 */
final class RecurrenceKey {

	private const UTC = 'UTC';

	/**
	 * Canonicalize a LIVE Google instance (authoritative). Null if it carries no
	 * usable originalStartTime.
	 *
	 * @param array<string, mixed> $instance a Google event instance resource
	 */
	public static function fromGoogleInstance(array $instance): ?string {
		$ost = $instance['originalStartTime'] ?? null;
		return is_array($ost) ? self::fromGoogleTimeObject($ost) : null;
	}

	/**
	 * Canonicalize a stored sibling recurrence_id token (the raw Google
	 * originalStartTime string we recorded). Advisory only — rows are
	 * eventually-consistent; the differ still re-resolves against live instances.
	 */
	public static function fromGoogleToken(string $token): ?string {
		if ($token === '') {
			return null;
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $token) === 1) {
			return 'D:' . $token;
		}
		try {
			return 'T:' . (new DateTimeImmutable($token))->setTimezone(new DateTimeZone(self::UTC))->format('Y-m-d\TH:i:s\Z');
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * Canonicalize an NC RECURRENCE-ID (single value). Null when timed with an
	 * unresolvable TZID — resolve via the live Google list instead.
	 *
	 * @param callable(string): bool $isResolvableTzid
	 */
	public static function fromIcsDateProp(Property $p, DateTimeZone $refZone, callable $isResolvableTzid): ?string {
		return self::fromIcsDateProps($p, $refZone, $isResolvableTzid)[0] ?? null;
	}

	/**
	 * Canonicalize ALL values of an NC date property. EXDATE may be multi-valued
	 * (comma-separated), so call once per property and expect a list. A value
	 * whose TZID is unresolvable yields null in that slot.
	 *
	 * @param callable(string): bool $isResolvableTzid
	 * @return list<string|null>
	 */
	public static function fromIcsDateProps(Property $p, DateTimeZone $refZone, callable $isResolvableTzid): array {
		$isDateOnly = self::isDateOnly($p);
		$tzid = isset($p['TZID']) ? (string)$p['TZID'] : null;
		try {
			/** @var list<DateTimeImmutable> $dts */
			$dts = $p->getDateTimes($refZone);
		} catch (Throwable) {
			return [null];
		}
		return self::canonicalizeValues($isDateOnly, $tzid, $dts, $isResolvableTzid);
	}

	/**
	 * The pure heart of the NC-side conversion, decoupled from Sabre: given the
	 * already-extracted property parts, apply the unresolvable-TZID gate and
	 * canonicalize each value. A timed value with a present-but-unresolvable TZID
	 * yields a single null (do not fabricate an instant). Pure.
	 *
	 * @param list<DateTimeInterface> $dateTimes the property's resolved value(s)
	 * @param callable(string): bool $isResolvableTzid
	 * @return list<string|null>
	 */
	public static function canonicalizeValues(bool $isDateOnly, ?string $tzid, array $dateTimes, callable $isResolvableTzid): array {
		if (!$isDateOnly && $tzid !== null && $tzid !== '' && !$isResolvableTzid($tzid)) {
			return [null];
		}
		$out = [];
		foreach ($dateTimes as $dt) {
			$out[] = self::keyForDateTime($dt, $isDateOnly);
		}
		return $out === [] ? [null] : $out;
	}

	/**
	 * The canonical key for an already-resolved datetime.
	 */
	public static function keyForDateTime(DateTimeInterface $dt, bool $isDateOnly): string {
		if ($isDateOnly) {
			return 'D:' . $dt->format('Y-m-d');
		}
		return 'T:' . DateTimeImmutable::createFromInterface($dt)->setTimezone(new DateTimeZone(self::UTC))->format('Y-m-d\TH:i:s\Z');
	}

	/**
	 * @param array<string, mixed> $t a Google time object ({date} | {dateTime[, timeZone]})
	 */
	private static function fromGoogleTimeObject(array $t): ?string {
		if (isset($t['date']) && is_string($t['date']) && $t['date'] !== '') {
			return 'D:' . $t['date'];
		}
		if (isset($t['dateTime']) && is_string($t['dateTime']) && $t['dateTime'] !== '') {
			try {
				return 'T:' . (new DateTimeImmutable($t['dateTime']))->setTimezone(new DateTimeZone(self::UTC))->format('Y-m-d\TH:i:s\Z');
			} catch (Throwable) {
				return null;
			}
		}
		return null;
	}

	private static function isDateOnly(Property $p): bool {
		if ($p instanceof Property\ICalendar\DateTime) {
			return !$p->hasTime();
		}
		return isset($p['VALUE']) && strtoupper((string)$p['VALUE']) === 'DATE';
	}
}
