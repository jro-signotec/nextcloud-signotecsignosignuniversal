<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Service;

use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagCreationForbiddenException;
use Psr\Log\LoggerInterface;

class FileTagService {
	private const LOG_PREFIX = '[FileTagService] ';
	private const OBJECT_TYPE = 'files';

	public function __construct(
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $tagMapper,
		private LoggerInterface $logger,
	) {
	}

	public function ensureTagExists(string $tagName): void {
		$tagName = trim($tagName);

		if ($tagName === '') {
			return;
		}

		try {
			$this->resolveTag($tagName);
		} catch (\Throwable $e) {
			$this->logger->warning(self::LOG_PREFIX . 'failed to ensure tag exists', [
				'tagName' => $tagName,
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * @param string[] $counterTagNames All tags to remove after assigning the new tag.
	 */
	public function assignTag(int $fileId, string $tagName, array $counterTagNames = []): void {
		$tagName = trim($tagName);

		if ($tagName === '') {
			return;
		}

		try {
			$tag = $this->resolveTag($tagName);
			$this->tagMapper->assignTags((string)$fileId, self::OBJECT_TYPE, [$tag->getId()]);
		} catch (\Throwable $e) {
			$this->logger->warning(self::LOG_PREFIX . 'failed to assign tag', [
				'fileId' => $fileId,
				'tagName' => $tagName,
				'error' => $e->getMessage(),
			]);
		}

		foreach ($counterTagNames as $counterTagName) {
			$this->removeTag($fileId, $counterTagName);
		}
	}

	private function removeTag(int $fileId, string $tagName): void {
		$tagName = trim($tagName);

		if ($tagName === '') {
			return;
		}

		try {
			$tag = $this->resolveTag($tagName);
			$this->tagMapper->unassignTags((string)$fileId, self::OBJECT_TYPE, [$tag->getId()]);
		} catch (\Throwable $e) {
			$this->logger->warning(self::LOG_PREFIX . 'failed to remove counter tag', [
				'fileId' => $fileId,
				'tagName' => $tagName,
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Find tag by name regardless of visibility settings, create if not found.
	 */
	private function resolveTag(string $tagName): ISystemTag {
		// getAllTags searches by name pattern — filter for exact match
		$existing = $this->tagManager->getAllTags(null, $tagName);
		foreach ($existing as $tag) {
			if ($tag->getName() === $tagName) {
				return $tag;
			}
		}

		try {
			return $this->tagManager->createTag($tagName, true, true);
		} catch (TagCreationForbiddenException $e) {
			$this->logger->warning(
				self::LOG_PREFIX . 'cannot create tag — tag does not exist and creation is not permitted in this context; create the tag manually in Nextcloud first',
				['tagName' => $tagName, 'error' => $e->getMessage()],
			);
			throw $e;
		}
	}
}
