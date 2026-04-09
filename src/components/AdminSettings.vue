<template>
	<div class="sectionSignotecSignoSignUniversal">
		<h2>
			{{
				t(
					'signotecsignosignuniversal',
					'Settings for SignotecSignoSignUniversal'
				)
			}}
		</h2>

		<form class="settings-form" @submit.prevent="saveSettings">
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

				<p v-else class="hint">
					{{
						t(
							'signotecsignosignuniversal',
							'Please set a password to use signature fields.'
						)
					}}
				</p>
			</div>

			<div v-if="settings.hasPassword">
				<div class="webhook-header">
					<div>
						<h3>
							{{
								t(
									'signotecsignosignuniversal',
									'Webhook URL for signature updates'
								)
							}}
						</h3>
					</div>
					<NcButton @click="CopyWebhookURLToClipboard">
						{{
							t(
								'signotecsignosignuniversal',
								'Copy Webhook URL to clipboard'
							)
						}}
					</NcButton>
				</div>

				<div class="signature-fields">
					<div class="section-header">
						<div>
							<h3>
								{{
									t(
										'signotecsignosignuniversal',
										'Signature fields'
									)
								}}
							</h3>
							<p class="section-description">
								{{
									t(
										'signotecsignosignuniversal',
										'Manage signature field placeholders and positions.'
									)
								}}
							</p>
						</div>

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
												'e.g. Max Mustermann'
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
				</div>
			</div>

			<div class="form-actions">
				<NcButton type="submit" variant="success" :disabled="isSaving">
					{{ t('signotecsignosignuniversal', 'Save') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'

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
	signatureFields: [],
})

const isSaving = ref(false)

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
	signatureFields: normalizeSignatureFields(data?.signatureFields),
})

const fetchSettings = async () => {
	try {
		const response = await axios.get(
			generateOcsUrl('/apps/signotecsignosignuniversal/settings'),
		)

		settings.value = normalizeSettingsResponse(response.data.ocs.data)
	} catch (error) {
		console.error('Failed to fetch settings', error)
		console.error('Response payload:', error.response?.data)
		showError(t('signotecsignosignuniversal', 'Failed to load settings'))
	}
}

const addSignatureField = () => {
	settings.value.signatureFields.push(createEmptySignatureField())
}

const CopyWebhookURLToClipboard = () => {
	const webhookUrl = `${globalThis.location.origin}${generateOcsUrl('/apps/signotecsignosignuniversal/webhook_updated').replace('?format=json', '')}`

	navigator.clipboard.writeText(webhookUrl)
		.then(() => {
			showSuccess(t('signotecsignosignuniversal', 'Webhook URL copied to clipboard'))
		})
		.catch((err) => {
			console.error('Failed to copy webhook URL', err)
			showError(t('signotecsignosignuniversal', 'Failed to copy Webhook URL'))
		})
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
		}

		const trimmedPassword = settings.value.password.trim()
		if (trimmedPassword !== '') {
			payload.password = trimmedPassword
		}

		if (settings.value.hasPassword) {
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

onMounted(fetchSettings)
</script>
