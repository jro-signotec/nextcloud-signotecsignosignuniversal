<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Controller;

use OCA\SignotecSignoSignUniversal\Dto\ViewerIndexDto;
use OCA\SignotecSignoSignUniversal\Service\PendingWebhookService;
use OCA\SignotecSignoSignUniversal\Service\WebhookProcessingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * @psalm-suppress UnusedClass
 */
class WebhookController extends OCSController {
	private const LOG_PREFIX = '[WebhookController] ';

	public function __construct(
		string $appName,
		IRequest $request,
		private WebhookProcessingService $webhookProcessingService,
		private PendingWebhookService $pendingWebhookService,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[ApiRoute(verb: 'POST', url: '/webhook_updated')]
	public function viewerWebhook(): DataResponse {
		$params = $this->request->getParams();

		$eventType = $params['Event']['EventType'] ?? null;
		$documentId = $params['Event']['EventData']['Document']['DocumentId'] ?? null;
		$viewerIndex = $params['Event']['ViewerIndex'] ?? null;
		$sharingcase = $params['Event']['EventData']['SharingCase'] ?? null;

		$this->logger->info(self::LOG_PREFIX . 'received webhook request', [
			'eventType' => is_scalar($eventType) ? (string)$eventType : null,
			'documentId' => is_scalar($documentId) ? (string)$documentId : null,
			'hasViewerIndex' => $viewerIndex !== null,
			'hasSharingCase' => is_array($sharingcase),
		]);

		if ($eventType !== 'DOCUMENT_UPDATED' || !is_scalar($documentId) || (string)$documentId === '') {
			$this->logger->warning(self::LOG_PREFIX . 'invalid webhook payload for document update', [
				'eventType' => is_scalar($eventType) ? (string)$eventType : null,
				'documentId' => is_scalar($documentId) ? (string)$documentId : null,
			]);

			return new DataResponse([
				'error' => 'Invalid event type or missing document id',
			], Http::STATUS_BAD_REQUEST);
		}

		$documentId = (string)$documentId;

		if (
			is_array($sharingcase)
			&& ($sharingcase['SharinCaseId'] ?? null) !== null
			&& ($sharingcase['State'] ?? null) === 'FINISHED'
		) {
			return $this->handleSharingcaseWebhook($documentId, $sharingcase);
		}

		$this->logger->warning(self::LOG_PREFIX . 'unsupported webhook payload', [
			'eventType' => (string)$eventType,
			'documentId' => $documentId,
			'hasViewerIndex' => $viewerIndex !== null,
			'hasSharingCase' => is_array($sharingcase),
		]);

		if ($viewerIndex !== null) {
			return $this->handleViewerIndexWebhook($documentId, $viewerIndex);
		}

		return new DataResponse([
			'error' => 'Unsupported webhook payload',
		], Http::STATUS_BAD_REQUEST);
	}

	private function handleViewerIndexWebhook(string $documentId, mixed $viewerIndex): DataResponse {
		$viewerIndexObj = is_string($viewerIndex) ? json_decode($viewerIndex, true) : null;
		$sourceTimestamp = $this->extractWebhookTimestamp();
		$viewerIndexDto = is_array($viewerIndexObj) ? ViewerIndexDto::fromArray($viewerIndexObj) : null;

		if ($viewerIndexDto === null) {
			$this->logger->warning(self::LOG_PREFIX . 'invalid viewer index payload', [
				'documentId' => $documentId,
			]);

			return new DataResponse([
				'error' => 'Invalid viewer index payload',
			], Http::STATUS_BAD_REQUEST);
		}

		$userId = $viewerIndexDto->getUserId();
		$fileId = $viewerIndexDto->getFileId();
		$nonce = $viewerIndexDto->getNonce();

		$isValid = $this->pendingWebhookService->validate(
			PendingWebhookService::WORKFLOW_VIEWER,
			$userId,
			$fileId,
			$nonce,
			$sourceTimestamp,
		);

		if (!$isValid) {
			$this->logger->warning(self::LOG_PREFIX . 'viewer webhook validation failed', [
				'documentId' => $documentId,
				'userId' => $userId,
				'fileId' => $fileId,
				'sourceTimestamp' => $sourceTimestamp,
			]);

			return new DataResponse([
				'error' => 'Webhook validation failed',
			], Http::STATUS_FORBIDDEN);
		}

		$this->logger->info(self::LOG_PREFIX . 'processing viewer webhook', [
			'documentId' => $documentId,
			'userId' => $userId,
			'fileId' => $fileId,
			'sourceTimestamp' => $sourceTimestamp,
		]);

		$result = $this->webhookProcessingService->downloadAndUpdateFile(
			$documentId,
			$fileId,
			$userId,
		);

		if (isset($result['error'])) {
			$this->logger->error(self::LOG_PREFIX . 'viewer webhook processing failed', [
				'documentId' => $documentId,
				'userId' => $userId,
				'fileId' => $fileId,
				'error' => $result['error'],
				'sourceTimestamp' => $sourceTimestamp,
			]);

			return new DataResponse($result, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->pendingWebhookService->markProcessed(
			PendingWebhookService::WORKFLOW_VIEWER,
			$userId,
			$fileId,
			$nonce,
			$sourceTimestamp,
		);

		$this->logger->info(self::LOG_PREFIX . 'viewer webhook processed successfully', [
			'documentId' => $documentId,
			'userId' => $userId,
			'fileId' => $fileId,
			'sourceTimestamp' => $sourceTimestamp,
		]);

		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * @param array<string, mixed> $sharingcase
	 */
	private function handleSharingcaseWebhook(string $documentId, array $sharingcase): DataResponse {
		$sharingcaseId = $sharingcase['SharinCaseId'];

		if (!is_scalar($sharingcaseId) || (string)$sharingcaseId === '') {
			$this->logger->warning(self::LOG_PREFIX . 'invalid sharing case id in webhook payload', [
				'documentId' => $documentId,
				'sharingCaseId' => is_scalar($sharingcaseId) ? (string)$sharingcaseId : null,
			]);

			return new DataResponse([
				'error' => 'Invalid sharing case id',
			], Http::STATUS_BAD_REQUEST);
		}

		$sharingcaseId = (string)$sharingcaseId;

		$this->logger->info(self::LOG_PREFIX . 'processing sharing case webhook', [
			'documentId' => $documentId,
			'sharingCaseId' => $sharingcaseId,
		]);

		$commentDto = $this->webhookProcessingService->parseSharingcaseComment($sharingcaseId);

		if ($commentDto === null) {
			$this->logger->error(self::LOG_PREFIX . 'failed to parse sharing case comment', [
				'documentId' => $documentId,
				'sharingCaseId' => $sharingcaseId,
			]);

			return new DataResponse([
				'error' => 'Could not parse sharing case comment',
			], Http::STATUS_BAD_REQUEST);
		}

		$userId = $commentDto->getUserId();
		$fileId = $commentDto->getFileId();
		$nonce = $commentDto->getNonce();

		$isValid = $this->pendingWebhookService->validate(
			PendingWebhookService::WORKFLOW_SHARINGCASE,
			$userId,
			$fileId,
			$nonce,
		);

		if (!$isValid) {
			$this->logger->warning(self::LOG_PREFIX . 'sharing case webhook validation failed', [
				'documentId' => $documentId,
				'sharingCaseId' => $sharingcaseId,
				'userId' => $userId,
				'fileId' => $fileId,
			]);

			return new DataResponse([
				'error' => 'Webhook validation failed',
			], Http::STATUS_FORBIDDEN);
		}

		$result = $this->webhookProcessingService->downloadAndUpdateFile(
			$documentId,
			$fileId,
			$userId,
			null,
		);

		if (isset($result['error'])) {
			$this->logger->error(self::LOG_PREFIX . 'sharing case webhook processing failed', [
				'documentId' => $documentId,
				'sharingCaseId' => $sharingcaseId,
				'userId' => $userId,
				'fileId' => $fileId,
				'error' => $result['error'],
			]);

			return new DataResponse($result, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->pendingWebhookService->markProcessed(
			PendingWebhookService::WORKFLOW_SHARINGCASE,
			$userId,
			$fileId,
			$nonce,
		);

		$this->logger->info(self::LOG_PREFIX . 'sharing case webhook processed successfully', [
			'documentId' => $documentId,
			'sharingCaseId' => $sharingcaseId,
			'userId' => $userId,
			'fileId' => $fileId,
		]);

		return new DataResponse($result, Http::STATUS_OK);
	}

	private function extractWebhookTimestamp(): ?string {
		$params = $this->request->getParams();

		$candidates = [
			$params['Event']['TimeStamp'] ?? null,
			$params['TimeStamp'] ?? null,
		];

		foreach ($candidates as $candidate) {
			if (is_string($candidate) && $candidate !== '') {
				return $candidate;
			}
		}

		return null;
	}
}
