<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Cli;

/**
 * Offline preflight. It does NOT certify external Chapa interoperability,
 * Laravel 11 security, Packagist ownership or production payout readiness.
 */
final class ReleaseReadiness
{
    /**
     * @return array{kind: string, offline_pass: bool, release_ready: bool,
     *     checks: array<string, bool>, outstanding: array<string, string>}
     */
    public function inspect(?string $root = null): array
    {
        $root ??= dirname(__DIR__, 2);
        $path = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $json = @file_get_contents($path.'composer.json');
        try {
            $manifest = is_string($json) ? json_decode($json, true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $manifest = null;
        }
        if (!is_array($manifest)) {
            $manifest = [];
        }
        $files = [
            'LICENSE', 'SECURITY.md', '.github/workflows/tests.yml',
            'docs/RELEASE.md', 'docs/TRANSFER-APPROVAL.md',
            'docs/PAYOUT-V2.md', 'src/Laravel/ChapaServiceProvider.php',
        ];
        $filesPresent = true;
        foreach ($files as $file) {
            if (!is_file($path.$file)) {
                $filesPresent = false;
            }
        }

        $checks = [
            'php_min_8_2' => PHP_VERSION_ID >= 80200,
            'ext_json' => extension_loaded('json'),
            'ext_openssl' => extension_loaded('openssl'),
            'composer_package_identity' => ($manifest['name'] ?? null) === 'nateyk/chapa-laravel',
            'composer_library_mit' => ($manifest['type'] ?? null) === 'library'
                && ($manifest['license'] ?? null) === 'MIT',
            'composer_php_support' => ($manifest['require']['php'] ?? null) === '^8.2',
            'composer_psr4' => ($manifest['autoload']['psr-4']['Nateyk\\Chapa\\'] ?? null) === 'src/',
            'composer_cli_entry' => in_array('bin/chapa', $manifest['bin'] ?? [], true),
            'release_files_present' => $filesPresent,
        ];

        return [
            'kind' => 'chapa_sdk_offline_release_preflight',
            'offline_pass' => !in_array(false, $checks, true),
            'release_ready' => false, // Never convert offline checks into live-money certification.
            'checks' => $checks,
            'outstanding' => [
                'chapa_v2_approval_callback' => 'requires_genuine_chapa_test_confirmation',
                'chapa_approval_signature_format' => 'requires_authenticated_test_callback',
                'chapa_test_mode_financial_flows' => 'requires_merchant_sandbox_acceptance',
                'laravel_11_security' => 'security_support_ended_2026_03_12',
                'packagist_registration' => 'requires_maintainer_account_registration',
                'tag_and_announcement' => 'deferred_until_release_approval',
            ],
        ];
    }
}
