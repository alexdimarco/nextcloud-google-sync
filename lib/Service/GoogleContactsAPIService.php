<?php

/**
 * Nextcloud - google
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\CalendarBridge\Service;

use DateTime;
use Exception;
use Generator;
use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\BackgroundJob\SyncContactsJob;
use OCA\DAV\CardDAV\CardDavBackend;
use OCP\BackgroundJob\IJobList;
use OCP\Contacts\IManager as IContactManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Service to make requests to Google v3 (JSON) API
 */
class GoogleContactsAPIService {

	// Outbound (NC -> Google) change classifications + write statuses, mirroring
	// the calendar reconciler. See docs/CONTACTS_SYNC.md §3.
	public const ECHO = 'echo';
	public const LOCAL_NEW = 'local_new';
	public const LOCAL_EDIT = 'local_edit';
	public const LOCAL_EDIT_INDETERMINATE = 'local_edit_indeterminate';
	public const LOCAL_DELETE = 'local_delete';
	public const ECHO_DELETE = 'echo_delete';
	public const CREATED = 'created';
	// Created in Google but the local map row could NOT be persisted (a DB error
	// the upsert's one retry didn't clear). The contact is an orphan; we MUST
	// advance the token past it so it is never re-POSTed (createContact has no
	// client id, so a replay would duplicate it). See reconcileOutbound.
	public const CREATED_ORPHAN = 'created_orphan';
	public const SKIPPED_GONE = 'skipped_gone';
	public const SKIPPED_REJECTED = 'skipped_rejected';
	public const ERROR = 'error';

	public function __construct(
		string $appName,
		private LoggerInterface $logger,
		private IContactManager $contactsManager,
		private CardDavBackend $cdBackend,
		private GoogleAPIService $googleApiService,
		private IConfig $config,
		private ContactMapService $contactMapService,
		private IJobList $jobList,
	) {
	}

	/**
	 * Get groups that are not empty and with type USER_CONTACT_GROUP
	 *
	 * @param string $userId
	 * @return array
	 */
	public function getContactGroupsById(string $userId): array {
		$groups = [];
		$params = [
			'pageSize' => 100,
		];
		do {
			$result = $this->googleApiService->request($userId, 'v1/contactGroups', $params, 'GET', 'https://people.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			if (isset($result['contactGroups']) && is_array($result['contactGroups'])) {
				foreach ($result['contactGroups'] as $group) {
					$groupType = $group['groupType'] ?? '';
					$memberCount = $group['memberCount'] ?? 0;
					if ($groupType === 'USER_CONTACT_GROUP' && $memberCount > 0) {
						$groupResourceName = $group['resourceName'];
						$groups[$groupResourceName] = $group;
					}
				}
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));
		return $groups;
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function getContactNumber(string $userId): array {
		$params = [
			'personFields' => implode(',', [
				'names',
			]),
		];
		$result = [];
		$contacts = $this->googleApiService->request($userId, 'v1/people/me/connections', $params, 'GET', 'https://people.googleapis.com/');
		if (isset($contacts['error'])) {
			return $contacts;
		}
		$result['nbContacts'] = $contacts['totalItems'] ?? 0;
		$scopes = $this->config->getUserValue($userId, Application::APP_ID, 'user_scopes', '{}');
		$scopes = json_decode($scopes, true);
		if (isset($scopes['can_access_other_contacts']) && $scopes['can_access_other_contacts'] === 1) {
			$params = [
				'readMask' => implode(',', [
					'names',
					'emailAddresses',
				]),
			];
			$otherContacts = $this->googleApiService->request($userId, 'v1/otherContacts', $params, 'GET', 'https://people.googleapis.com/');
			if (isset($otherContacts['error'])) {
				return $otherContacts;
			}
			$result['nbOtherContacts'] = $otherContacts['totalSize'] ?? 0;
		}
		return $result;
	}

	/**
	 * @param string $userId
	 * @param bool $otherContacts
	 * @return Generator
	 */
	public function getContactList(string $userId, bool $otherContacts = false): Generator {
		$params = [
			'personFields' => $this->connectionsPersonFields(),
			'pageSize' => 100,
		];
		do {
			$result = $this->googleApiService->request($userId, 'v1/people/me/connections', $params, 'GET', 'https://people.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			if (isset($result['connections']) && is_array($result['connections'])) {
				foreach ($result['connections'] as $contact) {
					yield $contact;
				}
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));
		if ($otherContacts) {
			$params = [
				'readMask' => implode(',', [
					'emailAddresses',
					'metadata',
					'names',
					'phoneNumbers',
					'photos',
				]),
				'sources' => 'READ_SOURCE_TYPE_CONTACT',
				'pageSize' => 100,
			];
			do {
				$result = $this->googleApiService->request($userId, 'v1/otherContacts', $params, 'GET', 'https://people.googleapis.com/');
				if (isset($result['error'])) {
					return $result;
				}
				if (isset($result['otherContacts']) && is_array($result['otherContacts'])) {
					foreach ($result['otherContacts'] as $contact) {
						yield $contact;
					}
				}
				$params['pageToken'] = $result['nextPageToken'] ?? '';
			} while (isset($result['nextPageToken']));
		}
		return [];
	}

	/**
	 * @param string $userId
	 * @param ?string $uri
	 * @param int $key
	 * @param ?string $newAddrBookName
	 * @return array
	 */
	public function importContacts(string $userId, ?string $uri, int $key, ?string $newAddrBookName): array {
		$existingAddressBook = null;
		if ($key === 0) {
			$addressBooks = $this->contactsManager->getUserAddressBooks();
			foreach ($addressBooks as $k => $ab) {
				if ($ab->getDisplayName() === $newAddrBookName) {
					$key = intval($ab->getKey());
					break;
				}
			}
			if ($key === 0) {
				$key = $this->cdBackend->createAddressBook('principals/users/' . $userId, $newAddrBookName, []);
			}
		} else {
			// existing address book
			// check if it exists
			$addressBooks = $this->contactsManager->getUserAddressBooks();
			$addressBook = null;
			foreach ($addressBooks as $k => $ab) {
				if ($ab->getUri() === $uri && intval($ab->getKey()) === $key) {
					$addressBook = $ab;
					break;
				}
			}
			if (!$addressBook) {
				return ['error' => 'no such address book'];
			}
			$existingAddressBook = $addressBook;
		}
		$otherContacts = $this->config->getUserValue($userId, Application::APP_ID, 'consider_other_contacts', '0') === '1';
		$groupsById = $this->getContactGroupsById($userId);
		$contacts = $this->getContactList($userId, $otherContacts);
		$nbAdded = 0;
		$nbUpdated = 0;
		$totalContactNumber = 0;
		foreach ($contacts as $k => $c) {
			$totalContactNumber++;

			$googleResourceName = $c['resourceName'] ?? null;
			if ($googleResourceName === null) {
				$this->logger->debug('Skipping contact with no resourceName', ['contact' => $c, 'app' => Application::APP_ID]);
				continue;
			}
			// The RAW resourceName (people/{id}) is Google's stable identity, stored
			// in the contact map; the slash-sanitized form is the NC card URI
			// (contacts are not shown in the Contacts app if their URI has slashes).
			$rawResourceName = (string)$googleResourceName;
			$googleResourceName = str_replace('/', '_', $googleResourceName);

			// check if contact exists and needs to be updated
			$existingContact = null;
			if ($existingAddressBook !== null) {
				$existingContact = $this->cdBackend->getCard($key, $googleResourceName);
				if ($existingContact) {
					$googleUpdateTime = $c['metadata']['sources'][0]['updateTime'] ?? null;
					if ($googleUpdateTime === null) {
						$googleUpdateTimestamp = 0;
					} else {
						try {
							$googleUpdateTimestamp = (new DateTime($googleUpdateTime))->getTimestamp();
						} catch (Exception|Throwable $e) {
							$googleUpdateTimestamp = 0;
						}
					}

					if ($googleUpdateTimestamp <= $existingContact['lastmodified']) {
						$this->logger->debug('Skipping existing contact which is up-to-date', ['contact' => $c, 'app' => Application::APP_ID]);
						// Backfill/refresh the identity map even when the card is current.
						$this->recordContactMapRow($key, $googleResourceName, $rawResourceName, $c, $existingContact);
						continue;
					}
				}
			}

			$vCard = $this->buildVCardFromPerson($userId, $c, $groupsById);

			if ($existingContact === null || $existingContact === false) {
				try {
					$this->cdBackend->createCard($key, $googleResourceName, $vCard->serialize());
					$nbAdded++;
				} catch (Throwable|Exception $e) {
					$this->logger->warning('Error when creating contact', ['exception' => $e, 'contact' => $c, 'app' => Application::APP_ID]);
				}
			} else {
				try {
					$this->cdBackend->updateCard($key, $googleResourceName, $vCard->serialize());
					$nbUpdated++;
				} catch (Throwable|Exception $e) {
					$this->logger->warning('Error when updating contact', ['exception' => $e, 'contact' => $c, 'app' => Application::APP_ID]);
				}
			}
			// Record the identity mapping (Track 2 foundation) for the card we just
			// wrote — best effort; skipped if the write failed (no card to read).
			$freshCard = $this->cdBackend->getCard($key, $googleResourceName);
			if (is_array($freshCard)) {
				$this->recordContactMapRow($key, $googleResourceName, $rawResourceName, $c, $freshCard);
			}
		}
		$this->logger->debug($totalContactNumber . ' contacts seen', ['app' => Application::APP_ID]);
		$this->logger->debug($nbAdded . ' contacts imported', ['app' => Application::APP_ID]);
		$contactGeneratorReturn = $contacts->getReturn();
		if (isset($contactGeneratorReturn['error'])) {
			return $contactGeneratorReturn;
		}
		return [
			'nbSeen' => $totalContactNumber,
			'nbAdded' => $nbAdded,
			'nbUpdated' => $nbUpdated,
		];
	}

	/**
	 * Record/refresh the identity-map row for one imported card (Track 2). Stores
	 * the RAW Google resourceName as the authoritative id, the Google etag +
	 * updateTime as the inbound baselines, and the NC card's etag/lastmodified as
	 * the NC baselines. Best-effort: ContactMapService swallows DB errors.
	 *
	 * @param array<string,mixed> $googleContact the People API person ($c)
	 * @param array<string,mixed> $ncCard the CardDavBackend card row (getCard result)
	 */
	/**
	 * Build a vCard from a Google People person. Extracted from importContacts so
	 * the continuous-sync engine can reuse the exact same field mapping.
	 *
	 * @param array<string,mixed> $c the People API person
	 * @param array<string,string> $groupsById group resourceName -> name (for CATEGORIES)
	 */
	private function buildVCardFromPerson(string $userId, array $c, array $groupsById): VCard {
		$vCard = new VCard();

		$displayName = '';
		// we just take first name
		if (isset($c['names']) && is_array($c['names'])) {
			/** @var array{displayName?: string, familyName?: string, givenName?: string, middleName?: string, honorificPrefix?: string, honorificSuffix?: string } $n */
			foreach ($c['names'] as $n) {
				$displayName = $n['displayName'] ?? '';
				$familyName = $n['familyName'] ?? '';
				$firstName = $n['givenName'] ?? '';
				$additionalName = $n['middleName'] ?? '';
				$prefix = $n['honorificPrefix'] ?? '';
				$suffix = $n['honorificSuffix'] ?? '';
				if ($familyName || $firstName || $additionalName || $prefix || $suffix) {
					$prop = $vCard->createProperty('N', [0 => $familyName, 1 => $firstName, 2 => $additionalName, 3 => $prefix, 4 => $suffix]);
					$vCard->add($prop);
				}
				break;
			}
		}
		if ($displayName) {
			$prop = $vCard->createProperty('FN', $displayName);
			$vCard->add($prop);
		}

		// notes
		if (isset($c['biographies']) && is_array($c['biographies'])) {
			foreach ($c['biographies'] as $biography) {
				if (isset($biography['value'], $biography['contentType']) && $biography['contentType'] === 'TEXT_PLAIN') {
					$prop = $vCard->createProperty('NOTE', $biography['value']);
					$vCard->add($prop);
				}
			}
		}

		// websites
		if (isset($c['urls']) && is_array($c['urls'])) {
			foreach ($c['urls'] as $url) {
				if (isset($url['value'])) {
					$params = [
						'value' => 'uri',
					];
					if (isset($url['formattedType']) || isset($url['type'])) {
						$params['type'] = $url['formattedType'] ?? $url['type'];
					}
					$prop = $vCard->createProperty('URL', $url['value'], $params);
					$vCard->add($prop);
				}
			}
		}

		// group/label
		if (isset($c['memberships']) && is_array($c['memberships'])) {
			$contactGroupNames = [];
			/** @var array{contactGroupMembership: array{contactGroupResourceName: mixed}} $membership */
			foreach ($c['memberships'] as $membership) {
				if (isset(
					$membership['contactGroupMembership'],
					$membership['contactGroupMembership']['contactGroupResourceName'],
					$groupsById[$membership['contactGroupMembership']['contactGroupResourceName']]
				)) {
					$group = $groupsById[$membership['contactGroupMembership']['contactGroupResourceName']];
					$groupName = $group['formattedName'];
					$contactGroupNames[] = $groupName;
				}
			}
			if (!empty($contactGroupNames)) {
				$prop = $vCard->createProperty('CATEGORIES', $contactGroupNames);
				$vCard->add($prop);
			}
		}

		// photo
		if (isset($c['photos']) && is_array($c['photos'])) {
			foreach ($c['photos'] as $photo) {
				if (isset($photo['url'])) {
					// determine photo type
					$type = 'JPEG';
					if (preg_match('/\.jpg$/i', $photo['url']) || preg_match('/\.jpeg$/i', $photo['url'])) {
						$type = 'JPEG';
					} elseif (preg_match('/\.png$/i', $photo['url'])) {
						$type = 'PNG';
					}
					$photoFile = $this->googleApiService->simpleRequest($userId, $photo['url']);
					if (!isset($photoFile['error'])) {
						// try again to determine photo type from response headers
						if (isset($photoFile['headers'], $photoFile['headers']['Content-Type'])) {
							if (is_array($photoFile['headers']['Content-Type']) && count($photoFile['headers']['Content-Type']) > 0) {
								$contentType = $photoFile['headers']['Content-Type'][0];
							} else {
								$contentType = $photoFile['headers']['Content-Type'];
							}
							if ($contentType === 'image/png') {
								$type = 'PNG';
							} elseif ($contentType === 'image/jpeg') {
								$type = 'JPEG';
							}
						}

						$b64Photo = stripslashes('data:image/' . strtolower($type) . ';base64\,') . base64_encode($photoFile['body']);
						try {
							$prop = $vCard->createProperty(
								'PHOTO',
								$b64Photo,
								[
									'type' => $type,
									// 'encoding' => 'b',
								]
							);
							$vCard->add($prop);
						} catch (Exception|Throwable $ex) {
							$this->logger->warning('Error when setting contact photo "' . '<redacted>' . '" ' . $ex->getMessage(), ['app' => Application::APP_ID]);
						}
						break;
					}
				}
			}
		}

		// address
		if (isset($c['addresses']) && is_array($c['addresses'])) {
			/** @var array{streetAddress?: string, extendedAddress?: string, postalCode?: string, city?: string, type?: string, country?: string, poBox?: string} $address */
			foreach ($c['addresses'] as $address) {
				$streetAddress = $address['streetAddress'] ?? '';
				$extendedAddress = $address['extendedAddress'] ?? '';
				$postalCode = $address['postalCode'] ?? '';
				$city = $address['city'] ?? '';
				$addrType = $address['type'] ?? '';
				$country = $address['country'] ?? '';
				$postOfficeBox = $address['poBox'] ?? '';

				$type = $addrType ? ['TYPE' => strtoupper($addrType)] : null;
				$addrProp = $vCard->createProperty('ADR',
					[0 => $postOfficeBox, 1 => $extendedAddress, 2 => $streetAddress, 3 => $city, 4 => '', 5 => $postalCode, 6 => $country],
					$type
				);
				$vCard->add($addrProp);
			}
		}

		// birthday
		if (isset($c['birthdays']) && is_array($c['birthdays'])) {
			foreach ($c['birthdays'] as $birthday) {
				if (isset($birthday['date'], $birthday['date']['year'], $birthday['date']['month'], $birthday['date']['day'])) {
					$date = new DateTime($birthday['date']['year'] . '-' . $birthday['date']['month'] . '-' . $birthday['date']['day']);
					$strDate = $date->format('Ymd');

					$type = ['VALUE' => 'DATE'];
					$prop = $vCard->createProperty('BDAY', $strDate, $type);
					$vCard->add($prop);
				} elseif (isset($birthday['date'], $birthday['date']['month'], $birthday['date']['day'])) {
					$type = ['VALUE' => 'DATE'];
					$month = $birthday['date']['month'];
					$month = strlen($month) === 2 ? $month : '0' . $month;
					$day = $birthday['date']['day'];
					$day = strlen($day) === 2 ? $day : '0' . $day;
					if (strlen($month) === 2 && strlen($day) === 2) {
						$prop = $vCard->createProperty('BDAY', '--' . $month . $day, $type);
						$vCard->add($prop);
					}
				} elseif (isset($birthday['text']) && is_string($birthday['text'])) {
					$type = ['VALUE' => 'text'];
					$prop = $vCard->createProperty('BDAY', $birthday['text'], $type);
					$vCard->add($prop);
				}
			}
		}

		if (isset($c['nicknames']) && is_array($c['nicknames'])) {
			foreach ($c['nicknames'] as $nick) {
				if (isset($nick['value'])) {
					$prop = $vCard->createProperty('NICKNAME', $nick['value']);
					$vCard->add($prop);
				}
			}
		}

		if (isset($c['emailAddresses']) && is_array($c['emailAddresses'])) {
			/** @var array{value?: string, type?: string} $email */
			foreach ($c['emailAddresses'] as $email) {
				if (isset($email['value'])) {
					$addrType = $email['type'] ?? '';
					$type = $addrType ? ['TYPE' => strtoupper($addrType)] : null;
					$prop = $vCard->createProperty('EMAIL', $email['value'], $type);
					$vCard->add($prop);
				}
			}
		}

		if (isset($c['phoneNumbers']) && is_array($c['phoneNumbers'])) {
			foreach ($c['phoneNumbers'] as $ph) {
				if (isset($ph['value'])) {
					$numberType = str_replace('mobile', 'cell', $ph['type'] ?? '');
					$numberType = str_replace('main', '', $numberType);
					$numberType = $numberType ?: 'home';
					$type = ['TYPE' => strtoupper($numberType)];
					$prop = $vCard->createProperty('TEL', $ph['value'], $type);
					$vCard->add($prop);
				}
			}
		}

		// we just take first org
		if (isset($c['organizations']) && is_array($c['organizations'])) {
			/** @var array{title?: string, name?: string} $org */
			foreach ($c['organizations'] as $org) {
				$name = $org['name'] ?? '';
				if ($name) {
					$prop = $vCard->createProperty('ORG', $name);
					$vCard->add($prop);
				}

				$title = $org['title'] ?? '';
				if ($title) {
					$prop = $vCard->createProperty('TITLE', $title);
					$vCard->add($prop);
				}
				break;
			}
		}
		return $vCard;
	}

	private function recordContactMapRow(int $addressBookId, string $cardUri, string $rawResourceName, array $googleContact, array $ncCard): void {
		$etag = isset($googleContact['etag']) ? (string)$googleContact['etag'] : null;
		$updateTime = $googleContact['metadata']['sources'][0]['updateTime'] ?? null;
		$this->contactMapService->recordMapping(
			$addressBookId,
			$cardUri,
			$rawResourceName,
			$etag,
			is_string($updateTime) ? $updateTime : null,
			isset($ncCard['lastmodified']) ? (int)$ncCard['lastmodified'] : null,
			isset($ncCard['etag']) ? (string)$ncCard['etag'] : null,
			'google',
		);
	}

	/** The People `personFields` mask shared by the full import and the delta pull. */
	private function connectionsPersonFields(): string {
		return implode(',', [
			'addresses', 'biographies', 'birthdays', 'emailAddresses', 'genders',
			'memberships', 'metadata', 'names', 'nicknames', 'organizations',
			'phoneNumbers', 'photos', 'relations', 'residences', 'urls',
		]);
	}

	/**
	 * Incremental People `connections.list` pull (the inbound delta for continuous
	 * sync). With a syncToken, returns only changes since it — INCLUDING deletions
	 * (a deleted person comes back with `metadata.deleted` = true). Without a token
	 * it is a full pull. `nextSyncToken` is only on the final page, so we page to
	 * the end. An expired token is reported via `expired` (the caller full-resyncs).
	 *
	 * @return array{persons?: list<array<string,mixed>>, syncToken?: ?string, expired?: bool, error?: mixed, statusCode?: int}
	 */
	public function getContactChanges(string $userId, ?string $syncToken): array {
		$params = [
			'personFields' => $this->connectionsPersonFields(),
			'pageSize' => 100,
			'requestSyncToken' => 'true',
		];
		if ($syncToken !== null && $syncToken !== '') {
			$params['syncToken'] = $syncToken;
		}
		$persons = [];
		$newSyncToken = null;
		do {
			$result = $this->googleApiService->request($userId, 'v1/people/me/connections', $params, 'GET', 'https://people.googleapis.com/');
			if (isset($result['error'])) {
				$status = isset($result['statusCode']) ? (int)$result['statusCode'] : 0;
				$errStr = is_string($result['error']) ? $result['error'] : (string)json_encode($result['error']);
				// People reports an expired sync token as HTTP 400. The reason code
				// (EXPIRED_SYNC_TOKEN) lives in the error `details`, which Guzzle may
				// truncate out of the exception message; the human message ("Sync
				// token is expired ...") survives the truncation, so match either.
				if ($status === 400 && (str_contains($errStr, 'EXPIRED_SYNC_TOKEN') || stripos($errStr, 'sync token') !== false)) {
					return ['expired' => true];
				}
				return ['error' => $result['error'], 'statusCode' => $status];
			}
			foreach (($result['connections'] ?? []) as $person) {
				if (is_array($person)) {
					$persons[] = $person;
				}
			}
			if (isset($result['nextSyncToken'])) {
				$newSyncToken = (string)$result['nextSyncToken'];
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']) && $result['nextPageToken'] !== '');
		return ['persons' => $persons, 'syncToken' => $newSyncToken];
	}

	/**
	 * Reconcile one address book against Google (inbound, Google -> NC): apply
	 * creates, edits, and DELETIONS from the People delta. Echo-suppressed via the
	 * map's baseline_etag; conflicts resolved last-writer-wins (ties -> Nextcloud).
	 * An expired sync token triggers a full resync (the map prevents duplicates).
	 *
	 * @return array{error?: mixed, nbSeen?: int, nbCreated?: int, nbUpdated?: int, nbDeleted?: int, nbEcho?: int}
	 */
	public function syncAddressBook(string $userId, int $addressBookId): array {
		// Defense-in-depth: only ever sync into the user's own address book (the
		// job is registered via the guarded setSyncContacts, but never write the
		// user's Google contacts into someone else's book even if the job arg is
		// stale or tampered).
		if (!$this->ownsAddressBook($userId, $addressBookId)) {
			$this->logger->warning(
				'Calendar Bridge: refusing to sync contacts into address book ' . $addressBookId . ' not owned by ' . $userId,
				['app' => Application::APP_ID],
			);
			return ['error' => 'not your address book'];
		}
		$tokenKey = 'contacts_sync_token_' . $addressBookId;
		$stored = $this->config->getUserValue($userId, Application::APP_ID, $tokenKey, '');
		$changes = $this->getContactChanges($userId, $stored === '' ? null : $stored);
		if (isset($changes['expired'])) {
			$this->logger->info(
				'Calendar Bridge: contacts sync token expired for address book ' . $addressBookId . '; full resync',
				['app' => Application::APP_ID],
			);
			$this->config->deleteUserValue($userId, Application::APP_ID, $tokenKey);
			$changes = $this->getContactChanges($userId, null);
		}
		if (isset($changes['error'])) {
			return ['error' => $changes['error']];
		}
		$groupsById = $this->getContactGroupsById($userId);
		$created = 0;
		$updated = 0;
		$deleted = 0;
		$echo = 0;
		$seen = 0;
		$applied = 0;
		// Hold the sync token (do NOT advance) if anything is left undone this run —
		// a write/delete failure OR the per-run budget cap — so the unfinished
		// changes are reprocessed next run (already-applied ones then read as ECHO,
		// so it converges without duplicates).
		$advance = true;
		foreach (($changes['persons'] ?? []) as $person) {
			if ($applied >= $this->contactsSyncBudget()) {
				$advance = false;
				break;
			}
			$seen++;
			$raw = isset($person['resourceName']) ? (string)$person['resourceName'] : '';
			if ($raw === '') {
				continue;
			}
			$uri = str_replace('/', '_', $raw);
			$row = $this->contactMapService->getRowForResourceName($addressBookId, $raw);

			// Google-side deletion is authoritative.
			if (($person['metadata']['deleted'] ?? false) === true) {
				if ($row !== null) {
					try {
						$this->cdBackend->deleteCard($addressBookId, $row->getNcCardUri());
						// Drop the map row ONLY after the card delete succeeds — else a
						// failed delete would leave an orphaned card with no map row to
						// find/retry it.
						$this->contactMapService->removeForCard($addressBookId, $row->getNcCardUri());
						$deleted++;
						$applied++;
					} catch (Throwable $e) {
						$advance = false;
						$this->logger->warning(
							'Calendar Bridge: failed to delete contact card ' . $row->getNcCardUri() . ': ' . $e->getMessage(),
							['app' => Application::APP_ID],
						);
					}
				}
				continue;
			}

			$incomingEtag = isset($person['etag']) ? (string)$person['etag'] : null;

			if ($row === null) {
				// New on Google -> create the NC card.
				if ($this->writeCardFromPerson($userId, $addressBookId, $uri, $raw, $person, $groupsById, false)) {
					$created++;
				} else {
					$advance = false;
				}
				$applied++;
				continue;
			}
			// Echo of our own outbound write (C2), or otherwise unchanged -> skip.
			if ($incomingEtag !== null && $incomingEtag === $row->getBaselineEtag()) {
				$echo++;
				continue;
			}
			// Real Google-side edit -> last-writer-wins.
			$existing = $this->cdBackend->getCard($addressBookId, $row->getNcCardUri());
			$ncLastmod = is_array($existing) ? (int)($existing['lastmodified'] ?? 0) : 0;
			$googleTs = self::parseGoogleTimestamp($person['metadata']['sources'][0]['updateTime'] ?? null);
			if ($googleTs > $ncLastmod) {
				if ($this->writeCardFromPerson($userId, $addressBookId, $row->getNcCardUri(), $raw, $person, $groupsById, true)) {
					$updated++;
				} else {
					$advance = false;
				}
				$applied++;
			} else {
				// NC is newer (or a tie -> NC wins). Leave the card; outbound (C2)
				// will push the NC version. Refresh the map's Google baselines so the
				// same change isn't reprocessed every run.
				$this->recordContactMapRow($addressBookId, $row->getNcCardUri(), $raw, $person, is_array($existing) ? $existing : []);
			}
		}
		// Advance the sync token only if every change in this delta was handled
		// (no failures, not budget-capped) — otherwise reprocess from the same
		// token next run. Matches the calendar reconciler's hold-on-failure rule.
		if ($advance && isset($changes['syncToken']) && $changes['syncToken'] !== null) {
			$this->config->setUserValue($userId, Application::APP_ID, $tokenKey, (string)$changes['syncToken']);
		}
		$this->logger->info(
			'Calendar Bridge: contacts sync address book ' . $addressBookId . ' — created=' . $created
				. ' updated=' . $updated . ' deleted=' . $deleted . ' echo=' . $echo . ' seen=' . $seen
				. ($advance ? '' : ' (token held; will resume next run)'),
			['app' => Application::APP_ID],
		);
		return ['nbSeen' => $seen, 'nbCreated' => $created, 'nbUpdated' => $updated, 'nbDeleted' => $deleted, 'nbEcho' => $echo, 'advanced' => $advance];
	}

	/** Max contact writes (create/update/delete) applied per sync run; cap-and-drain holds the token and resumes next run. Protected for test override. */
	protected function contactsSyncBudget(): int {
		return 1000;
	}

	/**
	 * Create or update one NC card from a Google person, then record the map row.
	 * Returns false (and logs) if the card write failed.
	 *
	 * @param array<string,mixed> $person
	 * @param array<string,string> $groupsById
	 */
	private function writeCardFromPerson(string $userId, int $addressBookId, string $cardUri, string $rawResourceName, array $person, array $groupsById, bool $isUpdate): bool {
		try {
			$vCard = $this->buildVCardFromPerson($userId, $person, $groupsById);
			if ($isUpdate) {
				$this->cdBackend->updateCard($addressBookId, $cardUri, $vCard->serialize());
			} else {
				$this->cdBackend->createCard($addressBookId, $cardUri, $vCard->serialize());
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: failed to write contact card ' . $cardUri . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return false;
		}
		$fresh = $this->cdBackend->getCard($addressBookId, $cardUri);
		$this->recordContactMapRow($addressBookId, $cardUri, $rawResourceName, $person, is_array($fresh) ? $fresh : []);
		return true;
	}

	private static function parseGoogleTimestamp(?string $updateTime): int {
		if ($updateTime === null || $updateTime === '') {
			return 0;
		}
		try {
			return (new DateTime($updateTime))->getTimestamp();
		} catch (Exception|Throwable $e) {
			return 0;
		}
	}

	/** Register (idempotently) the background contacts-sync job for an address book. */
	public function registerSyncContacts(string $userId, int $addressBookId): void {
		if ($this->isSyncContactsJobRegistered($userId, $addressBookId)) {
			return;
		}
		$this->jobList->add(SyncContactsJob::class, ['user_id' => $userId, 'addressbook_id' => $addressBookId]);
	}

	/** Remove the background contacts-sync job for an address book + drop its sync token. */
	public function unregisterSyncContacts(string $userId, int $addressBookId): void {
		foreach ($this->jobList->getJobsIterator(SyncContactsJob::class, null, 0) as $job) {
			$args = $job->getArgument();
			if (($args['user_id'] ?? null) === $userId && (int)($args['addressbook_id'] ?? -1) === $addressBookId) {
				$this->jobList->remove($job, $args);
				break;
			}
		}
		$this->config->deleteUserValue($userId, Application::APP_ID, 'contacts_sync_token_' . $addressBookId);
	}

	public function isSyncContactsJobRegistered(string $userId, int $addressBookId): bool {
		foreach ($this->jobList->getJobsIterator(SyncContactsJob::class, null, 0) as $job) {
			$args = $job->getArgument();
			if (($args['user_id'] ?? null) === $userId && (int)($args['addressbook_id'] ?? -1) === $addressBookId) {
				return true;
			}
		}
		return false;
	}

	/** Turn continuous contacts sync on/off for one address book. */
	public function setSyncContacts(string $userId, int $addressBookId, bool $enabled): void {
		if ($enabled) {
			if (!$this->ownsAddressBook($userId, $addressBookId)) {
				$this->logger->warning(
					'Calendar Bridge: refusing to enable contacts sync for address book ' . $addressBookId . ' not owned by ' . $userId,
					['app' => Application::APP_ID],
				);
				return;
			}
			$this->registerSyncContacts($userId, $addressBookId);
		} else {
			// Symmetric with the enable path. The controller only ever passes the
			// authenticated user's own id (so unregister can touch only their own
			// job/config today), but the guard keeps the gate honest against future
			// callers and refuses to clear sync state on a book the user can't access.
			if (!$this->ownsAddressBook($userId, $addressBookId)) {
				$this->logger->warning(
					'Calendar Bridge: refusing to disable contacts sync for address book ' . $addressBookId . ' not owned by ' . $userId,
					['app' => Application::APP_ID],
				);
				return;
			}
			$this->unregisterSyncContacts($userId, $addressBookId);
		}
	}

	/**
	 * Whether the user owns (and can write) this address book — the access-control
	 * gate for the sync/dedupe entry points so a crafted addressBookId cannot
	 * touch another user's contacts.
	 */
	private function ownsAddressBook(string $userId, int $addressBookId): bool {
		$principal = 'principals/users/' . $userId;
		foreach ($this->cdBackend->getAddressBooksForUser($principal) as $ab) {
			if ((int)($ab['id'] ?? 0) !== $addressBookId) {
				continue;
			}
			if ((string)($ab['uri'] ?? '') === 'system') {
				return false;
			}
			$owner = (string)($ab['{http://owncloud.org/ns}owner-principal'] ?? '');
			return $owner === '' || $owner === $principal;
		}
		return false;
	}

	/**
	 * The user's OWN address books, each with its current "Sync contacts" state —
	 * drives the toggle UI. Uses the CardDAV backend (session-independent, by
	 * principal) rather than the session-scoped IManager; skips the system address
	 * book and ones shared in from another user.
	 *
	 * @return list<array{id:int, displayname:string, uri:string, isSyncEnabled:bool}>
	 */
	public function getOwnAddressBooks(string $userId): array {
		$principal = 'principals/users/' . $userId;
		$out = [];
		foreach ($this->cdBackend->getAddressBooksForUser($principal) as $ab) {
			$uri = (string)($ab['uri'] ?? '');
			if ($uri === '' || $uri === 'system') {
				continue;
			}
			$owner = (string)($ab['{http://owncloud.org/ns}owner-principal'] ?? '');
			if ($owner !== '' && $owner !== $principal) {
				// shared in from another user — treat as read-only, not a sync target
				continue;
			}
			$id = (int)($ab['id'] ?? 0);
			$out[] = [
				'id' => $id,
				'displayname' => (string)($ab['{DAV:}displayname'] ?? $uri),
				'uri' => $uri,
				'isSyncEnabled' => $this->isSyncContactsJobRegistered($userId, $id),
			];
		}
		return $out;
	}

	/**
	 * One-shot de-duplication for an address book (Track 2 C1). The C0 identity
	 * map already PREVENTS new within-address-book duplicates; this collapses
	 * PRE-EXISTING ones — most realistically cards that lived in the address book
	 * before sync was enabled, which the sync then re-created as a second
	 * (Google-mapped) card.
	 *
	 * CONSERVATIVE keep-one rule (per docs/CONTACTS_SYNC.md §5): remove only an
	 * UNMAPPED "stray" card that high-confidence-matches EXACTLY ONE mapped
	 * (Google-synced) card — same normalized full name AND at least one shared
	 * email. It NEVER deletes a mapped card, NEVER acts on a low-confidence match
	 * (no name or no shared email), and SKIPS ambiguous matches (a stray matching
	 * two mapped cards). Purely-unmapped duplicate groups are left untouched.
	 * Idempotent: a second run finds nothing. Deleted cards go to the Contacts
	 * trash (recoverable).
	 *
	 * @return array{scanned:int, removed:int, ambiguous:int}
	 */
	public function dedupeAddressBook(string $userId, int $addressBookId): array {
		$empty = ['scanned' => 0, 'removed' => 0, 'ambiguous' => 0];
		if (!$this->ownsAddressBook($userId, $addressBookId)) {
			$this->logger->warning(
				'Calendar Bridge: refusing to dedupe address book ' . $addressBookId . ' not owned by ' . $userId,
				['app' => Application::APP_ID],
			);
			return $empty + ['error' => 'not your address book'];
		}
		// Load the whole map for this address book ONCE and fail closed on error:
		// a per-card lookup that returned null on a transient DB error would
		// misclassify a synced card as an unmapped stray and could delete it.
		$mappedUris = $this->contactMapService->getMappedCardUris($addressBookId);
		if ($mappedUris === null) {
			$this->logger->warning(
				'Calendar Bridge: dedupe aborted — could not load contact map for address book ' . $addressBookId,
				['app' => Application::APP_ID],
			);
			return $empty + ['error' => 'could not load contact map'];
		}
		$mapped = [];
		$unmapped = [];
		$scanned = 0;
		foreach ($this->cdBackend->getCards($addressBookId) as $card) {
			$uri = (string)($card['uri'] ?? '');
			if ($uri === '') {
				continue;
			}
			$scanned++;
			[$fn, $emails] = $this->cardIdentity((string)($card['carddata'] ?? ''));
			if ($fn === '') {
				continue; // cannot match safely without a name
			}
			$entry = ['uri' => $uri, 'fn' => $fn, 'emails' => $emails];
			if (isset($mappedUris[$uri])) {
				$mapped[] = $entry;
			} else {
				$unmapped[] = $entry;
			}
		}
		$removed = 0;
		$ambiguous = 0;
		foreach ($unmapped as $u) {
			if (count($u['emails']) === 0) {
				continue; // need a shared email for a high-confidence match
			}
			$matches = 0;
			foreach ($mapped as $m) {
				if ($m['fn'] === $u['fn'] && count(array_intersect_key($u['emails'], $m['emails'])) > 0) {
					$matches++;
				}
			}
			if ($matches === 0) {
				continue; // no synced canonical to fold into — leave it alone
			}
			if ($matches > 1) {
				$ambiguous++; // matches several distinct synced contacts — don't guess
				continue;
			}
			try {
				$this->cdBackend->deleteCard($addressBookId, $u['uri']);
				$removed++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Calendar Bridge: dedupe failed to delete card ' . $u['uri'] . ': ' . $e->getMessage(),
					['app' => Application::APP_ID],
				);
			}
		}
		$this->logger->info(
			'Calendar Bridge: dedupe address book ' . $addressBookId . ' — scanned=' . $scanned
				. ' removed=' . $removed . ' ambiguous=' . $ambiguous,
			['app' => Application::APP_ID],
		);
		return ['scanned' => $scanned, 'removed' => $removed, 'ambiguous' => $ambiguous];
	}

	/**
	 * The normalized full name + lowercased email set of a vCard — the matching
	 * key for de-duplication. Unparseable cards yield an empty name (no match).
	 *
	 * @return array{0:string, 1:array<string,true>}
	 */
	private function cardIdentity(string $carddata): array {
		$fn = '';
		$emails = [];
		try {
			$vcard = Reader::read($carddata);
			$fn = (string)preg_replace('/\s+/', ' ', strtolower(trim((string)($vcard->FN ?? ''))));
			foreach (($vcard->EMAIL ?? []) as $email) {
				$val = strtolower(trim((string)$email));
				if ($val !== '') {
					$emails[$val] = true;
				}
			}
		} catch (Throwable $e) {
			// unparseable -> empty identity, which matches nothing
		}
		return [$fn, $emails];
	}

	/**
	 * Whether the user granted the read-write `contacts` scope (gates all outbound
	 * NC -> Google contact writes; widen-and-gate — dormant until re-consent).
	 */
	public function hasContactsWriteScope(string $userId): bool {
		$scopes = json_decode($this->config->getUserValue($userId, Application::APP_ID, 'user_scopes', '{}'), true);
		return is_array($scopes) && ($scopes['can_write_contacts'] ?? 0) === 1;
	}

	/** Max outbound contact creates per run (cap-and-drain). Protected for test override. */
	protected function contactsCreateBudget(): int {
		return 50;
	}

	/**
	 * Reconcile one address book OUTBOUND (Nextcloud -> Google): push genuinely
	 * NC-origin new cards to Google as contacts (Track 2 C2a — CREATE only; edits
	 * and deletes are classified + logged but deferred to a later phase). Gated on
	 * the read-write contacts scope AND address-book ownership. Echo-suppressed via
	 * the map's nc_etag baseline; cap-and-drain with hold-token-on-failure. Fully
	 * defensive — runs after the (committed) inbound pass and must never break it.
	 *
	 * @return array{skipped?:string, error?:mixed, baselined?:string, rebaselined?:string, created?:int, advanced?:bool, counts?:array<string,int>}
	 */
	public function reconcileOutbound(string $userId, int $addressBookId): array {
		try {
			if (!$this->hasContactsWriteScope($userId)) {
				return ['skipped' => 'no_write_scope'];
			}
			if (!$this->ownsAddressBook($userId, $addressBookId)) {
				return ['error' => 'not your address book'];
			}
			$tokenKey = 'contacts_nc_token_' . $addressBookId;
			$stored = $this->config->getUserValue($userId, Application::APP_ID, $tokenKey, '');
			// First run: baseline at the current head and push NOTHING. Every card
			// already in a sync-enabled address book is Google-origin (the inbound
			// pass created it); there is no NC-origin bootstrap to bulk-push.
			if ($stored === '') {
				$head = $this->cdBackend->getChangesForAddressBook($addressBookId, '', 1);
				$token = (string)(($head['syncToken'] ?? '') ?: '');
				$this->config->setUserValue($userId, Application::APP_ID, $tokenKey, $token);
				return ['baselined' => $token];
			}
			$changes = $this->cdBackend->getChangesForAddressBook($addressBookId, $stored, 1);
			// Expired/unknown token (NC purged the change log) or a head lower than
			// the stored token (address book recreated) -> re-baseline at head. The
			// gap's changes are unrecoverable, but the map prevents duplicates.
			if ($changes === null || (int)($changes['syncToken'] ?? 0) < (int)$stored) {
				$fresh = $this->cdBackend->getChangesForAddressBook($addressBookId, '', 1);
				$token = (string)(($fresh['syncToken'] ?? '') ?: '');
				$this->config->setUserValue($userId, Application::APP_ID, $tokenKey, $token);
				$this->logger->warning(
					'Calendar Bridge: outbound contacts change token expired/stale for address book ' . $addressBookId . ', re-baselined',
					['app' => Application::APP_ID],
				);
				return ['rebaselined' => $token];
			}
			// Load the whole map ONCE and FAIL CLOSED: classifying create-vs-not by a
			// per-card lookup that returned null on a transient DB error would create
			// a DUPLICATE Google contact (a mapped card mis-seen as LOCAL_NEW).
			$mappedUris = $this->contactMapService->getMappedCardUris($addressBookId);
			if ($mappedUris === null) {
				return ['error' => 'could not load contact map'];
			}
			$advance = true;
			// An orphan (created in Google but unmapped) FORCES the token forward even
			// if a later card would otherwise hold it: replaying the batch would
			// re-POST the orphan and duplicate it. Orphan-over-duplicate.
			$mustAdvance = false;
			$created = 0;
			$counts = [];
			foreach (['added', 'modified'] as $type) {
				foreach (($changes[$type] ?? []) as $uri) {
					$uri = (string)$uri;
					if (!isset($mappedUris[$uri])) {
						$cls = self::LOCAL_NEW;
					} else {
						// Mapped card: edit-vs-echo (log-only in C2a; nc_etag read only here).
						// mapRowExists is TRUE because $uri is in $mappedUris (the bulk,
						// fail-closed load) — NOT because $row !== null. A transient
						// per-row read returning null must NOT downgrade to LOCAL_NEW
						// (that would re-create a mapped card == a duplicate).
						$row = $this->contactMapService->getRowForCard($addressBookId, $uri);
						$card = $this->cdBackend->getCard($addressBookId, $uri);
						if (!is_array($card)) {
							// Couldn't read a card we know is mapped -> classify on stale
							// data would be wrong; hold the token and retry next run
							// (safe: a mapped card can never be re-created here).
							$advance = false;
							continue;
						}
						$currentEtag = isset($card['etag']) ? (string)$card['etag'] : null;
						// NB (C2b): a card the INBOUND pass just wrote also surfaces here
						// (origin='google'); it is mapped, so it can only be ECHO/EDIT,
						// never LOCAL_NEW (no duplicate). classifyOutbound ignores the
						// origin column today; C2b must consult it before PUSHING edits
						// so an inbound write is never echoed back to Google.
						$cls = self::classifyOutbound($type, true, $row?->getNcEtag(), $currentEtag);
					}
					$counts[$cls] = ($counts[$cls] ?? 0) + 1;
					if ($cls === self::LOCAL_NEW) {
						if ($created >= $this->contactsCreateBudget()) {
							$advance = false; // cap-and-drain: hold token, drain next run
							break 2;
						}
						$status = $this->createNcContactInGoogle($userId, $addressBookId, $uri);
						if ($status === self::ERROR) {
							$advance = false;
						} elseif ($status === self::CREATED_ORPHAN) {
							$mustAdvance = true;
						}
						$created++;
						$this->logger->info(
							'Calendar Bridge: outbound contact create ' . $uri . ' -> ' . $status,
							['app' => Application::APP_ID],
						);
					} elseif ($cls !== self::ECHO) {
						// LOCAL_EDIT / LOCAL_EDIT_INDETERMINATE -> a later phase (C2b).
						$this->logger->info(
							'Calendar Bridge: outbound contact ' . $cls . ' ' . $uri . ' (deferred)',
							['app' => Application::APP_ID],
						);
					}
				}
			}
			foreach (($changes['deleted'] ?? []) as $uri) {
				$uri = (string)$uri;
				$cls = isset($mappedUris[$uri]) ? self::LOCAL_DELETE : self::ECHO_DELETE;
				$counts[$cls] = ($counts[$cls] ?? 0) + 1;
				if ($cls === self::LOCAL_DELETE) {
					$this->logger->info(
						'Calendar Bridge: outbound contact delete ' . $uri . ' (deferred)',
						['app' => Application::APP_ID],
					);
				}
			}
			// Advance the NC change token if every change was handled, OR if an
			// orphan forces it (never replay a created-but-unmapped contact). The
			// rare cost of $mustAdvance overriding a hold: a same-batch transient
			// failure isn't retried until its card next changes — strictly safer
			// than duplicating the orphan.
			$advanced = $advance || $mustAdvance;
			if ($advanced) {
				$this->config->setUserValue($userId, Application::APP_ID, $tokenKey, (string)($changes['syncToken'] ?? $stored));
			}
			return ['created' => $created, 'advanced' => $advanced, 'counts' => $counts];
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: outbound contacts reconcile failed for address book ' . $addressBookId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Pure classification of one NC card change for outbound sync. The contacts
	 * analog of OutboundReconcileService::classifyChange. See docs/CONTACTS_SYNC.md §3.
	 */
	public static function classifyOutbound(string $changeType, bool $mapRowExists, ?string $mapNcEtag, ?string $currentEtag): string {
		if ($changeType === 'deleted') {
			// Our own inbound delete already dropped the map row, so a missing row
			// means this delete is our echo.
			return $mapRowExists ? self::LOCAL_DELETE : self::ECHO_DELETE;
		}
		if (!$mapRowExists) {
			return self::LOCAL_NEW;
		}
		if ($mapNcEtag === null) {
			return self::LOCAL_EDIT_INDETERMINATE;
		}
		// The card's current etag equal to the baseline we recorded on our last
		// write == our own inbound echo; different == a genuine user edit.
		return ($currentEtag !== null && $currentEtag === $mapNcEtag) ? self::ECHO : self::LOCAL_EDIT;
	}

	/**
	 * Create a Google contact from an NC card, then record the map row (capturing
	 * the create's NC echo baseline). The contacts twin of
	 * OutboundWriteService::createLocalEventInGoogle. Returns a status constant.
	 */
	private function createNcContactInGoogle(string $userId, int $addressBookId, string $cardUri): string {
		$card = $this->cdBackend->getCard($addressBookId, $cardUri);
		if (!is_array($card)) {
			return self::SKIPPED_GONE;
		}
		try {
			$vcard = Reader::read((string)($card['carddata'] ?? ''));
		} catch (Throwable $e) {
			$this->logger->warning(
				'Calendar Bridge: outbound create — unparseable card ' . $cardUri . ': ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return self::SKIPPED_REJECTED;
		}
		$person = self::buildPersonFromVCard($vcard);
		if ($person === []) {
			// Nothing Google will accept (no name/email/phone/…). Terminal, not a
			// retry — re-POSTing an always-rejected body would wedge the token.
			return self::SKIPPED_REJECTED;
		}
		// personFields is a QUERY param; the body is the raw Person object.
		$endpoint = 'v1/people:createContact?personFields=' . rawurlencode($this->connectionsPersonFields());
		$result = $this->googleApiService->request($userId, $endpoint, $person, 'POST', 'https://people.googleapis.com/');
		if (isset($result['error'])) {
			$status = isset($result['statusCode']) ? (int)$result['statusCode'] : null;
			$errStr = is_string($result['error']) ? $result['error'] : (string)json_encode($result['error']);
			if (self::isPermanentBodyRejection($status)) {
				$this->logger->warning(
					'Calendar Bridge: outbound create permanently rejected (' . $status . ') for ' . $cardUri . '; left one-way: ' . $errStr,
					['app' => Application::APP_ID],
				);
				return self::SKIPPED_REJECTED;
			}
			$this->logger->warning(
				'Calendar Bridge: outbound create failed for ' . $cardUri . ' (status ' . ($status ?? '?') . '): ' . $errStr,
				['app' => Application::APP_ID],
			);
			return self::ERROR;
		}
		$resourceName = (string)($result['resourceName'] ?? '');
		if ($resourceName === '') {
			$this->logger->warning(
				'Calendar Bridge: outbound create returned no resourceName for ' . $cardUri,
				['app' => Application::APP_ID],
			);
			return self::ERROR;
		}
		$etag = isset($result['etag']) ? (string)$result['etag'] : null;
		if ($etag === null) {
			// Documented to always be returned; if it ever isn't, the row is still
			// recorded (resourceName maps it — not recording would orphan it). The
			// null Google baseline only costs one redundant inbound apply (which then
			// sets the baseline); outbound echo suppression rests on nc_etag below.
			$this->logger->warning(
				'Calendar Bridge: outbound create for ' . $cardUri . ' returned no etag; mapping with a null Google baseline',
				['app' => Application::APP_ID],
			);
		}
		// Guard each level: a thin/absent metadata would otherwise raise E_WARNING on
		// the intermediate keys (?? only guards the last), and a strict error handler
		// could turn that into a throw.
		$sources = (is_array($result['metadata'] ?? null) && is_array($result['metadata']['sources'] ?? null))
			? $result['metadata']['sources'] : [];
		$updateTime = (isset($sources[0]) && is_array($sources[0])) ? ($sources[0]['updateTime'] ?? null) : null;
		// Re-read the card to capture its CURRENT etag as the nc echo baseline (the
		// single load-bearing line: it makes a held-token replay classify ECHO, not
		// a spurious edit, so no duplicate Google contact).
		$fresh = $this->cdBackend->getCard($addressBookId, $cardUri);
		$recorded = $this->contactMapService->recordMapping(
			$addressBookId,
			$cardUri,
			$resourceName,
			$etag,
			is_string($updateTime) ? $updateTime : null,
			is_array($fresh) ? (int)($fresh['lastmodified'] ?? 0) : null,
			is_array($fresh) ? (string)($fresh['etag'] ?? '') : null,
			'nc',
		);
		if (!$recorded) {
			// The contact IS in Google but we couldn't map it. Re-POSTing would
			// duplicate it (no client id), so the caller must advance past it; we
			// surface the orphan loudly for operators.
			$this->logger->error(
				'Calendar Bridge: created Google contact ' . $resourceName . ' for ' . $cardUri
					. ' but FAILED to record its mapping — orphan (will not be re-created or further synced)',
				['app' => Application::APP_ID],
			);
			return self::CREATED_ORPHAN;
		}
		return self::CREATED;
	}

	private static function isPermanentBodyRejection(?int $status): bool {
		return $status === 400 || $status === 422;
	}

	/**
	 * Build a Google People `Person` body from an NC vCard — the reverse of
	 * {@see buildVCardFromPerson}. Emits only the fields C2 round-trips (names,
	 * emails, phones, addresses, organizations, note, urls); never emits
	 * etag/metadata/resourceName/primary/output-only fields. Returns [] when
	 * nothing mappable is present (caller treats that as a terminal skip).
	 *
	 * @return array<string,mixed>
	 */
	public static function buildPersonFromVCard(VCard $vcard): array {
		$person = [];

		$name = [];
		if (isset($vcard->N)) {
			$parts = $vcard->N->getParts(); // [family, given, additional, prefix, suffix]
			if (($parts[1] ?? '') !== '') {
				$name['givenName'] = (string)$parts[1];
			}
			if (($parts[0] ?? '') !== '') {
				$name['familyName'] = (string)$parts[0];
			}
			if (($parts[2] ?? '') !== '') {
				$name['middleName'] = (string)$parts[2];
			}
			if (($parts[3] ?? '') !== '') {
				$name['honorificPrefix'] = (string)$parts[3];
			}
			if (($parts[4] ?? '') !== '') {
				$name['honorificSuffix'] = (string)$parts[4];
			}
		}
		if (isset($vcard->FN) && (string)$vcard->FN !== '') {
			$name['unstructuredName'] = (string)$vcard->FN;
		}
		if ($name !== []) {
			$person['names'] = [$name];
		}

		foreach (($vcard->EMAIL ?? []) as $email) {
			$val = trim((string)$email);
			if ($val === '') {
				continue;
			}
			$entry = ['value' => $val];
			$type = strtolower(trim((string)($email['TYPE'] ?? '')));
			if ($type !== '') {
				$entry['type'] = $type;
			}
			$person['emailAddresses'][] = $entry;
		}

		foreach (($vcard->TEL ?? []) as $tel) {
			$val = trim((string)$tel);
			if ($val === '') {
				continue;
			}
			$entry = ['value' => $val];
			$type = str_replace('cell', 'mobile', strtolower(trim((string)($tel['TYPE'] ?? '')))); // reverse of the importer's mobile->cell
			if ($type !== '') {
				$entry['type'] = $type;
			}
			$person['phoneNumbers'][] = $entry;
		}

		foreach (($vcard->ADR ?? []) as $adr) {
			$p = $adr->getParts(); // [poBox, extended, street, city, region, postalCode, country]
			$entry = array_filter([
				'poBox' => (string)($p[0] ?? ''),
				'extendedAddress' => (string)($p[1] ?? ''),
				'streetAddress' => (string)($p[2] ?? ''),
				'city' => (string)($p[3] ?? ''),
				'region' => (string)($p[4] ?? ''),
				'postalCode' => (string)($p[5] ?? ''),
				'country' => (string)($p[6] ?? ''),
			], static fn ($v) => $v !== '');
			if ($entry === []) {
				continue;
			}
			$type = strtolower(trim((string)($adr['TYPE'] ?? '')));
			if ($type !== '') {
				$entry['type'] = $type;
			}
			$person['addresses'][] = $entry;
		}

		$org = [];
		if (isset($vcard->ORG) && (string)$vcard->ORG !== '') {
			$org['name'] = (string)$vcard->ORG;
		}
		if (isset($vcard->TITLE) && (string)$vcard->TITLE !== '') {
			$org['title'] = (string)$vcard->TITLE;
		}
		if ($org !== []) {
			$person['organizations'] = [$org];
		}

		if (isset($vcard->NOTE) && (string)$vcard->NOTE !== '') {
			$person['biographies'] = [['value' => (string)$vcard->NOTE, 'contentType' => 'TEXT_PLAIN']];
		}

		foreach (($vcard->URL ?? []) as $url) {
			$val = trim((string)$url);
			if ($val === '') {
				continue;
			}
			$entry = ['value' => $val];
			$type = strtolower(trim((string)($url['TYPE'] ?? '')));
			if ($type !== '') {
				$entry['type'] = $type;
			}
			$person['urls'][] = $entry;
		}

		return $person;
	}
}
