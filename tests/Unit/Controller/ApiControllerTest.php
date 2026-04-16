<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Tests\Unit\Controller;

use OCA\SignotecSignoSignUniversal\Controller\ApiController;
use OCA\SignotecSignoSignUniversal\Db\PendingWebhook;
use OCA\SignotecSignoSignUniversal\Dto\SignatureFieldDto;
use OCA\SignotecSignoSignUniversal\Service\FileCommentService;
use OCA\SignotecSignoSignUniversal\Service\FileTagService;
use OCA\SignotecSignoSignUniversal\Service\PendingWebhookService;
use OCA\SignotecSignoSignUniversal\Service\SettingsService;
use OCA\SignotecSignoSignUniversal\Service\SignoSignUniversal;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApiControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private SignoSignUniversal&MockObject $signoSignUniversal;
	private IRootFolder&MockObject $rootFolder;
	private IUserSession&MockObject $userSession;
	private PendingWebhookService&MockObject $pendingWebhookService;
	private LoggerInterface&MockObject $logger;
	private SettingsService&MockObject $settingsService;
	private FileCommentService&MockObject $fileCommentService;
	private FileTagService&MockObject $fileTagService;
	private ApiController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->signoSignUniversal = $this->createMock(SignoSignUniversal::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->pendingWebhookService = $this->createMock(PendingWebhookService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->fileCommentService = $this->createMock(FileCommentService::class);
		$this->fileTagService = $this->createMock(FileTagService::class);

		$this->controller = new ApiController(
			'signotecsignosignuniversal',
			$this->request,
			$this->signoSignUniversal,
			$this->rootFolder,
			$this->userSession,
			$this->pendingWebhookService,
			$this->logger,
			$this->settingsService,
			$this->fileCommentService,
			$this->fileTagService,
		);
	}

	public function testSignDocumentThrowsWhenFileIdIsMissing(): void {
		$this->request->expects(self::exactly(2))
			->method('getParam')
			->willReturnMap([
				['fileId', null, null],
				['fileName', '', ''],
			]);

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Missing fileId');

		$this->controller->signDocument();
	}

	public function testSignDocumentThrowsWhenFileIdIsInvalid(): void {
		$this->request->expects(self::exactly(2))
			->method('getParam')
			->willReturnMap([
				['fileId', null, 'abc'],
				['fileName', '', ''],
			]);

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Invalid fileId');

		$this->controller->signDocument();
	}

	public function testSignDocumentReturnsInternalServerErrorWhenTokenFetchFails(): void {
		$this->mockRequestForSignDocument(fileId: '42', fileName: '');

		$this->mockCurrentUserFile(
			userId: 'john',
			fileName: 'contract.pdf',
			binary: '%PDF test binary'
		);

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['error' => 'token error']);

		$response = $this->controller->signDocument();

		self::assertSame(500, $response->getStatus());
		self::assertSame([
			'error' => 'token error',
			'fileId' => 42,
			'fileName' => 'contract.pdf',
		], $response->getData());
	}

	public function testSignDocumentReturnsInternalServerErrorWhenUploadFails(): void {
		$this->mockRequestForSignDocument(fileId: '42', fileName: 'custom.pdf');

		$this->mockCurrentUserFile(
			userId: 'john',
			fileName: 'contract.pdf',
			binary: '%PDF test binary'
		);

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer token']);

		$this->signoSignUniversal->expects(self::once())
			->method('uploadDocument')
			->with('Bearer token', 'custom.pdf', '%PDF test binary')
			->willReturn(['error' => 'upload failed']);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken')
			->with('Bearer token')
			->willReturn(['success' => true]);

		$response = $this->controller->signDocument();

		self::assertSame(500, $response->getStatus());
		self::assertSame([
			'error' => 'upload failed',
			'fileId' => 42,
			'fileName' => 'custom.pdf',
		], $response->getData());
	}

	public function testSignDocumentReturnsSuccessWithoutSignatureFields(): void {
		$this->mockRequestForSignDocument(fileId: '42', fileName: '');

		$this->mockCurrentUserFile(
			userId: 'john',
			fileName: 'contract.pdf',
			binary: '%PDF test binary'
		);

		$pendingWebhook = $this->buildPendingWebhook('nonce-123');

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer viewer-token']);

		$this->signoSignUniversal->expects(self::once())
			->method('uploadDocument')
			->with('Bearer viewer-token', 'contract.pdf', '%PDF test binary')
			->willReturn(['id' => 123]);

		$this->signoSignUniversal->expects(self::once())
			->method('loadDocumentToViewer')
			->with('Bearer viewer-token', 123)
			->willReturn(['loaded' => true]);

		$this->pendingWebhookService->expects(self::once())
			->method('create')
			->with(
				PendingWebhookService::WORKFLOW_VIEWER,
				'john',
				42,
				'123'
			)
			->willReturn($pendingWebhook);

		$this->pendingWebhookService->expects(self::once())
			->method('attachToken')
			->with(
				PendingWebhookService::WORKFLOW_VIEWER,
				'john',
				42,
				'nonce-123',
				'Bearer viewer-token'
			);

		$this->signoSignUniversal->expects(self::once())
			->method('setViewerIndex')
			->with(
				'Bearer viewer-token',
				json_encode([
					'userId' => 'john',
					'fileId' => 42,
					'nonce' => 'nonce-123',
				], JSON_THROW_ON_ERROR)
			)
			->willReturn(['index' => 'ok']);

		$this->settingsService->expects(self::once())
			->method('getSignatureFields')
			->willReturn([]);

		$this->signoSignUniversal->expects(self::never())
			->method('addDynamicSignatureFields');

		$this->signoSignUniversal->expects(self::once())
			->method('getViewerURL')
			->with('Bearer viewer-token')
			->willReturn(['url' => 'https://viewer.example.test']);

		$this->signoSignUniversal->expects(self::never())
			->method('revokeInstanceToken');

		$response = $this->controller->signDocument();

		self::assertSame(200, $response->getStatus());
		self::assertSame([
			'fileId' => 42,
			'fileName' => 'contract.pdf',
			'documentId' => 123,
			'viewerUrl' => ['url' => 'https://viewer.example.test'],
			'viewerResult' => ['loaded' => true],
			'viewerIndexResult' => ['index' => 'ok'],
			'signatureFieldsResult' => [
				'success' => true,
				'results' => [],
				'errors' => [],
				'skipped' => true,
			],
			'uploadResult' => ['id' => 123],
			'message' => 'Document uploaded successfully.',
		], $response->getData());
	}

	public function testSignDocumentReturnsSuccessWithSignatureFields(): void {
		$this->mockRequestForSignDocument(fileId: '42', fileName: '');

		$this->mockCurrentUserFile(
			userId: 'john',
			fileName: 'contract.pdf',
			binary: '%PDF test binary'
		);

		$pendingWebhook = $this->buildPendingWebhook('nonce-123');
		$signatureFields = [
			new SignatureFieldDto(
				'sig-1',
				'Signer 1',
				'FIELD_1',
				180,
				60,
				5,
				10,
				true
			),
		];

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer viewer-token']);

		$this->signoSignUniversal->expects(self::once())
			->method('uploadDocument')
			->with('Bearer viewer-token', 'contract.pdf', '%PDF test binary')
			->willReturn(['id' => 123]);

		$this->signoSignUniversal->expects(self::once())
			->method('loadDocumentToViewer')
			->with('Bearer viewer-token', 123)
			->willReturn(['loaded' => true]);

		$this->pendingWebhookService->expects(self::once())
			->method('create')
			->with(
				PendingWebhookService::WORKFLOW_VIEWER,
				'john',
				42,
				'123'
			)
			->willReturn($pendingWebhook);

		$this->pendingWebhookService->expects(self::once())
			->method('attachToken')
			->with(
				PendingWebhookService::WORKFLOW_VIEWER,
				'john',
				42,
				'nonce-123',
				'Bearer viewer-token'
			);

		$this->signoSignUniversal->expects(self::once())
			->method('setViewerIndex')
			->with(
				'Bearer viewer-token',
				json_encode([
					'userId' => 'john',
					'fileId' => 42,
					'nonce' => 'nonce-123',
				], JSON_THROW_ON_ERROR)
			)
			->willReturn(['index' => 'ok']);

		$this->settingsService->expects(self::once())
			->method('getSignatureFields')
			->willReturn($signatureFields);

		$this->signoSignUniversal->expects(self::once())
			->method('addDynamicSignatureFields')
			->with('Bearer viewer-token', $signatureFields)
			->willReturn([
				'success' => true,
				'results' => [
					['index' => 0],
				],
				'errors' => [],
			]);

		$this->signoSignUniversal->expects(self::once())
			->method('getViewerURL')
			->with('Bearer viewer-token')
			->willReturn(['url' => 'https://viewer.example.test']);

		$this->signoSignUniversal->expects(self::never())
			->method('revokeInstanceToken');

		$response = $this->controller->signDocument();

		self::assertSame(200, $response->getStatus());
		self::assertSame(42, $response->getData()['fileId']);
		self::assertSame('contract.pdf', $response->getData()['fileName']);
		self::assertSame(123, $response->getData()['documentId']);
		self::assertSame(false, $response->getData()['signatureFieldsResult']['skipped']);
		self::assertSame(true, $response->getData()['signatureFieldsResult']['success']);
	}

	public function testSendDocumentThrowsWhenRecipientEmailIsMissing(): void {
		$this->request->expects(self::exactly(3))
			->method('getParam')
			->willReturnMap([
				['fileId', null, '42'],
				['fileName', '', ''],
				['recipientEmail', '', ''],
			]);

		$this->mockCurrentUserFile(
			userId: 'john',
			fileName: 'contract.pdf',
			binary: '%PDF test binary'
		);

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Missing recipientEmail');

		$this->controller->sendDocument();
	}

	public function testSendDocumentReturnsInternalServerErrorWhenSharingcaseCreationFails(): void {
		$this->mockRequestForSendDocument(
			fileId: '42',
			fileName: '',
			recipientEmail: 'recipient@example.test',
			password: 'secret',
			tanTarget: '',
			authType: '',
			locale: 'de'
		);

		$this->mockCurrentUserFile(
			userId: 'john',
			fileName: 'contract.pdf',
			binary: '%PDF test binary'
		);

		$pendingWebhook = $this->buildPendingWebhook('nonce-123');

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer token']);

		$this->signoSignUniversal->expects(self::once())
			->method('uploadDocument')
			->with('Bearer token', 'contract.pdf', '%PDF test binary')
			->willReturn(['id' => 123]);

		$this->pendingWebhookService->expects(self::once())
			->method('create')
			->with(
				PendingWebhookService::WORKFLOW_SHARINGCASE,
				'john',
				42,
				'123'
			)
			->willReturn($pendingWebhook);

		$this->settingsService->expects(self::once())
			->method('getSignatureFields')
			->willReturn([]);

		$this->signoSignUniversal->expects(self::once())
			->method('createSharingcase')
			->with(
				'Bearer token',
				123,
				'recipient@example.test',
				'secret',
				'',
				[
					'userId' => 'john',
					'fileId' => 42,
					'nonce' => 'nonce-123',
					'recipientEmail' => 'recipient@example.test',
				],
				[
					'signatureFields' => [],
				]
			)
			->willReturn(['error' => 'sharingcase failed']);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken')
			->with('Bearer token')
			->willReturn(['success' => true]);

		$response = $this->controller->sendDocument();

		self::assertSame(500, $response->getStatus());
		self::assertSame([
			'error' => 'Create Sharingcase Error: sharingcase failed',
			'fileId' => 42,
			'fileName' => 'contract.pdf',
			'documentConfiguration' => [
				'signatureFields' => [],
			],
		], $response->getData());
	}

	public function testSendDocumentReturnsSuccess(): void {
		$this->mockRequestForSendDocument(
			fileId: '42',
			fileName: '',
			recipientEmail: 'recipient@example.test',
			password: 'secret',
			tanTarget: '49123456789',
			authType: '',
			locale: 'de'
		);

		$this->mockCurrentUserFile(
			userId: 'john',
			fileName: 'contract.pdf',
			binary: '%PDF test binary'
		);

		$pendingWebhook = $this->buildPendingWebhook('nonce-123');
		$signatureFields = [
			new SignatureFieldDto(
				'sig-1',
				'Signer 1',
				'FIELD_1',
				180,
				60,
				5,
				10,
				true
			),
		];

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer token']);

		$this->signoSignUniversal->expects(self::once())
			->method('uploadDocument')
			->with('Bearer token', 'contract.pdf', '%PDF test binary')
			->willReturn(['id' => 123]);

		$this->pendingWebhookService->expects(self::once())
			->method('create')
			->with(
				PendingWebhookService::WORKFLOW_SHARINGCASE,
				'john',
				42,
				'123'
			)
			->willReturn($pendingWebhook);

		$this->settingsService->expects(self::once())
			->method('getSignatureFields')
			->willReturn($signatureFields);

		$expectedDocumentConfiguration = [
			'signatureFields' => [
				[
					'width' => 180,
					'height' => 60,
					'option' => 1,
					'signer' => 'Signer 1',
					'type' => 'DynamicSignatureField',
					'recursive' => true,
					'keyword' => 'FIELD_1',
					'offsetX' => 5,
					'offsetY' => 10,
				],
			],
		];

		$this->signoSignUniversal->expects(self::once())
			->method('createSharingcase')
			->with(
				'Bearer token',
				123,
				'recipient@example.test',
				'secret',
				'49123456789',
				[
					'userId' => 'john',
					'fileId' => 42,
					'nonce' => 'nonce-123',
					'recipientEmail' => 'recipient@example.test',
				],
				$expectedDocumentConfiguration
			)
			->willReturn(['id' => 555]);

		$this->pendingWebhookService->expects(self::once())
			->method('attachSharingcaseId')
			->with(
				PendingWebhookService::WORKFLOW_SHARINGCASE,
				'john',
				42,
				'nonce-123',
				'555'
			);

		$this->signoSignUniversal->expects(self::once())
			->method('notifySharingcase')
			->with('Bearer token', 555, 'de')
			->willReturn(['notify' => 'ok']);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken')
			->with('Bearer token')
			->willReturn(['success' => true]);

		$response = $this->controller->sendDocument();

		self::assertSame(200, $response->getStatus());
		self::assertSame([
			'fileId' => 42,
			'fileName' => 'contract.pdf',
			'documentId' => 123,
			'uploadResult' => ['id' => 123],
			'sharingcaseResult' => ['id' => 555],
			'notifyResult' => ['notify' => 'ok'],
			'documentConfiguration' => $expectedDocumentConfiguration,
			'message' => 'Document uploaded successfully.',
		], $response->getData());
	}

	private function mockRequestForSignDocument(string $fileId, string $fileName): void {
		$this->request->expects(self::exactly(2))
			->method('getParam')
			->willReturnMap([
				['fileId', null, $fileId],
				['fileName', '', $fileName],
			]);
	}

	private function mockRequestForSendDocument(
		string $fileId,
		string $fileName,
		string $recipientEmail,
		string $password,
		string $tanTarget,
		string $authType,
		string $locale,
	): void {
		$this->request->expects(self::exactly(7))
			->method('getParam')
			->willReturnMap([
				['fileId', null, $fileId],
				['fileName', '', $fileName],
				['recipientEmail', '', $recipientEmail],
				['password', '', $password],
				['tanTarget', '', $tanTarget],
				['authType', '', $authType],
				['locale', 'de', $locale],
			]);
	}

	private function mockCurrentUserFile(string $userId, string $fileName, string $binary): void {
		$user = $this->createMock(IUser::class);
		$userFolder = $this->createMock(Folder::class);
		$file = $this->createMock(File::class);

		$stream = fopen('php://temp', 'r+');
		fwrite($stream, $binary);
		rewind($stream);

		$this->userSession->expects(self::once())
			->method('getUser')
			->willReturn($user);

		$user->method('getUID')
			->willReturn($userId);

		$this->rootFolder->expects(self::once())
			->method('getUserFolder')
			->with($userId)
			->willReturn($userFolder);

		$userFolder->expects(self::once())
			->method('getById')
			->with(42)
			->willReturn([$file]);

		$file->expects(self::once())
			->method('fopen')
			->with('r')
			->willReturn($stream);

		$file->expects(self::once())
			->method('getName')
			->willReturn($fileName);
	}

	private function buildPendingWebhook(string $nonce): PendingWebhook {
		$entity = new PendingWebhook();
		$entity->setId(1);
		$entity->setWorkflowType(PendingWebhookService::WORKFLOW_VIEWER);
		$entity->setUserId('john');
		$entity->setFileId(42);
		$entity->setNonce($nonce);
		$entity->setStatus(PendingWebhookService::STATUS_PENDING);
		$entity->setExpiresAt(time() + 3600);

		return $entity;
	}
}
