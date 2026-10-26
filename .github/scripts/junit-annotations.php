<?php

declare(strict_types=1);

// Prints GitHub "::error" annotations for failed test cases in a JUnit XML report,
// so failures are readable through the check-run annotations API.
$file = $argv[1] ?? 'junit.xml';

if (! is_file($file)) {
    echo "::error::JUnit report {$file} not found\n";
    exit(0);
}

$xml = simplexml_load_file($file);

foreach ($xml->xpath('//testcase[failure or error]') ?: [] as $case) {
    $node = $case->failure ?? $case->error;
    $message = trim(preg_replace('/\s+/', ' ', (string) $node) ?? '');
    $title = (string) $case['class'].'::'.(string) $case['name'];
    echo '::error file='.(string) $case['file'].',title='.str_replace([',', ':'], ' ', $title).'::'.mb_substr($message, 0, 900)."\n";
}
