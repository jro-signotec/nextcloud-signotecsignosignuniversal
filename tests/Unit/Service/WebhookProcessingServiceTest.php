<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Tests\Unit\Service;

use OCA\SignotecSignoSignUniversal\Dto\SharingcaseCommentDto;
use OCA\SignotecSignoSignUniversal\Service\FileCommentService;
use OCA\SignotecSignoSignUniversal\Service\FileTagService;
use OCA\SignotecSignoSignUniversal\Service\SettingsService;
use OCA\SignotecSignoSignUniversal\Service\SignoSignUniversal;
use OCA\SignotecSignoSignUniversal\Service\WebhookProcessingService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookProcessingServiceTest extends TestCase {
	private SignoSignUniversal&MockObject $signoSignUniversal;
	private IRootFolder&MockObject $rootFolder;
	private LoggerInterface&MockObject $logger;
	private FileCommentService&MockObject $fileCommentService;
	private FileTagService&MockObject $fileTagService;
	private SettingsService&MockObject $settingsService;
	private WebhookProcessingService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->signoSignUniversal = $this->createMock(SignoSignUniversal::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->fileCommentService = $this->createMock(FileCommentService::class);
		$this->fileTagService = $this->createMock(FileTagService::class);
		$this->settingsService = $this->createMock(SettingsService::class);

		$this->service = new WebhookProcessingService(
			$this->signoSignUniversal,
			$this->rootFolder,
			$this->logger,
			$this->fileCommentService,
			$this->fileTagService,
			$this->settingsService,
		);
	}

	public function testDownloadAndUpdateFileUsesProvidedTokenAndUpdatesFile(): void {
		$file = $this->createMock(File::class);
		$parent = $this->createMock(Folder::class);
		$userFolder = $this->createMock(Folder::class);

		$this->signoSignUniversal->expects(self::never())
			->method('getInstanceToken');

		$this->signoSignUniversal->expects(self::once())
			->method('downloadDocument')
			->with('Bearer provided-token', 123)
			->willReturn(['file' => '%PDF signed content']);

		$this->signoSignUniversal->expects(self::never())
			->method('revokeInstanceToken');

		$this->rootFolder->expects(self::once())
			->method('getUserFolder')
			->with('john')
			->willReturn($userFolder);

		$userFolder->expects(self::once())
			->method('getById')
			->with(42)
			->willReturn([$file]);

		$file->method('getPath')
			->willReturn('/john/files/test.pdf');

		$file->expects(self::once())
			->method('putContent')
			->with('%PDF signed content');

		$file->expects(self::once())
			->method('touch')
			->with(self::isType('int'));

		$file->expects(self::once())
			->method('getParent')
			->willReturn($parent);

		$parent->expects(self::once())
			->method('touch');

		$this->settingsService->method('getCommentSigned')->willReturn('Signed by @userid@');
		$this->settingsService->method('getTagSigned')->willReturn('signed');
		$this->settingsService->method('getTagSend')->willReturn('in-progress');
		$this->settingsService->method('getTagRejected')->willReturn('rejected');

		$this->fileCommentService->expects(self::once())
			->method('addSignedComment')
			->with(42, 'john', '', 'Signed by @userid@');

		$this->fileTagService->expects(self::once())
			->method('assignTag')
			->with(42, 'signed', ['in-progress', 'rejected']);

		$result = $this->service->downloadAndUpdateFile(
			'123',
			42,
			'john',
			'Bearer provided-token',
		);

		self::assertSame([
			'message' => 'File updated successfully',
			'documentId' => '123',
			'fileId' => 42,
			'userId' => 'john',
		], $result);
	}

	public function testDownloadAndUpdateFileFetchesAndRevokesInstanceTokenWhenMissing(): void {
		$file = $this->createMock(File::class);
		$parent = $this->createMock(Folder::class);
		$userFolder = $this->createMock(Folder::class);

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer fetched-token']);

		$this->signoSignUniversal->expects(self::once())
			->method('downloadDocument')
			->with('Bearer fetched-token', 123)
			->willReturn(['file' => '%PDF signed content']);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken')
			->with('Bearer fetched-token');

		$this->rootFolder->expects(self::once())
			->method('getUserFolder')
			->with('john')
			->willReturn($userFolder);

		$userFolder->expects(self::once())
			->method('getById')
			->with(42)
			->willReturn([$file]);

		$file->method('getPath')->willReturn('/john/files/test.pdf');
		$file->expects(self::once())->method('putContent')->with('%PDF signed content');
		$file->expects(self::once())->method('touch')->with(self::isType('int'));
		$file->expects(self::once())->method('getParent')->willReturn($parent);
		$parent->expects(self::once())->method('touch');

		$this->settingsService->method('getCommentSigned')->willReturn('');
		$this->settingsService->method('getTagSigned')->willReturn('signed');
		$this->settingsService->method('getTagSend')->willReturn('');
		$this->fileCommentService->method('addSignedComment');
		$this->fileTagService->method('assignTag');

		$result = $this->service->downloadAndUpdateFile('123', 42, 'john');

		self::assertSame('File updated successfully', $result['message']);
		self::assertSame('123', $result['documentId']);
		self::assertSame(42, $result['fileId']);
		self::assertSame('john', $result['userId']);
	}

	public function testDownloadAndUpdateFilePassesRecipientEmailToComment(): void {
		$file = $this->createMock(File::class);
		$parent = $this->createMock(Folder::class);
		$userFolder = $this->createMock(Folder::class);

		$this->signoSignUniversal->method('downloadDocument')
			->willReturn(['file' => '%PDF content']);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$userFolder->method('getById')->willReturn([$file]);
		$file->method('getPath')->willReturn('/john/files/test.pdf');
		$file->method('putContent');
		$file->method('touch');
		$file->method('getParent')->willReturn($parent);
		$parent->method('touch');

		$this->settingsService->method('getCommentSigned')->willReturn('Signed for @mailto@');
		$this->settingsService->method('getTagSigned')->willReturn('signed');
		$this->settingsService->method('getTagSend')->willReturn('in-progress');

		$this->fileCommentService->expects(self::once())
			->method('addSignedComment')
			->with(42, 'john', 'signer@example.test', 'Signed for @mailto@');

		$this->fileTagService->method('assignTag');

		$this->service->downloadAndUpdateFile('123', 42, 'john', 'Bearer tok', 'signer@example.test');
	}

	public function testDownloadAndUpdateFileReturnsErrorWhenInstanceTokenFetchFails(): void {
		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['error' => 'token error']);

		$this->signoSignUniversal->expects(self::never())
			->method('downloadDocument');

		$result = $this->service->downloadAndUpdateFile('123', 42, 'john');

		self::assertSame([
			'error' => 'token error',
			'documentId' => '123',
			'fileId' => 42,
			'userId' => 'john',
		], $result);
	}

	public function testDownloadAndUpdateFileReturnsErrorForInvalidDocumentId(): void {
		$this->signoSignUniversal->expects(self::never())
			->method('downloadDocument');

		$result = $this->service->downloadAndUpdateFile('abc', 42, 'john', 'Bearer token');

		self::assertSame([
			'error' => 'Invalid document id',
			'documentId' => 'abc',
			'fileId' => 42,
			'userId' => 'john',
		], $result);
	}

	public function testDownloadAndUpdateFileReturnsErrorWhenDownloadFails(): void {
		$this->signoSignUniversal->expects(self::once())
			->method('downloadDocument')
			->with('Bearer token', 123)
			->willReturn(['error' => 'download failed']);

		$result = $this->service->downloadAndUpdateFile('123', 42, 'john', 'Bearer token');

		self::assertSame([
			'error' => 'download failed',
			'documentId' => '123',
			'fileId' => 42,
			'userId' => 'john',
		], $result);
	}

	public function testDownloadAndUpdateFileReturnsErrorWhenDownloadedDocumentIsEmpty(): void {
		$this->signoSignUniversal->expects(self::once())
			->method('downloadDocument')
			->with('Bearer token', 123)
			->willReturn(['file' => '']);

		$result = $this->service->downloadAndUpdateFile('123', 42, 'john', 'Bearer token');

		self::assertSame([
			'error' => 'Downloaded document is empty',
			'documentId' => '123',
			'fileId' => 42,
			'userId' => 'john',
		], $result);
	}

	public function testDownloadAndUpdateFileThrowsForInvalidFileId(): void {
		$this->signoSignUniversal->expects(self::once())
			->method('downloadDocument')
			->with('Bearer token', 123)
			->willReturn(['file' => '%PDF signed content']);

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Invalid file id');

		$this->service->downloadAndUpdateFile('123', 'invalid', 'john', 'Bearer token');
	}

	public function testDownloadAndUpdateFileThrowsWhenFileNotFound(): void {
		$userFolder = $this->createMock(Folder::class);

		$this->signoSignUniversal->expects(self::once())
			->method('downloadDocument')
			->with('Bearer token', 123)
			->willReturn(['file' => '%PDF signed content']);

		$this->rootFolder->expects(self::once())
			->method('getUserFolder')
			->with('john')
			->willReturn($userFolder);

		$userFolder->expects(self::once())
			->method('getById')
			->with(42)
			->willReturn([]);

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('42 File not found');

		$this->service->downloadAndUpdateFile('123', 42, 'john', 'Bearer token');
	}

	// -----------------------------------------------------------------------
	// parseSharingcaseComment
	// -----------------------------------------------------------------------

	public function testParseSharingcaseCommentReturnsDtoOnSuccess(): void {
		$comment = [
			'userId' => 'john',
			'fileId' => 42,
			'nonce' => 'nonce-123',
		];

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer token']);

		$this->signoSignUniversal->expects(self::once())
			->method('getSharingcase')
			->with('Bearer token', 555)
			->willReturn([
				'comment' => json_encode($comment, JSON_THROW_ON_ERROR),
			]);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken')
			->with('Bearer token')
			->willReturn(['success' => true]);

		$result = $this->service->parseSharingcaseComment('555');

		self::assertInstanceOf(SharingcaseCommentDto::class, $result);
		self::assertSame('john', $result->getUserId());
		self::assertSame(42, $result->getFileId());
		self::assertSame('nonce-123', $result->getNonce());
	}

	public function testParseSharingcaseCommentReturnsNullWhenTokenFetchFails(): void {
		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['error' => 'token error']);

		$this->signoSignUniversal->expects(self::never())
			->method('getSharingcase');

		self::assertNull($this->service->parseSharingcaseComment('555'));
	}

	public function testParseSharingcaseCommentReturnsNullWhenSharingcaseIdIsInvalid(): void {
		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer token']);

		$this->signoSignUniversal->expects(self::never())
			->method('getSharingcase');

		$this->signoSignUniversal->expects(self::never())
			->method('revokeInstanceToken');

		self::assertNull($this->service->parseSharingcaseComment('invalid'));
	}

	public function testParseSharingcaseCommentReturnsNullWhenCommentJsonIsInvalid(): void {
		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer token']);

		$this->signoSignUniversal->expects(self::once())
			->method('getSharingcase')
			->with('Bearer token', 555)
			->willReturn([
				'comment' => 'not-json',
			]);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken')
			->with('Bearer token')
			->willReturn(['success' => true]);

		self::assertNull($this->service->parseSharingcaseComment('555'));
	}

	public function testParseSharingcaseCommentReturnsNullWhenCommentStructureIsInvalid(): void {
		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer token']);

		$this->signoSignUniversal->expects(self::once())
			->method('getSharingcase')
			->with('Bearer token', 555)
			->willReturn([
				'comment' => json_encode([
					'userId' => 'john',
					'fileId' => 42,
					// missing 'nonce'
				], JSON_THROW_ON_ERROR),
			]);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken')
			->with('Bearer token')
			->willReturn(['success' => true]);

		self::assertNull($this->service->parseSharingcaseComment('555'));
	}

	// -----------------------------------------------------------------------
	// parseSharingcaseCommentAndReason
	// -----------------------------------------------------------------------

	public function testParseSharingcaseCommentAndReasonReturnsDtoAndReason(): void {
		$comment = ['userId' => 'john', 'fileId' => 42, 'nonce' => 'nonce-1', 'recipientEmail' => 'r@example.test'];

		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer token']);

		$this->signoSignUniversal->expects(self::once())
			->method('getSharingcase')
			->with('Bearer token', 555)
			->willReturn([
				'comment' => json_encode($comment, JSON_THROW_ON_ERROR),
				'rejectedReason' => 'Signature looks wrong',
			]);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken')
			->with('Bearer token')
			->willReturn(['success' => true]);

		$result = $this->service->parseSharingcaseCommentAndReason('555');

		self::assertInstanceOf(SharingcaseCommentDto::class, $result['dto']);
		self::assertSame('john', $result['dto']->getUserId());
		self::assertSame(42, $result['dto']->getFileId());
		self::assertSame('nonce-1', $result['dto']->getNonce());
		self::assertSame('r@example.test', $result['dto']->getRecipientEmail());
		self::assertSame('Signature looks wrong', $result['rejectedReason']);
	}

	public function testParseSharingcaseCommentAndReasonReturnsDtoWithEmptyReasonWhenMissing(): void {
		$comment = ['userId' => 'john', 'fileId' => 42, 'nonce' => 'nonce-1'];

		$this->signoSignUniversal->method('getInstanceToken')
			->willReturn(['token' => 'Bearer token']);

		$this->signoSignUniversal->method('getSharingcase')
			->willReturn([
				'comment' => json_encode($comment, JSON_THROW_ON_ERROR),
				// no 'rejectedReason' key
			]);

		$this->signoSignUniversal->method('revokeInstanceToken')->willReturn(['success' => true]);

		$result = $this->service->parseSharingcaseCommentAndReason('555');

		self::assertInstanceOf(SharingcaseCommentDto::class, $result['dto']);
		self::assertSame('', $result['rejectedReason']);
	}

	public function testParseSharingcaseCommentAndReasonReturnsNullDtoWhenTokenFails(): void {
		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['error' => 'token error']);

		$this->signoSignUniversal->expects(self::never())
			->method('getSharingcase');

		$result = $this->service->parseSharingcaseCommentAndReason('555');

		self::assertNull($result['dto']);
		self::assertSame('', $result['rejectedReason']);
	}

	public function testParseSharingcaseCommentAndReasonReturnsNullDtoWhenIdIsInvalid(): void {
		$this->signoSignUniversal->method('getInstanceToken')
			->willReturn(['token' => 'Bearer token']);

		$this->signoSignUniversal->expects(self::never())
			->method('getSharingcase');

		$this->signoSignUniversal->expects(self::never())
			->method('revokeInstanceToken');

		$result = $this->service->parseSharingcaseCommentAndReason('not-a-number');

		self::assertNull($result['dto']);
		self::assertSame('', $result['rejectedReason']);
	}

	public function testParseSharingcaseCommentAndReasonReturnsNullDtoWhenCommentJsonInvalid(): void {
		$this->signoSignUniversal->method('getInstanceToken')
			->willReturn(['token' => 'Bearer token']);

		$this->signoSignUniversal->method('getSharingcase')
			->willReturn([
				'comment' => 'not-json',
				'rejectedReason' => 'Bad signature',
			]);

		$this->signoSignUniversal->method('revokeInstanceToken')->willReturn(['success' => true]);

		$result = $this->service->parseSharingcaseCommentAndReason('555');

		self::assertNull($result['dto']);
		// rejectedReason is still extracted even when DTO parsing fails
		self::assertSame('Bad signature', $result['rejectedReason']);
	}

	public function testParseSharingcaseCommentAndReasonReturnsNullDtoWhenCommentStructureInvalid(): void {
		$this->signoSignUniversal->method('getInstanceToken')
			->willReturn(['token' => 'Bearer token']);

		$this->signoSignUniversal->method('getSharingcase')
			->willReturn([
				'comment' => json_encode(['userId' => 'john'], JSON_THROW_ON_ERROR),
				'rejectedReason' => 'Refused',
			]);

		$this->signoSignUniversal->method('revokeInstanceToken')->willReturn(['success' => true]);

		$result = $this->service->parseSharingcaseCommentAndReason('555');

		self::assertNull($result['dto']);
		self::assertSame('Refused', $result['rejectedReason']);
	}

	// -----------------------------------------------------------------------
	// handleRejection
	// -----------------------------------------------------------------------

	public function testHandleRejectionDeletesDocumentAddsCommentAndTag(): void {
		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['token' => 'Bearer tok']);

		$this->signoSignUniversal->expects(self::once())
			->method('deleteDocument')
			->with('Bearer tok', 123)
			->willReturn(['success' => true]);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken')
			->with('Bearer tok');

		$this->settingsService->method('getCommentRejected')->willReturn('Rejected: @reason@');
		$this->settingsService->method('getTagRejected')->willReturn('rejected');
		$this->settingsService->method('getTagSend')->willReturn('in-progress');
		$this->settingsService->method('getTagSigned')->willReturn('signed');

		$this->fileCommentService->expects(self::once())
			->method('addRejectedComment')
			->with(42, 'john', 'Bad signature', 'signer@example.test', 'Rejected: @reason@');

		$this->fileTagService->expects(self::once())
			->method('assignTag')
			->with(42, 'rejected', ['in-progress', 'signed']);

		$result = $this->service->handleRejection('123', 42, 'john', 'Bad signature', 'signer@example.test');

		self::assertSame([
			'message' => 'Rejection handled successfully',
			'documentId' => '123',
			'fileId' => 42,
			'userId' => 'john',
		], $result);
	}

	public function testHandleRejectionContinuesWhenDocumentDeleteFails(): void {
		$this->signoSignUniversal->method('getInstanceToken')
			->willReturn(['token' => 'Bearer tok']);

		$this->signoSignUniversal->expects(self::once())
			->method('deleteDocument')
			->willReturn(['error' => 'not found']);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken');

		$this->settingsService->method('getCommentRejected')->willReturn('');
		$this->settingsService->method('getTagRejected')->willReturn('rejected');
		$this->settingsService->method('getTagSend')->willReturn('');

		$this->fileCommentService->method('addRejectedComment');
		$this->fileTagService->method('assignTag');

		$result = $this->service->handleRejection('123', 42, 'john', 'reason', '');

		// Still succeeds despite remote deletion failure
		self::assertSame('Rejection handled successfully', $result['message']);
	}

	public function testHandleRejectionReturnsErrorWhenTokenFetchFails(): void {
		$this->signoSignUniversal->expects(self::once())
			->method('getInstanceToken')
			->willReturn(['error' => 'unreachable']);

		$this->signoSignUniversal->expects(self::never())
			->method('deleteDocument');

		$this->fileCommentService->expects(self::never())
			->method('addRejectedComment');

		$result = $this->service->handleRejection('123', 42, 'john', 'reason', '');

		self::assertSame([
			'error' => 'unreachable',
			'documentId' => '123',
			'fileId' => 42,
			'userId' => 'john',
		], $result);
	}
}
