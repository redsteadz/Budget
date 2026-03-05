<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;

/**
 * Static analysis of migration files to catch schema naming issues
 * before they reach production databases.
 */
class MigrationSchemaTest extends TestCase {
	private const MIGRATION_DIR = __DIR__ . '/../../../lib/Migration';

	/**
	 * Nextcloud prefixes table names (e.g. "oc_") and the total must fit
	 * within a 30-character identifier limit (Oracle). With a 3-char prefix
	 * the table name passed to createTable() must be <= 27 characters.
	 */
	private const MAX_TABLE_NAME_LENGTH = 27;

	/**
	 * Index names also get the prefix, same 30-char total limit.
	 * The name passed to addIndex/addUniqueIndex must be <= 27 characters.
	 */
	private const MAX_INDEX_NAME_LENGTH = 27;

	/**
	 * Migrations that shipped with known-long names before this test existed.
	 * These are fixed by later rename migrations; checking them would always
	 * fail since we can't change already-released migration files.
	 */
	private const LEGACY_SKIP_INDEX_CHECK = [
		'Version001000005Date20260106.php', // budget_tx_category_type_date → fixed in 040
	];

	private static function getMigrationFiles(): array {
		return glob(self::MIGRATION_DIR . '/Version*.php') ?: [];
	}

	public function testAllTableNamesWithinLimit(): void {
		$violations = [];

		foreach (self::getMigrationFiles() as $file) {
			$fileName = basename($file);
			$content = file_get_contents($file);

			preg_match_all('/createTable\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $matches);

			foreach ($matches[1] as $tableName) {
				if (strlen($tableName) > self::MAX_TABLE_NAME_LENGTH) {
					$violations[] = sprintf(
						'Table "%s" in %s is %d chars (max %d)',
						$tableName, $fileName, strlen($tableName), self::MAX_TABLE_NAME_LENGTH
					);
				}
			}
		}

		$this->assertEmpty(
			$violations,
			"Table names exceed the 27-char limit (30 with oc_ prefix):\n- " . implode("\n- ", $violations)
		);
	}

	public function testAllIndexNamesWithinLimit(): void {
		$violations = [];

		foreach (self::getMigrationFiles() as $file) {
			$fileName = basename($file);

			if (in_array($fileName, self::LEGACY_SKIP_INDEX_CHECK, true)) {
				continue;
			}

			$content = file_get_contents($file);

			preg_match_all('/add(?:Unique)?Index\([^)]*,\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $matches);

			foreach ($matches[1] as $indexName) {
				if (strlen($indexName) > self::MAX_INDEX_NAME_LENGTH) {
					$violations[] = sprintf(
						'Index "%s" in %s is %d chars (max %d)',
						$indexName, $fileName, strlen($indexName), self::MAX_INDEX_NAME_LENGTH
					);
				}
			}
		}

		$this->assertEmpty(
			$violations,
			"Index names exceed the 27-char limit (30 with oc_ prefix):\n- " . implode("\n- ", $violations)
		);
	}

	public function testBooleanColumnsAreNullable(): void {
		$violations = [];

		foreach (self::getMigrationFiles() as $file) {
			$fileName = basename($file);
			$content = file_get_contents($file);

			preg_match_all(
				'/addColumn\(\s*[\'"]([^\'"]+)[\'"].*?Types::BOOLEAN.*?\[(.*?)\]/s',
				$content,
				$matches,
				PREG_SET_ORDER
			);

			foreach ($matches as $match) {
				$columnName = $match[1];
				$options = $match[2];

				if (preg_match('/[\'"]notnull[\'"]\s*=>\s*true/', $options)) {
					$violations[] = sprintf(
						'Boolean column "%s" in %s has \'notnull\' => true',
						$columnName, $fileName
					);
				}
			}
		}

		$this->assertEmpty(
			$violations,
			"Boolean columns must use 'notnull' => false for cross-DB compatibility:\n- " . implode("\n- ", $violations)
		);
	}
}
