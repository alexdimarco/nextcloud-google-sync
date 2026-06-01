<?php

/**
 * Nextcloud - Google
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\CalendarBridge\AppInfo;

use OCA\CalendarBridge\Listener\CalendarDeletedListener;
use OCA\CalendarBridge\Notification\Notifier;
use OCA\DAV\Events\CalendarDeletedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {

	public const APP_ID = 'outside_provider_calendar_bridge';
	// consider that a job is not running anymore after N seconds
	public const IMPORT_JOB_TIMEOUT = 3600;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(Notifier::class);
		// Clean up a calendar-level pairing if the NC calendar is deleted
		// out-of-band (Calendar app / occ / account removal).
		$context->registerEventListener(CalendarDeletedEvent::class, CalendarDeletedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
