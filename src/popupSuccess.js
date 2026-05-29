import { loadState } from '@nextcloud/initial-state'

const state = loadState('outside_provider_calendar_bridge', 'popup-data')
const username = state.user_name

if (window.opener) {
	window.opener.postMessage({ username })
	window.close()
}
