import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { FileAction, registerFileAction } from '@nextcloud/files'
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

type FileActionNode = {
	id?: string | number
	fileid?: string | number
	basename?: string
	mime?: string
}

const generateOcsUrl = (path: string) => `/ocs/v2.php${path}?format=json`

const isSinglePdfSelection = (nodes?: FileActionNode[]): boolean => {
	if (nodes?.length !== 1) {
		return false
	}

	return nodes[0]?.mime === 'application/pdf'
}

const getNodeFileId = (node: FileActionNode): string | number | null => {
	return node.id ?? node.fileid ?? null
}

const isAxiosError = (error: unknown): error is {
	response?: { data?: unknown }
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
		spawnDialog(SignoSignDialog, {
			title: t('signotecsignosignuniversal', 'Request remote signing'),
			resolve,
			reject: () => resolve(null),
		})
	})
}

const signFileLocally = async (node: FileActionNode): Promise<void> => {
	const fileId = getNodeFileId(node)
	if (!fileId) {
		showError(t('signotecsignosignuniversal', 'No file ID found'))
		return
	}

	try {
		const res = await axios.post(
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
	} catch (error) {
		console.error(error)
		showRequestError(error, 'Failed to send file for local signing')
	}
}

const signFileRemotely = async (node: FileActionNode): Promise<void> => {
	const fileId = getNodeFileId(node)
	if (!fileId) {
		showError(t('signotecsignosignuniversal', 'No file ID found'))
		return
	}

	const result = await openRemoteSigningDialog()
	if (!result) {
		console.info('User cancelled the remote signing flow')
		return
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
	} catch (error) {
		showRequestError(error, 'Failed to send file for remote signing')
	}
}

registerFileAction(
	new FileAction({
		id: 'sign_file_local',
		displayName: () => t('signotecsignosignuniversal', 'Sign file'),
		iconSvgInline: () => fileSignIcon,
		mime: 'application/pdf',
		enabled: (nodes) => isSinglePdfSelection(nodes as FileActionNode[]),
		async exec(file) {
			if (!file) {
				showError(t('signotecsignosignuniversal', 'No file selected'))
				return
			}

			await signFileLocally(file as FileActionNode)
		},
	}),
)

registerFileAction(
	new FileAction({
		id: 'sign_file_remote',
		displayName: () => t('signotecsignosignuniversal', 'Request remote signing'),
		iconSvgInline: () => fileSignIcon,
		mime: 'application/pdf',
		enabled: (nodes) => isSinglePdfSelection(nodes as FileActionNode[]),
		async exec(file) {
			if (!file) {
				showError(t('signotecsignosignuniversal', 'No file selected'))
				return
			}

			await signFileRemotely(file as FileActionNode)
		},
	}),
)
