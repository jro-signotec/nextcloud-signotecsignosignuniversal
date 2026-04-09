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
	): DataResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			throw new OCSForbiddenException();
		}

		$result = $this->settingsService->setSettings($url, $username, $password, $signatureFields);

		if (isset($result['error'])) {
			return new DataResponse($result, 400);
		}

		return new DataResponse([
			'message' => $this->l->t('Settings updated'),
			'settings' => $this->settingsService->getPublicSettings(),
		]);
	}
}
