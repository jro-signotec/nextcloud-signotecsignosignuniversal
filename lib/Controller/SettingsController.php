<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Controller;

use OCA\SignotecSignoSignUniversal\Service\SettingsService;
use OCA\SignotecSignoSignUniversal\Service\SignoSignUniversal;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

final class SettingsController extends OCSController {
	private const SETTINGS_ROUTE = '/settings';

	public function __construct(
		string $appName,
		IRequest $request,
		private SettingsService $settingsService,
		private SignoSignUniversal $signoSignUniversal,
		private IL10N $l,
		private IGroupManager $groupManager,
		private IURLGenerator $urlGenerator,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[ApiRoute(verb: 'GET', url: self::SETTINGS_ROUTE)]
	public function getSettings(): DataResponse {
		if ($this->settingsService->getUrl() !== ''
			&& $this->settingsService->getUsername() !== ''
			&& $this->settingsService->getPassword() !== ''
		) {
			$this->testAndStoreConnection();
		}
		return new DataResponse($this->settingsService->getPublicSettings());
	}

	#[ApiRoute(verb: 'POST', url: self::SETTINGS_ROUTE)]
	public function setSettings(
		?string $url = null,
		?string $username = null,
		?string $password = null,
		mixed $signatureFields = null,
		?string $commentSend = null,
		?string $commentSigned = null,
		?string $commentRejected = null,
		?string $tagSend = null,
		?string $tagSigned = null,
		?string $tagRejected = null,
	): DataResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			throw new OCSForbiddenException();
		}

		$result = $this->settingsService->setSettings(array_filter([
			'url' => $url,
			'username' => $username,
			'password' => $password,
			'signatureFields' => $signatureFields,
			'commentSend' => $commentSend,
			'commentSigned' => $commentSigned,
			'commentRejected' => $commentRejected,
			'tagSend' => $tagSend,
			'tagSigned' => $tagSigned,
			'tagRejected' => $tagRejected,
		], static fn ($v) => $v !== null));

		if (isset($result['error'])) {
			return new DataResponse($result, 400);
		}

		$this->testAndStoreConnection();

		return new DataResponse([
			'message' => $this->l->t('Settings updated'),
			'settings' => $this->settingsService->getPublicSettings(),
		]);
	}

	#[ApiRoute(verb: 'DELETE', url: self::SETTINGS_ROUTE)]
	public function deleteSettings(): DataResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			throw new OCSForbiddenException();
		}

		$this->settingsService->deleteAllSettings();

		return new DataResponse(['message' => $this->l->t('All settings deleted')]);
	}

	#[ApiRoute(verb: 'POST', url: '/settings/webhookurl')]
	public function setWebhookUrl(): DataResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			throw new OCSForbiddenException();
		}

		$targetUrlDocumentSaved = $this->urlGenerator->getAbsoluteURL(
			'/ocs/v2.php/apps/signotecsignosignuniversal/webhook_updated'
		);

		$targetUrlSharedClosed = $this->urlGenerator->getAbsoluteURL(
			'/ocs/v2.php/apps/signotecsignosignuniversal/webhook_shared_closed'
		);

		$tokenResult = $this->signoSignUniversal->getInstanceToken();
		if (isset($tokenResult['error'])) {
			return new DataResponse(['error' => $tokenResult['error']], 500);
		}

		assert(isset($tokenResult['token']));
		$token = $tokenResult['token'];

		try {
			$userSettings = $this->signoSignUniversal->getUserSettings($token);
			if (isset($userSettings['error'])) {
				return new DataResponse(['error' => $userSettings['error']], 500);
			}

			$currentUrlDocumentSaved = is_string($userSettings['webhooks.documentUpdatedEndpoint'] ?? null)
				? (string)$userSettings['webhooks.documentUpdatedEndpoint']
				: '';

			$currentUrlSharedClosed = is_string($userSettings['webhooks.documentSharedClosedEndpoint'] ?? null)
				? (string)$userSettings['webhooks.documentSharedClosedEndpoint']
				: '';

			if ($currentUrlDocumentSaved === $targetUrlDocumentSaved && $currentUrlSharedClosed === $targetUrlSharedClosed) {
				return new DataResponse([
					'alreadyConfigured' => true,
					'webhookUrlSaved' => $targetUrlDocumentSaved,
					'webhookUrlSharedClosed' => $targetUrlSharedClosed,
					'message' => $this->l->t('Webhook URL is already configured correctly'),
				]);
			}

			$updateResult = $this->signoSignUniversal->updateUserSettings($token, [
				'webhooks.documentUpdatedEndpoint' => $targetUrlDocumentSaved,
				'webhooks.documentSharedClosedEndpoint' => $targetUrlSharedClosed,
			]);

			if (isset($updateResult['error'])) {
				return new DataResponse(['error' => $updateResult['error']], 500);
			}
		} finally {
			$this->signoSignUniversal->revokeInstanceToken($token);
		}

		$this->settingsService->setConnectionData(
			true,
			$this->settingsService->getSms77ApiKeySet(),
			$targetUrlDocumentSaved,
			$targetUrlSharedClosed,
		);

		return new DataResponse([
			'webhookUrlSaved' => $targetUrlDocumentSaved,
			'webhookUrlSharedClosed' => $targetUrlSharedClosed,
			'message' => $this->l->t('Webhook URL updated successfully'),
		]);
	}

	private function testAndStoreConnection(): void {
		$tokenResult = $this->signoSignUniversal->getInstanceToken();
		if (isset($tokenResult['error'])) {
			$this->settingsService->setConnectionData(false, false, '', '', $tokenResult['error']);
			return;
		}

		assert(isset($tokenResult['token']));
		$token = $tokenResult['token'];

		try {
			$userSettings = $this->signoSignUniversal->getUserSettings($token);
		} finally {
			$this->signoSignUniversal->revokeInstanceToken($token);
		}

		if (isset($userSettings['error'])) {
			$this->settingsService->setConnectionData(false, false, '', '', (string)$userSettings['error']);
			return;
		}

		$sms77KeySet = !empty($userSettings['sms77.apiKey']);
		$webhookDocumentUpdatedEndpoint = isset($userSettings['webhooks.documentUpdatedEndpoint'])
			&& is_string($userSettings['webhooks.documentUpdatedEndpoint'])
			? $userSettings['webhooks.documentUpdatedEndpoint']
			: '';
		$webhookSharedClosedEndpoint = isset($userSettings['webhooks.documentSharedClosedEndpoint'])
			&& is_string($userSettings['webhooks.documentSharedClosedEndpoint'])
			? $userSettings['webhooks.documentSharedClosedEndpoint']
			: '';

		$this->settingsService->setConnectionData(true, $sms77KeySet, $webhookDocumentUpdatedEndpoint, $webhookSharedClosedEndpoint);
	}
}
