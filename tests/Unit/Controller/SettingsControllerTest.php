<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Tests\Unit\Controller;

use OCA\SignotecSignoSignUniversal\Controller\SettingsController;
use OCA\SignotecSignoSignUniversal\Service\SettingsService;
use OCA\SignotecSignoSignUniversal\Service\SignoSignUniversal;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private SettingsService&MockObject $settingsService;
	private SignoSignUniversal&MockObject $signoSignUniversal;
	private IL10N&MockObject $l;
	private IGroupManager&MockObject $groupManager;
	private IURLGenerator&MockObject $urlGenerator;
	private SettingsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->signoSignUniversal = $this->createMock(SignoSignUniversal::class);
		$this->l = $this->createMock(IL10N::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);

		$this->controller = new SettingsController(
			'signotecsignosignuniversal',
			$this->request,
			$this->settingsService,
			$this->signoSignUniversal,
			$this->l,
			$this->groupManager,
			$this->urlGenerator,
			'admin',
		);
	}

	public function testGetSettingsReturnsPublicSettings(): void {
		$settings = [
			'url' => 'https://sign.example.test',
			'username' => 'demo-user',
			'hasPassword' => true,
			'signatureFields' => [],
		];

		// getUrl/getUsername/getPassword return '' by default → no connection test
		$this->settingsService->expects(self::once())
			->method('getPublicSettings')
			->willReturn($settings);

		$this->signoSignUniversal->expects(self::never())
			->method('getInstanceToken');

		$response = $this->controller->getSettings();

		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(200, $response->getStatus());
		self::assertSame($settings, $response->getData());
	}

	public function testGetSettingsTriggersConnectionTestWhenCredentialsAreSet(): void {
		$this->settingsService->method('getUrl')->willReturn('https://sign.example.test');
		$this->settingsService->method('getUsername')->willReturn('user');
		$this->settingsService->method('getPassword')->willReturn('pass');
		$this->settingsService->method('getPublicSettings')->willReturn([]);

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['error' => 'unreachable']);

		$this->settingsService->expects(self::once())
			->method('setConnectionData')
			->with(false, false, '', '', 'unreachable');

		$this->controller->getSettings();
	}

	public function testSetSettingsReturnsBadRequestWhenServiceReturnsError(): void {
		$this->groupManager->expects(self::once())
			->method('isAdmin')
			->with('admin')
			->willReturn(true);

		$this->settingsService->expects(self::once())
			->method('setSettings')
			->with(['url' => 'https://sign.example.test', 'username' => 'demo-user', 'password' => 'secret', 'signatureFields' => 'invalid'])
			->willReturn(['error' => 'signatureFields must be an array']);

		$this->settingsService->expects(self::never())
			->method('getPublicSettings');

		$response = $this->controller->setSettings(
			'https://sign.example.test',
			'demo-user',
			'secret',
			'invalid'
		);

		self::assertSame(400, $response->getStatus());
		self::assertSame(['error' => 'signatureFields must be an array'], $response->getData());
	}

	public function testSetSettingsReturnsUpdatedSettingsOnSuccess(): void {
		$updatedSettings = [
			'url' => 'https://sign.example.test',
			'username' => 'demo-user',
			'hasPassword' => true,
			'signatureFields' => [],
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

		$this->groupManager->expects(self::once())
			->method('isAdmin')
			->with('admin')
			->willReturn(true);

		$this->settingsService->expects(self::once())
			->method('setSettings')
			->with(['url' => 'https://sign.example.test', 'username' => 'demo-user', 'password' => 'secret', 'signatureFields' => $signatureFields])
			->willReturn(null);

		$this->settingsService->expects(self::once())
			->method('getPublicSettings')
			->willReturn($updatedSettings);

		// testAndStoreConnection — token fetch fails gracefully
		$this->signoSignUniversal->method('getInstanceToken')
			->willReturn(['error' => 'not needed']);
		$this->settingsService->method('setConnectionData');

		$this->l->expects(self::once())
			->method('t')
			->with('Settings updated')
			->willReturn('Settings updated');

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

	public function testSetSettingsPassesOnlyNonNullParams(): void {
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->signoSignUniversal->method('getInstanceToken')->willReturn(['error' => 'x']);
		$this->settingsService->method('setConnectionData');
		$this->l->method('t')->willReturn('Settings updated');
		$this->settingsService->method('getPublicSettings')->willReturn([]);

		// Only url passed → only 'url' key in array
		$this->settingsService->expects(self::once())
			->method('setSettings')
			->with(['url' => 'https://sign.example.test'])
			->willReturn(null);

		$this->controller->setSettings(url: 'https://sign.example.test');
	}

	public function testDeleteSettingsReturnsSuccessMessage(): void {
		$this->groupManager->expects(self::once())
			->method('isAdmin')
			->with('admin')
			->willReturn(true);

		$this->settingsService->expects(self::once())
			->method('deleteAllSettings');

		$this->l->expects(self::once())
			->method('t')
			->with('All settings deleted')
			->willReturn('All settings deleted');

		$response = $this->controller->deleteSettings();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['message' => 'All settings deleted'], $response->getData());
	}

	public function testSetWebhookUrlReturnsErrorWhenTokenFetchFails(): void {
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['error' => 'connection refused']);

		$response = $this->controller->setWebhookUrl();

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'connection refused'], $response->getData());
	}

	public function testSetWebhookUrlReturnsErrorWhenUserSettingsFetchFails(): void {
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer tok']);

		$this->signoSignUniversal->expects(self::once())
			->method('getUserSettings')
			->with('Bearer tok')
			->willReturn(['error' => 'forbidden']);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken')
			->with('Bearer tok');

		$response = $this->controller->setWebhookUrl();

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'forbidden'], $response->getData());
	}

	public function testSetWebhookUrlSkipsUpdateWhenAlreadyConfigured(): void {
		$docUrl = '/ocs/v2.php/apps/signotecsignosignuniversal/webhook_updated';
		$closedUrl = '/ocs/v2.php/apps/signotecsignosignuniversal/webhook_shared_closed';

		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->urlGenerator->expects(self::exactly(2))
			->method('getAbsoluteURL')
			->willReturnOnConsecutiveCalls($docUrl, $closedUrl);

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer tok']);

		$this->signoSignUniversal->expects(self::once())
			->method('getUserSettings')
			->willReturn([
				'webhooks.documentUpdatedEndpoint' => $docUrl,
				'webhooks.documentSharedClosedEndpoint' => $closedUrl,
			]);

		$this->signoSignUniversal->expects(self::never())
			->method('updateUserSettings');

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken');

		$this->l->expects(self::once())
			->method('t')
			->with('Webhook URL is already configured correctly')
			->willReturn('Webhook URL is already configured correctly');

		$response = $this->controller->setWebhookUrl();

		self::assertSame(200, $response->getStatus());
		self::assertSame([
			'alreadyConfigured' => true,
			'webhookUrlSaved' => $docUrl,
			'webhookUrlSharedClosed' => $closedUrl,
			'message' => 'Webhook URL is already configured correctly',
		], $response->getData());
	}

	public function testSetWebhookUrlUpdatesAndStoresOnSuccess(): void {
		$docUrl = '/ocs/v2.php/apps/signotecsignosignuniversal/webhook_updated';
		$closedUrl = '/ocs/v2.php/apps/signotecsignosignuniversal/webhook_shared_closed';

		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->urlGenerator->expects(self::exactly(2))
			->method('getAbsoluteURL')
			->willReturnOnConsecutiveCalls($docUrl, $closedUrl);

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer tok']);

		$this->signoSignUniversal->expects(self::once())
			->method('getUserSettings')
			->willReturn([
				'webhooks.documentUpdatedEndpoint' => 'old-url',
				'webhooks.documentSharedClosedEndpoint' => '',
			]);

		$this->signoSignUniversal->expects(self::once())
			->method('updateUserSettings')
			->with('Bearer tok', [
				'webhooks.documentUpdatedEndpoint' => $docUrl,
				'webhooks.documentSharedClosedEndpoint' => $closedUrl,
			])
			->willReturn(['success' => true]);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken')
			->with('Bearer tok');

		$this->settingsService->method('getSms77ApiKeySet')->willReturn(false);

		$this->settingsService->expects(self::once())
			->method('setConnectionData')
			->with(true, false, $docUrl, $closedUrl);

		$this->l->expects(self::once())
			->method('t')
			->with('Webhook URL updated successfully')
			->willReturn('Webhook URL updated successfully');

		$response = $this->controller->setWebhookUrl();

		self::assertSame(200, $response->getStatus());
		self::assertSame([
			'webhookUrlSaved' => $docUrl,
			'webhookUrlSharedClosed' => $closedUrl,
			'message' => 'Webhook URL updated successfully',
		], $response->getData());
	}

	public function testSetWebhookUrlReturnsErrorWhenUpdateFails(): void {
		$docUrl = '/ocs/v2.php/apps/signotecsignosignuniversal/webhook_updated';
		$closedUrl = '/ocs/v2.php/apps/signotecsignosignuniversal/webhook_shared_closed';

		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->urlGenerator->method('getAbsoluteURL')
			->willReturnOnConsecutiveCalls($docUrl, $closedUrl);

		$this->signoSignUniversal->method('getInstanceToken')
			->willReturn(['token' => 'Bearer tok']);

		$this->signoSignUniversal->method('getUserSettings')
			->willReturn(['webhooks.documentUpdatedEndpoint' => '', 'webhooks.documentSharedClosedEndpoint' => '']);

		$this->signoSignUniversal->method('updateUserSettings')
			->willReturn(['error' => 'write failed']);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken');

		$this->settingsService->expects(self::never())
			->method('setConnectionData');

		$response = $this->controller->setWebhookUrl();

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'write failed'], $response->getData());
	}
}
