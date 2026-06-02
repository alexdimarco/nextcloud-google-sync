<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alex DiMarco
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\CalendarBridge\Service;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Db\ContactMap;
use OCA\CalendarBridge\Db\ContactMapMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Identity + echo/conflict baseline for contacts sync (Track 2): the Nextcloud
 * card <-> Google `resourceName` mapping. The 1:1 contacts analog of
 * {@see EventMapService}. Every public method is defensive — a mapping failure
 * must never break the import/sync it shadows, so all DB work is wrapped and
 * only logged. See docs/CONTACTS_SYNC.md.
 */
class ContactMapService {

	public function __construct(
		private ContactMapMapper $mapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Upsert the mapping for one reconciled contact, keyed by (address book,
	 * Google resourceName). Null baselines are left unchanged on an existing row
	 * (so a partial update never clobbers a known etag). Defensive: returns false
	 * (and logs) on any failure, never throws.
	 */
	public function recordMapping(
		int $ncAddressbookId,
		string $ncCardUri,
		string $googleResourceName,
		?string $baselineEtag = null,
		?string $googleUpdated = null,
		?int $ncLastmodified = null,
		?string $ncEtag = null,
		string $origin = 'google',
	): bool {
		// Retry once: a concurrent insert can race us to the
		// (addressbook, resourceName) unique index between our find and our
		// insert; on the second pass the row now exists and we take the update
		// path instead of colliding.
		for ($attempt = 1; $attempt <= 2; $attempt++) {
			try {
				try {
					$row = $this->mapper->findByGoogleResourceName($ncAddressbookId, $googleResourceName);
				} catch (DoesNotExistException) {
					$row = new ContactMap();
					$row->setNcAddressbookId($ncAddressbookId);
					$row->setGoogleResourceName($googleResourceName);
					$row->setOrigin($origin);
				}
				$row->setNcCardUri($ncCardUri);
				if ($baselineEtag !== null) {
					$row->setBaselineEtag($baselineEtag);
				}
				if ($googleUpdated !== null) {
					$row->setGoogleUpdated($googleUpdated);
				}
				if ($ncLastmodified !== null) {
					$row->setNcLastmodified($ncLastmodified);
				}
				if ($ncEtag !== null) {
					$row->setNcEtag($ncEtag);
				}
				// recordMapping is the SUCCESS path (the card was written), so any
				// prior 'error' is now resolved -> 'synced'. Failures are recorded
				// separately via recordLastError(), which sets state='error'.
				$row->setState('synced');
				if ($row->getId() === null) {
					$this->mapper->insert($row);
				} else {
					$this->mapper->update($row);
				}
				return true;
			} catch (Throwable $e) {
				if ($attempt < 2) {
					continue;
				}
				$this->logger->warning(
					'Calendar Bridge: failed to record contact mapping ' . $googleResourceName . ' in address book '
						. $ncAddressbookId . ': ' . $e->getMessage(),
					['app' => Application::APP_ID],
				);
				return false;
			}
		}
		return false;
	}

	/** The map row for a Google resourceName in an address book, or null. Defensive. */
	public function getRowForResourceName(int $ncAddressbookId, string $googleResourceName): ?ContactMap {
		try {
			return $this->mapper->findByGoogleResourceName($ncAddressbookId, $googleResourceName);
		} catch (DoesNotExistException) {
			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: contact-map lookup failed for ' . $googleResourceName . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return null;
		}
	}

	/** The map row for an NC card, or null. Defensive. */
	public function getRowForCard(int $ncAddressbookId, string $ncCardUri): ?ContactMap {
		try {
			return $this->mapper->findByNcCard($ncAddressbookId, $ncCardUri);
		} catch (DoesNotExistException) {
			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: contact-map lookup failed for card ' . $ncCardUri . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return null;
		}
	}

	/** Remove the mapping for one NC card (e.g. the card or its Google contact was deleted). Defensive. */
	public function removeForCard(int $ncAddressbookId, string $ncCardUri): void {
		try {
			$this->mapper->deleteByNcCard($ncAddressbookId, $ncCardUri);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: failed to remove contact mapping for card ' . $ncCardUri . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
		}
	}

	/** Drop every mapping for an address book (sync turned off / address book gone). Defensive. */
	public function removeForAddressBook(int $ncAddressbookId): void {
		try {
			$this->mapper->deleteForAddressBook($ncAddressbookId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: failed to remove contact mappings for address book ' . $ncAddressbookId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
		}
	}

	/**
	 * The set of NC card URIs that have a mapping row in this address book, as
	 * [uri => true]. Returns NULL on a lookup error (distinct from an empty
	 * address book, which returns []) so a caller can FAIL CLOSED — e.g. the
	 * de-dup pass aborts rather than risk misclassifying a synced card as a
	 * deletable stray.
	 *
	 * @return array<string,true>|null
	 */
	public function getMappedCardUris(int $ncAddressbookId): ?array {
		try {
			$uris = [];
			foreach ($this->mapper->findForAddressBook($ncAddressbookId) as $row) {
				$uris[$row->getNcCardUri()] = true;
			}
			return $uris;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: failed to load contact map for address book ' . $ncAddressbookId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return null;
		}
	}

	/** Number of mapping rows for an address book. Defensive: 0 on error. */
	public function countForAddressBook(int $ncAddressbookId): int {
		try {
			return $this->mapper->countForAddressBook($ncAddressbookId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: failed to count contact mappings for address book ' . $ncAddressbookId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return 0;
		}
	}

	/** Record a diagnostic error on a row (best-effort). Defensive. */
	public function recordLastError(int $ncAddressbookId, string $googleResourceName, ?string $error): void {
		try {
			$row = $this->mapper->findByGoogleResourceName($ncAddressbookId, $googleResourceName);
			$row->setState($error === null ? 'synced' : 'error');
			$row->setLastError($error);
			$this->mapper->update($row);
		} catch (DoesNotExistException) {
			// nothing to annotate
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: failed to record contact-map error for ' . $googleResourceName . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
		}
	}
}
