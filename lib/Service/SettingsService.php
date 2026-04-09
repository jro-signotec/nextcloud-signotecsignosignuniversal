<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Service;

use OCA\SignotecSignoSignUniversal\Dto\SignatureFieldDto;
use OCP\IAppConfig;

final class SettingsService {
	private const SIGNATURE_FIELDS_KEY = 'signature_fields';

	public function __construct(
		private IAppConfig $config,
		private string $appName,
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
	 * @return array<string, mixed>|null
	 */
	public function setSettings(
		?string $url = null,
		?string $username = null,
		?string $password = null,
		mixed $signatureFields = null,
	): ?array {
		if ($url !== null) {
			$this->config->setValueString($this->appName, 'url', $url);
		}

		if ($username !== null) {
			$this->config->setValueString($this->appName, 'username', $username);
		}

		if ($password !== null) {
			$this->config->setValueString($this->appName, 'password', $password);
		}

		if ($signatureFields !== null) {
			$validationError = $this->validateSignatureFieldsInput($signatureFields);
			if ($validationError !== null) {
				return [
					'error' => $validationError,
				];
			}

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
			'signatureFields' => array_map(
				static fn (SignatureFieldDto $field): array => $field->toArray(),
				$this->getSignatureFields()
			),
		];
	}

	private function validateSignatureFieldsInput(mixed $signatureFields): ?string {
		if (!is_array($signatureFields)) {
			return 'signatureFields must be an array';
		}

		$ids = [];
		$searchTexts = [];

		foreach ($signatureFields as $index => $entry) {
			if (!is_array($entry)) {
				return sprintf('signatureFields[%d] must be an object', $index);
			}

			$id = trim((string)($entry['id'] ?? ''));
			$signerName = trim((string)($entry['signerName'] ?? ''));
			$searchText = trim((string)($entry['searchText'] ?? ''));

			if ($id === '') {
				return sprintf('signatureFields[%d].id must not be empty', $index);
			}

			if ($signerName === '') {
				return sprintf('signatureFields[%d].signerName must not be empty', $index);
			}

			if ($searchText === '') {
				return sprintf('signatureFields[%d].searchText must not be empty', $index);
			}

			if (in_array($id, $ids, true)) {
				return sprintf('signatureFields[%d].id must be unique', $index);
			}

			if (in_array($searchText, $searchTexts, true)) {
				return sprintf('signatureFields[%d].searchText must be unique', $index);
			}

			$ids[] = $id;
			$searchTexts[] = $searchText;

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

			if (
				!is_bool($entry['required'])
				&& !in_array($entry['required'], [0, 1, '0', '1'], true)
			) {
				return sprintf('signatureFields[%d].required must be boolean', $index);
			}
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
