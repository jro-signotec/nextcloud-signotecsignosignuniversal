<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Service;

use OCP\Comments\ICommentsManager;
use Psr\Log\LoggerInterface;

final class FileCommentService {
	private const LOG_PREFIX = '[FileCommentService] ';

	public function __construct(
		private ICommentsManager $commentsManager,
		private LoggerInterface $logger,
	) {
	}

	public function addSendComment(int $fileId, string $userId, string $recipientEmail, string $language): void {
		$message = match ($language) {
			'de' => 'Via signotec signoSign verschickt an: ' . $recipientEmail,
			'en' => 'Sent via signotec signoSign to: ' . $recipientEmail,
			default => null,
		};

		if ($message === null) {
			return;
		}

		try {
			$comment = $this->commentsManager->create('users', $userId, 'files', (string)$fileId);
			$comment->setMessage($message);
			$comment->setVerb('comment');
			$this->commentsManager->save($comment);
		} catch (\Throwable $e) {
			$this->logger->warning(self::LOG_PREFIX . 'failed to add send comment', [
				'fileId' => $fileId,
				'userId' => $userId,
				'error' => $e->getMessage(),
			]);
		}
	}

	public function addSignedComment(int $fileId, string $language): void {
		$message = match ($language) {
			'de' => 'Durch signotec signoSign aktualisiert',
			'en' => 'Updated by signotec signoSign',
			default => null,
		};

		if ($message === null) {
			return;
		}

		try {
			$comment = $this->commentsManager->create('bots', 'signotecsignosignuniversal', 'files', (string)$fileId);
			$comment->setMessage($message);
			$comment->setVerb('comment');
			$this->commentsManager->save($comment);
		} catch (\Throwable $e) {
			$this->logger->warning(self::LOG_PREFIX . 'failed to add signed comment', [
				'fileId' => $fileId,
				'error' => $e->getMessage(),
			]);
		}
	}
}
