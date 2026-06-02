<?php

namespace OCA\CalendarBridge\Settings;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Service\GoogleAPIService;
use OCA\CalendarBridge\Service\SecretService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;

class Personal implements ISettings {

	public function __construct(
		private IConfig $config,
		private IInitialState $initialStateService,
		private GoogleAPIService $googleAPIService,
		private ?string $userId,
		private SecretService $secretService,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		if ($this->userId === null) {
			return new TemplateResponse(Application::APP_ID, 'personalSettings');
		}
		$userName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_name');
		$considerAllEvents = $this->config->getUserValue($this->userId, Application::APP_ID, 'consider_all_events', '1') === '1';
		$considerSharedAlbums = $this->config->getUserValue($this->userId, Application::APP_ID, 'consider_shared_albums', '0') === '1';
		$considerOtherContacts = $this->config->getUserValue($this->userId, Application::APP_ID, 'consider_other_contacts', '0') === '1';

		// for OAuth
		$clientID = $this->secretService->getEncryptedAppValue('client_id');
		$clientSecret = $this->secretService->getEncryptedAppValue('client_secret') !== '';
		$usePopup = $this->config->getAppValue(Application::APP_ID, 'use_popup', '0');

		// make a request to potentially refresh the token before the settings page is loaded
		$accessToken = $this->secretService->getEncryptedUserValue($this->userId, 'token');
		if ($accessToken) {
			$this->googleAPIService->request($this->userId, 'oauth2/v1/userinfo', ['alt' => 'json']);
		}

		// Get scopes of user
		$userScopesString = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_scopes', '{}');
		/** @var bool|null|array $userScopes */
		$userScopes = json_decode($userScopesString, true);
		if (!is_array($userScopes)) {
			$userScopes = ['nothing' => 'nothing'];
		}

		$userConfig = [
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'use_popup' => ($usePopup === '1'),
			'user_name' => $userName,
			'consider_all_events' => $considerAllEvents,
			'consider_shared_albums' => $considerSharedAlbums,
			'consider_other_contacts' => $considerOtherContacts,
			'user_scopes' => $userScopes,
		];
		$this->initialStateService->provideInitialState('user-config', $userConfig);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'outside_provider_calendar_bridge';
	}

	public function getPriority(): int {
		return 10;
	}
}
