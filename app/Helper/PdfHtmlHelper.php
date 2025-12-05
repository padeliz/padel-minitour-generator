<?php

namespace Arshavinel\PadelMiniTour\Helper;

use Arshavinel\PadelMiniTour\DTO\PdfPlayer;

final class PdfHtmlHelper
{
    const PLAYERS_ROWS_SIZING = [
        '1' => [
            'img-width' => "145px",
            'points-slot-padding' => "20px 0",
            'total-points-padding' => "0 0 10px 10px",
            'total-points-font-size' => "150px",
            'match-slot-font-size' => "40px",
            'total-matches-padding' => "0 0 10px 5px",
            'total-matches-font-size' => "150px",
        ],
        '2' => [
            'img-width' => "135px",
            'points-slot-padding' => "0px 0",
            'total-points-padding' => "0 0 0px 10px",
            'total-points-font-size' => "150px",
            'match-slot-font-size' => "35px",
            'total-matches-padding' => "0 0 0px 5px",
            'total-matches-font-size' => "150px",
        ],
    ];

    public static function getMatchesMarginTop(int $countMatches): int
    {
        if ($countMatches >= 28) {
            $marginTop = 10;
        } elseif ($countMatches >= 26) {
            $marginTop = 13;
        } elseif ($countMatches >= 24) {
            $marginTop = 22;
        } elseif ($countMatches >= 22) {
            $marginTop = 60;
        } elseif ($countMatches >= 20) {
            $marginTop = 52;
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
            $marginTop = 50;
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
        } else {
            $marginTop = 90;
        }

        return $marginTop;
    }

    public static function getFontSize(PdfPlayer $pdfPlayer): int
    {
        $max = 43;

        if (strlen($pdfPlayer->getShortName()) > 9) {
            $max = $max - (3 * (strlen($pdfPlayer->getShortName()) - 9.5));
        }

        if (!$pdfPlayer->isCollectingPoints()) {
            return min($max, 32);
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

    /**
     * If player has more than 8 matches, split them in two rows.
     */
    public static function splitMatchRankingRows(int $countPlayers, int $playerMatchesCount): array
    {
        if ($playerMatchesCount > 8) {
            $a = min(7, $playerMatchesCount - 7);
            return [$a, $playerMatchesCount - $a];
        }

        if ($countPlayers <= 5) {
            $a = min(4, $playerMatchesCount - 3);
            return [$a, $playerMatchesCount - $a];
        }

        return [$playerMatchesCount];
    }
}
