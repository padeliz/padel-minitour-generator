<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Helper\EditionDivisionCourtHelper;
use PHPUnit\Framework\TestCase;

/**
 * Ensures the lottery court rip helper stays available (DB calls need a live connection).
 */
final class EditionDivisionCourtHelperTest extends TestCase
{
    public function test_first_court_name_method_exists(): void
    {
        $this->assertTrue(method_exists(EditionDivisionCourtHelper::class, 'firstCourtName'));
    }

    public function test_lottery_backend_uses_court_helper(): void
    {
        $backend = file_get_contents(dirname(__DIR__, 2) . '/outcomes/site/lottery/item/backend.php');

        $this->assertNotFalse($backend);
        $this->assertStringContainsString('EditionDivisionCourtHelper::firstCourtName', $backend);
        $this->assertStringNotContainsString('.court AS division_court', $backend);
    }
}
