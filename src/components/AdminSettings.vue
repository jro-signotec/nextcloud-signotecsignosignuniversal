<template>
	<div class="sectionSignotecSignoSignUniversal">
		<h2>
			{{
				t(
					'signotecsignosignuniversal',
					'signotec signoSign Settings'
				)
			}}
		</h2>

		<p v-if="hasUnsavedChanges" class="unsaved-warning">
			⚠ {{ t('signotecsignosignuniversal', 'Unsaved changes') }}
		</p>

		<form class="settings-form" @submit.prevent="saveSettings">
			<!-- Section 1: Connection data -->
			<NcSettingsSection
				:name="t('signotecsignosignuniversal', 'Connection data')"
				:description="t('signotecsignosignuniversal', 'Credentials for the signoSign/Universal API.')">
				<div class="form-group">
					<label for="url">{{
						t('signotecsignosignuniversal', 'URL')
					}}</label>
					<input id="url" v-model="settings.url" type="text" required>
				</div>

				<div class="form-group">
					<label for="username">{{
						t('signotecsignosignuniversal', 'Username')
					}}</label>
					<input id="username" v-model="settings.username" type="text" required>
				</div>

				<div class="form-group">
					<label for="password">{{
						t('signotecsignosignuniversal', 'Password')
					}}</label>
					<input id="password" v-model="settings.password" type="password" :required="!settings.hasPassword"
						:placeholder="settings.hasPassword
							? t(
								'signotecsignosignuniversal',
								'Already set - only enter to change'
							)
							: t(
								'signotecsignosignuniversal',
								'Password required'
							)
							">

					<p v-if="settings.hasPassword" class="hint">
						{{
							t(
								'signotecsignosignuniversal',
								'A password is already stored. Leave empty to keep it unchanged.'
							)
						}}
					</p>
				</div>

				<div class="form-actions form-actions--inline">
					<NcButton type="submit" variant="success" :disabled="isSaving">
						{{ t('signotecsignosignuniversal', 'Save') }}
					</NcButton>
				</div>

				<div v-if="settings.connectionValid" class="connection-status connection-status--ok">
					<span class="connection-status__icon">✓</span>
					{{ t('signotecsignosignuniversal', 'Connection successful') }}
				</div>

				<div v-else-if="settings.connectionError" class="connection-status connection-status--error">
					<span class="connection-status__icon">✗</span>
					{{ friendlyConnectionError }}
				</div>
			</NcSettingsSection>

			<!-- Section 2: signoSign settings -->
			<NcSettingsSection
				v-if="settings.connectionValid"
				:name="t('signotecsignosignuniversal', 'signoSign/Universal settings')"
				:description="t('signotecsignosignuniversal', 'Webhook and SMS configuration for signoSign/Universal.')">
				<div class="webhook-header">
					<div class="webhook-urls">
						<div>
							<h4>
								{{
									t(
										'signotecsignosignuniversal',
										'Webhook URL for signature updates'
									)
								}}
							</h4>
							<p v-if="settings.webhookDocumentUpdatedEndpoint" class="hint">
								{{ t('signotecsignosignuniversal', 'Current URL:') }}
								<code>{{ settings.webhookDocumentUpdatedEndpoint }}</code>
							</p>
							<p v-else class="hint">
								{{ t('signotecsignosignuniversal', 'Not set.') }}
							</p>
							<div :class="['status-row', settings.webhookDocumentUpdatedEndpoint === expectedWebhookUpdatedUrl ? 'status-row--ok' : 'status-row--off']">
								<span class="status-row__icon">{{ settings.webhookDocumentUpdatedEndpoint === expectedWebhookUpdatedUrl ? '✓' : '✗' }}</span>
								{{ t('signotecsignosignuniversal', 'Webhook URL matches expected URL') }}
							</div>
						</div>

						<div>
							<h4>
								{{
									t(
										'signotecsignosignuniversal',
										'Webhook URL for rejected sharing cases'
									)
								}}
							</h4>
							<p v-if="settings.webhookDocumentSharedClosedEndpoint" class="hint">
								{{ t('signotecsignosignuniversal', 'Current URL:') }}
								<code>{{ settings.webhookDocumentSharedClosedEndpoint }}</code>
							</p>
							<p v-else class="hint">
								{{ t('signotecsignosignuniversal', 'Not set') }}
							</p>
							<div :class="['status-row', settings.webhookDocumentSharedClosedEndpoint === expectedWebhookSharedClosedUrl ? 'status-row--ok' : 'status-row--off']">
								<span class="status-row__icon">{{ settings.webhookDocumentSharedClosedEndpoint === expectedWebhookSharedClosedUrl ? '✓' : '✗' }}</span>
								{{ t('signotecsignosignuniversal', 'Webhook URL matches expected URL') }}
							</div>
						</div>
					</div>
					<div class="webhook-actions">
						<NcButton :disabled="isSettingWebhook || webhooksAlreadyCorrect" @click="setWebhookUrl">
							{{
								t(
									'signotecsignosignuniversal',
									'Set Webhook URLs in signoSign/Universal'
								)
							}}
						</NcButton>
					</div>
				</div>

				<div class="sms-status">
					<h4>{{ t('signotecsignosignuniversal', 'SMS') }}</h4>
					<div :class="['status-row', settings.sms77ApiKeySet ? 'status-row--ok' : 'status-row--off']">
						<span class="status-row__icon">{{ settings.sms77ApiKeySet ? '✓' : '✗' }}</span>
						{{
							t(
								'signotecsignosignuniversal',
								'SMS API key configured (enables TAN via SMS)'
							)
						}}
					</div>
				</div>
			</NcSettingsSection>

			<!-- Section 3: Signature fields -->
			<NcSettingsSection
				v-if="settings.connectionValid"
				:name="t('signotecsignosignuniversal', 'Signature fields')"
				:description="t('signotecsignosignuniversal', 'Manage signature field placeholders and positions.')">
				<div class="section-header">
					<NcButton @click="addSignatureField">
						{{
							t(
								'signotecsignosignuniversal',
								'Add signature field'
							)
						}}
					</NcButton>
				</div>

				<div v-if="settings.signatureFields.length === 0" class="empty-state">
					{{
						t(
							'signotecsignosignuniversal',
							'No signature fields defined yet.'
						)
					}}
				</div>

				<div v-else class="table-wrapper">
					<table class="signature-table">
						<thead>
							<tr>
								<th>
									{{
										t(
											'signotecsignosignuniversal',
											'Signer'
										)
									}}
								</th>
								<th>
									{{
										t(
											'signotecsignosignuniversal',
											'Search text'
										)
									}}
								</th>
								<th>
									{{
										t('signotecsignosignuniversal', 'Width')
									}}
								</th>
								<th>
									{{
										t(
											'signotecsignosignuniversal',
											'Height'
										)
									}}
								</th>
								<th>
									{{
										t(
											'signotecsignosignuniversal',
											'Offset X'
										)
									}}
								</th>
								<th>
									{{
										t(
											'signotecsignosignuniversal',
											'Offset Y'
										)
									}}
								</th>
								<th>
									{{
										t(
											'signotecsignosignuniversal',
											'Required'
										)
									}}
								</th>
								<th>
									{{
										t(
											'signotecsignosignuniversal',
											'Actions'
										)
									}}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(
								field, index
							) in settings.signatureFields" :key="field.id || index">
								<td>
									<input :id="`field-signer-${index}`" v-model="field.signerName" type="text"
										:placeholder="t(
											'signotecsignosignuniversal',
											'e.g. John Doe'
										)
											" required>
								</td>

								<td>
									<input :id="`field-search-${index}`" v-model="field.searchText" type="text"
										:placeholder="t(
											'signotecsignosignuniversal',
											'e.g. [SIGN_HERE]'
										)
											" required>
								</td>

								<td>
									<input :id="`field-width-${index}`" v-model.number="field.width" type="number"
										min="1" required>
								</td>

								<td>
									<input :id="`field-height-${index}`" v-model.number="field.height" type="number"
										min="1" required>
								</td>

								<td>
									<input :id="`field-offsetx-${index}`" v-model.number="field.offsetX"
										type="number" required>
								</td>

								<td>
									<input :id="`field-offsety-${index}`" v-model.number="field.offsetY"
										type="number" required>
								</td>

								<td class="checkbox-cell">
									<input :id="`field-required-${index}`" v-model="field.required" type="checkbox">
								</td>

								<td class="actions-cell">
									<button type="button" class="icon-button" :title="t(
										'signotecsignosignuniversal',
										'Duplicate'
									)
										" :aria-label="t(
											'signotecsignosignuniversal',
											'Duplicate'
										)
											" @click="duplicateSignatureField(index)">
										<span class="icon icon-add" />
									</button>

									<button type="button" class="icon-button danger" :title="t(
										'signotecsignosignuniversal',
										'Remove'
									)
										" :aria-label="t(
											'signotecsignosignuniversal',
											'Remove'
										)
											" @click="removeSignatureField(index)">
										<span class="icon icon-delete" />
									</button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="form-actions form-actions--inline">
					<NcButton type="submit" variant="success" :disabled="isSaving">
						{{ t('signotecsignosignuniversal', 'Save') }}
					</NcButton>
				</div>
			</NcSettingsSection>

			<!-- Section 4: Nextcloud processing -->
			<NcSettingsSection
				v-if="settings.connectionValid"
				:name="t('signotecsignosignuniversal', 'Processing in Nextcloud')"
				:description="t('signotecsignosignuniversal', 'Automatically add comments and tags to files when sending or signing.')">
				<div class="form-group">
					<label for="comment-send">
						{{
							t(
								'signotecsignosignuniversal',
								'Comment when sending a request for signing'
							)
						}}
					</label>
					<input id="comment-send" v-model="settings.commentSend" type="text"
						:placeholder="t('signotecsignosignuniversal', 'e.g. Sent to @mailto@ via @authtype@')">
					<p class="hint">
						{{ t('signotecsignosignuniversal', 'Available placeholders: @mailto@ (recipient email), @authtype@ (auth method), @userid@ (sender)') }}
					</p>
				</div>

				<div class="form-group">
					<label for="comment-signed">
						{{
							t(
								'signotecsignosignuniversal',
								'Comment after signing'
							)
						}}
					</label>
					<input id="comment-signed" v-model="settings.commentSigned" type="text"
						:placeholder="t('signotecsignosignuniversal', 'e.g. Signed by @mailto@, sent by @userid@')">
					<p class="hint">
						{{ t('signotecsignosignuniversal', 'Available placeholders: @userid@ (sender), @mailto@ (recipient email)') }}
					</p>
				</div>

				<div class="form-group">
					<label for="comment-rejected">
						{{
							t(
								'signotecsignosignuniversal',
								'Comment when rejected'
							)
						}}
					</label>
					<input id="comment-rejected" v-model="settings.commentRejected" type="text"
						:placeholder="t('signotecsignosignuniversal', 'e.g. Rejected by @mailto@: @reason@')">
					<p class="hint">
						{{ t('signotecsignosignuniversal', 'Available placeholders: @reason@ (rejection reason), @mailto@ (recipient email)') }}
					</p>
				</div>

				<div class="form-group">
					<label for="tag-send">
						{{
							t(
								'signotecsignosignuniversal',
								'Tag when sending a request for signing'
							)
						}}
					</label>
					<input id="tag-send" v-model="settings.tagSend" type="text"
						:placeholder="t('signotecsignosignuniversal', 'e.g. sent-for-signing')">
				</div>

				<div class="form-group">
					<label for="tag-signed">
						{{
							t(
								'signotecsignosignuniversal',
								'Tag after signing'
							)
						}}
					</label>
					<input id="tag-signed" v-model="settings.tagSigned" type="text"
						:placeholder="t('signotecsignosignuniversal', 'e.g. signed')">
				</div>

				<div class="form-group">
					<label for="tag-rejected">
						{{
							t(
								'signotecsignosignuniversal',
								'Tag when rejected'
							)
						}}
					</label>
					<input id="tag-rejected" v-model="settings.tagRejected" type="text"
						:placeholder="t('signotecsignosignuniversal', 'e.g. rejected')">
				</div>

				<div class="form-actions form-actions--inline">
					<NcButton type="submit" variant="success" :disabled="isSaving">
						{{ t('signotecsignosignuniversal', 'Save') }}
					</NcButton>
				</div>
			</NcSettingsSection>
		</form>

		<!-- Section 5: Reset Settings -->
		<NcSettingsSection
			v-if="hasAnySettings"
			:name="t('signotecsignosignuniversal', 'Reset settings')"
			:description="t('signotecsignosignuniversal', 'Irreversible action.')">
			<NcButton variant="error" :disabled="isDeletingSettings" @click="deleteAllSettings">
				{{ t('signotecsignosignuniversal', 'Delete all settings') }}
			</NcButton>
		</NcSettingsSection>
	</div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { NcButton, NcSettingsSection } from '@nextcloud/vue'

const generateOcsUrl = (path) => `/ocs/v2.php${path}?format=json`

const createSignatureFieldId = () =>
	`signature_field_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`

const createEmptySignatureField = () => ({
	id: createSignatureFieldId(),
	signerName: '',
	searchText: '',
	width: 180,
	height: 60,
	offsetX: 0,
	offsetY: 0,
	required: false,
})

const settings = ref({
	url: '',
	username: '',
	password: '',
	hasPassword: false,
	connectionValid: false,
	connectionError: '',
	sms77ApiKeySet: false,
	webhookDocumentUpdatedEndpoint: '',
	webhookDocumentSharedClosedEndpoint: '',
	signatureFields: [],
	commentSend: '',
	commentSigned: '',
	commentRejected: '',
	tagSend: '',
	tagSigned: '',
	tagRejected: '',
})

const isSaving = ref(false)
const isSettingWebhook = ref(false)
const isDeletingSettings = ref(false)
const savedSettingsSnapshot = ref(null)

const expectedWebhookUpdatedUrl = computed(() =>
	`${globalThis.location.origin}/ocs/v2.php/apps/signotecsignosignuniversal/webhook_updated`,
)

const expectedWebhookSharedClosedUrl = computed(() =>
	`${globalThis.location.origin}/ocs/v2.php/apps/signotecsignosignuniversal/webhook_shared_closed`,
)

const hasAnySettings = computed(() =>
	settings.value.url !== ''
	|| settings.value.username !== ''
	|| settings.value.hasPassword,
)

const friendlyConnectionError = computed(() => {
	const err = settings.value.connectionError
	if (!err) {
		return ''
	}

	if (err.includes('401')) {
		return t(
			'signotecsignosignuniversal',
			'Invalid credentials. Please check your signoSign app settings: Username and Password must be correct.',
		)
	}

	if (err.includes('404')) {
		return t(
			'signotecsignosignuniversal',
			'Invalid URL. Please check your signoSign app settings: URL must be correct.',
		)
	}

	if (err.includes('Connection refused') || err.includes('Failed to connect') || err.includes('cURL error 7')) {
		return t(
			'signotecsignosignuniversal',
			'Could not connect to the signoSign/Universal server. Please check if the URL is reachable.',
		)
	}

	if (err.includes('SSL') || err.includes('certificate') || err.includes('cURL error 60')) {
		return t(
			'signotecsignosignuniversal',
			'SSL certificate error. Please check the server certificate or contact your administrator.',
		)
	}

	return err
})

const webhooksAlreadyCorrect = computed(() =>
	settings.value.webhookDocumentUpdatedEndpoint !== ''
	&& settings.value.webhookDocumentUpdatedEndpoint === expectedWebhookUpdatedUrl.value
	&& settings.value.webhookDocumentSharedClosedEndpoint !== ''
	&& settings.value.webhookDocumentSharedClosedEndpoint === expectedWebhookSharedClosedUrl.value,
)

const hasUnsavedChanges = computed(() => {
	if (savedSettingsSnapshot.value === null) {
		return false
	}
	return JSON.stringify(settings.value) !== savedSettingsSnapshot.value
})

const beforeUnloadHandler = (event) => {
	event.preventDefault()
}

watch(hasUnsavedChanges, (dirty) => {
	if (dirty) {
		window.addEventListener('beforeunload', beforeUnloadHandler)
	} else {
		window.removeEventListener('beforeunload', beforeUnloadHandler)
	}
})

onUnmounted(() => {
	window.removeEventListener('beforeunload', beforeUnloadHandler)
})

const normalizeSignatureFields = (fields) => {
	if (!Array.isArray(fields)) {
		return []
	}

	return fields.map((field) => ({
		id: field?.id || createSignatureFieldId(),
		signerName: field?.signerName || '',
		searchText: field?.searchText || '',
		width: field?.width || 180,
		height: field?.height || 60,
		offsetX: field?.offsetX || 0,
		offsetY: field?.offsetY || 0,
		required: Boolean(field?.required),
	}))
}

const normalizeSettingsResponse = (data) => ({
	url: data?.url ?? '',
	username: data?.username ?? '',
	password: '',
	hasPassword: Boolean(data?.hasPassword),
	connectionValid: Boolean(data?.connectionValid),
	connectionError: typeof data?.connectionError === 'string' ? data.connectionError : '',
	sms77ApiKeySet: Boolean(data?.sms77ApiKeySet),
	webhookDocumentUpdatedEndpoint: typeof data?.webhookDocumentUpdatedEndpoint === 'string'
		? data.webhookDocumentUpdatedEndpoint
		: '',
	webhookDocumentSharedClosedEndpoint: typeof data?.webhookDocumentSharedClosedEndpoint === 'string'
		? data.webhookDocumentSharedClosedEndpoint
		: '',
	signatureFields: normalizeSignatureFields(data?.signatureFields),
	commentSend: typeof data?.commentSend === 'string' ? data.commentSend : '',
	commentSigned: typeof data?.commentSigned === 'string' ? data.commentSigned : '',
	commentRejected: typeof data?.commentRejected === 'string' ? data.commentRejected : '',
	tagSend: typeof data?.tagSend === 'string' ? data.tagSend : '',
	tagSigned: typeof data?.tagSigned === 'string' ? data.tagSigned : '',
	tagRejected: typeof data?.tagRejected === 'string' ? data.tagRejected : '',
})

const fetchSettings = async () => {
	try {
		const response = await axios.get(
			generateOcsUrl('/apps/signotecsignosignuniversal/settings'),
		)

		settings.value = normalizeSettingsResponse(response.data.ocs.data)
		savedSettingsSnapshot.value = JSON.stringify(settings.value)
	} catch (error) {
		console.error('Failed to fetch settings', error)
		console.error('Response payload:', error.response?.data)
		showError(t('signotecsignosignuniversal', 'Failed to load settings'))
	}
}

const addSignatureField = () => {
	settings.value.signatureFields.push(createEmptySignatureField())
}

const setWebhookUrl = async () => {
	const confirmed = window.confirm(
		t(
			'signotecsignosignuniversal',
			'This will overwrite the webhook URLs configured in signoSign/Universal. Are you sure?',
		),
	)
	if (!confirmed) {
		return
	}

	if (isSettingWebhook.value) {
		return
	}

	isSettingWebhook.value = true

	try {
		const response = await axios.post(
			generateOcsUrl('/apps/signotecsignosignuniversal/settings/webhookurl'),
			{},
			{
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			},
		)

		const responseData = response.data?.ocs?.data ?? {}

		if (responseData.alreadyConfigured) {
			showSuccess(t('signotecsignosignuniversal', 'Webhook URLs are already configured correctly'))
		} else {
			showSuccess(
				responseData.message
				|| t('signotecsignosignuniversal', 'Webhook URLs updated successfully'),
			)
		}

		if (responseData.webhookUrlSaved) {
			settings.value.webhookDocumentUpdatedEndpoint = responseData.webhookUrlSaved
		}
		if (responseData.webhookUrlSharedClosed) {
			settings.value.webhookDocumentSharedClosedEndpoint = responseData.webhookUrlSharedClosed
		}
	} catch (error) {
		console.error('Failed to set Webhook URLs', error)
		showError(
			error.response?.data?.ocs?.data?.error
			|| t('signotecsignosignuniversal', 'Failed to set Webhook URLs'),
		)
	} finally {
		isSettingWebhook.value = false
	}
}

const removeSignatureField = (index) => {
	settings.value.signatureFields.splice(index, 1)
}

const duplicateSignatureField = (index) => {
	const field = settings.value.signatureFields[index]

	settings.value.signatureFields.splice(index + 1, 0, {
		...field,
		id: createSignatureFieldId(),
	})
}

const saveSettings = async () => {
	if (isSaving.value) {
		return
	}

	isSaving.value = true

	try {
		const payload = {
			url: settings.value.url,
			username: settings.value.username,
			commentSend: settings.value.commentSend,
			commentSigned: settings.value.commentSigned,
			commentRejected: settings.value.commentRejected,
			tagSend: settings.value.tagSend,
			tagSigned: settings.value.tagSigned,
			tagRejected: settings.value.tagRejected,
		}

		const trimmedPassword = settings.value.password.trim()
		if (trimmedPassword !== '') {
			payload.password = trimmedPassword
		}

		if (settings.value.connectionValid) {
			payload.signatureFields = settings.value.signatureFields
		}

		const response = await axios.post(
			generateOcsUrl('/apps/signotecsignosignuniversal/settings'),
			payload,
			{
				headers: {
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
			},
		)

		const responseData = response.data?.ocs?.data ?? {}
		const returnedSettings = responseData.settings ?? {}

		settings.value = normalizeSettingsResponse(returnedSettings)
		savedSettingsSnapshot.value = JSON.stringify(settings.value)

		showSuccess(
			responseData.message
			|| response.data?.ocs?.meta?.message
			|| t('signotecsignosignuniversal', 'Settings saved successfully'),
		)
	} catch (error) {
		console.error('Failed to save settings', error)
		console.error('Response status:', error.response?.status)
		console.error('Response payload:', error.response?.data)

		showError(
			error.response?.data?.ocs?.data?.error
			|| error.response?.data?.ocs?.meta?.message
			|| t('signotecsignosignuniversal', 'Failed to save settings'),
		)
	} finally {
		isSaving.value = false
	}
}

const deleteAllSettings = async () => {
	const confirmed = window.confirm(
		t(
			'signotecsignosignuniversal',
			'Delete all settings? This cannot be undone.',
		),
	)
	if (!confirmed) {
		return
	}

	if (isDeletingSettings.value) {
		return
	}

	isDeletingSettings.value = true

	try {
		await axios.delete(
			generateOcsUrl('/apps/signotecsignosignuniversal/settings'),
			{
				headers: {
					'OCS-APIRequest': 'true',
				},
			},
		)

		await fetchSettings()
		showSuccess(t('signotecsignosignuniversal', 'All settings deleted'))
	} catch (error) {
		console.error('Failed to delete settings', error)
		showError(
			error.response?.data?.ocs?.data?.error
			|| t('signotecsignosignuniversal', 'Failed to delete settings'),
		)
	} finally {
		isDeletingSettings.value = false
	}
}

onMounted(fetchSettings)
</script>

<style scoped>
.connection-status {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 12px;
	padding: 8px 12px;
	border-radius: var(--border-radius);
	font-weight: 500;
}

.connection-status--ok {
	color: var(--color-main-text);
	background-color: rgba(var(--color-success-rgb, 0, 130, 0), 0.45);
	border-inline-start: 3px solid var(--color-success);
}

.connection-status--ok .connection-status__icon {
	color: var(--color-main-text);
}

.connection-status--error {
	color: var(--color-main-text);
	background-color: var(--color-error-light, rgba(var(--color-error-rgb, 200, 0, 0), 0.45));
}

.connection-status__icon {
	font-size: 1.1em;
}

.status-row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
}

.status-row--ok {
	color: var(--color-main-text);
}

.status-row--ok .status-row__icon {
	color: var(--color-success);
}

.status-row--off {
	color: var(--color-text-maxcontrast);
}

.status-row__icon {
	font-size: 1.1em;
	font-weight: bold;
}

.sms-status {
	margin-top: 16px;
}

.sms-status h4 {
	margin-bottom: 4px;
}

.webhook-header {
	display: flex;
	gap: 16px;
	align-items: flex-start;
	justify-content: space-between;
	flex-wrap: wrap;
}

.webhook-urls {
	display: flex;
	flex-direction: column;
	gap: 16px;
	flex: 1;
}

.webhook-actions {
	display: flex;
	gap: 8px;
	flex-shrink: 0;
	align-self: flex-start;
}

.section-header {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 12px;
}

.table-wrapper {
	overflow-x: auto;
}

.signature-table {
	width: 100%;
	border-collapse: collapse;
}

.signature-table th,
.signature-table td {
	padding: 4px 8px;
	text-align: start;
	vertical-align: middle;
}

.signature-table input[type="text"],
.signature-table input[type="number"] {
	width: 100%;
	box-sizing: border-box;
}

.checkbox-cell {
	text-align: center;
}

.actions-cell {
	white-space: nowrap;
}

.icon-button {
	background: none;
	border: none;
	cursor: pointer;
	padding: 4px;
	color: var(--color-main-text);
	border-radius: var(--border-radius);
}

.icon-button:hover {
	background-color: var(--color-background-hover);
}

.icon-button.danger {
	color: var(--color-error);
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 500;
}

.form-group input[type="text"],
.form-group input[type="password"] {
	width: 100%;
	max-width: 400px;
}

.hint {
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.form-actions--inline {
	margin-top: 8px;
}

.empty-state {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 8px 0;
}

.unsaved-warning {
	display: inline-block;
	margin-bottom: 8px;
	padding: 6px 12px;
	border-radius: var(--border-radius);
	background-color: rgba(var(--color-warning-rgb, 200, 130, 0), 0.12);
	border-inline-start: 3px solid var(--color-warning, #c88200);
	color: var(--color-main-text);
	font-size: 0.9em;
}
</style>
