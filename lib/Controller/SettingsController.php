<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Controller;

use OCA\SignotecSignoSignUniversal\Service\SettingsService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;

final class SettingsController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private SettingsService $settingsService,
		private IL10N $l,
		private IGroupManager $groupManager,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[ApiRoute(verb: 'GET', url: '/settings')]
	public function getSettings(): DataResponse {
		return new DataResponse($this->settingsService->getPublicSettings());
	}

	#[ApiRoute(verb: 'POST', url: '/settings')]
	public function setSettings(
		?string $url = null,
		?string $username = null,
		?string $password = null,
		mixed $signatureFields = null,
		?string $commentLanguageSend = null,
		?string $commentLanguageSigned = null,
		?string $tagSend = null,
		?string $tagSigned = null,
	): DataResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			throw new OCSForbiddenException();
		}

		$result = $this->settingsService->setSettings(array_filter([
			'url' => $url,
			'username' => $username,
			'password' => $password,
			'signatureFields' => $signatureFields,
			'commentLanguageSend' => $commentLanguageSend,
			'commentLanguageSigned' => $commentLanguageSigned,
			'tagSend' => $tagSend,
			'tagSigned' => $tagSigned,
		], static fn ($v) => $v !== null));

		if (isset($result['error'])) {
			return new DataResponse($result, 400);
		}

		return new DataResponse([
			'message' => $this->l->t('Settings updated'),
			'settings' => $this->settingsService->getPublicSettings(),
		]);
	}
}
