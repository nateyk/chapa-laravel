<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Tests;

use Nateyk\Chapa\Cli\ReleaseReadiness;
use PHPUnit\Framework\TestCase;

final class ReleaseReadinessTest extends TestCase
{
    public function testOfflineChecksNeverClaimProductionOrChapaCompatibility(): void
    {
        $result = (new ReleaseReadiness())->inspect();
        self::assertTrue($result['offline_pass']);
        self::assertFalse($result['release_ready']);
        self::assertSame('requires_authenticated_test_callback', $result['outstanding']['chapa_approval_signature_format']);
        self::assertSame('requires_maintainer_account_registration', $result['outstanding']['packagist_registration']);
    }

    public function testMissingManifestFailsOfflinePreflightWithoutThrowing(): void
    {
        $result = (new ReleaseReadiness())->inspect(sys_get_temp_dir().'/missing-chapa-project-for-test');
        self::assertFalse($result['offline_pass']);
        self::assertFalse($result['release_ready']);
        self::assertFalse($result['checks']['release_files_present']);
    }
}
