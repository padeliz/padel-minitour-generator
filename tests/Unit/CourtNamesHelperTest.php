<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Helper\CourtNamesHelper;
use PHPUnit\Framework\TestCase;

final class CourtNamesHelperTest extends TestCase
{
    public function test_normalize_from_request_trims_and_returns_names(): void
    {
        $this->assertSame(
            ['Court A', 'Court B'],
            CourtNamesHelper::normalizeFromRequest([' Court A ', 'Court B', ''])
        );
    }

    public function test_normalize_from_request_rejects_non_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('court-names must be a non-empty array');

        CourtNamesHelper::normalizeFromRequest('Court A');
    }

    public function test_normalize_from_request_rejects_empty_after_trim(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one court name is required');

        CourtNamesHelper::normalizeFromRequest(['', '   ']);
    }

    public function test_normalize_from_request_rejects_too_many_courts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At most 4 courts are allowed');

        CourtNamesHelper::normalizeFromRequest(['A', 'B', 'C', 'D', 'E']);
    }

    public function test_normalize_from_request_rejects_case_insensitive_duplicates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Court names must be unique');

        CourtNamesHelper::normalizeFromRequest(['Padel One', 'padel one']);
    }
}
