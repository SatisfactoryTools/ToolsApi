<?php declare(strict_types = 1);

/**
 * Runs the link preview text rules against the fixtures shared with the frontend
 * (sftools-frontend: src/app/Model/Meta/meta-text-fixtures.json, run by `npm run test:meta-text`; a copy of
 * tests/meta-text-fixtures.json). No framework needed: php tests/MetaTextTest.php
 */

use greeny\SatisfactoryTools\Api\Model\Services\Meta\MetaText;

require __DIR__ . '/../app/Model/Services/Meta/MetaText.php';

$fixtures = json_decode((string) file_get_contents(__DIR__ . '/meta-text-fixtures.json'), true, flags: JSON_THROW_ON_ERROR);

$failures = 0;
$total = 0;
foreach (['description' => MetaText::description(...), 'title' => MetaText::title(...)] as $kind => $function) {
	foreach ($fixtures[$kind] as $i => $case) {
		$total++;
		$actual = $function($case['input']);
		if ($actual !== $case['expected']) {
			$failures++;
			printf("FAIL %s #%d (%s)\n  expected: %s\n  actual:   %s\n", $kind, $i, $case['note'] ?? '', $case['expected'], $actual);
		}
	}
}

printf("%d/%d passed\n", $total - $failures, $total);
exit($failures === 0 ? 0 : 1);
