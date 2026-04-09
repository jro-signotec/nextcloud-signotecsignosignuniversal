<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method void setWorkflowType(string $workflowType)
 * @method string getWorkflowType()
 * @method void setUserId(string $userId)
 * @method string getUserId()
 * @method void setFileId(int $fileId)
 * @method int getFileId()
 * @method void setNonce(string $nonce)
 * @method string getNonce()
 * @method void setDocumentId(?string $documentId)
 * @method ?string getDocumentId()
 * @method void setSharingcaseId(?string $sharingcaseId)
 * @method ?string getSharingcaseId()
 * @method void setStatus(string $status)
 * @method string getStatus()
 * @method void setExpiresAt(int $expiresAt)
 * @method int getExpiresAt()
 * @method void setProcessedAt(?int $processedAt)
 * @method ?int getProcessedAt()
 * @method void setLastSourceTimestamp(?int $lastSourceTimestamp)
 * @method ?int getLastSourceTimestamp()
 * @method void setToken(?string $token)
 * @method ?string getToken()
 */
class PendingWebhook extends Entity {
	protected string $workflowType = '';
	protected string $userId = '';
	protected int $fileId = 0;
	protected string $nonce = '';
	protected ?string $documentId = null;
	protected ?string $sharingcaseId = null;
	protected string $status = '';
	protected int $expiresAt = 0;
	protected ?int $processedAt = null;
	protected ?int $lastSourceTimestamp = null;
	protected ?string $token = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('expiresAt', 'integer');
		$this->addType('processedAt', 'integer');
		$this->addType('lastSourceTimestamp', 'integer');
		$this->addType('token', 'string');
	}
}
