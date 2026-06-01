<?php

/**
 * Nextcloud - Google
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\CalendarBridge\AppInfo;

use OCA\CalendarBridge\Listener\CalendarPairingListener;
use OCA\CalendarBridge\Notification\Notifier;
use OCA\DAV\Events\CalendarDeletedEvent;
use OCA\DAV\Events\CalendarMovedToTrashEvent;
use OCA\DAV\Events\CalendarRestoredEvent;
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
		// Keep a calendar-level pairing in step with its NC calendar's lifecycle
		// when acted on out-of-band: delete -> unlink, trash -> pause (keep the
		// link), restore -> resume. The Google calendar is never deleted here.
		$context->registerEventListener(CalendarDeletedEvent::class, CalendarPairingListener::class);
		$context->registerEventListener(CalendarMovedToTrashEvent::class, CalendarPairingListener::class);
		$context->registerEventListener(CalendarRestoredEvent::class, CalendarPairingListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
