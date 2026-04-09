<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Tests\Unit\Controller;

use OCA\SignotecSignoSignUniversal\Controller\SettingsController;
use OCA\SignotecSignoSignUniversal\Service\SettingsService;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private SettingsService&MockObject $settingsService;
	private SettingsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->settingsService = $this->createMock(SettingsService::class);

		$this->controller = new SettingsController(
			'signotecsignosignuniversal',
			$this->request,
			$this->settingsService,
		);
	}

	public function testGetSettingsReturnsPublicSettings(): void {
		$settings = [
			'url' => 'https://sign.example.test',
			'username' => 'demo-user',
			'hasPassword' => true,
			'signatureFields' => [
				[
					'id' => 'sig-1',
					'signerName' => 'Signer 1',
					'searchText' => 'FIELD_1',
					'width' => 180,
					'height' => 60,
					'offsetX' => 0,
					'offsetY' => 0,
					'required' => true,
				],
			],
		];

		$this->settingsService->expects(self::once())
			->method('getPublicSettings')
			->willReturn($settings);

		$response = $this->controller->getSettings();

		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(200, $response->getStatus());
		self::assertSame($settings, $response->getData());
	}

	public function testSetSettingsReturnsBadRequestWhenServiceReturnsError(): void {
		$this->settingsService->expects(self::once())
			->method('setSettings')
			->with(
				'https://sign.example.test',
				'demo-user',
				'secret',
				'invalid'
			)
			->willReturn([
				'error' => 'signatureFields must be an array',
			]);

		$this->settingsService->expects(self::never())
			->method('getPublicSettings');

		$response = $this->controller->setSettings(
			'https://sign.example.test',
			'demo-user',
			'secret',
			'invalid'
		);

		self::assertSame(400, $response->getStatus());
		self::assertSame([
			'error' => 'signatureFields must be an array',
		], $response->getData());
	}

	public function testSetSettingsReturnsUpdatedSettingsOnSuccess(): void {
		$updatedSettings = [
			'url' => 'https://sign.example.test',
			'username' => 'demo-user',
			'hasPassword' => true,
			'signatureFields' => [
				[
					'id' => 'sig-1',
					'signerName' => 'Signer 1',
					'searchText' => 'FIELD_1',
					'width' => 180,
					'height' => 60,
					'offsetX' => 0,
					'offsetY' => 0,
					'required' => false,
				],
			],
		];

		$signatureFields = [
			[
				'id' => 'sig-1',
				'signerName' => 'Signer 1',
				'searchText' => 'FIELD_1',
				'width' => 180,
				'height' => 60,
				'offsetX' => 0,
				'offsetY' => 0,
				'required' => false,
			],
		];

		$this->settingsService->expects(self::once())
			->method('setSettings')
			->with(
				'https://sign.example.test',
				'demo-user',
				'secret',
				$signatureFields
			)
			->willReturn(null);

		$this->settingsService->expects(self::once())
			->method('getPublicSettings')
			->willReturn($updatedSettings);

		$response = $this->controller->setSettings(
			'https://sign.example.test',
			'demo-user',
			'secret',
			$signatureFields
		);

		self::assertSame(200, $response->getStatus());
		self::assertSame([
			'message' => 'Settings updated',
			'settings' => $updatedSettings,
		], $response->getData());
	}
}
