<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Controller;

use OCA\SignotecSignoSignUniversal\Service\SettingsService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IL10N;

class SettingsController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private SettingsService $settingsService,
		private IL10N $l,
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
