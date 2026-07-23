<?php

namespace Arshavinel\PadelMiniTour\Service;

/**
 * Distinct-opponent counts derived from match-making playersMet maps.
 */
final class OpponentsMetSummary
{
    /**
     * @param array<int, array<int, int>>|null $playersMet
     * @return array{min: int|null, max: int|null}
     */
    public static function fromPlayersMet(?array $playersMet, int $playersCount): array
    {
        if ($playersMet === null || $playersMet === []) {
            return ['min' => null, 'max' => null];
        }

        $perPlayer = [];
        for ($p = 0; $p < $playersCount; $p++) {
            $perPlayer[] = isset($playersMet[$p]) ? count($playersMet[$p]) : 0;
        }

        if ($perPlayer === []) {
            return ['min' => null, 'max' => null];
        }

        return [
            'min' => min($perPlayer),
            'max' => max($perPlayer),
        ];
    }

    /**
     * Simultaneous time slots (rounds on the first court). Prefer
     * {@see TemplateMatchDerivation::roundsCount()} for new call sites.
     *
     * @param array<int, array<int, mixed>>|null $matchesByCourt
     */
    public static function roundsCount(?array $matchesByCourt): ?int
    {
        return TemplateMatchDerivation::roundsCount($matchesByCourt);
    }
}
