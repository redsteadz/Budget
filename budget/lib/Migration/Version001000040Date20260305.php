<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Shorten index name budget_tx_category_type_date (28 chars) to fit
 * the 27-char limit for prefixed database identifiers.
 */
class Version001000040Date20260305 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_transactions')) {
			return null;
		}

		$table = $schema->getTable('budget_transactions');

		if ($table->hasIndex('budget_tx_category_type_date')) {
			$table->dropIndex('budget_tx_category_type_date');
			$table->addIndex(['category_id', 'type', 'date'], 'budget_tx_cat_type_date');
		}

		return $schema;
	}
}
