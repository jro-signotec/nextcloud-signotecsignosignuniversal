<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Service;

use OCP\Comments\ICommentsManager;
use Psr\Log\LoggerInterface;

final class FileCommentService {
	private const LOG_PREFIX = '[FileCommentService] ';
	private const COMMENT_PREFIX = '[signotec signoSign] ';
	private const PLACEHOLDER_USERID = '@userid@';
	private const PLACEHOLDER_MAILTO = '@mailto@';
	private const PLACEHOLDER_AUTHTYPE = '@authtype@';
	private const PLACEHOLDER_REASON = '@reason@';

	public function __construct(
		private ICommentsManager $commentsManager,
		private LoggerInterface $logger,
	) {
	}

	public function addSendComment(
		int $fileId,
		string $userId,
		string $recipientEmail,
		string $authType,
		string $commentTemplate,
	): void {
		if ($commentTemplate === '') {
			return;
		}

		$message = self::COMMENT_PREFIX . str_replace(
			[self::PLACEHOLDER_MAILTO, self::PLACEHOLDER_AUTHTYPE, self::PLACEHOLDER_USERID],
			[$recipientEmail, $authType, $userId],
			$commentTemplate,
		);

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

	public function addRejectedComment(
		int $fileId,
		string $userId,
		string $rejectionReason,
		string $recipientEmail,
		string $commentTemplate,
	): void {
		if ($commentTemplate === '') {
			return;
		}

		$message = self::COMMENT_PREFIX . str_replace(
			[self::PLACEHOLDER_REASON, self::PLACEHOLDER_USERID, self::PLACEHOLDER_MAILTO],
			[$rejectionReason, $userId, $recipientEmail],
			$commentTemplate,
		);

		try {
			$comment = $this->commentsManager->create('users', $userId, 'files', (string)$fileId);
			$comment->setMessage($message);
			$comment->setVerb('comment');
			$this->commentsManager->save($comment);
		} catch (\Throwable $e) {
			$this->logger->warning(self::LOG_PREFIX . 'failed to add rejected comment', [
				'fileId' => $fileId,
				'userId' => $userId,
				'error' => $e->getMessage(),
			]);
		}
	}

	public function addSignedComment(
		int $fileId,
		string $userId,
		string $recipientEmail,
		string $commentTemplate,
	): void {
		if ($commentTemplate === '') {
			return;
		}

		$message = self::COMMENT_PREFIX . str_replace(
			[self::PLACEHOLDER_USERID, self::PLACEHOLDER_MAILTO],
			[$userId, $recipientEmail],
			$commentTemplate,
		);

		try {
			$comment = $this->commentsManager->create('users', $userId, 'files', (string)$fileId);
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
