<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Dto;

final class SharingcaseCommentDto {
	public function __construct(
		private string $userId,
		private int $fileId,
		private string $nonce,
		private string $recipientEmail = '',
	) {
	}

	public function toArray(): array {
		return [
			'userId' => $this->userId,
			'fileId' => $this->fileId,
			'nonce' => $this->nonce,
			'recipientEmail' => $this->recipientEmail,
		];
	}

	public static function fromArray(array $data): ?self {
		if (
			!isset($data['userId'], $data['fileId'], $data['nonce'])
			|| !is_string($data['userId'])
			|| !is_string($data['nonce'])
			|| !is_numeric($data['fileId'])
		) {
			return null;
		}

		$userId = trim($data['userId']);
		$nonce = trim($data['nonce']);

		if ($userId === '' || $nonce === '') {
			return null;
		}

		$recipientEmail = isset($data['recipientEmail']) && is_string($data['recipientEmail'])
			? trim($data['recipientEmail'])
			: '';

		return new self(
			$userId,
			(int)$data['fileId'],
			$nonce,
			$recipientEmail,
		);
	}

	public function getUserId(): string {
		return $this->userId;
	}

	public function getFileId(): int {
		return $this->fileId;
	}

	public function getNonce(): string {
		return $this->nonce;
	}

	public function getRecipientEmail(): string {
		return $this->recipientEmail;
	}
}
