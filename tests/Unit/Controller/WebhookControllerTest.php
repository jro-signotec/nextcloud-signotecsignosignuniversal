<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Tests\Unit\Controller;

use OCA\SignotecSignoSignUniversal\Controller\WebhookController;
use OCA\SignotecSignoSignUniversal\Dto\SharingcaseCommentDto;
use OCA\SignotecSignoSignUniversal\Service\PendingWebhookService;
use OCA\SignotecSignoSignUniversal\Service\WebhookProcessingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private WebhookProcessingService&MockObject $webhookProcessingService;
	private PendingWebhookService&MockObject $pendingWebhookService;
	private LoggerInterface&MockObject $logger;
	private WebhookController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->webhookProcessingService = $this->createMock(WebhookProcessingService::class);
		$this->pendingWebhookService = $this->createMock(PendingWebhookService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new WebhookController(
			'signotecsignosignuniversal',
			$this->request,
			$this->webhookProcessingService,
			$this->pendingWebhookService,
			$this->logger,
		);
	}

	public function testViewerWebhookReturnsBadRequestForInvalidEventType(): void {
		$this->request->expects(self::once())
			->method('getParams')
			->willReturn([
				'Event' => [
					'EventType' => 'OTHER_EVENT',
					'EventData' => [
						'Document' => [
							'DocumentId' => '123',
						],
					],
				],
			]);

		$response = $this->controller->viewerWebhook();

		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([
			'error' => 'Invalid event type or missing document id',
		], $response->getData());
	}

	public function testViewerWebhookReturnsBadRequestForMissingDocumentId(): void {
		$this->request->expects(self::once())
			->method('getParams')
			->willReturn([
				'Event' => [
					'EventType' => 'DOCUMENT_UPDATED',
					'EventData' => [
						'Document' => [],
					],
				],
			]);

		$response = $this->controller->viewerWebhook();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([
			'error' => 'Invalid event type or missing document id',
		], $response->getData());
	}

	public function testViewerWebhookReturnsBadRequestForUnsupportedPayload(): void {
		$this->request->expects(self::once())
			->method('getParams')
			->willReturn([
				'Event' => [
					'EventType' => 'DOCUMENT_UPDATED',
					'EventData' => [
						'Document' => [
							'DocumentId' => '123',
						],
					],
				],
			]);

		$response = $this->controller->viewerWebhook();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([
			'error' => 'Unsupported webhook payload',
		], $response->getData());
	}

	public function testViewerWebhookReturnsBadRequestWhenViewerIndexIsInvalid(): void {
		$params = [
			'Event' => [
				'EventType' => 'DOCUMENT_UPDATED',
				'EventData' => [
					'Document' => [
						'DocumentId' => '123',
					],
				],
				'ViewerIndex' => 'not-json',
			],
		];

		$this->request->expects(self::exactly(2))
			->method('getParams')
			->willReturn($params);

		$response = $this->controller->viewerWebhook();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([
			'error' => 'Invalid viewer index payload',
		], $response->getData());
	}

	public function testViewerWebhookReturnsForbiddenWhenValidationFails(): void {
		$params = [
			'Event' => [
				'EventType' => 'DOCUMENT_UPDATED',
				'TimeStamp' => '2026-03-31T10:00:00+00:00',
				'EventData' => [
					'Document' => [
						'DocumentId' => '123',
					],
				],
				'ViewerIndex' => json_encode([
					'userId' => 'john',
					'fileId' => 42,
					'nonce' => 'nonce-1',
				], JSON_THROW_ON_ERROR),
			],
		];

		$this->request->expects(self::exactly(2))
			->method('getParams')
			->willReturn($params);

		$this->pendingWebhookService->expects(self::once())
			->method('validate')
			->with(
				PendingWebhookService::WORKFLOW_VIEWER,
				'john',
				42,
				'nonce-1',
				'2026-03-31T10:00:00+00:00',
			)
			->willReturn(false);

		$this->webhookProcessingService->expects(self::never())
			->method('downloadAndUpdateFile');

		$response = $this->controller->viewerWebhook();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame([
			'error' => 'Webhook validation failed',
		], $response->getData());
	}

	public function testViewerWebhookUsesFallbackTimestampFromTopLevel(): void {
		$params = [
			'Event' => [
				'EventType' => 'DOCUMENT_UPDATED',
				'EventData' => [
					'Document' => [
						'DocumentId' => '123',
					],
				],
				'ViewerIndex' => json_encode([
					'userId' => 'john',
					'fileId' => 42,
					'nonce' => 'nonce-1',
				], JSON_THROW_ON_ERROR),
			],
			'TimeStamp' => '2026-03-31T11:00:00+00:00',
		];

		$this->request->expects(self::exactly(2))
			->method('getParams')
			->willReturn($params);

		$this->pendingWebhookService->expects(self::once())
			->method('validate')
			->with(
				PendingWebhookService::WORKFLOW_VIEWER,
				'john',
				42,
				'nonce-1',
				'2026-03-31T11:00:00+00:00',
			)
			->willReturn(false);

		$response = $this->controller->viewerWebhook();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame([
			'error' => 'Webhook validation failed',
		], $response->getData());
	}

	public function testViewerWebhookReturnsInternalServerErrorWhenProcessingFails(): void {
		$params = [
			'Event' => [
				'EventType' => 'DOCUMENT_UPDATED',
				'TimeStamp' => '2026-03-31T10:00:00+00:00',
				'EventData' => [
					'Document' => [
						'DocumentId' => '123',
					],
				],
				'ViewerIndex' => json_encode([
					'userId' => 'john',
					'fileId' => 42,
					'nonce' => 'nonce-1',
				], JSON_THROW_ON_ERROR),
			],
		];

		$this->request->expects(self::exactly(2))
			->method('getParams')
			->willReturn($params);

		$this->pendingWebhookService->expects(self::once())
			->method('validate')
			->willReturn(true);

		$this->webhookProcessingService->expects(self::once())
			->method('downloadAndUpdateFile')
			->with('123', 42, 'john')
			->willReturn([
				'error' => 'download failed',
				'documentId' => '123',
				'fileId' => 42,
				'userId' => 'john',
			]);

		$this->pendingWebhookService->expects(self::never())
			->method('markProcessed');

		$response = $this->controller->viewerWebhook();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame([
			'error' => 'download failed',
			'documentId' => '123',
			'fileId' => 42,
			'userId' => 'john',
		], $response->getData());
	}

	public function testViewerWebhookReturnsOkAndMarksProcessedOnSuccess(): void {
		$params = [
			'Event' => [
				'EventType' => 'DOCUMENT_UPDATED',
				'TimeStamp' => '2026-03-31T10:00:00+00:00',
				'EventData' => [
					'Document' => [
						'DocumentId' => '123',
					],
				],
				'ViewerIndex' => json_encode([
					'userId' => 'john',
					'fileId' => 42,
					'nonce' => 'nonce-1',
				], JSON_THROW_ON_ERROR),
			],
		];

		$this->request->expects(self::exactly(2))
			->method('getParams')
			->willReturn($params);

		$this->pendingWebhookService->expects(self::once())
			->method('validate')
			->willReturn(true);

		$this->webhookProcessingService->expects(self::once())
			->method('downloadAndUpdateFile')
			->with('123', 42, 'john')
			->willReturn([
				'message' => 'File updated successfully',
				'documentId' => '123',
				'fileId' => 42,
				'userId' => 'john',
			]);

		$this->pendingWebhookService->expects(self::once())
			->method('markProcessed')
			->with(
				PendingWebhookService::WORKFLOW_VIEWER,
				'john',
				42,
				'nonce-1',
				'2026-03-31T10:00:00+00:00',
			);

		$response = $this->controller->viewerWebhook();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([
			'message' => 'File updated successfully',
			'documentId' => '123',
			'fileId' => 42,
			'userId' => 'john',
		], $response->getData());
	}

	public function testSharingcaseWebhookReturnsBadRequestForInvalidSharingcaseId(): void {
		$this->request->expects(self::once())
			->method('getParams')
			->willReturn([
				'Event' => [
					'EventType' => 'DOCUMENT_UPDATED',
					'EventData' => [
						'Document' => [
							'DocumentId' => '123',
						],
						'SharingCase' => [
							'SharinCaseId' => '',
							'State' => 'FINISHED',
						],
					],
				],
			]);

		$response = $this->controller->viewerWebhook();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([
			'error' => 'Invalid sharing case id',
		], $response->getData());
	}

	public function testSharingcaseWebhookReturnsBadRequestWhenCommentCannotBeParsed(): void {
		$this->request->expects(self::once())
			->method('getParams')
			->willReturn([
				'Event' => [
					'EventType' => 'DOCUMENT_UPDATED',
					'EventData' => [
						'Document' => [
							'DocumentId' => '123',
						],
						'SharingCase' => [
							'SharinCaseId' => '555',
							'State' => 'FINISHED',
						],
					],
				],
			]);

		$this->webhookProcessingService->expects(self::once())
			->method('parseSharingcaseComment')
			->with('555')
			->willReturn(null);

		$response = $this->controller->viewerWebhook();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([
			'error' => 'Could not parse sharing case comment',
		], $response->getData());
	}

	public function testSharingcaseWebhookReturnsForbiddenWhenValidationFails(): void {
		$this->request->expects(self::once())
			->method('getParams')
			->willReturn([
				'Event' => [
					'EventType' => 'DOCUMENT_UPDATED',
					'EventData' => [
						'Document' => [
							'DocumentId' => '123',
						],
						'SharingCase' => [
							'SharinCaseId' => '555',
							'State' => 'FINISHED',
						],
					],
				],
			]);

		$this->webhookProcessingService->expects(self::once())
			->method('parseSharingcaseComment')
			->with('555')
			->willReturn(new SharingcaseCommentDto('john', 42, 'nonce-1'));

		$this->pendingWebhookService->expects(self::once())
			->method('validate')
			->with(
				PendingWebhookService::WORKFLOW_SHARINGCASE,
				'john',
				42,
				'nonce-1',
			)
			->willReturn(false);

		$this->webhookProcessingService->expects(self::never())
			->method('downloadAndUpdateFile');

		$response = $this->controller->viewerWebhook();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame([
			'error' => 'Webhook validation failed',
		], $response->getData());
	}

	public function testSharingcaseWebhookReturnsInternalServerErrorWhenProcessingFails(): void {
		$this->request->expects(self::once())
			->method('getParams')
			->willReturn([
				'Event' => [
					'EventType' => 'DOCUMENT_UPDATED',
					'EventData' => [
						'Document' => [
							'DocumentId' => '123',
						],
						'SharingCase' => [
							'SharinCaseId' => '555',
							'State' => 'FINISHED',
						],
					],
				],
			]);

		$this->webhookProcessingService->expects(self::once())
			->method('parseSharingcaseComment')
			->with('555')
			->willReturn(new SharingcaseCommentDto('john', 42, 'nonce-1'));

		$this->pendingWebhookService->expects(self::once())
			->method('validate')
			->with(
				PendingWebhookService::WORKFLOW_SHARINGCASE,
				'john',
				42,
				'nonce-1',
			)
			->willReturn(true);

		$this->webhookProcessingService->expects(self::once())
			->method('downloadAndUpdateFile')
			->with('123', 42, 'john', null)
			->willReturn([
				'error' => 'download failed',
				'documentId' => '123',
				'fileId' => 42,
				'userId' => 'john',
			]);

		$this->pendingWebhookService->expects(self::never())
			->method('markProcessed');

		$response = $this->controller->viewerWebhook();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame([
			'error' => 'download failed',
			'documentId' => '123',
			'fileId' => 42,
			'userId' => 'john',
		], $response->getData());
	}

	public function testSharingcaseWebhookReturnsOkAndMarksProcessedOnSuccess(): void {
		$this->request->expects(self::once())
			->method('getParams')
			->willReturn([
				'Event' => [
					'EventType' => 'DOCUMENT_UPDATED',
					'EventData' => [
						'Document' => [
							'DocumentId' => '123',
						],
						'SharingCase' => [
							'SharinCaseId' => '555',
							'State' => 'FINISHED',
						],
					],
				],
			]);

		$this->webhookProcessingService->expects(self::once())
			->method('parseSharingcaseComment')
			->with('555')
			->willReturn(new SharingcaseCommentDto('john', 42, 'nonce-1'));

		$this->pendingWebhookService->expects(self::once())
			->method('validate')
			->with(
				PendingWebhookService::WORKFLOW_SHARINGCASE,
				'john',
				42,
				'nonce-1',
			)
			->willReturn(true);

		$this->webhookProcessingService->expects(self::once())
			->method('downloadAndUpdateFile')
			->with('123', 42, 'john', null)
			->willReturn([
				'message' => 'File updated successfully',
				'documentId' => '123',
				'fileId' => 42,
				'userId' => 'john',
			]);

		$this->pendingWebhookService->expects(self::once())
			->method('markProcessed')
			->with(
				PendingWebhookService::WORKFLOW_SHARINGCASE,
				'john',
				42,
				'nonce-1',
			);

		$response = $this->controller->viewerWebhook();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([
			'message' => 'File updated successfully',
			'documentId' => '123',
			'fileId' => 42,
			'userId' => 'john',
		], $response->getData());
	}
}
