<?php

namespace Arshavinel\PadelMiniTour\Helper;

final class PdfHtmlHelper
{
    public static function getMatchesMarginTop(int $countMatches): int
    {
        if ($countMatches >= 28) {
            $marginTop = 10;
        } elseif ($countMatches >= 26) {
            $marginTop = 13;
        } elseif ($countMatches >= 24) {
            $marginTop = 40;
        } elseif ($countMatches >= 22) {
            $marginTop = 60;
        } elseif ($countMatches >= 20) {
            $marginTop = 67;
        } elseif ($countMatches == 19) {
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

    public static function getPlayersMarginTop(int $countPlayers): int
    {
        if ($countPlayers == 12) {
            $marginTop = 58;
        } elseif ($countPlayers == 11) {
            $marginTop = 62;
        } elseif ($countPlayers == 10) {
            $marginTop = 66;
        } elseif ($countPlayers == 9) {
            $marginTop = 70;
        } elseif ($countPlayers == 8) {
            $marginTop = 74;
        } elseif ($countPlayers == 7) {
            $marginTop = 78;
        } elseif ($countPlayers == 6) {
            $marginTop = 82;
        }

        return $marginTop;
    }

    public static function getFontSize(string $name): int
    {
        $max = 43;

        if (strlen($name) > 9) {
            return $max - (3 * (strlen($name) - 9.5));
        }

        return $max;
    }

    public static function getPlayerFontSize(string $name): int
    {
        $max = 33;

        if (strlen($name) > 9) {
            return $max - (3 * (strlen($name) - 9.5));
        }

        return $max;
    }
}
