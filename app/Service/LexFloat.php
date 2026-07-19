<?php

namespace Arshavinel\PadelMiniTour\Service;

/**
 * Two-decimal indifference band for float lex tiers and float prune bounds.
 *
 * Quantizes comparison only; stored JSON metrics stay full precision.
 * Non-finite values (e.g. INF sentinels) pass through unquantized.
 */
final class LexFloat
{
    public const SCALE = 100;

    /** Quantize for comparison only. */
    public static function quantize(float $value): float
    {
        if (!is_finite($value)) {
            return $value;
        }

        return round($value * self::SCALE) / self::SCALE;
    }

    /**
     * @return int -1 if $a < $b at 2dp, 0 if equal at 2dp, +1 if $a > $b at 2dp
     */
    public static function compare(float $a, float $b): int
    {
        return self::quantize($a) <=> self::quantize($b);
    }

    /** True when $a is strictly better than $b for a maximize tier. */
    public static function isBetterMax(float $a, float $b): bool
    {
        return self::compare($a, $b) > 0;
    }

    /** True when $a is strictly better than $b for a minimize tier. */
    public static function isBetterMin(float $a, float $b): bool
    {
        return self::compare($a, $b) < 0;
    }
}
