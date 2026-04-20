<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Service;

use OCA\SignotecSignoSignUniversal\Dto\SignatureFieldDto;
use OCP\IAppConfig;
use OCP\IL10N;

final class SettingsService {
	private const SIGNATURE_FIELDS_KEY = 'signature_fields';
	private const COMMENT_SEND_KEY = 'comment_send';
	private const COMMENT_SIGNED_KEY = 'comment_signed';
	private const TAG_SEND_KEY = 'tag_send';
	private const TAG_SIGNED_KEY = 'tag_signed';
	private const CONNECTION_VALID_KEY = 'connection_valid';
	private const SMS77_API_KEY_SET_KEY = 'sms77_api_key_set';
	private const WEBHOOK_ENDPOINT_KEY = 'webhook_document_updated_endpoint';
	private const WEBHOOK_SHARED_CLOSED_ENDPOINT_KEY = 'webhook_document_shared_closed_endpoint';
	private const COMMENT_REJECTED_KEY = 'comment_rejected';
	private const TAG_REJECTED_KEY = 'tag_rejected';
	private const CONNECTION_ERROR_KEY = 'connection_error';

	public function __construct(
		private IAppConfig $config,
		private string $appName,
		private FileTagService $fileTagService,
		private IL10N $l,
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

	public function getCommentSend(): string {
		return $this->config->getValueString($this->appName, self::COMMENT_SEND_KEY, '');
	}

	public function getCommentSigned(): string {
		return $this->config->getValueString($this->appName, self::COMMENT_SIGNED_KEY, '');
	}

	public function getTagSend(): string {
		return $this->config->getValueString($this->appName, self::TAG_SEND_KEY, '');
	}

	public function getTagSigned(): string {
		return $this->config->getValueString($this->appName, self::TAG_SIGNED_KEY, '');
	}

	public function getConnectionValid(): bool {
		return $this->config->getValueString($this->appName, self::CONNECTION_VALID_KEY, '0') === '1';
	}

	public function getSms77ApiKeySet(): bool {
		return $this->config->getValueString($this->appName, self::SMS77_API_KEY_SET_KEY, '0') === '1';
	}

	public function getWebhookDocumentUpdatedEndpoint(): string {
		return $this->config->getValueString($this->appName, self::WEBHOOK_ENDPOINT_KEY, '');
	}

	public function getWebhookDocumentSharedClosedEndpoint(): string {
		return $this->config->getValueString($this->appName, self::WEBHOOK_SHARED_CLOSED_ENDPOINT_KEY, '');
	}

	public function getCommentRejected(): string {
		return $this->config->getValueString($this->appName, self::COMMENT_REJECTED_KEY, '');
	}

	public function getTagRejected(): string {
		return $this->config->getValueString($this->appName, self::TAG_REJECTED_KEY, '');
	}

	public function getConnectionError(): string {
		return $this->config->getValueString($this->appName, self::CONNECTION_ERROR_KEY, '');
	}

	public function setConnectionData(bool $valid, bool $sms77KeySet, string $webhookEndpoint, string $webhookSharedClosedEndpoint = '', string $error = ''): void {
		$this->config->setValueString($this->appName, self::CONNECTION_VALID_KEY, $valid ? '1' : '0');
		$this->config->setValueString($this->appName, self::SMS77_API_KEY_SET_KEY, $sms77KeySet ? '1' : '0');
		$this->config->setValueString($this->appName, self::WEBHOOK_ENDPOINT_KEY, $webhookEndpoint);
		$this->config->setValueString($this->appName, self::WEBHOOK_SHARED_CLOSED_ENDPOINT_KEY, $webhookSharedClosedEndpoint);
		$this->config->setValueString($this->appName, self::CONNECTION_ERROR_KEY, $error);
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

	public function deleteAllSettings(): void {
		foreach ([
			'url',
			'username',
			'password',
			self::SIGNATURE_FIELDS_KEY,
			self::COMMENT_SEND_KEY,
			self::COMMENT_SIGNED_KEY,
			self::COMMENT_REJECTED_KEY,
			self::TAG_SEND_KEY,
			self::TAG_SIGNED_KEY,
			self::TAG_REJECTED_KEY,
			self::CONNECTION_VALID_KEY,
			self::SMS77_API_KEY_SET_KEY,
			self::WEBHOOK_ENDPOINT_KEY,
			self::WEBHOOK_SHARED_CLOSED_ENDPOINT_KEY,
			self::CONNECTION_ERROR_KEY,
		] as $key) {
			$this->config->deleteKey($this->appName, $key);
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|null
	 */
	public function setSettings(array $input): ?array {
		$signatureFields = $input['signatureFields'] ?? null;

		$error = $signatureFields !== null ? $this->validateSignatureFieldsInput($signatureFields) : null;
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

		if (isset($input['commentSend'])) {
			$this->config->setValueString($this->appName, self::COMMENT_SEND_KEY, (string)$input['commentSend']);
		}

		if (isset($input['commentSigned'])) {
			$this->config->setValueString($this->appName, self::COMMENT_SIGNED_KEY, (string)$input['commentSigned']);
		}

		if (isset($input['commentRejected'])) {
			$this->config->setValueString($this->appName, self::COMMENT_REJECTED_KEY, (string)$input['commentRejected']);
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

		if (isset($input['tagRejected'])) {
			$tagRejected = trim((string)$input['tagRejected']);
			$this->config->setValueString($this->appName, self::TAG_REJECTED_KEY, $tagRejected);
			$this->fileTagService->ensureTagExists($tagRejected);
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

	/**
	 * @return array<string, mixed>
	 */
	public function getPublicSettings(): array {
		return [
			'url' => $this->getUrl(),
			'username' => $this->getUsername(),
			'hasPassword' => $this->getPassword() !== '',
			'connectionValid' => $this->getConnectionValid(),
			'connectionError' => $this->getConnectionError(),
			'sms77ApiKeySet' => $this->getSms77ApiKeySet(),
			'webhookDocumentUpdatedEndpoint' => $this->getWebhookDocumentUpdatedEndpoint(),
			'webhookDocumentSharedClosedEndpoint' => $this->getWebhookDocumentSharedClosedEndpoint(),
			'signatureFields' => array_map(
				static fn (SignatureFieldDto $field): array => $field->toArray(),
				$this->getSignatureFields()
			),
			'commentSend' => $this->getCommentSend(),
			'commentSigned' => $this->getCommentSigned(),
			'commentRejected' => $this->getCommentRejected(),
			'tagSend' => $this->getTagSend(),
			'tagSigned' => $this->getTagSigned(),
			'tagRejected' => $this->getTagRejected(),
		];
	}

	private function validateSignatureFieldsInput(mixed $signatureFields): ?string {
		if (!is_array($signatureFields)) {
			return $this->l->t('signatureFields must be an array');
		}

		$ids = [];
		$signerNames = [];

		foreach ($signatureFields as $index => $entry) {
			if (!is_array($entry)) {
				return $this->l->t('signatureFields[%d] must be an object', [$index]);
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
			return $this->l->t('signatureFields[%d].id must not be empty', [$index]);
		}

		if (in_array($id, $ids, true)) {
			return $this->l->t('signatureFields[%d].id must be unique', [$index]);
		}

		$ids[] = $id;

		if ($signerName === '') {
			return $this->l->t('signatureFields[%d].signerName must not be empty', [$index]);
		}

		if (in_array($signerName, $signerNames, true)) {
			return $this->l->t('signatureFields[%d].signerName must be unique', [$index]);
		}

		$signerNames[] = $signerName;

		if ($searchText === '') {
			return $this->l->t('signatureFields[%d].searchText must not be empty', [$index]);
		}

		if (!array_key_exists('width', $entry) || (int)$entry['width'] <= 0) {
			return $this->l->t('signatureFields[%d].width must be greater than 0', [$index]);
		}

		if (!array_key_exists('height', $entry) || (int)$entry['height'] <= 0) {
			return $this->l->t('signatureFields[%d].height must be greater than 0', [$index]);
		}

		if (!array_key_exists('offsetX', $entry)) {
			return $this->l->t('signatureFields[%d].offsetX is required', [$index]);
		}

		if (!array_key_exists('offsetY', $entry)) {
			return $this->l->t('signatureFields[%d].offsetY is required', [$index]);
		}

		if (!array_key_exists('required', $entry)) {
			return $this->l->t('signatureFields[%d].required is required', [$index]);
		}

		if (!is_bool($entry['required']) && !in_array($entry['required'], [0, 1, '0', '1'], true)) {
			return $this->l->t('signatureFields[%d].required must be boolean', [$index]);
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
