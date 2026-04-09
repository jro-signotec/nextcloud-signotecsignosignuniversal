<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Tests\Unit\Service;

use OCA\SignotecSignoSignUniversal\Db\PendingWebhook;
use OCA\SignotecSignoSignUniversal\Db\PendingWebhookMapper;
use OCA\SignotecSignoSignUniversal\Service\PendingWebhookService;
use OCA\SignotecSignoSignUniversal\Service\SignoSignUniversal;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PendingWebhookServiceTest extends TestCase {
	private PendingWebhookMapper&MockObject $mapper;
	private LoggerInterface&MockObject $logger;
	private SignoSignUniversal&MockObject $signoSignUniversal;
	private PendingWebhookService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(PendingWebhookMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->signoSignUniversal = $this->createMock(SignoSignUniversal::class);

		$this->service = new PendingWebhookService(
			$this->mapper,
			$this->logger,
			$this->signoSignUniversal,
		);
	}

	public function testCreateInsertsPendingWebhookEntry(): void {
		$this->mapper->expects(self::once())
			->method('findExpiredEntries')
			->willReturn([]);

		$this->mapper->expects(self::once())
			->method('findProcessedBefore')
			->willReturn([]);

		$this->mapper->expects(self::once())
			->method('insert')
			->with(self::callback(static function (PendingWebhook $entity): bool {
				return $entity->getWorkflowType() === PendingWebhookService::WORKFLOW_VIEWER
					&& $entity->getUserId() === 'john'
					&& $entity->getFileId() === 42
					&& $entity->getDocumentId() === '123'
					&& $entity->getSharingcaseId() === null
					&& $entity->getStatus() === PendingWebhookService::STATUS_PENDING
					&& $entity->getProcessedAt() === null
					&& $entity->getLastSourceTimestamp() === null
					&& $entity->getToken() === null
					&& strlen($entity->getNonce()) === 32
					&& ctype_xdigit($entity->getNonce())
					&& $entity->getExpiresAt() > time();
			}))
			->willReturnCallback(static function (PendingWebhook $entity): PendingWebhook {
				$entity->setId(99);
				return $entity;
			});

		$result = $this->service->create(
			PendingWebhookService::WORKFLOW_VIEWER,
			'john',
			42,
			'123',
		);

		self::assertSame(99, $result->getId());
		self::assertSame(PendingWebhookService::WORKFLOW_VIEWER, $result->getWorkflowType());
		self::assertSame('john', $result->getUserId());
		self::assertSame(42, $result->getFileId());
		self::assertSame('123', $result->getDocumentId());
		self::assertSame(PendingWebhookService::STATUS_PENDING, $result->getStatus());
		self::assertNull($result->getProcessedAt());
		self::assertNull($result->getLastSourceTimestamp());
		self::assertNull($result->getToken());
		self::assertSame(32, strlen($result->getNonce()));
	}

	public function testValidateReturnsFalseWhenEntryDoesNotExist(): void {
		$this->mapper->expects(self::once())
			->method('findLatestByWorkflowAndNonce')
			->with(PendingWebhookService::WORKFLOW_VIEWER, 'john', 42, 'nonce-1')
			->willThrowException(new DoesNotExistException('not found'));

		self::assertFalse(
			$this->service->validate(
				PendingWebhookService::WORKFLOW_VIEWER,
				'john',
				42,
				'nonce-1',
			)
		);
	}

	public function testValidateReturnsFalseAndExpiresEntryWhenExpired(): void {
		$entity = $this->buildWebhookEntity(
			status: PendingWebhookService::STATUS_PENDING,
			expiresAt: time() - 10,
		);

		$this->mapper->expects(self::once())
			->method('findLatestByWorkflowAndNonce')
			->willReturn($entity);

		$this->mapper->expects(self::once())
			->method('update')
			->with(self::callback(static function (PendingWebhook $updated): bool {
				return $updated->getStatus() === PendingWebhookService::STATUS_EXPIRED;
			}));

		self::assertFalse(
			$this->service->validate(
				PendingWebhookService::WORKFLOW_VIEWER,
				'john',
				42,
				'nonce-1',
			)
		);
	}

	public function testValidateReturnsTrueForPendingEntry(): void {
		$entity = $this->buildWebhookEntity(
			status: PendingWebhookService::STATUS_PENDING,
			expiresAt: time() + 3600,
		);

		$this->mapper->expects(self::once())
			->method('findLatestByWorkflowAndNonce')
			->willReturn($entity);

		$this->mapper->expects(self::never())->method('update');

		self::assertTrue(
			$this->service->validate(
				PendingWebhookService::WORKFLOW_VIEWER,
				'john',
				42,
				'nonce-1',
			)
		);
	}

	public function testValidateReturnsTrueForProcessedViewerWhenTimestampIsNewer(): void {
		$entity = $this->buildWebhookEntity(
			status: PendingWebhookService::STATUS_PROCESSED,
			expiresAt: time() + 3600,
			lastSourceTimestamp: strtotime('2026-03-30T10:00:00+00:00'),
		);

		$this->mapper->expects(self::once())
			->method('findLatestByWorkflowAndNonce')
			->willReturn($entity);

		self::assertTrue(
			$this->service->validate(
				PendingWebhookService::WORKFLOW_VIEWER,
				'john',
				42,
				'nonce-1',
				'2026-03-30T11:00:00+00:00',
			)
		);
	}

	public function testValidateReturnsFalseForProcessedViewerWhenTimestampIsOlder(): void {
		$entity = $this->buildWebhookEntity(
			status: PendingWebhookService::STATUS_PROCESSED,
			expiresAt: time() + 3600,
			lastSourceTimestamp: strtotime('2026-03-30T11:00:00+00:00'),
		);

		$this->mapper->expects(self::once())
			->method('findLatestByWorkflowAndNonce')
			->willReturn($entity);

		self::assertFalse(
			$this->service->validate(
				PendingWebhookService::WORKFLOW_VIEWER,
				'john',
				42,
				'nonce-1',
				'2026-03-30T10:00:00+00:00',
			)
		);
	}

	public function testValidateReturnsFalseForProcessedViewerWhenTimestampMissing(): void {
		$entity = $this->buildWebhookEntity(
			status: PendingWebhookService::STATUS_PROCESSED,
			expiresAt: time() + 3600,
			lastSourceTimestamp: strtotime('2026-03-30T11:00:00+00:00'),
		);

		$this->mapper->expects(self::once())
			->method('findLatestByWorkflowAndNonce')
			->willReturn($entity);

		self::assertFalse(
			$this->service->validate(
				PendingWebhookService::WORKFLOW_VIEWER,
				'john',
				42,
				'nonce-1',
				null,
			)
		);
	}

	public function testValidateReturnsFalseForProcessedSharingcase(): void {
		$entity = $this->buildWebhookEntity(
			workflowType: PendingWebhookService::WORKFLOW_SHARINGCASE,
			status: PendingWebhookService::STATUS_PROCESSED,
			expiresAt: time() + 3600,
		);

		$this->mapper->expects(self::once())
			->method('findLatestByWorkflowAndNonce')
			->willReturn($entity);

		self::assertFalse(
			$this->service->validate(
				PendingWebhookService::WORKFLOW_SHARINGCASE,
				'john',
				42,
				'nonce-1',
			)
		);
	}

	public function testMarkProcessedUpdatesEntityWithTimestamp(): void {
		$entity = $this->buildWebhookEntity(
			status: PendingWebhookService::STATUS_PENDING,
			expiresAt: time() + 3600,
		);

		$this->mapper->expects(self::once())
			->method('findLatestByWorkflowAndNonce')
			->willReturn($entity);

		$this->mapper->expects(self::once())
			->method('update')
			->with(self::callback(static function (PendingWebhook $updated): bool {
				return $updated->getStatus() === PendingWebhookService::STATUS_PROCESSED
					&& $updated->getProcessedAt() !== null
					&& $updated->getLastSourceTimestamp() === strtotime('2026-03-30T12:34:56+00:00');
			}));

		$this->service->markProcessed(
			PendingWebhookService::WORKFLOW_VIEWER,
			'john',
			42,
			'nonce-1',
			'2026-03-30T12:34:56+00:00',
		);
	}

	public function testMarkProcessedDoesNothingWhenEntryDoesNotExist(): void {
		$this->mapper->expects(self::once())
			->method('findLatestByWorkflowAndNonce')
			->willThrowException(new DoesNotExistException('not found'));

		$this->mapper->expects(self::never())->method('update');

		$this->service->markProcessed(
			PendingWebhookService::WORKFLOW_VIEWER,
			'john',
			42,
			'nonce-1',
		);

		self::assertTrue(true);
	}

	public function testAttachTokenUpdatesEntity(): void {
		$entity = $this->buildWebhookEntity();

		$this->mapper->expects(self::once())
			->method('findLatestByWorkflowAndNonce')
			->willReturn($entity);

		$this->mapper->expects(self::once())
			->method('update')
			->with(self::callback(static function (PendingWebhook $updated): bool {
				return $updated->getToken() === 'Bearer abc';
			}));

		$this->service->attachToken(
			PendingWebhookService::WORKFLOW_VIEWER,
			'john',
			42,
			'nonce-1',
			'Bearer abc',
		);
	}

	public function testAttachSharingcaseIdUpdatesPendingEntry(): void {
		$entity = $this->buildWebhookEntity(
			workflowType: PendingWebhookService::WORKFLOW_SHARINGCASE,
			status: PendingWebhookService::STATUS_PENDING,
		);

		$this->mapper->expects(self::once())
			->method('findPendingByWorkflowAndNonce')
			->willReturn($entity);

		$this->mapper->expects(self::once())
			->method('update')
			->with(self::callback(static function (PendingWebhook $updated): bool {
				return $updated->getSharingcaseId() === '555';
			}));

		$this->service->attachSharingcaseId(
			PendingWebhookService::WORKFLOW_SHARINGCASE,
			'john',
			42,
			'nonce-1',
			'555',
		);
	}

	#[RunInSeparateProcess]
	public function testCleanupExpiredDeletesEntryWhenRemoteCleanupSucceeds(): void {
		$entity = $this->buildWebhookEntity(
			documentId: '123',
			token: 'Bearer stored-token',
			status: PendingWebhookService::STATUS_PENDING,
			expiresAt: time() - 100,
		);

		$this->mapper->expects(self::once())
			->method('findExpiredEntries')
			->willReturn([$entity]);

		$this->mapper->expects(self::once())
			->method('findProcessedBefore')
			->willReturn([]);

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer cleanup-token']);

		$this->signoSignUniversal->expects(self::once())
			->method('deleteDocument')
			->with('Bearer cleanup-token', 123)
			->willReturn(['success' => true]);

		$revokedTokens = [];

		$this->signoSignUniversal->expects(self::exactly(2))
			->method('revokeInstanceToken')
			->willReturnCallback(function (string $token) use (&$revokedTokens): array {
				$revokedTokens[] = $token;
				return ['success' => true];
			});

		$this->mapper->expects(self::once())
			->method('delete')
			->with($entity);

		$deletedCount = $this->service->cleanupExpired();

		self::assertSame(1, $deletedCount);
		self::assertSame(['Bearer cleanup-token', 'Bearer stored-token'], $revokedTokens);
	}

	#[RunInSeparateProcess]
	public function testCleanupExpiredMarksEntryAsCleanupFailedWhenStoredTokenRevokeFails(): void {
		$entity = $this->buildWebhookEntity(
			documentId: null,
			token: 'Bearer stored-token',
			status: PendingWebhookService::STATUS_PENDING,
			expiresAt: time() - 100,
		);

		$this->mapper->expects(self::once())
			->method('findExpiredEntries')
			->willReturn([$entity]);

		$this->mapper->expects(self::once())
			->method('findProcessedBefore')
			->willReturn([]);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken')
			->with('Bearer stored-token')
			->willReturn(['error' => 'revoke failed']);

		$this->mapper->expects(self::never())->method('delete');

		$this->mapper->expects(self::once())
			->method('update')
			->with(self::callback(static function (PendingWebhook $updated): bool {
				return $updated->getStatus() === PendingWebhookService::STATUS_CLEANUP_FAILED_EXPIRED;
			}));

		$deletedCount = $this->service->cleanupExpired();

		self::assertSame(0, $deletedCount);
	}

	#[RunInSeparateProcess]
	public function testCleanupExpiredTreats403DocumentDeleteAsSuccess(): void {
		$entity = $this->buildWebhookEntity(
			documentId: '123',
			token: null,
			status: PendingWebhookService::STATUS_PENDING,
			expiresAt: time() - 100,
		);

		$this->mapper->expects(self::once())
			->method('findExpiredEntries')
			->willReturn([$entity]);

		$this->mapper->expects(self::once())
			->method('findProcessedBefore')
			->willReturn([]);

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer cleanup-token']);

		$this->signoSignUniversal->expects(self::once())
			->method('deleteDocument')
			->with('Bearer cleanup-token', 123)
			->willReturn(['error' => '403 forbidden']);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken')
			->with('Bearer cleanup-token')
			->willReturn(['success' => true]);

		$this->mapper->expects(self::once())
			->method('delete')
			->with($entity);

		$deletedCount = $this->service->cleanupExpired();

		self::assertSame(1, $deletedCount);
	}

	private function buildWebhookEntity(
		string $workflowType = PendingWebhookService::WORKFLOW_VIEWER,
		string $status = PendingWebhookService::STATUS_PENDING,
		int $expiresAt = 9999999999,
		?int $processedAt = null,
		?int $lastSourceTimestamp = null,
		?string $documentId = '123',
		?string $sharingcaseId = null,
		?string $token = null,
	): PendingWebhook {
		$entity = new PendingWebhook();
		$entity->setId(1);
		$entity->setWorkflowType($workflowType);
		$entity->setUserId('john');
		$entity->setFileId(42);
		$entity->setNonce('nonce-1');
		$entity->setDocumentId($documentId);
		$entity->setSharingcaseId($sharingcaseId);
		$entity->setStatus($status);
		$entity->setExpiresAt($expiresAt);
		$entity->setProcessedAt($processedAt);
		$entity->setLastSourceTimestamp($lastSourceTimestamp);
		$entity->setToken($token);

		return $entity;
	}
}
