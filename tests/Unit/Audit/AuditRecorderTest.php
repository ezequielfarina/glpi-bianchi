<?php

declare(strict_types=1);

namespace App\Tests\Unit\Audit;

use App\Audit\Service\AuditCatalog;
use App\Audit\Service\AuditRecorder;
use PHPUnit\Framework\TestCase;

/** ADR-0015: máscara de segredos e descrição das ações. */
final class AuditRecorderTest extends TestCase
{
    public function testScrubMasksSecretsRecursively(): void
    {
        $out = AuditRecorder::scrub([
            'name' => 'X', 'password' => 'p', 'new_password' => 'p2', 'community' => 'public', 'license_key' => 'AAAA',
            'nested' => ['api_token' => 't', 'ok' => 1], 'empty_secret' => '', 'target' => '10.0.0.0/24',
        ]);
        self::assertSame('X', $out['name']);
        self::assertSame('***', $out['password']);
        self::assertSame('***', $out['new_password']);
        self::assertSame('***', $out['community']);
        self::assertSame('***', $out['license_key']);
        self::assertSame(['api_token' => '***', 'ok' => 1], $out['nested']);
        self::assertSame('', $out['empty_secret']);
        self::assertSame('10.0.0.0/24', $out['target']);
        self::assertSame('***', AuditRecorder::scrub(['target' => 'https://x'], true)['target']);
    }

    public function testDescribeFallsBackForUnknownRoutes(): void
    {
        self::assertSame('stock.out', AuditRecorder::describe('ui_stock_out', 'POST')[0]);
        self::assertSame(['ui_x', 'POST ui_x', 'other', null, null], AuditRecorder::describe('ui_x', 'POST'));
        foreach (AuditCatalog::ROUTES as [, , $module]) {
            self::assertArrayHasKey($module, AuditCatalog::MODULES);
        }
    }
}
