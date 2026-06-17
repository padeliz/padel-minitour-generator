<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\EventDivision;
use PHPUnit\Framework\TestCase;

final class EventDivisionTest extends TestCase
{
    public function test_organizers_and_partners_constants_are_non_empty(): void
    {
        $this->assertNotEmpty(EventDivision::ORGANIZERS);
        $this->assertNotEmpty(EventDivision::PARTNERS);
        $this->assertArrayHasKey(1, EventDivision::ORGANIZERS);
        $this->assertArrayHasKey(1, EventDivision::PARTNERS);
    }
}
