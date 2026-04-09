<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Service;

use OCA\SignotecSignoSignUniversal\Dto\SharingcaseCommentDto;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

final class WebhookProcessingService {
	private const LOG_PREFIX = '[WebhookProcessingService] ';

	public function __construct(
		private SignoSignUniversal $signoSignUniversal,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param int|string $fileId
	 * @return array<string, mixed>
	 */
	public function downloadAndUpdateFile(
		string $documentId,
		int|string $fileId,
		string $userId,
		?string $token = null,
	): array {
		$this->logger->info(self::LOG_PREFIX . 'starting document download and file update', [
			'documentId' => $documentId,
			'fileId' => (string)$fileId,
			'userId' => $userId,
		]);

		$ownsToken = false;

		if ($token === null || $token === '') {
			$tokenResult = $this->signoSignUniversal->getInstanceToken();
			if (isset($tokenResult['error'])) {
				$this->logger->error(self::LOG_PREFIX . 'failed to retrieve instance token', [
					'documentId' => $documentId,
					'fileId' => (string)$fileId,
					'userId' => $userId,
					'error' => $tokenResult['error'],
				]);

				return [
					'error' => $tokenResult['error'],
					'documentId' => $documentId,
					'fileId' => $fileId,
					'userId' => $userId,
				];
			}

			$token = $tokenResult['token'] ?? null;
			if (!is_string($token) || $token === '') {
				$this->logger->error(self::LOG_PREFIX . 'instance token response was empty or invalid', [
					'documentId' => $documentId,
					'fileId' => (string)$fileId,
					'userId' => $userId,
				]);

				return [
					'error' => 'Could not retrieve instance token',
					'documentId' => $documentId,
					'fileId' => $fileId,
					'userId' => $userId,
				];
			}

			$ownsToken = true;
		}

		if (!ctype_digit($documentId)) {
			$this->logger->warning(self::LOG_PREFIX . 'invalid document id', [
				'documentId' => $documentId,
				'fileId' => (string)$fileId,
				'userId' => $userId,
			]);

			return [
				'error' => 'Invalid document id',
				'documentId' => $documentId,
				'fileId' => $fileId,
				'userId' => $userId,
			];
		}

		$documentResult = $this->signoSignUniversal->downloadDocument($token, (int)$documentId);
		if (isset($documentResult['error'])) {
			$this->logger->error(self::LOG_PREFIX . 'failed to download signed document', [
				'documentId' => $documentId,
				'fileId' => (string)$fileId,
				'userId' => $userId,
				'error' => $documentResult['error'],
			]);

			return [
				'error' => $documentResult['error'],
				'documentId' => $documentId,
				'fileId' => $fileId,
				'userId' => $userId,
			];
		}

		$signedPdfContent = $documentResult['file'] ?? null;
		if (!is_string($signedPdfContent) || $signedPdfContent === '') {
			$this->logger->error(self::LOG_PREFIX . 'downloaded document is empty', [
				'documentId' => $documentId,
				'fileId' => (string)$fileId,
				'userId' => $userId,
			]);

			return [
				'error' => 'Downloaded document is empty',
				'documentId' => $documentId,
				'fileId' => $fileId,
				'userId' => $userId,
			];
		}

		$file = $this->resolveUserFile($userId, $fileId);
		$file->putContent($signedPdfContent);
		$file->touch(time());
		$file->getParent()->touch();

		$this->logger->info(self::LOG_PREFIX . 'file successfully updated', [
			'documentId' => $documentId,
			'fileId' => (string)$fileId,
			'userId' => $userId,
			'path' => $file->getPath(),
		]);

		if ($ownsToken) {
			$this->signoSignUniversal->revokeInstanceToken($token);
		}

		return [
			'message' => 'File updated successfully',
			'documentId' => $documentId,
			'fileId' => $fileId,
			'userId' => $userId,
		];
	}

	/**
	 * @return SharingcaseCommentDto|null
	 */
	public function parseSharingcaseComment(string $sharingcaseId): ?SharingcaseCommentDto {
		$tokenResult = $this->signoSignUniversal->getInstanceToken();
		if (isset($tokenResult['error'])) {
			$this->logger->error(self::LOG_PREFIX . 'failed to retrieve instance token for sharing case', [
				'sharingcaseId' => $sharingcaseId,
				'error' => $tokenResult['error'],
			]);

			return null;
		}

		$token = $tokenResult['token'] ?? null;
		if (!is_string($token) || $token === '') {
			$this->logger->error(self::LOG_PREFIX . 'sharing case token response was empty or invalid', [
				'sharingcaseId' => $sharingcaseId,
			]);

			return null;
		}

		if (!ctype_digit($sharingcaseId)) {
			$this->logger->warning(self::LOG_PREFIX . 'invalid sharing case id', [
				'sharingcaseId' => $sharingcaseId,
			]);

			return null;
		}

		try {
			$commentResult = $this->signoSignUniversal->getSharingcase($token, (int)$sharingcaseId);
		} finally {
			$revokeResult = $this->signoSignUniversal->revokeInstanceToken($token);

			if (isset($revokeResult['error'])) {
				$this->logger->warning(self::LOG_PREFIX . 'failed to revoke instance token after reading sharing case', [
					'sharingcaseId' => $sharingcaseId,
					'error' => $revokeResult['error'],
				]);
			}
		}

		if (isset($commentResult['error'])) {
			$this->logger->warning(self::LOG_PREFIX . 'failed to load sharing case details', [
				'sharingcaseId' => $sharingcaseId,
				'error' => $commentResult['error'],
			]);

			return null;
		}

		$commentJson = $commentResult['comment'] ?? null;
		if (!is_string($commentJson) || $commentJson === '') {
			$this->logger->warning(self::LOG_PREFIX . 'sharing case does not contain a comment', [
				'sharingcaseId' => $sharingcaseId,
			]);

			return null;
		}

		$commentArr = json_decode($commentJson, true);
		if (!is_array($commentArr)) {
			$this->logger->warning(self::LOG_PREFIX . 'sharing case comment is not valid JSON', [
				'sharingcaseId' => $sharingcaseId,
			]);

			return null;
		}

		$dto = SharingcaseCommentDto::fromArray($commentArr);

		if ($dto === null) {
			$this->logger->warning(self::LOG_PREFIX . 'sharing case comment does not match expected DTO structure', [
				'sharingcaseId' => $sharingcaseId,
			]);

			return null;
		}

		return $dto;
	}

	/**
	 * @param int|string $fileId
	 */
	private function resolveUserFile(string $userId, int|string $fileId): File {
		if (!ctype_digit((string)$fileId)) {
			$this->logger->warning(self::LOG_PREFIX . 'invalid file id', [
				'userId' => $userId,
				'fileId' => (string)$fileId,
			]);

			throw new OCSBadRequestException('Invalid file id');
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$files = $userFolder->getById((int)$fileId);

		if ($files === [] || !isset($files[0]) || !$files[0] instanceof File) {
			$this->logger->warning(self::LOG_PREFIX . 'file not found', [
				'userId' => $userId,
				'fileId' => (string)$fileId,
			]);

			throw new OCSBadRequestException($fileId . ' File not found');
		}

		return $files[0];
	}
}
