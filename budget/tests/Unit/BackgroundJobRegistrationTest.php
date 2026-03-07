<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verify that all background job classes in lib/BackgroundJob/
 * are registered in appinfo/info.xml.
 */
class BackgroundJobRegistrationTest extends TestCase {
	private const JOB_DIR = __DIR__ . '/../../lib/BackgroundJob';
	private const INFO_XML = __DIR__ . '/../../appinfo/info.xml';

	public function testAllBackgroundJobsRegistered(): void {
		$jobFiles = glob(self::JOB_DIR . '/*.php') ?: [];
		$this->assertNotEmpty($jobFiles, 'No background job files found');

		$infoXml = file_get_contents(self::INFO_XML);
		$violations = [];

		foreach ($jobFiles as $file) {
			$className = pathinfo($file, PATHINFO_FILENAME);
			$fqcn = 'OCA\\Budget\\BackgroundJob\\' . $className;

			if (strpos($infoXml, $fqcn) === false) {
				$violations[] = sprintf(
					'%s is not registered in appinfo/info.xml',
					$fqcn
				);
			}
		}

		$this->assertEmpty(
			$violations,
			"Background jobs exist but are not registered in info.xml:\n- " . implode("\n- ", $violations)
		);
	}
}
