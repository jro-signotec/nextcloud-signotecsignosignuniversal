<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Tests\Unit\Service;

use OCA\SignotecSignoSignUniversal\Dto\SharingcaseCommentDto;
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
	private WebhookProcessingService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->signoSignUniversal = $this->createMock(SignoSignUniversal::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new WebhookProcessingService(
			$this->signoSignUniversal,
			$this->rootFolder,
			$this->logger,
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

		$result = $this->service->downloadAndUpdateFile('123', 42, 'john');

		self::assertSame('File updated successfully', $result['message']);
		self::assertSame('123', $result['documentId']);
		self::assertSame(42, $result['fileId']);
		self::assertSame('john', $result['userId']);
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
				], JSON_THROW_ON_ERROR),
			]);

		$this->signoSignUniversal->expects(self::once())
			->method('revokeInstanceToken')
			->with('Bearer token')
			->willReturn(['success' => true]);

		self::assertNull($this->service->parseSharingcaseComment('555'));
	}
}
