import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { registerFileAction } from '@nextcloud/files'
import type { ActionContext, INode } from '@nextcloud/files'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'

import SignoSignDialog from './components/SignoSignDialog.vue'
import './styles/signo-sign-dialog.scss'
import fileSignIcon from '../img/file-sign.svg?raw'

type RemoteSigningDialogResult = {
	recipientEmail: string
	notificationLanguage: string
	authType: { label: string; value: string }
	password: string
	tanTarget: string
	mailSubject: string
	mailMessage: string
	mailSignatureText: string
} | null

const generateOcsUrl = (path: string) => `/ocs/v2.php${path}?format=json`

const isSinglePdfSelection = (context: ActionContext): boolean => {
	return context.nodes.length === 1 && context.nodes[0]?.mime === 'application/pdf'
}

const getNodeFileId = (node: INode): string | undefined => node.id

const isAxiosError = (error: unknown): error is {
	response?: { data?: { ocs?: { data?: { error?: string } } } }
	message?: string
	request?: unknown
} => {
	return typeof error === 'object' && error !== null && 'response' in error
}

const extractErrorMessage = (error: unknown): string | null => {
	if (!isAxiosError(error)) {
		return null
	}

	return error.response?.data?.ocs?.data?.error ?? null
}

const showRequestError = (error: unknown, fallbackLogLabel: string): void => {
	const errorMsg = extractErrorMessage(error)
	console.error(errorMsg)

	if (errorMsg === 'Username or password or baseurl not set in settings') {
		showError(
			t(
				'signotecsignosignuniversal',
				'Please check your signoSign app settings: Username, Password and Base URL must be set.',
			),
		)
		return
	}

	if (errorMsg?.includes('401 Unauthorized')) {
		showError(
			t(
				'signotecsignosignuniversal',
				'Invalid credentials. Please check your signoSign app settings: Username and Password must be correct.',
			),
		)
		return
	}

	if (errorMsg?.includes('404 Not Found')) {
		showError(
			t(
				'signotecsignosignuniversal',
				'Invalid URL. Please check your signoSign app settings: URL must be correct.',
			),
		)
		return
	}

	console.error(fallbackLogLabel, error)

	if (isAxiosError(error)) {
		console.error('Error details', {
			error: errorMsg,
			message: error.message,
			request: error.request,
			response: error.response?.data,
		})

		showError(
			t('signotecsignosignuniversal', 'Network error') + ': ' + error.message,
		)
		return
	}

	showError(t('signotecsignosignuniversal', 'Unknown error'))
}

const openRemoteSigningDialog = async (): Promise<RemoteSigningDialogResult> => {
	return await new Promise<RemoteSigningDialogResult>((resolve) => {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		spawnDialog(SignoSignDialog as any, {
			title: t('signotecsignosignuniversal', 'Request remote signing'),
			resolve,
			reject: () => resolve(null),
		})
	})
}

const signFileLocally = async (node: INode): Promise<boolean> => {
	const fileId = getNodeFileId(node)
	if (!fileId) {
		showError(t('signotecsignosignuniversal', 'No file ID found'))
		return false
	}

	try {
		const res = await axios.post<{ ocs: { data: { viewerUrl?: { url: string } } } }>(
			generateOcsUrl('/apps/signotecsignosignuniversal/uploadandsign'),
			{
				fileId,
				fileName: node.basename,
			},
			{
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
			},
		)

		const viewerUrl = res.data?.ocs?.data?.viewerUrl?.url
		if (viewerUrl) {
			window.open(viewerUrl, '_blank', 'noopener')
		}

		showSuccess(
			t('signotecsignosignuniversal', 'Signing request sent successfully')
			+ ': '
			+ String(node.basename ?? ''),
		)
		return true
	} catch (error) {
		console.error(error)
		showRequestError(error, 'Failed to send file for local signing')
		return false
	}
}

const signFileRemotely = async (node: INode): Promise<boolean> => {
	const fileId = getNodeFileId(node)
	if (!fileId) {
		showError(t('signotecsignosignuniversal', 'No file ID found'))
		return false
	}

	const result = await openRemoteSigningDialog()
	if (!result) {
		console.info('User cancelled the remote signing flow')
		return null as unknown as boolean
	}

	try {
		await axios.post(
			generateOcsUrl('/apps/signotecsignosignuniversal/uploadandsend'),
			{
				fileId,
				fileName: node.basename,
				recipientEmail: result.recipientEmail,
				locale: result.notificationLanguage,
				password: result.password ?? '',
				tanTarget: result.tanTarget ?? '',
				authType: result.authType?.label ?? '',
				mailSubject: result.mailSubject ?? '',
				mailMessage: result.mailMessage ?? '',
				mailSignatureText: result.mailSignatureText ?? '',
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
			t('signotecsignosignuniversal', 'Signing request sent successfully')
			+ ': '
			+ String(node.basename ?? ''),
		)
		return true
	} catch (error) {
		showRequestError(error, 'Failed to send file for remote signing')
		return false
	}
}

registerFileAction({
	id: 'sign_file_local',
	displayName: () => t('signotecsignosignuniversal', 'Sign file'),
	iconSvgInline: () => fileSignIcon,
	enabled: (context) => isSinglePdfSelection(context),
	async exec(context) {
		return await signFileLocally(context.nodes[0])
	},
})

registerFileAction({
	id: 'sign_file_remote',
	displayName: () => t('signotecsignosignuniversal', 'Request remote signing'),
	iconSvgInline: () => fileSignIcon,
	enabled: (context) => isSinglePdfSelection(context),
	async exec(context) {
		return await signFileRemotely(context.nodes[0])
	},
})
