<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Helper\PlayerDivisionHistoryHelper;
use PHPUnit\Framework\TestCase;

final class PlayerDivisionHistoryHelperTest extends TestCase
{
    public function test_last_division_title_subquery_joins_editions_and_uses_division_title(): void
    {
        $sql = PlayerDivisionHistoryHelper::lastDivisionTitleSubquery();

        $this->assertStringContainsString('edition_divisions.division_title', $sql);
        $this->assertStringContainsString('JOIN editions', $sql);
        $this->assertStringContainsString('ORDER BY editions.date DESC', $sql);
        $this->assertStringContainsString('LIMIT 1', $sql);
        $this->assertStringNotContainsString('divisions.name', $sql);
    }

    public function test_last_division_title_subquery_uses_custom_player_id_column(): void
    {
        $sql = PlayerDivisionHistoryHelper::lastDivisionTitleSubquery('p.id_player');

        $this->assertStringContainsString('edition_participations.player_id = p.id_player', $sql);
    }
}
