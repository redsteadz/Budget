<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add status column to budget_transactions for scheduled transaction support.
 * Back-fills existing future bill transactions as 'scheduled'.
 */
class Version001000037Date20260303 extends SimpleMigrationStep {

	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('budget_transactions');

		if (!$table->hasColumn('status')) {
			$table->addColumn('status', Types::STRING, [
				'notnull' => false,
				'length' => 16,
				'default' => 'cleared',
			]);

			$table->addIndex(['status', 'date'], 'bgt_tx_status_date');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$output->info('Back-filling scheduled status for future bill transactions...');

		$today = date('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->update('budget_transactions')
			->set('status', $qb->createNamedParameter('scheduled'))
			->where($qb->expr()->isNotNull('bill_id'))
			->andWhere($qb->expr()->gt('date', $qb->createNamedParameter($today)));

		$updated = $qb->executeStatement();

		if ($updated > 0) {
			$output->info("Marked {$updated} future bill transaction(s) as scheduled");
		} else {
			$output->info('No future bill transactions found to update');
		}
	}
}
