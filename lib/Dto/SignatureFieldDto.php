<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Dto;

final class SignatureFieldDto {
	public function __construct(
		private string $id,
		private string $signerName,
		private string $searchText,
		private int $width = 180,
		private int $height = 60,
		private int $offsetX = 0,
		private int $offsetY = 0,
		private bool $required = false,
	) {
	}

	public static function fromArray(array $data): ?self {
		$id = trim((string)($data['id'] ?? ''));
		$signerName = trim((string)($data['signerName'] ?? ''));
		$searchText = trim((string)($data['searchText'] ?? ''));

		if ($id === '' || $signerName === '' || $searchText === '') {
			return null;
		}

		$width = (int)($data['width'] ?? 180);
		$height = (int)($data['height'] ?? 60);

		if ($width <= 0 || $height <= 0) {
			return null;
		}

		return new self(
			$id,
			$signerName,
			$searchText,
			$width,
			$height,
			(int)($data['offsetX'] ?? 0),
			(int)($data['offsetY'] ?? 0),
			filter_var($data['required'] ?? false, FILTER_VALIDATE_BOOL),
		);
	}

	public function toArray(): array {
		return [
			'id' => $this->id,
			'signerName' => $this->signerName,
			'searchText' => $this->searchText,
			'width' => $this->width,
			'height' => $this->height,
			'offsetX' => $this->offsetX,
			'offsetY' => $this->offsetY,
			'required' => $this->required,
		];
	}

	public function toViewerPayload(): array {
		return [
			'width' => $this->width,
			'height' => $this->height,
			'mandatory' => $this->required ? 'true' : 'false',
			'xoffset' => $this->offsetX,
			'yoffset' => $this->offsetY,
			'recursive' => 'true',
			'keyword' => $this->searchText,
			'signatureFieldName' => $this->signerName,
		];
	}

	public function toSharingcasePayload(): array {
		return [
			'width' => $this->width,
			'height' => $this->height,
			'option' => $this->required ? 1 : 0,
			'signer' => $this->signerName,
			'type' => 'DynamicSignatureField',
			'recursive' => true,
			'keyword' => $this->searchText,
			'offsetX' => $this->offsetX,
			'offsetY' => $this->offsetY,
		];
	}

	public function getId(): string {
		return $this->id;
	}

	public function getSignerName(): string {
		return $this->signerName;
	}

	public function getSearchText(): string {
		return $this->searchText;
	}

	public function getWidth(): int {
		return $this->width;
	}

	public function getHeight(): int {
		return $this->height;
	}

	public function getOffsetX(): int {
		return $this->offsetX;
	}

	public function getOffsetY(): int {
		return $this->offsetY;
	}

	public function isRequired(): bool {
		return $this->required;
	}
}
