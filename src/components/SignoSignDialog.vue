<template>
	<NcModal class="send-modal" size="large" @close="onReject">
		<div class="send-modal-content">
			<h2 class="stc-title">
				{{ dialogTitle }}
			</h2>

			<div class="external-label">
				<label for="recipientField">{{ recipientEmailLabel }}</label>
				<NcTextField id="recipientField" ref="recipientRef" v-model="recipientEmail" :label-outside="true"
					type="email" required />
			</div>

			<hr>

			<NcSelect v-model="authType" :options="availableAuthTypes" :clearable="false" :input-label="authenticationLabel"
				label="label" value-key="value" />

			<div v-if="authType.value === 'password'" class="external-label">
				<label for="passwordField">{{ passwordLabel }}</label>
				<NcTextField id="passwordField" v-model="password" :label-outside="true" required />
			</div>

			<div v-if="authType.value === 'tan_sms'" class="external-label">
				<label for="tanPhoneField">{{ tanPhoneLabel }}</label>
				<NcTextField id="tanPhoneField" v-model="tanTarget" :label-outside="true" type="tel" required />
			</div>

			<div v-if="authType.value === 'tan_email'" class="external-label">
				<label for="tanMailField">{{ tanEmailLabel }}</label>
				<NcTextField id="tanMailField" v-model="tanTarget" :label-outside="true" type="email" required />
			</div>

			<hr>

			<div class="confirm-modal__buttons">
				<NcButton variant="tertiary" @click="onReject">
					{{ cancelLabel }}
				</NcButton>
				<NcButton variant="primary" :disabled="isSendDisabled" @click="onResolve">
					{{ sendLabel }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script lang="ts">
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { NcButton, NcModal, NcSelect, NcTextField } from '@nextcloud/vue'

const generateOcsUrl = (path: string) => `/ocs/v2.php${path}?format=json`
const PREFS_URL = generateOcsUrl('/apps/signotecsignosignuniversal/userprefs')
const SETTINGS_URL = generateOcsUrl('/apps/signotecsignosignuniversal/settings')

type AuthMode = 'none' | 'password' | 'tan_sms' | 'tan_email'
type AuthOption = { label: string; value: AuthMode }

const AllAuthTypes: AuthOption[] = [
	{
		label: t('signotecsignosignuniversal', 'No authentication'),
		value: 'none',
	},
	{
		label: t('signotecsignosignuniversal', 'Password'),
		value: 'password',
	},
	{
		label: t('signotecsignosignuniversal', 'TAN via SMS'),
		value: 'tan_sms',
	},
	{
		label: t('signotecsignosignuniversal', 'TAN via email'),
		value: 'tan_email',
	},
]

export default {
	name: 'SignoSignDialog',
	components: {
		NcModal,
		NcButton,
		NcTextField,
		NcSelect,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
		resolve: {
			type: Function,
			required: true,
		},
		reject: {
			type: Function,
			required: true,
		},
	},
	emits: ['resolve', 'reject', 'close'],
	data() {
		return {
			recipientEmail: '',
			authType: AllAuthTypes[0] as AuthOption,
			password: '',
			tanTarget: '',
			sms77ApiKeySet: false,
		}
	},
	computed: {
		availableAuthTypes(): AuthOption[] {
			if (this.sms77ApiKeySet) {
				return AllAuthTypes
			}
			return AllAuthTypes.filter(o => o.value !== 'tan_sms')
		},
		dialogTitle(): string {
			return this.title || t('signotecsignosignuniversal', 'Request a remote signature')
		},
		authenticationLabel(): string {
			return t('signotecsignosignuniversal', 'Authentication')
		},
		cancelLabel(): string {
			return t('signotecsignosignuniversal', 'Cancel')
		},
		sendLabel(): string {
			return t('signotecsignosignuniversal', 'Send')
		},
		recipientEmailLabel(): string {
			return t('signotecsignosignuniversal', 'Recipient email')
		},
		passwordLabel(): string {
			return t('signotecsignosignuniversal', 'Password')
		},
		tanPhoneLabel(): string {
			return t('signotecsignosignuniversal', 'TAN phone number')
		},
		tanEmailLabel(): string {
			return t('signotecsignosignuniversal', 'TAN email')
		},
		isValidEmail(): boolean {
			return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.recipientEmail.trim())
		},
		isSendDisabled(): boolean {
			if (this.recipientEmail.trim() === '' || !this.isValidEmail) {
				return true
			}
			if (this.authType.value === 'password' && this.password.trim() === '') {
				return true
			}
			if (
				(this.authType.value === 'tan_sms' || this.authType.value === 'tan_email')
				&& this.tanTarget.trim() === ''
			) {
				return true
			}
			return false
		},
	},
	async mounted() {
		try {
			const [prefsRes, settingsRes] = await Promise.all([
				axios.get<{ ocs: { data: { authType: AuthMode } } }>(PREFS_URL),
				axios.get<{ ocs: { data: { sms77ApiKeySet: boolean } } }>(SETTINGS_URL),
			])

			this.sms77ApiKeySet = Boolean(settingsRes.data.ocs.data.sms77ApiKeySet)

			const savedAuthMode = prefsRes.data.ocs.data.authType
			// If saved pref is tan_sms but SMS is not available, fall back to default
			if (savedAuthMode === 'tan_sms' && !this.sms77ApiKeySet) {
				this.authType = AllAuthTypes[0]
			} else {
				const match = AllAuthTypes.find(o => o.value === savedAuthMode)
				if (match) {
					this.authType = match
				}
			}
		} catch {
			// keep defaults
		}
		(this.$refs.recipientRef as { focus?: () => void } | undefined)?.focus?.()
	},
	methods: {
		onReject() {
			this.reject?.()
			this.$emit('close')
		},
		onResolve() {
			axios.post(PREFS_URL, { authType: this.authType.value }).catch(() => {
				// best-effort, don't block signing
			})

			const data = {
				recipientEmail: this.recipientEmail.trim(),
				authType: this.authType,
				password: this.password.trim(),
				tanTarget: this.tanTarget.trim(),
			}

			this.resolve(data)
			this.$emit('close')
		},
	},
}
</script>
