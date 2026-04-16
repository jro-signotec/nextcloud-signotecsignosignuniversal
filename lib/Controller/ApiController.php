<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Controller;

use OCA\SignotecSignoSignUniversal\Dto\SharingcaseCommentDto;
use OCA\SignotecSignoSignUniversal\Dto\SignatureFieldDto;
use OCA\SignotecSignoSignUniversal\Dto\ViewerIndexDto;
use OCA\SignotecSignoSignUniversal\Service\FileCommentService;
use OCA\SignotecSignoSignUniversal\Service\FileTagService;
use OCA\SignotecSignoSignUniversal\Service\PendingWebhookService;
use OCA\SignotecSignoSignUniversal\Service\SettingsService;
use OCA\SignotecSignoSignUniversal\Service\SignoSignUniversal;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

final class ApiController extends OCSController {
	private const LOG_PREFIX = '[ApiController] ';

	public function __construct(
		string $appName,
		IRequest $request,
		private SignoSignUniversal $signoSignUniversal,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private PendingWebhookService $pendingWebhookService,
		private LoggerInterface $logger,
		private SettingsService $settingsService,
		private FileCommentService $fileCommentService,
		private FileTagService $fileTagService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/uploadandsign')]
	public function signDocument(): DataResponse {
		$fileId = $this->request->getParam('fileId');
		$fileName = (string)$this->request->getParam('fileName', '');

		if (!$fileId) {
			throw new OCSBadRequestException('Missing fileId');
		}

		if (!ctype_digit((string)$fileId)) {
			throw new OCSBadRequestException('Invalid fileId');
		}

		$fileId = (int)$fileId;

		$fileData = $this->getFileBinaryForCurrentUser($fileId);
		$user = $fileData['user'];
		$binary = $fileData['binary'];
		$fileName = $fileName !== '' ? $fileName : $fileData['fileName'];
		$userId = $user->getUID();


		$this->logger->info(self::LOG_PREFIX . 'starting local signing', [
			'fileId' => $fileId,
			'fileName' => $fileName,
			'userId' => $userId,
		]);

		$tokenResult = $this->signoSignUniversal->getInstanceToken();
		if (isset($tokenResult['error'])) {
			$this->logger->error(self::LOG_PREFIX . 'failed to retrieve instance token for local signing', [
				'fileId' => $fileId,
				'fileName' => $fileName,
				'userId' => $userId,
				'error' => $tokenResult['error'],
			]);

			return new DataResponse([
				'error' => $tokenResult['error'],
				'fileId' => $fileId,
				'fileName' => $fileName,
			], 500);
		}

		assert(isset($tokenResult['token']));
		$token = $tokenResult['token'];
		$keepViewerToken = false;

		try {
			$uploadResult = $this->signoSignUniversal->uploadDocument($token, $fileName, $binary);
			if (isset($uploadResult['error'])) {
				$this->logger->error(self::LOG_PREFIX . 'failed to upload document for local signing', [
					'fileId' => $fileId,
					'fileName' => $fileName,
					'userId' => $userId,
					'error' => $uploadResult['error'],
				]);

				return new DataResponse([
					'error' => $uploadResult['error'],
					'fileId' => $fileId,
					'fileName' => $fileName,
				], 500);
			}

			if (!isset($uploadResult['id']) || !is_numeric($uploadResult['id'])) {
				$this->logger->error(self::LOG_PREFIX . 'upload response does not contain a valid document id', [
					'fileId' => $fileId,
					'fileName' => $fileName,
					'userId' => $userId,
					'uploadResult' => $uploadResult,
				]);

				return new DataResponse([
					'error' => 'Upload response does not contain a valid document id',
					'fileId' => $fileId,
					'fileName' => $fileName,
					'uploadResult' => $uploadResult,
				], 500);
			}

			$documentId = (int)$uploadResult['id'];

			$viewerResult = $this->signoSignUniversal->loadDocumentToViewer($token, $documentId);
			if (isset($viewerResult['error'])) {
				$this->logger->error(self::LOG_PREFIX . 'failed to load document into viewer', [
					'fileId' => $fileId,
					'fileName' => $fileName,
					'userId' => $userId,
					'documentId' => $documentId,
					'error' => $viewerResult['error'],
				]);

				return new DataResponse([
					'error' => 'Load Document Error: ' . $viewerResult['error'],
					'fileId' => $fileId,
					'fileName' => $fileName,
					'documentId' => $documentId,
				], 500);
			}

			$pendingWebhook = $this->pendingWebhookService->create(
				PendingWebhookService::WORKFLOW_VIEWER,
				$userId,
				$fileId,
				(string)$documentId,
			);

			$this->pendingWebhookService->attachToken(
				PendingWebhookService::WORKFLOW_VIEWER,
				$userId,
				$fileId,
				$pendingWebhook->getNonce(),
				$token,
			);

			$viewerIndexDto = new ViewerIndexDto(
				$userId,
				$fileId,
				$pendingWebhook->getNonce(),
			);

			$viewerIndexResult = $this->signoSignUniversal->setViewerIndex(
				$token,
				json_encode($viewerIndexDto->toArray(), JSON_THROW_ON_ERROR)
			);

			if (isset($viewerIndexResult['error'])) {
				$this->logger->error(self::LOG_PREFIX . 'failed to set viewer index', [
					'fileId' => $fileId,
					'fileName' => $fileName,
					'userId' => $userId,
					'documentId' => $documentId,
					'error' => $viewerIndexResult['error'],
				]);

				return new DataResponse([
					'error' => 'Set Viewer Index Error: ' . $viewerIndexResult['error'],
					'fileId' => $fileId,
					'fileName' => $fileName,
				], 500);
			}

			$signatureFields = $this->settingsService->getSignatureFields();
			$signatureFieldsResult = [
				'success' => true,
				'results' => [],
				'errors' => [],
				'skipped' => false,
			];

			if ($signatureFields === []) {
				$signatureFieldsResult['skipped'] = true;

				$this->logger->info(self::LOG_PREFIX . 'no configured dynamic signature fields found, continuing without fields', [
					'fileId' => $fileId,
					'fileName' => $fileName,
					'userId' => $userId,
					'documentId' => $documentId,
				]);
			} else {
				$this->logger->info(self::LOG_PREFIX . 'adding configured dynamic signature fields to viewer', [
					'fileId' => $fileId,
					'fileName' => $fileName,
					'userId' => $userId,
					'documentId' => $documentId,
					'fieldCount' => count($signatureFields),
				]);

				$signatureFieldsResult = $this->signoSignUniversal->addDynamicSignatureFields(
					$token,
					$signatureFields,
				);
				$signatureFieldsResult['skipped'] = false;

				if (($signatureFieldsResult['success'] ?? false) !== true) {
					$this->logger->warning(self::LOG_PREFIX . 'one or more dynamic signature fields could not be added, continuing anyway', [
						'fileId' => $fileId,
						'fileName' => $fileName,
						'userId' => $userId,
						'documentId' => $documentId,
						'signatureFieldsResult' => $signatureFieldsResult,
					]);
				} else {
					$this->logger->info(self::LOG_PREFIX . 'all configured dynamic signature fields added successfully', [
						'fileId' => $fileId,
						'fileName' => $fileName,
						'userId' => $userId,
						'documentId' => $documentId,
						'fieldCount' => count($signatureFields),
					]);
				}
			}

			$viewerUrlResult = $this->signoSignUniversal->getViewerURL($token);

			if (isset($viewerUrlResult['error'])) {
				$this->logger->error(self::LOG_PREFIX . 'failed to retrieve viewer URL', [
					'fileId' => $fileId,
					'fileName' => $fileName,
					'userId' => $userId,
					'documentId' => $documentId,
					'error' => $viewerUrlResult['error'],
				]);

				return new DataResponse([
					'error' => 'Get Viewer URL Error: ' . $viewerUrlResult['error'],
					'fileId' => $fileId,
					'fileName' => $fileName,
					'signatureFieldsResult' => $signatureFieldsResult,
				], 500);
			}

			$keepViewerToken = true;

			$this->logger->info(self::LOG_PREFIX . 'local signing initialized successfully', [
				'fileId' => $fileId,
				'fileName' => $fileName,
				'userId' => $userId,
				'documentId' => $documentId,
				'signatureFieldCount' => count($signatureFields),
				'signatureFieldErrors' => count($signatureFieldsResult['errors'] ?? []),
			]);

			return new DataResponse([
				'fileId' => $fileId,
				'fileName' => $fileName,
				'documentId' => $documentId,
				'viewerUrl' => $viewerUrlResult,
				'viewerResult' => $viewerResult,
				'viewerIndexResult' => $viewerIndexResult,
				'signatureFieldsResult' => $signatureFieldsResult,
				'uploadResult' => $uploadResult,
				'message' => 'Document uploaded successfully.',
			]);
		} finally {
			if (!$keepViewerToken) {
				$revokeResult = $this->signoSignUniversal->revokeInstanceToken($token);

				if (isset($revokeResult['error'])) {
					$this->logger->warning(self::LOG_PREFIX . 'failed to revoke viewer token after unsuccessful initialization', [
						'fileId' => $fileId,
						'fileName' => $fileName,
						'userId' => $userId,
						'error' => $revokeResult['error'],
					]);
				}
			}
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/uploadandsend')]
	public function sendDocument(): DataResponse {
		$fileId = $this->request->getParam('fileId');
		$fileName = (string)$this->request->getParam('fileName', '');

		if (!$fileId) {
			throw new OCSBadRequestException('Missing fileId');
		}

		if (!ctype_digit((string)$fileId)) {
			throw new OCSBadRequestException('Invalid fileId');
		}

		$fileId = (int)$fileId;

		$fileData = $this->getFileBinaryForCurrentUser($fileId);
		$user = $fileData['user'];
		$binary = $fileData['binary'];
		$fileName = $fileName !== '' ? $fileName : $fileData['fileName'];

		$recipientEmail = trim((string)$this->request->getParam('recipientEmail', ''));
		if ($recipientEmail === '') {
			throw new OCSBadRequestException('Missing recipientEmail');
		}

		if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
			throw new OCSBadRequestException('Invalid email format');
		}

		$password = (string)$this->request->getParam('password', '');
		$tanTarget = (string)$this->request->getParam('tanTarget', '');
		$authType = (string)$this->request->getParam('authType', '');
		$locale = (string)$this->request->getParam('locale', 'de');
		$userId = $user->getUID();

		$this->logger->info(self::LOG_PREFIX . 'starting sharing case', [
			'fileId' => $fileId,
			'fileName' => $fileName,
			'userId' => $userId,
			'recipientEmail' => $recipientEmail,
		]);

		$tokenResult = $this->signoSignUniversal->getInstanceToken();
		if (isset($tokenResult['error'])) {
			$this->logger->error(self::LOG_PREFIX . 'failed to retrieve instance token for sharing case', [
				'fileId' => $fileId,
				'fileName' => $fileName,
				'userId' => $userId,
				'error' => $tokenResult['error'],
			]);

			return new DataResponse([
				'error' => $tokenResult['error'],
				'fileId' => $fileId,
				'fileName' => $fileName,
			], 500);
		}

		assert(isset($tokenResult['token']));
		$token = $tokenResult['token'];

		try {
			$uploadResult = $this->signoSignUniversal->uploadDocument($token, $fileName, $binary);
			if (isset($uploadResult['error'])) {
				$this->logger->error(self::LOG_PREFIX . 'failed to upload document for sharing case', [
					'fileId' => $fileId,
					'fileName' => $fileName,
					'userId' => $userId,
					'error' => $uploadResult['error'],
				]);

				return new DataResponse([
					'error' => $uploadResult['error'],
					'fileId' => $fileId,
					'fileName' => $fileName,
				], 500);
			}

			if (!isset($uploadResult['id']) || !is_numeric($uploadResult['id'])) {
				$this->logger->error(self::LOG_PREFIX . 'upload response does not contain a valid document id for sharing case', [
					'fileId' => $fileId,
					'fileName' => $fileName,
					'userId' => $userId,
					'uploadResult' => $uploadResult,
				]);

				return new DataResponse([
					'error' => 'Upload response does not contain a valid document id',
					'fileId' => $fileId,
					'fileName' => $fileName,
					'uploadResult' => $uploadResult,
				], 500);
			}

			$documentId = (int)$uploadResult['id'];

			$pendingWebhook = $this->pendingWebhookService->create(
				PendingWebhookService::WORKFLOW_SHARINGCASE,
				$userId,
				$fileId,
				(string)$documentId,
			);

			$sharingcaseCommentDto = new SharingcaseCommentDto(
				$userId,
				$fileId,
				$pendingWebhook->getNonce(),
				$recipientEmail,
			);

			$documentConfiguration = [
				'signatureFields' => array_map(
					static fn (SignatureFieldDto $field): array => $field->toSharingcasePayload(),
					$this->settingsService->getSignatureFields()
				),
			];

			$sharingcaseResult = $this->signoSignUniversal->createSharingcase(
				$token,
				$documentId,
				$recipientEmail,
				$password,
				$tanTarget,
				$sharingcaseCommentDto->toArray(),
				$documentConfiguration
			);

			if (isset($sharingcaseResult['error'])) {
				$this->logger->error(self::LOG_PREFIX . 'failed to create sharing case', [
					'fileId' => $fileId,
					'fileName' => $fileName,
					'userId' => $userId,
					'documentId' => $documentId,
					'error' => $sharingcaseResult['error'],
				]);

				return new DataResponse([
					'error' => 'Create Sharingcase Error: ' . $sharingcaseResult['error'],
					'fileId' => $fileId,
					'fileName' => $fileName,
					'documentConfiguration' => $documentConfiguration,
				], 500);
			}

			if (!isset($sharingcaseResult['id']) || !is_numeric($sharingcaseResult['id'])) {
				$this->logger->error(self::LOG_PREFIX . 'sharing case response does not contain a valid id', [
					'fileId' => $fileId,
					'fileName' => $fileName,
					'userId' => $userId,
					'documentId' => $documentId,
					'sharingcaseResult' => $sharingcaseResult,
				]);

				return new DataResponse([
					'error' => 'Sharingcase response does not contain a valid id',
					'fileId' => $fileId,
					'fileName' => $fileName,
					'sharingcaseResult' => $sharingcaseResult,
				], 500);
			}

			$sharingcaseId = (int)$sharingcaseResult['id'];

			$this->pendingWebhookService->attachSharingcaseId(
				PendingWebhookService::WORKFLOW_SHARINGCASE,
				$userId,
				$fileId,
				$pendingWebhook->getNonce(),
				(string)$sharingcaseId,
			);

			$notifyResult = $this->signoSignUniversal->notifySharingcase(
				$token,
				$sharingcaseId,
				$locale
			);

			if (isset($notifyResult['error'])) {
				$this->logger->error(self::LOG_PREFIX . 'failed to notify sharing case', [
					'fileId' => $fileId,
					'fileName' => $fileName,
					'userId' => $userId,
					'documentId' => $documentId,
					'sharingcaseId' => $sharingcaseId,
					'error' => $notifyResult['error'],
				]);

				return new DataResponse([
					'error' => 'Notify Sharingcase Error: ' . $notifyResult['error'],
					'fileId' => $fileId,
					'fileName' => $fileName,
					'sharingcaseId' => $sharingcaseId,
				], 500);
			}

			$this->logger->info(self::LOG_PREFIX . 'sharing case initialized successfully', [
				'fileId' => $fileId,
				'fileName' => $fileName,
				'userId' => $userId,
				'documentId' => $documentId,
				'sharingcaseId' => $sharingcaseId,
				'signatureFieldCount' => count($documentConfiguration['signatureFields']),
			]);

			$this->fileCommentService->addSendComment(
				$fileId,
				$userId,
				$recipientEmail,
				$authType,
				$this->settingsService->getCommentSend(),
			);

			$this->fileTagService->assignTag($fileId, $this->settingsService->getTagSend(), [$this->settingsService->getTagSigned(), $this->settingsService->getTagRejected()]);

			return new DataResponse([
				'fileId' => $fileId,
				'fileName' => $fileName,
				'documentId' => $documentId,
				'uploadResult' => $uploadResult,
				'sharingcaseResult' => $sharingcaseResult,
				'notifyResult' => $notifyResult,
				'documentConfiguration' => $documentConfiguration,
				'message' => 'Document uploaded successfully.',
			]);
		} finally {
			$revokeResult = $this->signoSignUniversal->revokeInstanceToken($token);

			if (isset($revokeResult['error'])) {
				$this->logger->warning(self::LOG_PREFIX . 'failed to revoke instance token after sharing case flow', [
					'fileId' => $fileId,
					'fileName' => $fileName,
					'userId' => $userId,
					'error' => $revokeResult['error'],
				]);
			}
		}
	}

	/**
	 * @return array{user: \OCP\IUser, node: mixed, binary: string, fileName: string}
	 */
	private function getFileBinaryForCurrentUser(int $fileId): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSBadRequestException('Could not determine user');
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$nodes = $userFolder->getById($fileId);

		if ($nodes === []) {
			throw new OCSBadRequestException($fileId . ' File not found');
		}

		$node = $nodes[0];

		if (!$node instanceof File) {
			throw new OCSBadRequestException('Not a file: ' . $fileId);
		}

		$stream = $node->fopen('r');
		if ($stream === false) {
			throw new \RuntimeException('Could not open file for reading: ' . $fileId);
		}
		$binary = stream_get_contents($stream);
		fclose($stream);

		if ($binary === false) {
			throw new \RuntimeException('Could not read file');
		}

		return [
			'user' => $user,
			'node' => $node,
			'binary' => $binary,
			'fileName' => $node->getName(),
		];
	}
}
