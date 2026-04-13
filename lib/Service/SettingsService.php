<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Service;

use OCA\SignotecSignoSignUniversal\Dto\SignatureFieldDto;
use OCP\IAppConfig;

final class SettingsService {
	private const SIGNATURE_FIELDS_KEY = 'signature_fields';
	private const COMMENT_LANGUAGE_SEND_KEY = 'comment_language_send';
	private const COMMENT_LANGUAGE_SIGNED_KEY = 'comment_language_signed';
	private const VALID_COMMENT_LANGUAGES = ['none', 'de', 'en'];
	private const TAG_SEND_KEY = 'tag_send';
	private const TAG_SIGNED_KEY = 'tag_signed';

	public function __construct(
		private IAppConfig $config,
		private string $appName,
		private FileTagService $fileTagService,
	) {
	}

	public function getUrl(): string {
		return $this->config->getValueString($this->appName, 'url', '');
	}

	public function getUsername(): string {
		return $this->config->getValueString($this->appName, 'username', '');
	}

	public function getPassword(): string {
		return $this->config->getValueString($this->appName, 'password', '');
	}

	public function getCommentLanguageSend(): string {
		return $this->config->getValueString($this->appName, self::COMMENT_LANGUAGE_SEND_KEY, 'none');
	}

	public function getCommentLanguageSigned(): string {
		return $this->config->getValueString($this->appName, self::COMMENT_LANGUAGE_SIGNED_KEY, 'none');
	}

	public function getTagSend(): string {
		return $this->config->getValueString($this->appName, self::TAG_SEND_KEY, '');
	}

	public function getTagSigned(): string {
		return $this->config->getValueString($this->appName, self::TAG_SIGNED_KEY, '');
	}

	/**
	 * @return list<SignatureFieldDto>
	 */
	public function getSignatureFields(): array {
		$raw = $this->config->getValueString($this->appName, self::SIGNATURE_FIELDS_KEY, '[]');
		$decoded = json_decode($raw, true);

		if (!is_array($decoded)) {
			return [];
		}

		$result = [];

		foreach ($decoded as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$dto = SignatureFieldDto::fromArray($entry);
			if ($dto !== null) {
				$result[] = $dto;
			}
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|null
	 */
	public function setSettings(array $input): ?array {
		$commentLanguageSend = isset($input['commentLanguageSend']) ? (string)$input['commentLanguageSend'] : null;
		$commentLanguageSigned = isset($input['commentLanguageSigned']) ? (string)$input['commentLanguageSigned'] : null;
		$signatureFields = $input['signatureFields'] ?? null;

		$error = $this->validateSettingsInput($commentLanguageSend, $commentLanguageSigned, $signatureFields);
		if ($error !== null) {
			return ['error' => $error];
		}

		if (isset($input['url'])) {
			$this->config->setValueString($this->appName, 'url', (string)$input['url']);
		}

		if (isset($input['username'])) {
			$this->config->setValueString($this->appName, 'username', (string)$input['username']);
		}

		if (isset($input['password'])) {
			$this->config->setValueString($this->appName, 'password', (string)$input['password']);
		}

		if ($commentLanguageSend !== null) {
			$this->config->setValueString($this->appName, self::COMMENT_LANGUAGE_SEND_KEY, $commentLanguageSend);
		}

		if ($commentLanguageSigned !== null) {
			$this->config->setValueString($this->appName, self::COMMENT_LANGUAGE_SIGNED_KEY, $commentLanguageSigned);
		}

		if (isset($input['tagSend'])) {
			$tagSend = trim((string)$input['tagSend']);
			$this->config->setValueString($this->appName, self::TAG_SEND_KEY, $tagSend);
			$this->fileTagService->ensureTagExists($tagSend);
		}

		if (isset($input['tagSigned'])) {
			$tagSigned = trim((string)$input['tagSigned']);
			$this->config->setValueString($this->appName, self::TAG_SIGNED_KEY, $tagSigned);
			$this->fileTagService->ensureTagExists($tagSigned);
		}

		if ($signatureFields !== null) {
			$normalized = $this->normalizeSignatureFields($signatureFields);
			$this->config->setValueString(
				$this->appName,
				self::SIGNATURE_FIELDS_KEY,
				json_encode($normalized, JSON_THROW_ON_ERROR)
			);
		}

		return null;
	}

	private function validateSettingsInput(
		?string $commentLanguageSend,
		?string $commentLanguageSigned,
		mixed $signatureFields,
	): ?string {
		$languageError = $this->validateCommentLanguages($commentLanguageSend, $commentLanguageSigned);
		if ($languageError !== null) {
			return $languageError;
		}

		return $signatureFields !== null ? $this->validateSignatureFieldsInput($signatureFields) : null;
	}

	private function validateCommentLanguages(?string $send, ?string $signed): ?string {
		foreach (['commentLanguageSend' => $send, 'commentLanguageSigned' => $signed] as $field => $value) {
			if ($value !== null && !in_array($value, self::VALID_COMMENT_LANGUAGES, true)) {
				return 'Invalid ' . $field . ' value';
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getPublicSettings(): array {
		return [
			'url' => $this->getUrl(),
			'username' => $this->getUsername(),
			'hasPassword' => $this->getPassword() !== '',
			'signatureFields' => array_map(
				static fn (SignatureFieldDto $field): array => $field->toArray(),
				$this->getSignatureFields()
			),
			'commentLanguageSend' => $this->getCommentLanguageSend(),
			'commentLanguageSigned' => $this->getCommentLanguageSigned(),
			'tagSend' => $this->getTagSend(),
			'tagSigned' => $this->getTagSigned(),
		];
	}

	private function validateSignatureFieldsInput(mixed $signatureFields): ?string {
		if (!is_array($signatureFields)) {
			return 'signatureFields must be an array';
		}

		$ids = [];
		$signerNames = [];

		foreach ($signatureFields as $index => $entry) {
			if (!is_array($entry)) {
				return sprintf('signatureFields[%d] must be an object', $index);
			}

			$error = $this->validateSignatureFieldEntry($index, $entry, $ids, $signerNames);
			if ($error !== null) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param list<string> $ids
	 * @param list<string> $signerNames
	 */
	private function validateSignatureFieldEntry(int $index, array $entry, array &$ids, array &$signerNames): ?string {
		$id = trim((string)($entry['id'] ?? ''));
		$signerName = trim((string)($entry['signerName'] ?? ''));
		$searchText = trim((string)($entry['searchText'] ?? ''));

		if ($id === '') {
			return sprintf('signatureFields[%d].id must not be empty', $index);
		}

		if (in_array($id, $ids, true)) {
			return sprintf('signatureFields[%d].id must be unique', $index);
		}

		$ids[] = $id;

		if ($signerName === '') {
			return sprintf('signatureFields[%d].signerName must not be empty', $index);
		}

		if (in_array($signerName, $signerNames, true)) {
			return sprintf('signatureFields[%d].signerName must be unique', $index);
		}

		$signerNames[] = $signerName;

		if ($searchText === '') {
			return sprintf('signatureFields[%d].searchText must not be empty', $index);
		}

		if (!array_key_exists('width', $entry) || (int)$entry['width'] <= 0) {
			return sprintf('signatureFields[%d].width must be greater than 0', $index);
		}

		if (!array_key_exists('height', $entry) || (int)$entry['height'] <= 0) {
			return sprintf('signatureFields[%d].height must be greater than 0', $index);
		}

		if (!array_key_exists('offsetX', $entry)) {
			return sprintf('signatureFields[%d].offsetX is required', $index);
		}

		if (!array_key_exists('offsetY', $entry)) {
			return sprintf('signatureFields[%d].offsetY is required', $index);
		}

		if (!array_key_exists('required', $entry)) {
			return sprintf('signatureFields[%d].required is required', $index);
		}

		if (!is_bool($entry['required']) && !in_array($entry['required'], [0, 1, '0', '1'], true)) {
			return sprintf('signatureFields[%d].required must be boolean', $index);
		}

		return null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function normalizeSignatureFields(mixed $signatureFields): array {
		if (!is_array($signatureFields)) {
			return [];
		}

		$normalized = [];

		foreach ($signatureFields as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$dto = SignatureFieldDto::fromArray($entry);
			if ($dto === null) {
				continue;
			}

			$normalized[] = $dto->toArray();
		}

		return $normalized;
	}
}
