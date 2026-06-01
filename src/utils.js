import { showError } from '@nextcloud/dialogs'

let mytimer = 0
export function delay(callback, ms) {
	return function() {
		const context = this
		const args = arguments
		clearTimeout(mytimer)
		mytimer = setTimeout(function() {
			callback.apply(context, args)
		}, ms || 0)
	}
}

export function humanFileSize(bytes, approx = false, si = false, dp = 1) {
	const thresh = si ? 1000 : 1024

	if (Math.abs(bytes) < thresh) {
		return bytes + ' B'
	}

	const units = si
		? ['kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB']
		: ['KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB']
	let u = -1
	const r = 10 ** dp

	do {
		bytes /= thresh
		++u
	} while (Math.round(Math.abs(bytes) * r) / r >= thresh && u < units.length - 1)

	if (approx) {
		return Math.floor(bytes) + ' ' + units[u]
	} else {
		return bytes.toFixed(dp) + ' ' + units[u]
	}
}

// Escape text destined for an isHTML:true toast. The error headline + JSON dump
// can contain server/Google-controlled content, so they MUST NOT be interpolated
// as live HTML (XSS).
function escapeHtml(value) {
	return String(value ?? '')
		.replaceAll('&', '&amp;')
		.replaceAll('<', '&lt;')
		.replaceAll('>', '&gt;')
		.replaceAll('"', '&quot;')
		.replaceAll("'", '&#39;')
}

function getDetails(error) {
	try {
		const html = error.response?.request?.responseText
		if (!html) {
			throw Error('Not an HTML response')
		}

		const parser = new DOMParser()
		const htmlDoc = parser.parseFromString(html, 'text/html')
		return htmlDoc.querySelector('main').innerHTML
	} catch (e) {
		const json = JSON.stringify(error, Object.getOwnPropertyNames(error), 2)
		return `<pre><code>${escapeHtml(json)}</code></pre>`
	}
}

// Prefer the server's structured error body as the headline so the actionable
// message (e.g. "reconnect your Google account") isn't buried under axios's
// generic "Request failed with status code N". Falls back to error.message for
// network failures / non-JSON bodies.
function serverMessage(error) {
	const data = error.response?.data
	if (data && typeof data.error === 'string' && data.error.trim() !== '') {
		return data.error
	}
	if (typeof data === 'string' && data.trim() !== '') {
		return data
	}
	return error.message
}

export function showServerError(error, message) {
	// In the worst case, I can instruct people to dig through the browser console
	// in GitHub issues.
	console.error(error)

	const summary = t('outside_provider_calendar_bridge', 'Details')
	const details = getDetails(error)

	showError(`
		<div style="padding: 10px;">
			<h2>${escapeHtml(message)}: ${escapeHtml(serverMessage(error))}</h2>
			<details>
				<summary>${summary}</summary>
				${details}
			</details>
		</div>`, { isHTML: true })
}
