<?php

namespace Arshavinel\PadelMiniTour\Helper;

final class LotteryHtmlHelper
{
    public static function getFirstTextSize(string $text, bool $hasImage): int
    {
        $size = 100 - (int) (strlen($text) * ($hasImage ? 1.1 : 0.9));

        return max(20, $size);
    }

    public static function getSecondTextSize(string $firstText, string $secondText): int
    {
        $size = min(self::getFirstTextSize($firstText, false), 100 - (int) (strlen($secondText)) * 1.2);

        return max(28, (int) $size * 0.9);
    }
}
