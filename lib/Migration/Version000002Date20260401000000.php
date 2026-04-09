<?php

declare(strict_types=1);

namespace OCA\SignotecSignoSignUniversal\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000002Date20260401000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('stsu_pending_webhooks')) {
			$table = $schema->createTable('stsu_pending_webhooks');

			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'unsigned' => true,
				'notnull' => true,
			]);
			$table->addColumn('workflow_type', 'string', [
				'length' => 32,
				'notnull' => true,
			]);
			$table->addColumn('user_id', 'string', [
				'length' => 64,
				'notnull' => true,
			]);
			$table->addColumn('file_id', 'integer', [
				'notnull' => true,
			]);
			$table->addColumn('nonce', 'string', [
				'length' => 64,
				'notnull' => true,
			]);
			$table->addColumn('document_id', 'string', [
				'length' => 64,
				'notnull' => false,
			]);
			$table->addColumn('sharingcase_id', 'string', [
				'length' => 64,
				'notnull' => false,
			]);
			$table->addColumn('status', 'string', [
				'length' => 32,
				'notnull' => true,
			]);
			$table->addColumn('expires_at', 'integer', [
				'notnull' => true,
			]);
			$table->addColumn('processed_at', 'integer', [
				'notnull' => false,
			]);
			$table->addColumn('last_source_timestamp', 'integer', [
				'notnull' => false,
			]);
			$table->addColumn('token', 'string', [
				'length' => 512,
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['workflow_type'], 'stsu_pwh_wf');
			$table->addIndex(['user_id'], 'stsu_pwh_usr');
			$table->addIndex(['file_id'], 'stsu_pwh_file');
			$table->addIndex(['nonce'], 'stsu_pwh_nonce');
			$table->addIndex(['status'], 'stsu_pwh_stat');
			$table->addIndex(['last_source_timestamp'], 'stsu_pwh_lastsrc_ts');
			$table->addIndex(['token'], 'stsu_pwh_token');
		}

		return $schema;
	}
}
