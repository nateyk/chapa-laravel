<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Nateyk\Chapa\Cli\ReleaseReadiness;

$checks = 0;
$assert = static function (bool $passed, string $label) use (&$checks): void {
    ++$checks;
    if (!$passed) {
        throw new RuntimeException('Release preflight failed: '.$label);
    }
};

$report = (new ReleaseReadiness())->inspect();
$assert($report['offline_pass'] === true, 'required local metadata');
$assert($report['release_ready'] === false, 'never declare production readiness');
$assert($report['checks']['composer_package_identity'] === true, 'package identity');
$assert($report['checks']['composer_psr4'] === true, 'autoloader');
$assert($report['checks']['composer_cli_entry'] === true, 'CLI executable');
$assert($report['checks']['release_files_present'] === true, 'release documentation');
$assert($report['outstanding']['chapa_v2_approval_callback'] === 'requires_genuine_chapa_test_confirmation', 'Chapa interoperability gate');
$assert($report['outstanding']['laravel_11_security'] === 'security_support_ended_2026_03_12', 'Laravel 11 security status');
$encoded = json_encode($report, JSON_THROW_ON_ERROR);
$assert(!str_contains($encoded, 'CHAPA_LIVE_'), 'no raw private key');
$assert(!str_contains($encoded, 'account_number'), 'no banking data');
$assert(!str_contains($encoded, 'approval_secret'), 'no signing secret');
$assert(str_contains($encoded, '"release_ready":false'), 'explicit release block');
echo "OK: $checks offline release-preflight assertions passed; external gates remain pending.\n";
