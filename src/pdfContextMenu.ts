import { loadTranslations, t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { FileAction, registerFileAction } from '@nextcloud/files'

import { createApp } from 'vue'
import SignoSignDialog from './components/SignoSignDialog.vue'

import fileSignIcon from '../img/file-sign.svg?raw'

await loadTranslations('signotecsignosignuniversal')

type RemoteSigningDialogResult = {
	recipientEmail: string
	authType: { label: string; value: string }
	password: string
	tanTarget: string
} | null

const generateOcsUrl = (path: string) => `/ocs/v2.php${path}?format=json`

registerFileAction(
	new FileAction({
		id: 'sign_file_local',
		displayName: () => t('signotecsignosignuniversal', 'Sign file'),
		iconSvgInline: () => fileSignIcon,
		mime: 'application/pdf',
		enabled: (nodes) => {
			if (!nodes || nodes.length !== 1) {
				return
			}
			return nodes.every((node) => node.mime === 'application/pdf')
		},
		async exec(file) {
			if (!file.fileid) {
				showError(t('signotecsignosignuniversal', 'No file ID found'))
				return
			}

			try {
				const res = await axios.post(
					generateOcsUrl('/apps/signotecsignosignuniversal/uploadandsign'),
					{ fileId: file.fileid, fileName: file.basename },
					{
						headers: {
							'OCS-APIRequest': 'true',
							'Content-Type': 'application/json',
							Accept: 'application/json',
						},
					},
				)

				if (res.data.ocs?.data?.viewerUrl?.url) {
					window.open(res.data.ocs.data.viewerUrl.url, '_blank')
				}

				showSuccess(
					t('signotecsignosignuniversal', 'Signing request sent successfully')
					+ ': '
					+ res.data.ocs.data.fileId,
				)

				return
			} catch (e) {
				const errorMsg = e.response.data.ocs.data.error ?? null
				if (errorMsg === 'Username or password or baseurl not set in settings') {
					showError(t('signotecsignosignuniversal', 'Please check your SignoSign Universal app settings: Username, Password and Base URL must be set.'))
				} else if (errorMsg.includes('401 Unauthorized')) {
					showError(t('signotecsignosignuniversal', 'Invalid credentials. Please check your SignoSign Universal app settings: Username and Password must be correct.'))
				} else {
					console.error('Failed to send file for local signing', e)
					console.error('Error details', {
						error: errorMsg,
						message: e.message,
						request: e.request,
					})

					showError(t('signotecsignosignuniversal', 'Network error') + ': ' + e)
				}
			}
		},

	}),
)

registerFileAction(
	new FileAction({
		id: 'sign_file_remote',
		displayName: () => t('signotecsignosignuniversal', 'Send file for remote signing'),
		iconSvgInline: () => fileSignIcon,
		mime: 'application/pdf',
		enabled: (nodes) => {
			if (!nodes || nodes.length !== 1) {
				return
			}
			return nodes.every((node) => node.mime === 'application/pdf')
		},
		async exec(file) {
			if (!file.fileid) {
				showError(t('signotecsignosignuniversal', 'No file ID found'))
				return
			}

			let app
			const containerId = 'confirmation-' + Math.random().toString(16).slice(2)
			const container = document.createElement('div')
			container.id = containerId
			document.body.appendChild(container)

			const result = await new Promise<RemoteSigningDialogResult>((resolve, reject) => {
				app = createApp(SignoSignDialog, {
					title: t('signotecsignosignuniversal', 'Send file for remote signing'),
					resolve,
					reject,
				})
				app.mount(`#${containerId}`)
			})

			app.unmount && app.unmount()
			document.body.removeChild(container)

			if (!result) {
				console.info('User cancelled the remote signing flow')
				return
			}

			try {
				await axios.post(
					generateOcsUrl('/apps/signotecsignosignuniversal/uploadandsend'),
					{
						fileId: file.fileid,
						fileName: file.basename,
						recipientEmail: result.recipientEmail,
						password: result.password ?? '',
						tanTarget: result.tanTarget ?? '',
					},
					{
						headers: {
							'OCS-APIRequest': 'true',
							'Content-Type': 'application/json',
							Accept: 'application/json',
						},
					},
				)

				showSuccess(
					t('signotecsignosignuniversal', 'Remote signing request sent successfully')
					+ ': '
					+ file.basename,
				)

				return
			} catch (e) {
				const errorMsg = e.response.data.ocs.data.error ?? null
				if (errorMsg === 'Username or password or baseurl not set in settings') {
					showError(t('signotecsignosignuniversal', 'Please check your SignoSign Universal app settings: Username, Password and Base URL must be set.'))
				} else if (errorMsg.includes('401 Unauthorized')) {
					showError(t('signotecsignosignuniversal', 'Invalid credentials. Please check your SignoSign Universal app settings: Username and Password must be correct.'))
				} else {
					console.error('Failed to send file for remote signing', e)
					console.error('Error details', {
						error: errorMsg,
						message: e.message,
						request: e.request,
					})

					showError(t('signotecsignosignuniversal', 'Network error') + ': ' + e)
				}
			}
		},
	}),
)
