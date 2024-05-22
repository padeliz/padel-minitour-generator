<?php

namespace Arshavinel\PadelMiniTour\Helper;

final class PdfHtmlHelper
{
    public static function getMatchesMarginTop(int $countMatches): int
    {
        if ($countMatches >= 28) {
            $marginTop = 14;
        } elseif ($countMatches >= 26) {
            $marginTop = 30;
        } elseif ($countMatches >= 24) {
            $marginTop = 40;
        } elseif ($countMatches >= 22) {
            $marginTop = 60;
        } elseif ($countMatches >= 20) {
            $marginTop = 75;
        } elseif ($countMatches >= 18) {
            $marginTop = 90;
        } elseif ($countMatches >= 16) {
            $marginTop = 105;
        } elseif ($countMatches >= 14) {
            $marginTop = 120;
        } elseif ($countMatches >= 12) {
            $marginTop = 145;
        } elseif ($countMatches >= 8) {
            $marginTop = 180;
        } else {
            $marginTop = 215;
        }

        return $marginTop;
    }

    public static function getFontSize(string $name): int
    {
        $max = 43;

        if (strlen($name) > 10) {
            return $max - (3 * (strlen($name) - 10));
        }

        return $max;
    }
}
