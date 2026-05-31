<?php

namespace OCA\CalendarBridge\BackgroundJob;

use \OCP\AppFramework\Utility\ITimeFactory;
use \OCP\BackgroundJob\TimedJob;

use OCA\CalendarBridge\AppInfo\Application;
use OCA\CalendarBridge\Service\GoogleCalendarAPIService;
use Psr\Log\LoggerInterface;

class ImportCalendarJob extends TimedJob {

	private GoogleCalendarAPIService $service;
	private LoggerInterface $logger;

	public function __construct(ITimeFactory $timeFactory, GoogleCalendarAPIService $service, LoggerInterface $logger) {
		parent::__construct($timeFactory);
		$this->service = $service;
		$this->logger = $logger;
		parent::setInterval(1);
	}

	/**
	 * @param array{user_id: string, cal_id: string, cal_name: string,color: string} $argument
	 */
	#[\Override]
	protected function run($argument): void {
		// echo() stays for `occ background-job:*` visibility; the logger calls make
		// a failed sync visible in nextcloud.log under system cron too (echo there
		// goes nowhere), where verify-pass findings and import errors are surfaced.
		echo(date("Y-m-d H:i:s") . ' Importing ' . $argument['cal_name'] . '...');
		$result = $this->service->safeImportCalendar(
			$argument['user_id'],
			$argument['cal_id'],
			$argument['cal_name'],
			$argument['color'],
		);
		if (isset($result['error'])) {
			echo(' error: ' . $result['error'] . PHP_EOL);
			$this->logger->warning(
				'Calendar Bridge: import failed for calendar "' . $argument['cal_name'] . '" (user '
					. $argument['user_id'] . '): ' . $result['error'],
				['app' => Application::APP_ID],
			);
		} else {
			echo(' done. Added ' . $result['nbAdded'] . PHP_EOL);
		}
	}

}
