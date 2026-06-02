<template>
	<div id="google_prefs" class="section">
		<h2>
			<GoogleIcon />
			{{ t('outside_provider_calendar_bridge', 'Google Synchronization') }}
		</h2>
		<p v-if="!showOAuth" class="settings-hint">
			{{ t('outside_provider_calendar_bridge', 'No Google OAuth app configured. Ask your Nextcloud administrator to configure Google connected accounts admin section.') }}
		</p>
		<div v-else
			id="google-content">
			<h3>{{ t('outside_provider_calendar_bridge', 'Authentication') }}</h3>
			<button v-if="!connected" class="google-oauth" @click="onOAuthClick">
				<GoogleIconColor />
				<span>{{ t('outside_provider_calendar_bridge', 'Sign in with Google') }}</span>
			</button>
			<div v-else>
				<div class="line">
					<label class="google-connected">
						<CheckIcon />
						{{ t('outside_provider_calendar_bridge', 'Connected as {user}', { user: state.user_name }) }}
					</label>
					<NcButton @click="onLogoutClick">
						<template #icon>
							<CloseIcon />
						</template>
						{{ t('outside_provider_calendar_bridge', 'Disconnect from Google') }}
					</NcButton>
				</div>
				<br>
				<div v-if="nbContacts + nbOtherContacts >= 0"
					id="google-contacts">
					<h3>{{ t('outside_provider_calendar_bridge', 'Contacts') }}</h3>
					<div class="line">
						<NcCheckboxRadioSwitch v-if="!importingContacts && state.user_scopes.can_access_other_contacts"
							:model-value="state.consider_other_contacts"
							@update:model-value="onContactsConsiderOtherChange">
							{{ t('outside_provider_calendar_bridge', 'Include other contacts') }}
						</NcCheckboxRadioSwitch>
					</div>
					<div class="line">
						<label>
							<AccountGroupOutlineIcon />
							{{ state.consider_other_contacts
								? t('outside_provider_calendar_bridge', '{amount} Google + {otherAmount} other contacts', { amount: nbContacts, otherAmount: nbOtherContacts })
								: t('outside_provider_calendar_bridge', '{amount} Google contacts', { amount: nbContacts }) }}
						</label>
						<NcButton @click="onImportContacts">
							<template #icon>
								<AccountMultipleOutlineIcon />
							</template>
							{{ t('outside_provider_calendar_bridge', 'Import Google Contacts in Nextcloud') }}
						</NcButton>
					</div>
					<br>
					<div class="line">
						<select v-if="showAddressBooks"
							v-model.number="selectedAddressBook">
							<option :value="-1">
								{{ t('outside_provider_calendar_bridge', 'Choose where to import the contacts') }}
							</option>
							<option :value="0">
								➕ {{ t('outside_provider_calendar_bridge', 'New address book') }}
							</option>
							<option v-for="(ab, k) in addressbooks" :key="k" :value="k">
								📕 {{ ab.name }}
							</option>
						</select>
						<input v-if="showAddressBooks && selectedAddressBook === 0"
							v-model="newAddressBookName"
							type="text"
							class="contact-input"
							:placeholder="t('outside_provider_calendar_bridge', 'address book name')">
						<NcButton v-if="showAddressBooks && selectedAddressBook > -1 && (selectedAddressBook > 0 || newAddressBookName)"
							:class="{ loading: importingContacts }"
							@click="onFinalImportContacts">
							<template #icon>
								<TrayArrowDownIcon />
							</template>
							{{ t('outside_provider_calendar_bridge', 'Import in "{name}" address book', { name: selectedAddressBookName }) }}
						</NcButton>
						<br>
					</div>
					<br>
					<div v-if="ncAddressBooks.length > 0">
						<label class="cb-hint">
							{{ t('outside_provider_calendar_bridge', 'Continuously sync your Google contacts into a Nextcloud address book (Google → Nextcloud):') }}
						</label>
						<div v-for="ab in ncAddressBooks" :key="ab.id" class="line">
							<NcCheckboxRadioSwitch
								:model-value="ab.isSyncEnabled"
								:loading="loadingSyncContacts[ab.id]"
								@update:model-value="onSyncContactsChange(ab)">
								{{ ab.displayname }}
							</NcCheckboxRadioSwitch>
							<NcButton :class="{ loading: dedupingContacts[ab.id] }"
								:disabled="!!dedupingContacts[ab.id]"
								@click="onDedupeContacts(ab)">
								{{ t('outside_provider_calendar_bridge', 'Remove duplicates') }}
							</NcButton>
						</div>
					</div>
				</div>
				<div v-if="calendars.length > 0">
					<h3>{{ t('outside_provider_calendar_bridge', 'Calendars') }}</h3>
					<NcCheckboxRadioSwitch
						:model-value="state.consider_all_events"
						@update:model-value="onConsiderAllEventsChange">
						{{ t('outside_provider_calendar_bridge', 'Import all events including Birthdays') }}
					</NcCheckboxRadioSwitch>
					<NcButton v-if="unsyncedCalendars.length > 0"
						:class="{ loading: syncingAll }"
						:disabled="syncingAll"
						@click="onSyncAll">
						{{ t('outside_provider_calendar_bridge', 'Sync all ({count} not synced)', { count: unsyncedCalendars.length }) }}
					</NcButton>
					<div v-for="cal in calendars" :key="cal.id" class="calendar-item">
						<label>
							<NcAppNavigationIconBullet :color="getCalendarColor(cal)" />
							<span>{{ getCalendarLabel(cal) }}</span>
							<span v-if="!cal.isJobRegistered" class="cb-hint">{{ t('outside_provider_calendar_bridge', '(not synced)') }}</span>
						</label>
						<NcButton
							:class="{ loading: importingCalendar[cal.id] }"
							@click="onCalendarImport(cal)">
							<template #icon>
								<CalendarImportOutlineIcon />
							</template>
							{{ t('outside_provider_calendar_bridge', 'Import calendar') }}
						</NcButton>
						<NcCheckboxRadioSwitch
							:model-value="cal.isJobRegistered"
							:loading="loadingSyncCalendar[cal.id]"
							@update:model-value="onCalendarSyncChange(cal)">
							{{ t('outside_provider_calendar_bridge', 'Sync calendar') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-if="state.user_scopes.can_write_calendar"
							:model-value="cal.isTwoWayEnabled"
							:loading="loadingTwoWay[cal.id]"
							:disabled="!cal.isTwoWayEnabled && (!cal.isJobRegistered || !isCalendarWritable(cal))"
							@update:model-value="onCalendarTwoWayChange(cal)">
							{{ t('outside_provider_calendar_bridge', 'Two-way sync (Nextcloud → Google)') }}
							<span v-if="cal.isJobRegistered && !isCalendarWritable(cal)" class="cb-hint">
								{{ t('outside_provider_calendar_bridge', '(read-only calendar)') }}
							</span>
							<span v-else-if="!cal.isJobRegistered" class="cb-hint">
								{{ t('outside_provider_calendar_bridge', '(enable Sync calendar first)') }}
							</span>
						</NcCheckboxRadioSwitch>
					</div>
					<br>
				</div>
				<div v-if="connected && state.user_scopes.can_access_calendar"
					id="cb-nc-calendars">
					<h3>{{ t('outside_provider_calendar_bridge', 'Your Nextcloud calendars') }}</h3>
					<p class="cb-hint">
						{{ t('outside_provider_calendar_bridge', 'Create a matching Google calendar from one of your Nextcloud calendars and keep them in two-way sync.') }}
					</p>
					<p v-if="!state.user_scopes.can_create_calendar || !state.user_scopes.can_write_calendar" class="cb-hint">
						{{ t('outside_provider_calendar_bridge', 'Reconnect your Google account to enable creating Google calendars (needs calendar create + write access).') }}
					</p>
					<div v-for="nc in ncCalendars" :key="nc.uri" class="calendar-item">
						<label>
							<NcAppNavigationIconBullet v-if="nc.color" :color="nc.color.replace('#', '')" />
							<span>{{ nc.displayname }}</span>
						</label>
						<NcButton v-if="!nc.isLinked"
							:class="{ loading: loadingNcLink[nc.uri] }"
							:disabled="!state.user_scopes.can_create_calendar || !state.user_scopes.can_write_calendar || !!loadingNcLink[nc.uri]"
							@click="onLinkNcCalendar(nc)">
							{{ t('outside_provider_calendar_bridge', 'Create in Google + sync') }}
						</NcButton>
						<template v-else>
							<span class="cb-hint">{{ t('outside_provider_calendar_bridge', 'Linked & syncing') }}</span>
							<NcButton :disabled="!!loadingNcLink[nc.uri]" @click="onDisconnectNcCalendar(nc)">
								{{ t('outside_provider_calendar_bridge', 'Disconnect') }}
							</NcButton>
							<NcButton type="error"
								:disabled="!!loadingNcLink[nc.uri]"
								@click="onDeleteBoth(nc)">
								{{ t('outside_provider_calendar_bridge', 'Delete both calendars') }}
							</NcButton>
						</template>
					</div>
					<p v-if="ncCalendars.length === 0" class="cb-hint">
						{{ t('outside_provider_calendar_bridge', 'No eligible Nextcloud calendars to sync.') }}
					</p>
					<br>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import CheckIcon from 'vue-material-design-icons/Check.vue'
import AccountGroupOutlineIcon from 'vue-material-design-icons/AccountGroupOutline.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CalendarImportOutlineIcon from 'vue-material-design-icons/CalendarImportOutline.vue'
import TrayArrowDownIcon from 'vue-material-design-icons/TrayArrowDown.vue'
import AccountMultipleOutlineIcon from 'vue-material-design-icons/AccountMultipleOutline.vue'

import GoogleIcon from './icons/GoogleIcon.vue'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcAppNavigationIconBullet from '@nextcloud/vue/components/NcAppNavigationIconBullet'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcButton from '@nextcloud/vue/components/NcButton'
import { showServerError } from '../utils.js'
import GoogleIconColor from './icons/GoogleIconColor.vue'

export default {
	name: 'PersonalSettings',

	components: {
		GoogleIconColor,
		GoogleIcon,
		NcAppNavigationIconBullet,
		NcButton,
		NcCheckboxRadioSwitch,
		CloseIcon,
		AccountMultipleOutlineIcon,
		TrayArrowDownIcon,
		CalendarImportOutlineIcon,
		CheckIcon,
		AccountGroupOutlineIcon,
	},

	props: [],

	data() {
		return {
			state: loadState('outside_provider_calendar_bridge', 'user-config'),
			redirect_uri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/outside_provider_calendar_bridge/oauth-redirect'),
			// calendars
			calendars: [],
			importingCalendar: {},
			loadingSyncCalendar: {},
			loadingTwoWay: {},
			syncingAll: false,
			// NC -> Google calendar-level sync
			ncCalendars: [],
			loadingNcLink: {},
			// contacts
			considerOtherContacts: false,
			addressbooks: [],
			nbContacts: 0,
			nbOtherContacts: 0,
			showAddressBooks: false,
			selectedAddressBook: 0,
			newAddressBookName: 'Google Contacts import',
			importingContacts: false,
			// continuous contacts sync (Google -> NC)
			ncAddressBooks: [],
			loadingSyncContacts: {},
			dedupingContacts: {},
		}
	},

	computed: {
		showOAuth() {
			return this.state.client_id && this.state.client_secret
		},
		connected() {
			return this.state.user_name && this.state.user_name !== ''
		},
		unsyncedCalendars() {
			return this.calendars.filter(c => !c.isJobRegistered)
		},
		selectedAddressBookName() {
			return this.selectedAddressBook === 0
				? this.newAddressBookName
				: this.addressbooks[this.selectedAddressBook].name
		},
		selectedAddressBookUri() {
			return this.selectedAddressBook === 0
				? null
				: this.addressbooks[this.selectedAddressBook].uri
		},
	},

	watch: {
	},

	mounted() {
		const paramString = window.location.search.slice(1)
		// eslint-disable-next-line
		const urlParams = new URLSearchParams(paramString)
		const ghToken = urlParams.get('googleToken')
		if (ghToken === 'success') {
			showSuccess(t('outside_provider_calendar_bridge', 'Successfully connected to Google!'))
		} else if (ghToken === 'error') {
			showError(t('outside_provider_calendar_bridge', 'Google connection error:') + ' ' + urlParams.get('message'))
		}

		this.loadData()
	},

	methods: {
		loadData() {
			// get informations if we are connected
			if (this.showOAuth && this.connected) {
				if (this.state.user_scopes.can_access_calendar) {
					this.getGoogleCalendarList()
					this.getNcCalendarList()
					this.getLocalAddressBooks()
				}
				if (this.state.user_scopes.can_access_contacts) {
					this.getNbGoogleContacts()
					this.getNcAddressBooks()
				}
			}
		},
		onLogoutClick() {
			this.state.user_name = ''
			this.saveOptions({ user_name: this.state.user_name })
		},
		saveOptions(values, callback = null) {
			const req = {
				values,
			}
			const url = generateUrl('/apps/outside_provider_calendar_bridge/config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('outside_provider_calendar_bridge', 'Google options saved'))
					// callback
					if (callback) {
						callback(response)
					}
				})
				.catch((error) => {
					showServerError(
						error,
						t('outside_provider_calendar_bridge', 'Failed to save Google options'),
					)
				})
		},
		onOAuthClick() {
			const oauthState = Math.random().toString(36).substring(3)
			const scopes = [
				'openid',
				'profile',
				'https://www.googleapis.com/auth/calendar.readonly',
				'https://www.googleapis.com/auth/calendar.events.readonly',
				// Read-write events scope: enables opt-in two-way (NC -> Google)
				// sync. Existing read-only users keep working until they reconnect.
				'https://www.googleapis.com/auth/calendar.events',
				// Create + manage app-created calendars: enables creating a Google
				// calendar from a Nextcloud one. Granted on reconnect; harmless if
				// unused. (calendar-level NC -> Google sync.)
				'https://www.googleapis.com/auth/calendar.app.created',
				'https://www.googleapis.com/auth/contacts.readonly',
				// Read-write contacts scope: enables opt-in outbound (NC -> Google)
				// contacts sync. Granted on reconnect; harmless if unused.
				'https://www.googleapis.com/auth/contacts',
				'https://www.googleapis.com/auth/contacts.other.readonly',
			]
			const requestUrl = 'https://accounts.google.com/o/oauth2/v2/auth?'
				+ 'client_id=' + encodeURIComponent(this.state.client_id)
				+ '&redirect_uri=' + encodeURIComponent(this.redirect_uri)
				+ '&response_type=code'
				+ '&access_type=offline'
				+ '&prompt=consent'
				+ '&state=' + encodeURIComponent(oauthState)
				+ '&scope=' + encodeURIComponent(scopes.join(' '))

			const req = {
				values: {
					oauth_state: oauthState,
					redirect_uri: this.redirect_uri,
				},
			}
			const url = generateUrl('/apps/outside_provider_calendar_bridge/config')
			axios.put(url, req).then((response) => {
				if (this.state.use_popup) {
					const ssoWindow = window.open(
						requestUrl,
						t('outside_provider_calendar_bridge', 'Sign in with Google'),
						'toolbar=no, menubar=no, width=600, height=700',
					)
					ssoWindow.focus()
					window.addEventListener('message', (event) => {
						console.debug('Child window message received', event)
						this.state.user_name = event.data.username
						this.loadData()
					})
				} else {
					window.location.replace(requestUrl)
				}
			}).catch((error) => {
				showServerError(
					error,
					t('outside_provider_calendar_bridge', 'Failed to save Google OAuth state'),
				)
			})
		},
		getGoogleCalendarList() {
			const url = generateUrl('/apps/outside_provider_calendar_bridge/calendars')
			axios.get(url)
				.then((response) => {
					if (response.data && response.data.length && response.data.length > 0) {
						this.calendars = response.data
					}
				})
				.catch((error) => {
					showServerError(
						error,
						t('outside_provider_calendar_bridge', 'Failed to get calendar list'),
					)
				})
		},
		onSyncAll() {
			const toSync = this.unsyncedCalendars
			if (toSync.length === 0) {
				return
			}
			this.syncingAll = true
			const url = generateUrl('/apps/outside_provider_calendar_bridge/set-sync-calendar')
			// registerSyncCalendar is idempotent; allSettled so one failure does not
			// abort the rest. Reconcile UI state per fulfilled result.
			Promise.allSettled(toSync.map((cal) =>
				axios.get(url, {
					params: {
						calId: cal.id,
						desiredState: true,
						calName: this.getCalendarLabel(cal),
						color: cal.backgroundColor || '#0082c9',
					},
				}).then(() => { cal.isJobRegistered = true }),
			)).then((results) => {
				const failed = results.filter(r => r.status === 'rejected').length
				if (failed === 0) {
					showSuccess(t('outside_provider_calendar_bridge', 'Started syncing all calendars'))
				} else {
					showError(this.n('outside_provider_calendar_bridge', '{n} calendar could not be synced', '{n} calendars could not be synced', failed, { n: failed }))
				}
			}).finally(() => {
				this.syncingAll = false
				this.getGoogleCalendarList()
			})
		},
		getNcCalendarList() {
			const url = generateUrl('/apps/outside_provider_calendar_bridge/nc-calendars')
			axios.get(url)
				.then((response) => {
					this.ncCalendars = response.data || []
				})
				.catch((error) => {
					showServerError(error, t('outside_provider_calendar_bridge', 'Failed to get Nextcloud calendar list'))
				})
		},
		onLinkNcCalendar(nc) {
			this.loadingNcLink[nc.uri] = true
			const url = generateUrl('/apps/outside_provider_calendar_bridge/sync-nc-calendar')
			axios.post(url, { ncCalUri: nc.uri })
				.then(() => {
					showSuccess(t('outside_provider_calendar_bridge', 'Created in Google and syncing'))
					this.getNcCalendarList()
					this.getGoogleCalendarList()
				})
				.catch((error) => {
					showServerError(error, t('outside_provider_calendar_bridge', 'Failed to create the Google calendar'))
				})
				.finally(() => {
					this.loadingNcLink[nc.uri] = false
				})
		},
		onDisconnectNcCalendar(nc) {
			this.loadingNcLink[nc.uri] = true
			const url = generateUrl('/apps/outside_provider_calendar_bridge/disconnect-nc-calendar')
			axios.post(url, { googleCalId: nc.googleCalId })
				.then(() => {
					showSuccess(t('outside_provider_calendar_bridge', 'Disconnected (both calendars kept)'))
					this.getNcCalendarList()
					this.getGoogleCalendarList()
				})
				.catch((error) => {
					showServerError(error, t('outside_provider_calendar_bridge', 'Failed to disconnect'))
				})
				.finally(() => {
					this.loadingNcLink[nc.uri] = false
				})
		},
		onDeleteBoth(nc) {
			// eslint-disable-next-line no-alert
			const ok = window.confirm(
				t('outside_provider_calendar_bridge',
					'Delete BOTH the Nextcloud calendar "{name}" and its Google calendar? The Google calendar is removed permanently; the Nextcloud one goes to the calendar trash.',
					{ name: nc.displayname }),
			)
			if (!ok) {
				return
			}
			this.loadingNcLink[nc.uri] = true
			const url = generateUrl('/apps/outside_provider_calendar_bridge/linked-calendars')
			axios.delete(url, { params: { ncCalUri: nc.uri } })
				.then(() => {
					showSuccess(t('outside_provider_calendar_bridge', 'Deleted both calendars'))
					this.getNcCalendarList()
					this.getGoogleCalendarList()
				})
				.catch((error) => {
					showServerError(error, t('outside_provider_calendar_bridge', 'Failed to delete calendars'))
				})
				.finally(() => {
					this.loadingNcLink[nc.uri] = false
				})
		},
		getCalendarLabel(cal) {
			return cal.summary || cal.id
		},
		getCalendarColor(cal) {
			return cal.backgroundColor
				? cal.backgroundColor.replace('#', '')
				: '0082c9'
		},
		getNbGoogleContacts() {
			const url = generateUrl('/apps/outside_provider_calendar_bridge/contact-number')
			axios.get(url)
				.then((response) => {
					if (response.data && Object.keys(response.data).length > 0) {
						this.nbContacts = response.data.nbContacts
						this.nbOtherContacts = response.data.nbOtherContacts ?? 0
					}
				})
				.catch((error) => {
					showServerError(
						error,
						t('outside_provider_calendar_bridge', 'Failed to get number of Google contacts'),
					)
				})
				.then(() => {
				})
		},
		getNcAddressBooks() {
			const url = generateUrl('/apps/outside_provider_calendar_bridge/nc-addressbooks')
			axios.get(url)
				.then((response) => {
					if (Array.isArray(response.data)) {
						this.ncAddressBooks = response.data
					}
				})
				.catch((error) => {
					showServerError(error, t('outside_provider_calendar_bridge', 'Failed to list address books'))
				})
		},
		onSyncContactsChange(ab) {
			const desired = !ab.isSyncEnabled
			this.loadingSyncContacts[ab.id] = true
			const url = generateUrl('/apps/outside_provider_calendar_bridge/sync-contacts')
			axios.post(url, { addressBookId: ab.id, enabled: desired })
				.then(() => {
					ab.isSyncEnabled = desired
					showSuccess(desired
						? t('outside_provider_calendar_bridge', 'Contacts sync enabled')
						: t('outside_provider_calendar_bridge', 'Contacts sync disabled'))
				})
				.catch((error) => {
					showServerError(error, t('outside_provider_calendar_bridge', 'Failed to change contacts sync'))
				})
				.finally(() => {
					this.loadingSyncContacts[ab.id] = false
				})
		},
		onDedupeContacts(ab) {
			this.dedupingContacts[ab.id] = true
			const url = generateUrl('/apps/outside_provider_calendar_bridge/dedupe-contacts')
			axios.post(url, { addressBookId: ab.id })
				.then((response) => {
					const removed = (response.data && response.data.removed) ? response.data.removed : 0
					showSuccess(t('outside_provider_calendar_bridge', 'Removed {count} duplicate contact(s)', { count: removed }))
				})
				.catch((error) => {
					showServerError(error, t('outside_provider_calendar_bridge', 'Failed to remove duplicate contacts'))
				})
				.finally(() => {
					this.dedupingContacts[ab.id] = false
				})
		},
		getLocalAddressBooks() {
			const url = generateUrl('/apps/outside_provider_calendar_bridge/local-addressbooks')
			axios.get(url)
				.then((response) => {
					if (response.data && Object.keys(response.data).length > 0) {
						this.addressbooks = response.data
					}
				})
				.catch((error) => {
					showServerError(
						error,
						t('outside_provider_calendar_bridge', 'Failed to get address book list'),
					)
				})
		},
		onImportContacts() {
			this.selectedAddressBook = 0
			this.showAddressBooks = !this.showAddressBooks
		},
		onFinalImportContacts() {
			this.importingContacts = true
			const req = {
				params: {
					uri: this.selectedAddressBookUri,
					key: this.selectedAddressBook,
					newAddressBookName: this.selectedAddressBook > 0 ? null : this.newAddressBookName,
				},
			}
			const url = generateUrl('/apps/outside_provider_calendar_bridge/import-contacts')
			axios.get(url, req)
				.then((response) => {
					const nbSeen = response.data.nbSeen
					const nbAdded = response.data.nbAdded
					const nbUpdated = response.data.nbUpdated
					showSuccess(
						this.n(
							'outside_provider_calendar_bridge',
							'{nbSeen} Google contact seen. {nbAdded} added, {nbUpdated} updated in {name}',
							'{nbSeen} Google contacts seen. {nbAdded} added, {nbUpdated} updated in {name}',
							nbSeen,
							{ nbAdded, nbSeen, nbUpdated, name: this.selectedAddressBookName },
						),
					)
					this.showAddressBooks = false
				})
				.catch((error) => {
					showServerError(
						error,
						t('outside_provider_calendar_bridge', 'Failed to get address book list'),
					)
				})
				.then(() => {
					this.importingContacts = false
				})
		},
		onCalendarImport(cal) {
			const calId = cal.id
			this.importingCalendar[calId] = true
			const req = {
				params: {
					calId,
					calName: this.getCalendarLabel(cal),
					color: cal.backgroundColor || '#0082c9',
				},
			}
			const url = generateUrl('/apps/outside_provider_calendar_bridge/import-calendar')
			axios.get(url, req)
				.then((response) => {
					const nbAdded = response.data.nbAdded
					const nbUpdated = response.data.nbUpdated
					const total = nbAdded + nbUpdated
					const calName = response.data.calName
					showSuccess(
						this.n(
							'outside_provider_calendar_bridge',
							'{total} event successfully imported in {name} ({nbAdded} created, {nbUpdated} updated)',
							'{total} events successfully imported in {name} ({nbAdded} created, {nbUpdated} updated)',
							total,
							{ total, nbAdded, nbUpdated, name: calName },
						),
					)
				})
				.catch((error) => {
					showServerError(
						error,
						t('outside_provider_calendar_bridge', 'Failed to import Google calendar'),
					)
				})
				.then(() => {
					this.importingCalendar[calId] = false
				})
		},
		onCalendarSyncChange(cal) {
			const desiredState = !cal.isJobRegistered
			const calId = cal.id
			const req = {
				params: {
					calId,
					desiredState,
					calName: this.getCalendarLabel(cal),
					color: cal.backgroundColor || '#0082c9',
				},
			}
			this.loadingSyncCalendar[calId] = true
			const actionMessage = `${desiredState ? '' : 'un'}register`
			const successMessage = `Successfully ${actionMessage}ed background job`
			const errorMessage = `Failed to ${actionMessage} background job`
			const url = generateUrl('/apps/outside_provider_calendar_bridge/set-sync-calendar')
			axios.get(url, req)
				.then((_response) => {
					cal.isJobRegistered = desiredState
					showSuccess(
						this.n('outside_provider_calendar_bridge', successMessage, successMessage, 1),
					)
				})
				.catch((error) => {
					console.error(errorMessage, error)
					showServerError(
						error,
						t('outside_provider_calendar_bridge', errorMessage),
					)
				})
				.finally(() => {
					this.loadingSyncCalendar[calId] = false
				})
		},
		isCalendarWritable(cal) {
			return cal.accessRole === 'owner' || cal.accessRole === 'writer'
		},
		onCalendarTwoWayChange(cal) {
			const desiredState = !cal.isTwoWayEnabled
			const calId = cal.id
			this.loadingTwoWay[calId] = true
			const url = generateUrl('/apps/outside_provider_calendar_bridge/set-two-way-sync')
			axios.get(url, { params: { calId, desiredState } })
				.then((_response) => {
					cal.isTwoWayEnabled = desiredState
					showSuccess(t('outside_provider_calendar_bridge',
						desiredState ? 'Two-way sync enabled' : 'Two-way sync disabled'))
				})
				.catch((error) => {
					console.error('Failed to change two-way sync', error)
					showServerError(error, t('outside_provider_calendar_bridge', 'Failed to change two-way sync'))
				})
				.finally(() => {
					this.loadingTwoWay[calId] = false
				})
		},
		onContactsConsiderOtherChange(newValue) {
			this.state.consider_other_contacts = newValue
			this.saveOptions({ consider_other_contacts: this.state.consider_other_contacts ? '1' : '0' }, this.getNbGoogleContacts)
		},
		onConsiderAllEventsChange(newValue) {
			this.state.consider_all_events = newValue
			this.saveOptions({ consider_all_events: this.state.consider_all_events ? '0' : '1' })
		},
	},
}
</script>

<style scoped lang="scss">
#google-content {
	margin-inline-start: 40px;

	h3 {
		font-weight: bold;
	}

	.cb-hint {
		margin-inline-start: 6px;
		color: var(--color-text-maxcontrast);
		font-size: 90%;
	}

	.line {
		display: flex;
		align-items: center;
		gap: 20px;

		label {
			width: 300px;
			display: flex;
			.material-design-icon {
				margin-inline-end: 8px;
			}
		}
	}

	.calendar-item {
		display: flex;
		gap: 20px;
		align-items: center;
		margin: 8px 0;
		label {
			width: 300px;
		}
		button {
			height: 40px;
			min-height: 40px;
		}
	}
	/* There are better ways to do this, */
	/* but I'm trying to avoid conflicts with upstream*/
	.calendar-button-sync {
		margin-inline-start: 10px;
	}

	#google-contacts {
		select {
			width: 300px;
		}
		.contact-input {
			width: 200px;
		}
	}

	.check-option {
		margin-inline-start: 5px;
	}

	.google-oauth {
		color: white;
		background-color: #4580F1;
		border-radius: 4px;
		padding: 0;
		display: flex;
		align-items: center;
		span {
			padding: 0 8px 0 8px;
			font-size: 1.1em;
		}
	}
}

h2,
.settings-hint {
	display: flex;
	span {
		margin-inline-end: 8px;
	}
}

::v-deep .app-navigation-entry__icon-bullet {
	display: inline-block;
	padding: 0;
	height: 12px;
	margin: 0 8px 0 10px;
}

.sync-checkbox {
	margin-inline-start: 20px;
}

</style>
