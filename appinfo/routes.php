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

return [
	'routes' => [
		['name' => 'config#oauthRedirect', 'url' => '/oauth-redirect', 'verb' => 'GET'],
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
		['name' => 'config#getLocalAddressBooks', 'url' => '/local-addressbooks', 'verb' => 'GET'],
		['name' => 'config#popupSuccessPage', 'url' => '/popup-success', 'verb' => 'GET'],

		['name' => 'googleAPI#getCalendarList', 'url' => '/calendars', 'verb' => 'GET'],
		['name' => 'googleAPI#getContactNumber', 'url' => '/contact-number', 'verb' => 'GET'],
		['name' => 'googleAPI#importCalendar', 'url' => '/import-calendar', 'verb' => 'GET'],
		['name' => 'googleAPI#registerSyncCalendar', 'url' => '/sync-calendar', 'verb' => 'GET'],
		['name' => 'googleAPI#setSyncCalendar', 'url' => '/set-sync-calendar', 'verb' => 'GET'],
		['name' => 'googleAPI#setTwoWaySync', 'url' => '/set-two-way-sync', 'verb' => 'GET'],
		// Calendar-level NC -> Google sync (P-c)
		['name' => 'googleAPI#getNcCalendarList', 'url' => '/nc-calendars', 'verb' => 'GET'],
		['name' => 'googleAPI#syncNcCalendarToGoogle', 'url' => '/sync-nc-calendar', 'verb' => 'POST'],
		['name' => 'googleAPI#disconnectNcCalendar', 'url' => '/disconnect-nc-calendar', 'verb' => 'POST'],
		['name' => 'googleAPI#deleteLinkedCalendars', 'url' => '/linked-calendars', 'verb' => 'DELETE'],
		['name' => 'googleAPI#resetRegisteredSyncCalendar', 'url' => '/reset-sync-calendar', 'verb' => 'DELETE'],
		['name' => 'googleAPI#importContacts', 'url' => '/import-contacts', 'verb' => 'GET'],
		// Continuous contacts sync (Track 2, C0)
		['name' => 'googleAPI#getNcAddressBooks', 'url' => '/nc-addressbooks', 'verb' => 'GET'],
		['name' => 'googleAPI#setSyncContacts', 'url' => '/sync-contacts', 'verb' => 'POST'],
		['name' => 'googleAPI#dedupeContacts', 'url' => '/dedupe-contacts', 'verb' => 'POST'],
	]
];
