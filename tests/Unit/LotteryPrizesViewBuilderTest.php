<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\LotteryPrizesViewBuilder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

final class LotteryPrizesViewBuilderTest extends TestCase
{
    public function test_lucky_status_classification(): void
    {
        $this->assertSame('accepted', LotteryPrizesViewBuilder::luckyStatus(100, null));
        $this->assertSame('accepted', LotteryPrizesViewBuilder::luckyStatus(100, 200));
        $this->assertSame('rejected', LotteryPrizesViewBuilder::luckyStatus(null, 200));
        $this->assertSame('pending', LotteryPrizesViewBuilder::luckyStatus(null, null));
    }

    public function test_format_interval_label(): void
    {
        $this->assertSame('12:00 – 16:00', LotteryPrizesViewBuilder::formatIntervalLabel('12:00:00', '16:00:00'));
        $this->assertSame('Unscheduled', LotteryPrizesViewBuilder::formatIntervalLabel(null, null, true));
        $this->assertSame('Unscheduled', LotteryPrizesViewBuilder::formatIntervalLabel(null, null));
    }

    public function test_aggregate_rules_into_prizes_deduplicates_and_sums_quantity(): void
    {
        $rules = [
            (object) [
                'id_lottery_rule' => 1,
                'lottery_id' => 10,
                'rule_prize_quantity' => 1,
                'orderliness' => 2,
                'image' => 'a.png',
                'template' => 'IMAGE_LEFT',
                'box_1_text' => 'Prize A',
                'box_1_bg_color' => '#000',
                'box_1_text_color' => '#fff',
                'box_2_text' => null,
                'box_2_bg_color' => null,
                'box_2_text_color' => null,
            ],
            (object) [
                'id_lottery_rule' => 2,
                'lottery_id' => 10,
                'rule_prize_quantity' => 1,
                'orderliness' => 1,
                'image' => 'a.png',
                'template' => 'IMAGE_LEFT',
                'box_1_text' => 'Prize A',
                'box_1_bg_color' => '#000',
                'box_1_text_color' => '#fff',
                'box_2_text' => null,
                'box_2_bg_color' => null,
                'box_2_text_color' => null,
            ],
            (object) [
                'id_lottery_rule' => 3,
                'lottery_id' => 20,
                'rule_prize_quantity' => 2,
                'orderliness' => 3,
                'image' => null,
                'template' => null,
                'box_1_text' => 'Prize B',
                'box_1_bg_color' => '#111',
                'box_1_text_color' => '#eee',
                'box_2_text' => null,
                'box_2_bg_color' => null,
                'box_2_text_color' => null,
            ],
        ];

        $luckiesByRuleId = [
            1 => [(object) ['id_lucky' => 100, 'orderliness' => 2, 'id_player' => 1, 'status' => 'accepted']],
            2 => [(object) ['id_lucky' => 101, 'orderliness' => 1, 'id_player' => 2, 'status' => 'pending']],
            3 => [(object) ['id_lucky' => 102, 'orderliness' => 3, 'id_player' => 3, 'status' => 'rejected']],
        ];

        $prizes = LotteryPrizesViewBuilder::aggregateRulesIntoPrizes($rules, $luckiesByRuleId);

        $this->assertCount(2, $prizes);

        $prizeA = $prizes[0];
        $this->assertSame(10, $prizeA['lottery']->id_lottery);
        $this->assertSame(2, $prizeA['lottery']->interval_quantity);
        $this->assertCount(2, $prizeA['luckies']);
        $this->assertSame(101, $prizeA['luckies'][0]->id_lucky);
        $this->assertSame(100, $prizeA['luckies'][1]->id_lucky);

        $prizeB = $prizes[1];
        $this->assertSame(20, $prizeB['lottery']->id_lottery);
        $this->assertSame(2, $prizeB['lottery']->interval_quantity);
        $this->assertCount(1, $prizeB['luckies']);
        $this->assertSame('rejected', $prizeB['luckies'][0]->status);
    }
}
