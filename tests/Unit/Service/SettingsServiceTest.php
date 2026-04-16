<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Tests\Unit\Service;

use OCA\SignotecSignoSignUniversal\Dto\SignatureFieldDto;
use OCA\SignotecSignoSignUniversal\Service\FileTagService;
use OCA\SignotecSignoSignUniversal\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SettingsServiceTest extends TestCase {
	private IAppConfig&MockObject $config;
	private FileTagService&MockObject $fileTagService;
	private IL10N&MockObject $l;
	private SettingsService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IAppConfig::class);
		$this->fileTagService = $this->createMock(FileTagService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(static function (string $text, array $parameters = []): string {
			return empty($parameters) ? $text : vsprintf($text, $parameters);
		});
		$this->service = new SettingsService($this->config, 'signotecsignosignuniversal', $this->fileTagService, $this->l);
	}

	public function testGetUrlReturnsConfiguredValue(): void {
		$this->config->expects(self::once())
			->method('getValueString')
			->with('signotecsignosignuniversal', 'url', '')
			->willReturn('https://sign.example.test');

		self::assertSame('https://sign.example.test', $this->service->getUrl());
	}

	public function testGetUsernameReturnsConfiguredValue(): void {
		$this->config->expects(self::once())
			->method('getValueString')
			->with('signotecsignosignuniversal', 'username', '')
			->willReturn('demo-user');

		self::assertSame('demo-user', $this->service->getUsername());
	}

	public function testGetPasswordReturnsConfiguredValue(): void {
		$this->config->expects(self::once())
			->method('getValueString')
			->with('signotecsignosignuniversal', 'password', '')
			->willReturn('secret');

		self::assertSame('secret', $this->service->getPassword());
	}

	public function testGetCommentRejectedReturnsConfiguredValue(): void {
		$this->config->expects(self::once())
			->method('getValueString')
			->with('signotecsignosignuniversal', 'comment_rejected', '')
			->willReturn('Rejected: @reason@');

		self::assertSame('Rejected: @reason@', $this->service->getCommentRejected());
	}

	public function testGetTagRejectedReturnsConfiguredValue(): void {
		$this->config->expects(self::once())
			->method('getValueString')
			->with('signotecsignosignuniversal', 'tag_rejected', '')
			->willReturn('rejected');

		self::assertSame('rejected', $this->service->getTagRejected());
	}

	public function testGetConnectionErrorReturnsConfiguredValue(): void {
		$this->config->expects(self::once())
			->method('getValueString')
			->with('signotecsignosignuniversal', 'connection_error', '')
			->willReturn('Connection refused');

		self::assertSame('Connection refused', $this->service->getConnectionError());
	}

	public function testGetSignatureFieldsReturnsEmptyArrayWhenJsonIsInvalid(): void {
		$this->config->expects(self::once())
			->method('getValueString')
			->with('signotecsignosignuniversal', 'signature_fields', '[]')
			->willReturn('not-json');

		self::assertSame([], $this->service->getSignatureFields());
	}

	public function testGetSignatureFieldsReturnsOnlyValidDtos(): void {
		$payload = json_encode([
			[
				'id' => 'sig-1',
				'signerName' => 'Max Mustermann',
				'searchText' => 'UNTERSCHRIFT_1',
				'width' => 180,
				'height' => 60,
				'offsetX' => 5,
				'offsetY' => 10,
				'required' => true,
			],
			[
				'id' => '',
				'signerName' => 'Invalid',
				'searchText' => 'INVALID',
				'width' => 180,
				'height' => 60,
				'offsetX' => 0,
				'offsetY' => 0,
				'required' => false,
			],
			'garbage',
		], JSON_THROW_ON_ERROR);

		$this->config->expects(self::once())
			->method('getValueString')
			->with('signotecsignosignuniversal', 'signature_fields', '[]')
			->willReturn($payload);

		$result = $this->service->getSignatureFields();

		self::assertCount(1, $result);
		self::assertContainsOnlyInstancesOf(SignatureFieldDto::class, $result);

		self::assertSame('sig-1', $result[0]->getId());
		self::assertSame('Max Mustermann', $result[0]->getSignerName());
		self::assertSame('UNTERSCHRIFT_1', $result[0]->getSearchText());
		self::assertSame(180, $result[0]->getWidth());
		self::assertSame(60, $result[0]->getHeight());
		self::assertSame(5, $result[0]->getOffsetX());
		self::assertSame(10, $result[0]->getOffsetY());
		self::assertTrue($result[0]->isRequired());
	}

	public function testDeleteAllSettingsDeletesAllKeys(): void {
		$expectedKeys = [
			'url',
			'username',
			'password',
			'signature_fields',
			'comment_send',
			'comment_signed',
			'comment_rejected',
			'tag_send',
			'tag_signed',
			'tag_rejected',
			'connection_valid',
			'sms77_api_key_set',
			'webhook_document_updated_endpoint',
			'webhook_document_shared_closed_endpoint',
			'connection_error',
		];

		$deletedKeys = [];

		$this->config->expects(self::exactly(count($expectedKeys)))
			->method('deleteKey')
			->willReturnCallback(function (string $app, string $key) use (&$deletedKeys): void {
				$deletedKeys[] = $key;
			});

		$this->service->deleteAllSettings();

		self::assertSame($expectedKeys, $deletedKeys);
	}

	public function testSetSettingsReturnsErrorWhenSignatureFieldsIsNotArray(): void {
		$result = $this->service->setSettings(['signatureFields' => 'invalid']);

		self::assertSame([
			'error' => 'signatureFields must be an array',
		], $result);
	}

	public function testSetSettingsReturnsErrorForDuplicateIds(): void {
		$result = $this->service->setSettings(['signatureFields' => [
			[
				'id' => 'same',
				'signerName' => 'Signer A',
				'searchText' => 'TEXT_A',
				'width' => 180,
				'height' => 60,
				'offsetX' => 0,
				'offsetY' => 0,
				'required' => true,
			],
			[
				'id' => 'same',
				'signerName' => 'Signer B',
				'searchText' => 'TEXT_B',
				'width' => 180,
				'height' => 60,
				'offsetX' => 0,
				'offsetY' => 0,
				'required' => false,
			],
		]]);

		self::assertSame([
			'error' => 'signatureFields[1].id must be unique',
		], $result);
	}

	public function testSetSettingsReturnsErrorForDuplicateSearchText(): void {
		$result = $this->service->setSettings(['signatureFields' => [
			[
				'id' => 'a',
				'signerName' => 'Signer A',
				'searchText' => 'TEXT',
				'width' => 180,
				'height' => 60,
				'offsetX' => 0,
				'offsetY' => 0,
				'required' => true,
			],
			[
				'id' => 'b',
				'signerName' => 'Signer B',
				'searchText' => 'TEXT',
				'width' => 180,
				'height' => 60,
				'offsetX' => 0,
				'offsetY' => 0,
				'required' => false,
			],
		]]);

		self::assertSame([
			'error' => 'signatureFields[1].searchText must be unique',
		], $result);
	}

	public function testSetSettingsReturnsErrorWhenRequiredIsMissing(): void {
		$result = $this->service->setSettings(['signatureFields' => [
			[
				'id' => 'a',
				'signerName' => 'Signer A',
				'searchText' => 'TEXT_A',
				'width' => 180,
				'height' => 60,
				'offsetX' => 0,
				'offsetY' => 0,
			],
		]]);

		self::assertSame([
			'error' => 'signatureFields[0].required is required',
		], $result);
	}

	public function testSetSettingsStoresNormalizedSignatureFields(): void {
		$signatureFields = [
			[
				'id' => 'sig-1',
				'signerName' => 'Signer 1',
				'searchText' => 'FIELD_1',
				'width' => 200,
				'height' => 80,
				'offsetX' => 11,
				'offsetY' => 22,
				'required' => 1,
			],
			[
				'id' => 'sig-2',
				'signerName' => 'Signer 2',
				'searchText' => 'FIELD_2',
				'width' => 210,
				'height' => 90,
				'offsetX' => -5,
				'offsetY' => 15,
				'required' => false,
			],
		];

		$this->config->expects(self::once())
			->method('setValueString')
			->with(
				'signotecsignosignuniversal',
				'signature_fields',
				self::callback(static function (string $json): bool {
					$decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

					return $decoded === [
						[
							'id' => 'sig-1',
							'signerName' => 'Signer 1',
							'searchText' => 'FIELD_1',
							'width' => 200,
							'height' => 80,
							'offsetX' => 11,
							'offsetY' => 22,
							'required' => true,
						],
						[
							'id' => 'sig-2',
							'signerName' => 'Signer 2',
							'searchText' => 'FIELD_2',
							'width' => 210,
							'height' => 90,
							'offsetX' => -5,
							'offsetY' => 15,
							'required' => false,
						],
					];
				})
			)
			->willReturn(true);

		$result = $this->service->setSettings(['signatureFields' => $signatureFields]);

		self::assertNull($result);
	}

	public function testSetSettingsStoresScalarSettingsAndSignatureFields(): void {
		$calls = [];

		$this->config->expects(self::exactly(4))
			->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value) use (&$calls): bool {
				$calls[] = [$app, $key, $value];
				return true;
			});

		$result = $this->service->setSettings([
			'url' => 'https://sign.example.test',
			'username' => 'demo-user',
			'password' => 'secret',
			'signatureFields' => [
				[
					'id' => 'sig-1',
					'signerName' => 'Signer 1',
					'searchText' => 'FIELD_1',
					'width' => 180,
					'height' => 60,
					'offsetX' => 0,
					'offsetY' => 0,
					'required' => false,
				],
			],
		]);

		self::assertNull($result);

		self::assertSame('signotecsignosignuniversal', $calls[0][0]);
		self::assertSame('url', $calls[0][1]);
		self::assertSame('https://sign.example.test', $calls[0][2]);

		self::assertSame('signotecsignosignuniversal', $calls[1][0]);
		self::assertSame('username', $calls[1][1]);
		self::assertSame('demo-user', $calls[1][2]);

		self::assertSame('signotecsignosignuniversal', $calls[2][0]);
		self::assertSame('password', $calls[2][1]);
		self::assertSame('secret', $calls[2][2]);

		self::assertSame('signotecsignosignuniversal', $calls[3][0]);
		self::assertSame('signature_fields', $calls[3][1]);
		self::assertJson($calls[3][2]);
	}

	public function testSetSettingsStoresCommentTemplates(): void {
		$calls = [];

		$this->config->expects(self::exactly(3))
			->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value) use (&$calls): bool {
				$calls[] = [$key, $value];
				return true;
			});

		$result = $this->service->setSettings([
			'commentSend' => 'Sent to @mailto@',
			'commentSigned' => 'Signed by @userid@',
			'commentRejected' => 'Rejected: @reason@',
		]);

		self::assertNull($result);
		self::assertSame(['comment_send', 'Sent to @mailto@'], $calls[0]);
		self::assertSame(['comment_signed', 'Signed by @userid@'], $calls[1]);
		self::assertSame(['comment_rejected', 'Rejected: @reason@'], $calls[2]);
	}

	public function testSetSettingsStoresTagsAndCallsEnsureTagExists(): void {
		$calls = [];
		$ensuredTags = [];

		$this->config->expects(self::exactly(3))
			->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value) use (&$calls): bool {
				$calls[] = [$key, $value];
				return true;
			});

		$this->fileTagService->expects(self::exactly(3))
			->method('ensureTagExists')
			->willReturnCallback(function (string $tag) use (&$ensuredTags): void {
				$ensuredTags[] = $tag;
			});

		$result = $this->service->setSettings([
			'tagSend' => '  in-progress  ',
			'tagSigned' => 'signed',
			'tagRejected' => ' rejected ',
		]);

		self::assertNull($result);

		// Tags must be trimmed before storing
		self::assertSame(['tag_send', 'in-progress'], $calls[0]);
		self::assertSame(['tag_signed', 'signed'], $calls[1]);
		self::assertSame(['tag_rejected', 'rejected'], $calls[2]);

		// ensureTagExists called with trimmed values
		self::assertSame(['in-progress', 'signed', 'rejected'], $ensuredTags);
	}

	public function testGetPublicSettingsReturnsExpectedStructure(): void {
		$this->config->method('getValueString')
			->willReturnMap([
				['signotecsignosignuniversal', 'url', '', 'https://sign.example.test'],
				['signotecsignosignuniversal', 'username', '', 'demo-user'],
				['signotecsignosignuniversal', 'password', '', 'secret'],
				['signotecsignosignuniversal', 'connection_valid', '0', '1'],
				['signotecsignosignuniversal', 'connection_error', '', ''],
				['signotecsignosignuniversal', 'sms77_api_key_set', '0', '1'],
				['signotecsignosignuniversal', 'webhook_document_updated_endpoint', '', 'https://nc.example.test/webhook_updated'],
				['signotecsignosignuniversal', 'webhook_document_shared_closed_endpoint', '', 'https://nc.example.test/webhook_shared_closed'],
				[
					'signotecsignosignuniversal',
					'signature_fields',
					'[]',
					json_encode([
						[
							'id' => 'sig-1',
							'signerName' => 'Signer 1',
							'searchText' => 'FIELD_1',
							'width' => 180,
							'height' => 60,
							'offsetX' => 0,
							'offsetY' => 0,
							'required' => true,
						],
					], JSON_THROW_ON_ERROR),
				],
				['signotecsignosignuniversal', 'comment_send', '', 'Sent to @mailto@'],
				['signotecsignosignuniversal', 'comment_signed', '', 'Signed by @userid@'],
				['signotecsignosignuniversal', 'comment_rejected', '', 'Rejected: @reason@'],
				['signotecsignosignuniversal', 'tag_send', '', 'in-progress'],
				['signotecsignosignuniversal', 'tag_signed', '', 'signed'],
				['signotecsignosignuniversal', 'tag_rejected', '', 'rejected'],
			]);

		$result = $this->service->getPublicSettings();

		self::assertSame('https://sign.example.test', $result['url']);
		self::assertSame('demo-user', $result['username']);
		self::assertTrue($result['hasPassword']);
		self::assertTrue($result['connectionValid']);
		self::assertSame('', $result['connectionError']);
		self::assertTrue($result['sms77ApiKeySet']);
		self::assertSame('https://nc.example.test/webhook_updated', $result['webhookDocumentUpdatedEndpoint']);
		self::assertSame('https://nc.example.test/webhook_shared_closed', $result['webhookDocumentSharedClosedEndpoint']);
		self::assertSame([
			[
				'id' => 'sig-1',
				'signerName' => 'Signer 1',
				'searchText' => 'FIELD_1',
				'width' => 180,
				'height' => 60,
				'offsetX' => 0,
				'offsetY' => 0,
				'required' => true,
			],
		], $result['signatureFields']);
		self::assertSame('Sent to @mailto@', $result['commentSend']);
		self::assertSame('Signed by @userid@', $result['commentSigned']);
		self::assertSame('Rejected: @reason@', $result['commentRejected']);
		self::assertSame('in-progress', $result['tagSend']);
		self::assertSame('signed', $result['tagSigned']);
		self::assertSame('rejected', $result['tagRejected']);
	}
}
