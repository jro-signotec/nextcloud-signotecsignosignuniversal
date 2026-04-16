<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Service;

use OCA\SignotecSignoSignUniversal\Db\PendingWebhook;
use OCA\SignotecSignoSignUniversal\Db\PendingWebhookMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Log\LoggerInterface;

class PendingWebhookService {
	private const LOG_PREFIX = '[PendingWebhookService] ';

	public const WORKFLOW_VIEWER = 'viewer';
	public const WORKFLOW_SHARINGCASE = 'sharingcase';

	public const STATUS_PENDING = 'pending';
	public const STATUS_PROCESSED = 'processed';
	public const STATUS_EXPIRED = 'expired';
	public const STATUS_CLEANUP_FAILED_PROCESSED = 'cleanup_failed_processed';
	public const STATUS_CLEANUP_FAILED_EXPIRED = 'cleanup_failed_expired';

	private const DEFAULT_TTL = 60 * 60 * 24 * 90; // 90 days until expiration by default, since the webhook can be triggered at any time after the signing process is started and we want to allow for late webhooks to be properly marked as processed
	private const PROCESSED_RETENTION = 60 * 60 * 24; // keep entries for 24 hours after being marked as processed to allow for late webhooks to still be properly handled

	public function __construct(
		private PendingWebhookMapper $pendingWebhookMapper,
		private LoggerInterface $logger,
		private SignoSignUniversal $signoSignUniversal,
	) {
	}

	public function create(
		string $workflowType,
		string $userId,
		int $fileId,
		?string $documentId = null,
		?string $sharingcaseId = null,
		int $ttl = self::DEFAULT_TTL,
	): PendingWebhook {
		$this->cleanupExpired();

		$entity = new PendingWebhook();
		$entity->setWorkflowType($workflowType);
		$entity->setUserId($userId);
		$entity->setFileId($fileId);
		$entity->setNonce(bin2hex(random_bytes(16)));
		$entity->setDocumentId($documentId);
		$entity->setSharingcaseId($sharingcaseId);
		$entity->setStatus(self::STATUS_PENDING);
		$entity->setExpiresAt(time() + $ttl);
		$entity->setProcessedAt(null);
		$entity->setLastSourceTimestamp(null);
		$entity->setToken(null);

		$entity = $this->pendingWebhookMapper->insert($entity);

		$this->logger->info(self::LOG_PREFIX . 'created pending webhook entry', [
			'id' => $entity->getId(),
			'workflowType' => $workflowType,
			'userId' => $userId,
			'fileId' => $fileId,
			'documentId' => $documentId,
			'sharingcaseId' => $sharingcaseId,
			'expiresAt' => $entity->getExpiresAt(),
		]);

		return $entity;
	}

	public function validate(
		string $workflowType,
		string $userId,
		int $fileId,
		string $nonce,
		?string $sourceTimestamp = null,
	): bool {
		$entity = $this->findLatestEntry($workflowType, $userId, $fileId, $nonce);

		if ($entity === null) {
			$this->logger->warning(self::LOG_PREFIX . 'pending webhook entry not found', [
				'workflowType' => $workflowType,
				'userId' => $userId,
				'fileId' => $fileId,
				'nonce' => $nonce,
				'sourceTimestamp' => $sourceTimestamp,
			]);

			return false;
		}

		if ($entity->getExpiresAt() < time()) {
			$entity->setStatus(self::STATUS_EXPIRED);
			$this->pendingWebhookMapper->update($entity);

			$this->logger->warning(self::LOG_PREFIX . 'pending webhook entry expired', [
				'id' => $entity->getId(),
				'workflowType' => $workflowType,
				'userId' => $userId,
				'fileId' => $fileId,
				'nonce' => $nonce,
				'expiresAt' => $entity->getExpiresAt(),
			]);

			return false;
		}

		if ($entity->getStatus() === self::STATUS_PENDING) {
			return true;
		}

		if (
			$workflowType === self::WORKFLOW_VIEWER
			&& $entity->getStatus() === self::STATUS_PROCESSED
		) {
			$incomingTimestamp = $this->parseTimestampToUnix($sourceTimestamp);
			$storedTimestamp = $entity->getLastSourceTimestamp();

			if ($incomingTimestamp === null) {
				$this->logger->warning(self::LOG_PREFIX . 'viewer webhook rejected because timestamp is missing or invalid', [
					'id' => $entity->getId(),
					'workflowType' => $workflowType,
					'userId' => $userId,
					'fileId' => $fileId,
					'nonce' => $nonce,
					'sourceTimestamp' => $sourceTimestamp,
					'storedTimestamp' => $storedTimestamp,
				]);

				return false;
			}

			if ($storedTimestamp === null || $incomingTimestamp > $storedTimestamp) {
				$this->logger->info(self::LOG_PREFIX . 'viewer webhook re-accepted for newer timestamp', [
					'id' => $entity->getId(),
					'workflowType' => $workflowType,
					'userId' => $userId,
					'fileId' => $fileId,
					'nonce' => $nonce,
					'sourceTimestamp' => $incomingTimestamp,
					'storedTimestamp' => $storedTimestamp,
				]);

				return true;
			}

			$this->logger->info(self::LOG_PREFIX . 'viewer webhook skipped because timestamp is not newer', [
				'id' => $entity->getId(),
				'workflowType' => $workflowType,
				'userId' => $userId,
				'fileId' => $fileId,
				'nonce' => $nonce,
				'sourceTimestamp' => $incomingTimestamp,
				'storedTimestamp' => $storedTimestamp,
			]);

			return false;
		}

		return false;
	}

	public function markProcessed(
		string $workflowType,
		string $userId,
		int $fileId,
		string $nonce,
		?string $sourceTimestamp = null,
	): void {
		$entity = $this->findLatestEntry($workflowType, $userId, $fileId, $nonce);

		if ($entity === null) {
			$this->logger->warning(self::LOG_PREFIX . 'cannot mark pending webhook as processed because it was not found', [
				'workflowType' => $workflowType,
				'userId' => $userId,
				'fileId' => $fileId,
				'nonce' => $nonce,
				'sourceTimestamp' => $sourceTimestamp,
			]);

			return;
		}

		$entity->setStatus(self::STATUS_PROCESSED);
		$entity->setProcessedAt(time());
		$entity->setLastSourceTimestamp($this->parseTimestampToUnix($sourceTimestamp));

		$this->pendingWebhookMapper->update($entity);

		$this->logger->info(self::LOG_PREFIX . 'marked pending webhook as processed', [
			'id' => $entity->getId(),
			'workflowType' => $workflowType,
			'userId' => $userId,
			'fileId' => $fileId,
			'lastSourceTimestamp' => $entity->getLastSourceTimestamp(),
		]);
	}

	public function attachToken(
		string $workflowType,
		string $userId,
		int $fileId,
		string $nonce,
		string $token,
	): void {
		$entity = $this->findLatestEntry($workflowType, $userId, $fileId, $nonce);

		if ($entity === null) {
			$this->logger->warning(self::LOG_PREFIX . 'cannot attach token because pending webhook entry was not found', [
				'workflowType' => $workflowType,
				'userId' => $userId,
				'fileId' => $fileId,
				'nonce' => $nonce,
			]);

			return;
		}

		$entity->setToken($token);
		$this->pendingWebhookMapper->update($entity);
	}

	public function attachSharingcaseId(
		string $workflowType,
		string $userId,
		int $fileId,
		string $nonce,
		string $sharingcaseId,
	): void {
		$entity = $this->findPendingEntry($workflowType, $userId, $fileId, $nonce);

		if ($entity === null) {
			$this->logger->warning(self::LOG_PREFIX . 'cannot attach sharing case id because pending webhook entry was not found', [
				'workflowType' => $workflowType,
				'userId' => $userId,
				'fileId' => $fileId,
				'nonce' => $nonce,
				'sharingcaseId' => $sharingcaseId,
			]);

			return;
		}

		$entity->setSharingcaseId($sharingcaseId);
		$this->pendingWebhookMapper->update($entity);

		$this->logger->info(self::LOG_PREFIX . 'attached sharing case id to pending webhook entry', [
			'id' => $entity->getId(),
			'workflowType' => $workflowType,
			'userId' => $userId,
			'fileId' => $fileId,
			'sharingcaseId' => $sharingcaseId,
		]);
	}

	public function cleanupExpired(): int {
		$now = time();

		static $lastRun = 0;
		if ($lastRun !== 0 && ($now - $lastRun) < 300) {
			return 0;
		}
		$lastRun = $now;

		$deletedCount = 0;

		foreach ($this->pendingWebhookMapper->findExpiredEntries($now) as $entity) {
			if ($this->cleanupRemoteResources($entity)) {
				$this->pendingWebhookMapper->delete($entity);
				$deletedCount++;
			} else {
				$this->markCleanupFailed($entity);
			}
		}

		$threshold = $now - self::PROCESSED_RETENTION;

		foreach ($this->pendingWebhookMapper->findProcessedBefore($threshold) as $entity) {
			if ($this->cleanupRemoteResources($entity)) {
				$this->pendingWebhookMapper->delete($entity);
				$deletedCount++;
			} else {
				$this->markCleanupFailed($entity);
			}
		}

		if ($deletedCount > 0) {
			$this->logger->info(self::LOG_PREFIX . 'cleanup removed old pending webhook entries', [
				'deletedCount' => $deletedCount,
			]);
		}

		return $deletedCount;
	}

	private function cleanupRemoteResources(PendingWebhook $entity): bool {
		$documentCleanupOk = true;


		$documentId = $entity->getDocumentId();
		if ($documentId !== null && ctype_digit($documentId)) {
			$instanceTokenResult = $this->signoSignUniversal->getInstanceToken();

			if (isset($instanceTokenResult['error'])) {
				$this->logger->warning(self::LOG_PREFIX . 'failed to retrieve instance token for cleanup', [
					'id' => $entity->getId(),
					'workflowType' => $entity->getWorkflowType(),
					'userId' => $entity->getUserId(),
					'fileId' => $entity->getFileId(),
					'documentId' => $documentId,
					'error' => $instanceTokenResult['error'],
				]);

				$documentCleanupOk = false;
			} else {
				assert(isset($instanceTokenResult['token']));
				$instanceToken = $instanceTokenResult['token'];

				try {
					$deleteResult = $this->signoSignUniversal->deleteDocument($instanceToken, (int)$documentId);

					if (isset($deleteResult['error'])) {
						if ($this->isDocumentAlreadyGoneResult($deleteResult)) {
							$this->logger->info(self::LOG_PREFIX . 'remote document already gone during cleanup, treating as success', [
								'id' => $entity->getId(),
								'workflowType' => $entity->getWorkflowType(),
								'userId' => $entity->getUserId(),
								'fileId' => $entity->getFileId(),
								'documentId' => $documentId,
								'error' => $deleteResult['error'],
							]);
						} else {
							$this->logger->warning(self::LOG_PREFIX . 'failed to delete remote document during cleanup', [
								'id' => $entity->getId(),
								'workflowType' => $entity->getWorkflowType(),
								'userId' => $entity->getUserId(),
								'fileId' => $entity->getFileId(),
								'documentId' => $documentId,
								'error' => $deleteResult['error'],
							]);

							$documentCleanupOk = false;
						}
					} else {
						$this->logger->info(self::LOG_PREFIX . 'deleted remote document during cleanup', [
							'id' => $entity->getId(),
							'workflowType' => $entity->getWorkflowType(),
							'userId' => $entity->getUserId(),
							'fileId' => $entity->getFileId(),
							'documentId' => $documentId,
						]);
					}
				} finally {
					$revokeInstanceTokenResult = $this->signoSignUniversal->revokeInstanceToken($instanceToken);

					if (isset($revokeInstanceTokenResult['error'])) {
						$this->logger->warning(self::LOG_PREFIX . 'failed to revoke cleanup instance token', [
							'id' => $entity->getId(),
							'workflowType' => $entity->getWorkflowType(),
							'userId' => $entity->getUserId(),
							'fileId' => $entity->getFileId(),
							'error' => $revokeInstanceTokenResult['error'],
						]);
					}
				}
			}
		}

		$storedTokenCleanupOk = $this->revokeStoredTokenIfPresent($entity);

		return $documentCleanupOk && $storedTokenCleanupOk;
	}

	private function revokeStoredTokenIfPresent(PendingWebhook $entity): bool {
		$token = $entity->getToken();

		if ($token === null || $token === '') {
			return true;
		}

		$result = $this->signoSignUniversal->revokeInstanceToken($token);

		if (isset($result['error'])) {
			$this->logger->warning(self::LOG_PREFIX . 'failed to revoke stored token during cleanup', [
				'id' => $entity->getId(),
				'workflowType' => $entity->getWorkflowType(),
				'userId' => $entity->getUserId(),
				'fileId' => $entity->getFileId(),
				'error' => $result['error'],
			]);

			return false;
		}

		$this->logger->info(self::LOG_PREFIX . 'revoked stored token during cleanup', [
			'id' => $entity->getId(),
			'workflowType' => $entity->getWorkflowType(),
			'userId' => $entity->getUserId(),
			'fileId' => $entity->getFileId(),
		]);

		return true;
	}

	private function isDocumentAlreadyGoneResult(array $deleteResult): bool {
		$error = strtolower((string)($deleteResult['error'] ?? ''));

		return str_contains($error, '403');
	}

	private function markCleanupFailed(PendingWebhook $entity): void {
		$status = $entity->getProcessedAt() !== null
			? self::STATUS_CLEANUP_FAILED_PROCESSED
			: self::STATUS_CLEANUP_FAILED_EXPIRED;

		$entity->setStatus($status);
		$this->pendingWebhookMapper->update($entity);

		$this->logger->warning(self::LOG_PREFIX . 'marked pending webhook cleanup as failed', [
			'id' => $entity->getId(),
			'workflowType' => $entity->getWorkflowType(),
			'userId' => $entity->getUserId(),
			'fileId' => $entity->getFileId(),
			'status' => $status,
		]);
	}

	private function parseTimestampToUnix(?string $timestamp): ?int {
		if ($timestamp === null || $timestamp === '') {
			return null;
		}

		try {
			return (new \DateTimeImmutable($timestamp))->getTimestamp();
		} catch (\Throwable $e) {
			$this->logger->warning(self::LOG_PREFIX . 'failed to parse source timestamp', [
				'timestamp' => $timestamp,
				'error' => $e->getMessage(),
			]);

			return null;
		}
	}

	private function findPendingEntry(
		string $workflowType,
		string $userId,
		int $fileId,
		string $nonce,
	): ?PendingWebhook {
		try {
			return $this->pendingWebhookMapper->findPendingByWorkflowAndNonce(
				$workflowType,
				$userId,
				$fileId,
				$nonce,
			);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}

	private function findLatestEntry(
		string $workflowType,
		string $userId,
		int $fileId,
		string $nonce,
	): ?PendingWebhook {
		try {
			return $this->pendingWebhookMapper->findLatestByWorkflowAndNonce(
				$workflowType,
				$userId,
				$fileId,
				$nonce,
			);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}
}
