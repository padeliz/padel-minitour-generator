<?php

declare(strict_types=1);

namespace Arshavinel\PadelMiniTour\Service;

use Arshavinel\PadelMiniTour\Helper\LotteryHtmlHelper;
use Arshavinel\PadelMiniTour\Table\Division;
use Arshavinel\PadelMiniTour\Table\Edition\EditionDivision;
use Arshavinel\PadelMiniTour\Table\Edition\Lottery;
use Arshavinel\PadelMiniTour\Table\Edition\LotteryLucky;
use Arshavinel\PadelMiniTour\Table\Edition\LotteryRule;
use Arshavinel\PadelMiniTour\Table\Edition\Participation;
use Arshavinel\PadelMiniTour\Table\Player;
use Arshavinel\PadelMiniTour\Table\Prize;
use Arshavinel\PadelMiniTour\Table\PrizeFromClub;

final class LotteryPrizesViewBuilder
{
    private const UNSCHEDULED_KEY = '__unscheduled__';

    public function build(int $editionId, ?int $clubId, ?object $nextEdition = null): array
    {
        $rules = $this->fetchRules($editionId, $clubId);
        if ($rules === []) {
            return [];
        }

        $luckiesByRuleId = $this->fetchLuckiesByRuleId($editionId);
        $intervalKeys = $this->collectIntervalKeys($rules);
        $intervals = [];

        foreach ($intervalKeys as $intervalKey) {
            $intervalRules = array_values(array_filter(
                $rules,
                fn(object $rule): bool => $this->intervalKeyForRule($rule) === $intervalKey
            ));

            if ($intervalRules === []) {
                continue;
            }

            $first = $intervalRules[0];
            $intervals[] = [
                'label' => self::formatIntervalLabel(
                    $intervalKey === self::UNSCHEDULED_KEY ? null : $first->playing_start_time,
                    $intervalKey === self::UNSCHEDULED_KEY ? null : $first->playing_end_time,
                    $intervalKey === self::UNSCHEDULED_KEY
                ),
                'prizes' => $this->buildPrizesForInterval($intervalRules, $luckiesByRuleId, $nextEdition),
            ];
        }

        return $intervals;
    }

    public static function luckyStatus(?int $acceptedAt, ?int $rejectedAt): string
    {
        if ($acceptedAt !== null) {
            return 'accepted';
        }

        if ($rejectedAt !== null) {
            return 'rejected';
        }

        return 'pending';
    }

    public static function formatIntervalLabel(?string $start, ?string $end, bool $unscheduled = false): string
    {
        if ($unscheduled || ($start === null && $end === null)) {
            return 'Unscheduled';
        }

        $startLabel = $start !== null ? date('H:i', strtotime($start)) : '?';
        $endLabel = $end !== null ? date('H:i', strtotime($end)) : '?';

        return $startLabel . ' – ' . $endLabel;
    }

    /**
     * @param list<object> $rules
     * @return list<array{lottery: object, luckies: list<object>}>
     */
    public static function aggregateRulesIntoPrizes(array $rules, array $luckiesByRuleId): array
    {
        /** @var array<int, list<object>> $byLotteryId */
        $byLotteryId = [];

        foreach ($rules as $rule) {
            $lotteryId = (int) $rule->lottery_id;
            $byLotteryId[$lotteryId][] = $rule;
        }

        $prizes = [];

        foreach ($byLotteryId as $lotteryRules) {
            usort($lotteryRules, static function (object $a, object $b): int {
                $orderliness = ($a->orderliness ?? 0) <=> ($b->orderliness ?? 0);
                if ($orderliness !== 0) {
                    return $orderliness;
                }

                return self::ruleId($a) <=> self::ruleId($b);
            });

            $first = $lotteryRules[0];
            $intervalQuantity = 0;
            $minOrderliness = PHP_INT_MAX;
            $minRuleId = PHP_INT_MAX;

            foreach ($lotteryRules as $rule) {
                $intervalQuantity += (int) ($rule->rule_prize_quantity ?? 0);
                $minOrderliness = min($minOrderliness, (int) ($rule->orderliness ?? 0));
                $minRuleId = min($minRuleId, self::ruleId($rule));
            }

            $luckies = [];

            foreach ($lotteryRules as $rule) {
                $ruleId = self::ruleId($rule);
                foreach ($luckiesByRuleId[$ruleId] ?? [] as $lucky) {
                    $luckies[] = $lucky;
                }
            }

            usort($luckies, static function (object $a, object $b): int {
                $orderliness = ($a->orderliness ?? 0) <=> ($b->orderliness ?? 0);
                if ($orderliness !== 0) {
                    return $orderliness;
                }

                return self::luckyId($a) <=> self::luckyId($b);
            });

            $prizes[] = [
                'sort_orderliness' => $minOrderliness,
                'sort_rule_id' => $minRuleId,
                'lottery' => (object) [
                    'id_lottery' => (int) $first->lottery_id,
                    'interval_quantity' => $intervalQuantity,
                    'image' => $first->image ?? null,
                    'template' => $first->template ?? null,
                    'box_1_text' => $first->box_1_text ?? null,
                    'box_1_bg_color' => $first->box_1_bg_color ?? null,
                    'box_1_text_color' => $first->box_1_text_color ?? null,
                    'box_2_text' => $first->box_2_text ?? null,
                    'box_2_bg_color' => $first->box_2_bg_color ?? null,
                    'box_2_text_color' => $first->box_2_text_color ?? null,
                ],
                'luckies' => $luckies,
            ];
        }

        usort($prizes, static function (array $a, array $b): int {
            $orderliness = $a['sort_orderliness'] <=> $b['sort_orderliness'];
            if ($orderliness !== 0) {
                return $orderliness;
            }

            return $a['sort_rule_id'] <=> $b['sort_rule_id'];
        });

        foreach ($prizes as &$prize) {
            unset($prize['sort_orderliness'], $prize['sort_rule_id']);
        }
        unset($prize);

        return $prizes;
    }

    /**
     * @param list<object> $rules
     * @param array<int, list<object>> $luckiesByRuleId
     * @return list<array{lottery: object, luckies: list<object>}>
     */
    private function buildPrizesForInterval(array $rules, array $luckiesByRuleId, ?object $nextEdition): array
    {
        $prizes = self::aggregateRulesIntoPrizes($rules, $luckiesByRuleId);

        foreach ($prizes as &$prize) {
            $lottery = $prize['lottery'];
            $lottery->box_1_text = LotteryHtmlHelper::replaceEditionNextPlaceholders($lottery->box_1_text ?? null, $nextEdition);
            $lottery->box_2_text = LotteryHtmlHelper::replaceEditionNextPlaceholders($lottery->box_2_text ?? null, $nextEdition);
        }
        unset($prize);

        return $prizes;
    }

    /**
     * @param list<object> $rules
     * @return list<string>
     */
    private function collectIntervalKeys(array $rules): array
    {
        $keys = [];
        $seen = [];

        foreach ($rules as $rule) {
            $key = $this->intervalKeyForRule($rule);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $keys[] = $key;
            }
        }

        usort($keys, function (string $a, string $b) use ($rules): int {
            if ($a === self::UNSCHEDULED_KEY) {
                return 1;
            }
            if ($b === self::UNSCHEDULED_KEY) {
                return -1;
            }

            $aStart = $this->startTimeForKey($a, $rules);
            $bStart = $this->startTimeForKey($b, $rules);

            return strcmp($aStart, $bStart);
        });

        return $keys;
    }

    /**
     * @param list<object> $rules
     */
    private function startTimeForKey(string $key, array $rules): string
    {
        foreach ($rules as $rule) {
            if ($this->intervalKeyForRule($rule) === $key) {
                return (string) ($rule->playing_start_time ?? '');
            }
        }

        return '';
    }

    private static function ruleId(object $rule): int
    {
        if (method_exists($rule, 'id') && $rule->id() !== null) {
            return (int) $rule->id();
        }

        return (int) ($rule->id_lottery_rule ?? 0);
    }

    private static function luckyId(object $lucky): int
    {
        if (method_exists($lucky, 'id') && $lucky->id() !== null) {
            return (int) $lucky->id();
        }

        return (int) ($lucky->id_lucky ?? 0);
    }

    private function intervalKeyForRule(object $rule): string
    {
        if ($rule->edition_division_id === null) {
            return self::UNSCHEDULED_KEY;
        }

        return (string) $rule->playing_start_time . '|' . (string) $rule->playing_end_time;
    }

    /**
     * @return list<object>
     */
    private function fetchRules(int $editionId, ?int $clubId): array
    {
        return LotteryRule::select([
            'columns' => "
                " . LotteryRule::TABLE . ".id_lottery_rule,
                " . LotteryRule::TABLE . ".lottery_id,
                " . LotteryRule::TABLE . ".rule_prize_quantity,
                " . LotteryRule::TABLE . ".orderliness,
                " . LotteryRule::TABLE . ".edition_division_id,
                " . EditionDivision::TABLE . ".playing_start_time,
                " . EditionDivision::TABLE . ".playing_end_time,
                COALESCE(" . PrizeFromClub::TABLE . ".image, " . Prize::TABLE . ".image) AS image,
                COALESCE(" . PrizeFromClub::TABLE . ".template, " . Prize::TABLE . ".template) AS template,
                COALESCE(" . PrizeFromClub::TABLE . ".box_1_text, " . Prize::TABLE . ".box_1_text) AS box_1_text,
                COALESCE(" . PrizeFromClub::TABLE . ".box_1_bg_color, " . Prize::TABLE . ".box_1_bg_color) AS box_1_bg_color,
                COALESCE(" . PrizeFromClub::TABLE . ".box_1_text_color, " . Prize::TABLE . ".box_1_text_color) AS box_1_text_color,
                COALESCE(" . PrizeFromClub::TABLE . ".box_2_text, " . Prize::TABLE . ".box_2_text) AS box_2_text,
                COALESCE(" . PrizeFromClub::TABLE . ".box_2_bg_color, " . Prize::TABLE . ".box_2_bg_color) AS box_2_bg_color,
                COALESCE(" . PrizeFromClub::TABLE . ".box_2_text_color, " . Prize::TABLE . ".box_2_text_color) AS box_2_text_color
            ",
            'joins' => [
                [
                    'type' => 'INNER',
                    'table' => Lottery::TABLE,
                    'on' => LotteryRule::TABLE . '.lottery_id = ' . Lottery::TABLE . '.id_lottery',
                ],
                [
                    'type' => 'INNER',
                    'table' => Prize::TABLE,
                    'on' => Lottery::TABLE . '.prize_id = ' . Prize::TABLE . '.id_prize',
                ],
                [
                    'type' => 'LEFT',
                    'table' => PrizeFromClub::TABLE,
                    'on' => Prize::TABLE . '.id_prize = ' . PrizeFromClub::TABLE . '.prize_id AND (' . PrizeFromClub::TABLE . '.club_id IS NULL OR ' . PrizeFromClub::TABLE . '.club_id = ?)',
                ],
                [
                    'type' => 'LEFT',
                    'table' => EditionDivision::TABLE,
                    'on' => LotteryRule::TABLE . '.edition_division_id = ' . EditionDivision::TABLE . '.' . EditionDivision::PRIMARY_KEY,
                ],
            ],
            'where' => Lottery::TABLE . '.edition_id = ?',
            'order' => "(" . EditionDivision::TABLE . ".playing_start_time IS NULL), "
                . EditionDivision::TABLE . ".playing_start_time ASC, "
                . LotteryRule::TABLE . ".orderliness ASC, "
                . LotteryRule::TABLE . ".id_lottery_rule ASC",
        ], [$clubId, $editionId]);
    }

    /**
     * @return array<int, list<object>>
     */
    private function fetchLuckiesByRuleId(int $editionId): array
    {
        $rows = LotteryLucky::select([
            'columns' => "
                " . LotteryLucky::TABLE . ".id_lucky,
                " . LotteryLucky::TABLE . ".lottery_rule_id,
                " . LotteryLucky::TABLE . ".accepted_at,
                " . LotteryLucky::TABLE . ".rejected_at,
                " . LotteryRule::TABLE . ".orderliness,
                " . Participation::TABLE . ".player_id AS lucky_player_id,
                " . Division::TABLE . ".name AS division_name,
                " . Division::TABLE . ".color AS division_color
            ",
            'joins' => [
                [
                    'type' => 'INNER',
                    'table' => LotteryRule::TABLE,
                    'on' => LotteryLucky::TABLE . '.lottery_rule_id = ' . LotteryRule::TABLE . '.' . LotteryRule::PRIMARY_KEY,
                ],
                [
                    'type' => 'INNER',
                    'table' => Lottery::TABLE,
                    'on' => LotteryRule::TABLE . '.lottery_id = ' . Lottery::TABLE . '.id_lottery',
                ],
                [
                    'type' => 'INNER',
                    'table' => Participation::TABLE,
                    'on' => LotteryLucky::TABLE . '.drawn_participation_id = ' . Participation::TABLE . '.' . Participation::PRIMARY_KEY,
                ],
                [
                    'type' => 'INNER',
                    'table' => Player::TABLE,
                    'on' => Participation::TABLE . '.player_id = ' . Player::TABLE . '.id_player',
                ],
                [
                    'type' => 'INNER',
                    'table' => EditionDivision::TABLE,
                    'on' => EditionDivision::TABLE . '.' . EditionDivision::PRIMARY_KEY . ' = ' . Participation::TABLE . '.edition_division_id',
                ],
                [
                    'type' => 'INNER',
                    'table' => Division::TABLE,
                    'on' => Division::TABLE . '.' . Division::PRIMARY_KEY . ' = ' . EditionDivision::TABLE . '.division_id',
                ],
            ],
            'where' => Lottery::TABLE . '.edition_id = ?',
            'order' => LotteryRule::TABLE . '.orderliness ASC, ' . LotteryLucky::TABLE . '.id_lucky ASC',
        ], [$editionId]);

        $byRuleId = [];

        foreach ($rows as $row) {
            $row->status = self::luckyStatus(
                $row->accepted_at !== null ? (int) $row->accepted_at : null,
                $row->rejected_at !== null ? (int) $row->rejected_at : null
            );
            $ruleId = (int) $row->lottery_rule_id;
            $byRuleId[$ruleId][] = $row;
        }

        return $byRuleId;
    }
}
