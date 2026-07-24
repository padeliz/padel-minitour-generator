<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Helper\CourtLabelHelper;
use PHPUnit\Framework\TestCase;

final class CourtLabelHelperTest extends TestCase
{
    public function test_parse_strips_indoor_suffix(): void
    {
        $this->assertSame(
            ['name' => '4', 'is_indoor' => true],
            CourtLabelHelper::parse('4 (indoor)')
        );
    }

    public function test_parse_strips_outdoor_suffix_case_insensitive(): void
    {
        $this->assertSame(
            ['name' => '7', 'is_indoor' => false],
            CourtLabelHelper::parse('7 (OUTDOOR)')
        );
    }

    public function test_parse_keeps_composite_label_as_one_name(): void
    {
        $this->assertSame(
            ['name' => '1 and 2 (two stages)', 'is_indoor' => null],
            CourtLabelHelper::parse('1 and 2 (two stages)')
        );
    }

    public function test_parse_silent_label_leaves_is_indoor_null(): void
    {
        $this->assertSame(
            ['name' => 'Queen Court', 'is_indoor' => null],
            CourtLabelHelper::parse('  Queen Court  ')
        );
    }

    public function test_parse_trims_and_collapses_whitespace(): void
    {
        $this->assertSame(
            ['name' => 'Court 1', 'is_indoor' => true],
            CourtLabelHelper::parse("  Court   1  (indoor)  ")
        );
    }

    public function test_resolve_is_indoor_prefers_label(): void
    {
        $this->assertTrue(CourtLabelHelper::resolveIsIndoor(true, 0, 10));
        $this->assertFalse(CourtLabelHelper::resolveIsIndoor(false, 10, 0));
    }

    public function test_resolve_is_indoor_uses_location_majority_when_silent(): void
    {
        $this->assertTrue(CourtLabelHelper::resolveIsIndoor(null, 5, 0));
        $this->assertFalse(CourtLabelHelper::resolveIsIndoor(null, 0, 3));
    }

    public function test_resolve_is_indoor_tie_prefers_indoor(): void
    {
        $this->assertTrue(CourtLabelHelper::resolveIsIndoor(null, 4, 4));
    }
}
