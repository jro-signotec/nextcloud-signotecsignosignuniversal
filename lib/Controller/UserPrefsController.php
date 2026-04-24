<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Controller;

use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\Config\IUserConfig;
use OCP\IRequest;

final class UserPrefsController extends OCSController {
	private const VALID_AUTH_TYPES = ['none', 'password', 'tan_sms', 'tan_email'];
	private const PREF_AUTH_TYPE = 'sign_dialog_auth_type';

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserConfig $userPreferences,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/userprefs')]
	public function getPrefs(): DataResponse {
		return new DataResponse([
			'authType' => $this->userPreferences->getValueString(
				$this->userId,
				$this->appName,
				self::PREF_AUTH_TYPE,
				'none',
			),
		]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/userprefs')]
	public function setPrefs(?string $authType = null): DataResponse {
		if ($authType === null || !in_array($authType, self::VALID_AUTH_TYPES, true)) {
			throw new OCSBadRequestException('Invalid authType');
		}

		$this->userPreferences->setValueString($this->userId, $this->appName, self::PREF_AUTH_TYPE, $authType);

		return new DataResponse(['authType' => $authType]);
	}
}
