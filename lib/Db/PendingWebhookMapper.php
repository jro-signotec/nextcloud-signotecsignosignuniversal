<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Db;

use OCA\SignotecSignoSignUniversal\Service\PendingWebhookService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<PendingWebhook>
 */
class PendingWebhookMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'stsu_pending_webhooks', PendingWebhook::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findPendingByWorkflowAndNonce(
		string $workflowType,
		string $userId,
		int $fileId,
		string $nonce,
	): PendingWebhook {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('workflow_type', $qb->createNamedParameter($workflowType)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nonce', $qb->createNamedParameter($nonce)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(PendingWebhookService::STATUS_PENDING)));

		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findLatestByWorkflowAndNonce(
		string $workflowType,
		string $userId,
		int $fileId,
		string $nonce,
	): PendingWebhook {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('workflow_type', $qb->createNamedParameter($workflowType)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nonce', $qb->createNamedParameter($nonce)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		return $this->findEntity($qb);
	}

	/**
	 * @return list<PendingWebhook>
	 */
	public function findExpiredEntries(int $timestamp): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->lt('expires_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT))
			)
			->andWhere(
				$qb->expr()->in(
					'status',
					$qb->createNamedParameter([
						PendingWebhookService::STATUS_PENDING,
						PendingWebhookService::STATUS_EXPIRED,
					], IQueryBuilder::PARAM_STR_ARRAY)
				)
			);

		return $this->findEntities($qb);
	}

	/**
	 * @return list<PendingWebhook>
	 */
	public function findProcessedBefore(int $timestamp): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->lt('processed_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT))
			)
			->andWhere(
				$qb->expr()->eq(
					'status',
					$qb->createNamedParameter(PendingWebhookService::STATUS_PROCESSED)
				)
			);

		return $this->findEntities($qb);
	}
}
