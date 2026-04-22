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

			<NcSelect v-model="notificationLanguage" :options="availableLanguages" :clearable="false"
				:input-label="notificationLanguageLabel" label="label" value-key="value" />

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

			<details class="email-options">
				<summary>{{ emailOptionsLabel }}</summary>
				<div class="email-options__fields">
					<div class="external-label">
						<label for="mailSubjectField">{{ mailSubjectLabel }}</label>
						<NcTextField id="mailSubjectField" v-model="mailSubject" :label-outside="true" />
					</div>
					<div class="external-label">
						<label for="mailMessageField">{{ mailMessageLabel }}</label>
						<NcTextArea id="mailMessageField" v-model="mailMessage" :label-outside="true" :rows="2" />
					</div>
					<div class="external-label">
						<label for="mailSignatureTextField">{{ mailSignatureTextLabel }}</label>
						<NcTextArea id="mailSignatureTextField" v-model="mailSignatureText" :label-outside="true" :rows="2" />
					</div>
				</div>
			</details>

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
import { t, getLanguage } from '@nextcloud/l10n'
import { NcButton, NcModal, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'

const generateOcsUrl = (path: string) => `/ocs/v2.php${path}?format=json`
const PREFS_URL = generateOcsUrl('/apps/signotecsignosignuniversal/userprefs')
const SETTINGS_URL = generateOcsUrl('/apps/signotecsignosignuniversal/settings')

type AuthMode = 'none' | 'password' | 'tan_sms' | 'tan_email'
type AuthOption = { label: string; value: AuthMode }

type LangCode = 'de' | 'en' | 'fr'
type LangOption = { label: string; value: LangCode }

export default {
	name: 'SignoSignDialog',
	components: {
		NcModal,
		NcButton,
		NcTextField,
		NcTextArea,
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
		const userLang = getLanguage().split('-')[0] as LangCode
		return {
			recipientEmail: '',
			notificationLanguageCode: (['de', 'en', 'fr'].includes(userLang) ? userLang : 'en') as LangCode,
			authTypeValue: 'none' as AuthMode,
			password: '',
			tanTarget: '',
			mailSubject: '',
			mailMessage: '',
			mailSignatureText: '',
			sms77ApiKeySet: false,
		}
	},
	computed: {
		allLanguages(): LangOption[] {
			return [
				{ label: t('signotecsignosignuniversal', 'German'), value: 'de' as LangCode },
				{ label: t('signotecsignosignuniversal', 'English'), value: 'en' as LangCode },
				{ label: t('signotecsignosignuniversal', 'French'), value: 'fr' as LangCode },
			]
		},
		allAuthTypes(): AuthOption[] {
			return [
				{ label: t('signotecsignosignuniversal', 'No authentication'), value: 'none' as AuthMode },
				{ label: t('signotecsignosignuniversal', 'Password'), value: 'password' as AuthMode },
				{ label: t('signotecsignosignuniversal', 'TAN via SMS'), value: 'tan_sms' as AuthMode },
				{ label: t('signotecsignosignuniversal', 'TAN via email'), value: 'tan_email' as AuthMode },
			]
		},
		notificationLanguage: {
			get(): LangOption {
				return this.allLanguages.find((l: LangOption) => l.value === this.notificationLanguageCode)
					?? this.allLanguages.find((l: LangOption) => l.value === 'en')!
			},
			set(option: LangOption) {
				this.notificationLanguageCode = option.value
			},
		},
		authType: {
			get(): AuthOption {
				return this.allAuthTypes.find((o: AuthOption) => o.value === this.authTypeValue)
					?? this.allAuthTypes[0]!
			},
			set(option: AuthOption) {
				this.authTypeValue = option.value
			},
		},
		availableLanguages(): LangOption[] {
			return this.allLanguages
		},
		availableAuthTypes(): AuthOption[] {
			if (this.sms77ApiKeySet) {
				return this.allAuthTypes
			}
			return this.allAuthTypes.filter((o: AuthOption) => o.value !== 'tan_sms')
		},
		dialogTitle(): string {
			return this.title || t('signotecsignosignuniversal', 'Request a remote signature')
		},
		notificationLanguageLabel(): string {
			return t('signotecsignosignuniversal', 'Notification language')
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
		emailOptionsLabel(): string {
			return t('signotecsignosignuniversal', 'Email options (optional)')
		},
		mailSubjectLabel(): string {
			return t('signotecsignosignuniversal', 'Subject')
		},
		mailMessageLabel(): string {
			return t('signotecsignosignuniversal', 'MailText')
		},
		mailSignatureTextLabel(): string {
			return t('signotecsignosignuniversal', 'MailSignature')
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
				this.authTypeValue = 'none'
			} else if ((['none', 'password', 'tan_sms', 'tan_email'] as AuthMode[]).includes(savedAuthMode)) {
				this.authTypeValue = savedAuthMode
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
				notificationLanguage: this.notificationLanguage.value,
				authType: this.authType,
				password: this.password.trim(),
				tanTarget: this.tanTarget.trim(),
				mailSubject: this.mailSubject.trim(),
				mailMessage: this.mailMessage.trim(),
				mailSignatureText: this.mailSignatureText.trim(),
			}

			this.resolve(data)
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.email-options {
	margin-block: 8px;
}

.email-options > summary {
	cursor: pointer;
	user-select: none;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.email-options__fields {
	margin-top: 8px;
	display: flex;
	flex-direction: column;
	gap: 4px;
}
</style>
