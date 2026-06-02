<?php

/**
 * Nextcloud - google
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\CalendarBridge\Controller;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Service\GoogleCalendarAPIService;
use OCA\CalendarBridge\Service\GoogleContactsAPIService;
use OCA\CalendarBridge\Service\OutboundReconcileService;
use OCA\CalendarBridge\Service\SecretService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class GoogleAPIController extends Controller {

	private string $accessToken;

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private GoogleContactsAPIService $googleContactsAPIService,
		private GoogleCalendarAPIService $googleCalendarAPIService,
		private OutboundReconcileService $outboundReconcileService,
		private ?string $userId,
		private SecretService $secretService,
	) {
		parent::__construct($appName, $request);
		$this->accessToken = $this->userId !== null ? $this->secretService->getEncryptedUserValue($this->userId, 'token') : '';
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getContactNumber(): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], 400);
		}
		/** @var array{error?:string} $result */
		$result = $this->googleContactsAPIService->getContactNumber($this->userId);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getCalendarList(): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], 400);
		}
		/** @var array{error?:array{id: string}} $result */
		$result = $this->googleCalendarAPIService->getCalendarList($this->userId);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			foreach ($result as $key => $cal) {
				$isJobRegistered = $this->googleCalendarAPIService->
					isJobRegisteredForCalendar($this->userId, $cal["id"]);
				$result[$key]["isJobRegistered"] = $isJobRegistered;
				$result[$key]["isTwoWayEnabled"] = $this->outboundReconcileService->isTwoWayEnabled($this->userId, $cal["id"]);
			}
			$response = new DataResponse($result);
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return DataResponse
	 */
	public function importCalendar(string $calId, string $calName, ?string $color = null): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], 400);
		}
		/** @var array{error?:string} $result */
		$result = $this->googleCalendarAPIService->importCalendar($this->userId, $calId, $calName, $color);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return DataResponse
	 */
	public function registerSyncCalendar(string $calId, string $calName, ?string $color = null): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$this->googleCalendarAPIService->registerSyncCalendar(
			$this->userId, $calId, $calName, $color);
		$response = new DataResponse("OK", 200);
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return DataResponse
	 */
	public function unregisterSyncCalendar(string $calId): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$this->googleCalendarAPIService->unregisterSyncCalendar(
			$this->userId, $calId);
		$response = new DataResponse("OK", 200);
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $calId
	 * @param string $calName
	 * @param bool $desiredState
	 * @param ?string $color
	 * @return DataResponse
	 */
	public function setSyncCalendar(string $calId, bool $desiredState, string $calName, ?string $color = null): DataResponse {


		if ($this->accessToken === '') {
			return new DataResponse('', 400);
		}

		if (true == $desiredState) {
			return $this->registerSyncCalendar($calId, $calName, $color);
		} else {
			return $this->unregisterSyncCalendar($calId);
		}
	}

	/**
	 * Turn two-way (Nextcloud -> Google) sync on/off for one calendar.
	 * Enabling is rejected unless the user granted the read-write
	 * calendar.events scope (defense in depth — the UI also hides the toggle).
	 *
	 * @NoAdminRequired
	 *
	 * @param string $calId
	 * @param bool $desiredState
	 * @return DataResponse
	 */
	public function setTwoWaySync(string $calId, bool $desiredState): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		if ($desiredState && !$this->outboundReconcileService->hasWriteScope($this->userId)) {
			return new DataResponse(
				['error' => 'The read-write calendar scope has not been granted; reconnect your Google account to enable two-way sync.'],
				403,
			);
		}
		$this->outboundReconcileService->setTwoWayEnabled($this->userId, $calId, $desiredState);
		return new DataResponse(['calId' => $calId, 'isTwoWayEnabled' => $desiredState]);
	}

	// ============ Calendar-level NC -> Google sync (P-c) ============

	/**
	 * List the user's own Nextcloud calendars eligible for NC -> Google linking.
	 *
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getNcCalendarList(): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], 400);
		}
		return new DataResponse($this->googleCalendarAPIService->getOwnNcCalendars($this->userId));
	}

	/**
	 * Create a Google calendar from a Nextcloud one and enable two-way sync.
	 * Requires BOTH the calendar.app.created (create) and calendar.events (write)
	 * scopes — defense in depth (the UI also gates the button).
	 *
	 * @NoAdminRequired
	 *
	 * @param string $ncCalUri
	 * @return DataResponse
	 */
	public function syncNcCalendarToGoogle(string $ncCalUri): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		if (!$this->hasCreateScope() || !$this->outboundReconcileService->hasWriteScope($this->userId)) {
			return new DataResponse(
				['error' => 'The calendar-create and read-write scopes are not both granted; reconnect your Google account to enable this.'],
				403,
			);
		}
		$result = $this->googleCalendarAPIService->linkNcCalendarToGoogle($this->userId, $ncCalUri);
		return new DataResponse($result, isset($result['error']) ? 400 : 200);
	}

	/**
	 * Disconnect a linked pair (stop syncing; KEEP both calendars).
	 *
	 * @NoAdminRequired
	 *
	 * @param string $googleCalId
	 * @return DataResponse
	 */
	public function disconnectNcCalendar(string $googleCalId): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$this->googleCalendarAPIService->disconnectNcCalendar($this->userId, $googleCalId);
		return new DataResponse(['disconnected' => true]);
	}

	/**
	 * DESTRUCTIVE: delete BOTH the Nextcloud and Google calendar of a linked pair.
	 * The caller (UI) must have confirmed.
	 *
	 * @NoAdminRequired
	 *
	 * @param string $ncCalUri
	 * @return DataResponse
	 */
	public function deleteLinkedCalendars(string $ncCalUri): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse('', 400);
		}
		$result = $this->googleCalendarAPIService->deleteLinkedCalendars($this->userId, $ncCalUri);
		return new DataResponse($result, isset($result['error']) ? 400 : 200);
	}

	/** Whether the user granted the calendar.app.created scope. */
	private function hasCreateScope(): bool {
		if ($this->userId === null) {
			return false;
		}
		$scopes = json_decode($this->config->getUserValue($this->userId, Application::APP_ID, 'user_scopes', '{}'), true);
		return is_array($scopes) && ($scopes['can_create_calendar'] ?? 0) === 1;
	}

	/**
	 * @return DataResponse
	 */
	public function resetRegisteredSyncCalendar(): DataResponse {
		if (!$this->userSession->isLoggedIn() || !$this->groupManager->isAdmin($this->userSession->getUser()->getUID())) {
			return new DataResponse('You must be a server admin to perform this action.', 401);
		}

		$this->googleCalendarAPIService->resetRegisteredSyncCalendar();
		return new DataResponse('OK', 200);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param ?string $uri
	 * @param int $key
	 * @param ?string $newAddressBookName
	 * @return DataResponse
	 */
	public function importContacts(?string $uri = '', int $key = 0, ?string $newAddressBookName = ''): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], 400);
		}
		/** @var array{error?:string} $result */
		$result = $this->googleContactsAPIService->importContacts($this->userId, $uri, $key, $newAddressBookName);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}
}
