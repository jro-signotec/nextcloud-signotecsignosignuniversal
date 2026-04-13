<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Service;

use OCA\SignotecSignoSignUniversal\Dto\SignatureFieldDto;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use Throwable;

final class SignoSignUniversal {
	private const CONTENT_TYPE_FORM = 'application/x-www-form-urlencoded';
	private const CONTENT_TYPE_PDF = 'application/pdf';

	public function __construct(
		private IClientService $clientService,
		private SettingsService $settingsService,
	) {
	}

	private function getClient(): IClient {
		return $this->clientService->newClient();
	}

	private function getBaseUrl(): string {
		return rtrim($this->settingsService->getUrl(), '/');
	}

	private function getUsername(): string {
		return $this->settingsService->getUsername();
	}

	private function getPassword(): string {
		return $this->settingsService->getPassword();
	}

	private function buildUrl(string $path): string {
		return $this->getBaseUrl() . '/signoSignUniversal/rest' . $path;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeJsonResponse(string $body, string $fallbackError): array {
		if ($body === '') {
			return ['error' => $fallbackError];
		}

		$result = json_decode($body, true);

		if (!is_array($result)) {
			return ['error' => $fallbackError];
		}

		return $result;
	}

	/**
	 * @return array{token:string}|array{error:string}
	 */
	public function getInstanceToken(): array {
		if ($this->getUsername() === '' || $this->getPassword() === '' || $this->getBaseUrl() === '') {
			return ['error' => 'Username or password or baseurl not set in settings'];
		}

		try {
			$response = $this->getClient()->post($this->buildUrl('/instancetoken'), [
				'headers' => [
					'Content-Type' => self::CONTENT_TYPE_FORM,
				],
				'form_params' => [
					'username' => $this->getUsername(),
					'password' => $this->getPassword(),
				],
			]);

			$data = trim((string)$response->getBody());
			if ($data === '') {
				return ['error' => 'No token received'];
			}

			return ['token' => 'Bearer ' . $data];
		} catch (Throwable $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @return array{success:true}|array{error:string}
	 */
	public function revokeInstanceToken(string $token): array {
		try {
			$this->getClient()->delete($this->buildUrl('/instancetoken'), [
				'headers' => [
					'Authorization' => $token,
				],
			]);

			return ['success' => true];
		} catch (Throwable $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function uploadDocument(string $token, string $fileName, string $binary): array {
		try {
			$response = $this->getClient()->post($this->buildUrl('/documents'), [
				'headers' => [
					'Authorization' => $token,
				],
				'multipart' => [
					[
						'name' => 'document',
						'contents' => $binary,
						'filename' => $fileName,
						'headers' => [
							'Content-Type' => self::CONTENT_TYPE_PDF,
						],
					],
					[
						'name' => 'fileName',
						'contents' => $fileName,
					],
				],
			]);

			return $this->decodeJsonResponse(
				(string)$response->getBody(),
				'No response from upload',
			);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function loadDocumentToViewer(string $token, int $docId): array {
		try {
			$response = $this->getClient()->put($this->buildUrl('/viewer/document'), [
				'headers' => [
					'Content-Type' => self::CONTENT_TYPE_FORM,
					'Authorization' => $token,
				],
				'form_params' => [
					'documentId' => $docId,
				],
			]);

			return $this->decodeJsonResponse(
				(string)$response->getBody(),
				'No response from viewer load',
			);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @return array{index:string}|array{error:string}
	 */
	public function setViewerIndex(string $token, string $index): array {
		try {
			$response = $this->getClient()->put($this->buildUrl('/viewer/meta/index'), [
				'headers' => [
					'Content-Type' => self::CONTENT_TYPE_FORM,
					'Authorization' => $token,
				],
				'form_params' => [
					'index' => $index,
				],
			]);

			return ['index' => (string)$response->getBody()];
		} catch (Throwable $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function addDynamicSignatureField(
		string $token,
		int $width,
		int $height,
		bool $mandatory,
		int $xoffset,
		int $yoffset,
		string $keyword,
		string $signatureFieldName,
	): array {
		if ($width <= 0) {
			return [
				'success' => false,
				'error' => 'Width must be greater than 0',
			];
		}

		if ($height <= 0) {
			return [
				'success' => false,
				'error' => 'Height must be greater than 0',
			];
		}

		if (trim($keyword) === '') {
			return [
				'success' => false,
				'error' => 'Keyword must not be empty',
			];
		}

		if (trim($signatureFieldName) === '') {
			return [
				'success' => false,
				'error' => 'Signature field name must not be empty',
			];
		}

		try {
			$response = $this->getClient()->post($this->buildUrl('/viewer/document/dynamicsignaturefields'), [
				'headers' => [
					'Authorization' => $token,
					'Content-Type' => self::CONTENT_TYPE_FORM,
				],
				'form_params' => [
					'width' => $width,
					'height' => $height,
					'mandatory' => $mandatory ? 'true' : 'false',
					'xoffset' => $xoffset,
					'yoffset' => $yoffset,
					'recursive' => 'true',
					'keyword' => $keyword,
					'signatureFieldName' => $signatureFieldName,
				],
			]);

			$body = trim((string)$response->getBody());
			if ($body === '') {
				return ['success' => true];
			}

			$result = json_decode($body, true);

			if (is_array($result)) {
				$result['success'] = $result['success'] ?? true;
				return $result;
			}

			return [
				'success' => true,
				'response' => $body,
			];
		} catch (Throwable $e) {
			return [
				'success' => false,
				'error' => $e->getMessage(),
			];
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function addDynamicSignatureFieldDto(string $token, SignatureFieldDto $field): array {
		return $this->addDynamicSignatureField(
			$token,
			$field->getWidth(),
			$field->getHeight(),
			$field->isRequired(),
			$field->getOffsetX(),
			$field->getOffsetY(),
			$field->getSearchText(),
			$field->getSignerName(),
		);
	}

	/**
	 * @param list<SignatureFieldDto> $signatureFields
	 * @return array<string, mixed>
	 */
	public function addDynamicSignatureFields(string $token, array $signatureFields): array {
		if ($signatureFields === []) {
			return [
				'success' => true,
				'results' => [],
				'errors' => [],
			];
		}

		$results = [];
		$errors = [];

		foreach ($signatureFields as $index => $field) {
			$result = $this->addDynamicSignatureFieldDto($token, $field);

			$results[] = [
				'index' => $index,
				'field' => $field->toArray(),
				'result' => $result,
			];

			if (isset($result['error'])) {
				$errors[] = [
					'index' => $index,
					'signerName' => $field->getSignerName(),
					'searchText' => $field->getSearchText(),
					'error' => $result['error'],
				];
			}
		}

		return [
			'success' => $errors === [],
			'results' => $results,
			'errors' => $errors,
		];
	}

	/**
	 * @return array{url:string}|array{error:string}
	 */
	public function getViewerURL(string $token): array {
		try {
			$response = $this->getClient()->post($this->buildUrl('/viewer/accessurl'), [
				'headers' => [
					'Authorization' => $token,
					'Content-Type' => self::CONTENT_TYPE_FORM,
				],
			]);

			$body = trim((string)$response->getBody());
			if ($body === '') {
				return ['error' => 'No viewer URL received'];
			}

			return ['url' => $body];
		} catch (Throwable $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @return array{file:string}|array{error:string}
	 */
	public function downloadDocument(string $token, int $docId): array {
		try {
			$response = $this->getClient()->get($this->buildUrl('/documents/' . $docId . '/filedata'), [
				'headers' => [
					'Authorization' => $token,
				],
			]);

			return ['file' => (string)$response->getBody()];
		} catch (Throwable $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function deleteDocument(string $token, int $docId): array {
		if ($docId <= 0) {
			return ['error' => 'Document ID must be greater than 0'];
		}

		try {
			$response = $this->getClient()->delete($this->buildUrl('/documents/' . $docId), [
				'headers' => [
					'Authorization' => $token,
				],
			]);

			return $this->decodeJsonResponse(
				(string)$response->getBody(),
				'No response from delete document',
			);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param array<string, mixed> $comment
	 * @param array<string, mixed> $documentConfiguration
	 * @return array<string, mixed>
	 */
	public function createSharingcase(
		string $token,
		int $docId,
		string $recipientEmail,
		string $password,
		string $tanTarget,
		array $comment,
		array $documentConfiguration,
	): array {
		try {
			$commentJson = json_encode($comment, JSON_THROW_ON_ERROR);
			$documentConfigurationJson = json_encode($documentConfiguration, JSON_THROW_ON_ERROR);

			$formParams = [
				'documentId' => $docId,
				'emailAddressee' => $recipientEmail,
				'comment' => $commentJson,
				'documentConfiguration' => $documentConfigurationJson,
			];

			if ($tanTarget !== '') {
				$formParams['tanAddressee'] = $tanTarget;
			} else {
				$formParams['password'] = $password;
			}

			$response = $this->getClient()->post($this->buildUrl('/sharingcases'), [
				'headers' => [
					'Authorization' => $token,
					'Content-Type' => self::CONTENT_TYPE_FORM,
				],
				'form_params' => $formParams,
			]);

			return $this->decodeJsonResponse(
				(string)$response->getBody(),
				'No response from sharingcase creation',
			);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function notifySharingcase(string $token, int $sharingcaseId, string $locale): array {
		try {
			$response = $this->getClient()->post($this->buildUrl('/notify/mail/sharingcase'), [
				'headers' => [
					'Authorization' => $token,
					'Content-Type' => self::CONTENT_TYPE_FORM,
				],
				'form_params' => [
					'locale' => $locale,
					'sharingcaseId' => $sharingcaseId,
				],
			]);

			$body = (string)$response->getBody();
			$result = json_decode($body, true);

			if (!is_array($result)) {
				return ['notify' => $body];
			}

			return $result;
		} catch (Throwable $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getSharingcase(string $token, int $sharingcaseId): array {
		try {
			$response = $this->getClient()->get($this->buildUrl('/sharingcases/' . $sharingcaseId), [
				'headers' => [
					'Authorization' => $token,
				],
			]);

			return $this->decodeJsonResponse(
				(string)$response->getBody(),
				'No response from sharingcase',
			);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage()];
		}
	}
}
