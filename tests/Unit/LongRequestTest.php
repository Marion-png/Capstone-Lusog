<?php

namespace Tests\Unit;

use App\Support\LongRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The deployment stopped a slow photo reader at 30 seconds with a fatal error:
 * Production log, 2026-11-03, POST /health-records/consent-forms/scan.
 */
class LongRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        ini_set('max_execution_time', '0');

        parent::tearDown();
    }

    #[Test]
    public function a_limit_in_force_is_extended(): void
    {
        ini_set('max_execution_time', '30');

        LongRequest::allow(390);

        $this->assertSame('390', ini_get('max_execution_time'));
    }

    #[Test]
    public function a_process_with_no_limit_keeps_having_none(): void
    {
        ini_set('max_execution_time', '0');

        LongRequest::allow(390);

        $this->assertSame('0', ini_get('max_execution_time'));
    }
}
