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

    public static function getActivitiesMarginTop(int $activitiesCount): int
    {
        if ($activitiesCount >= 27) {
            $marginTop = 10;
        } elseif ($activitiesCount >= 25) {
            $marginTop = 13;
        } elseif ($activitiesCount >= 23) {
            $marginTop = 22;
        } elseif ($activitiesCount >= 21) {
            $marginTop = 60;
        } elseif ($activitiesCount >= 19) {
            $marginTop = 92;
        } elseif ($activitiesCount >= 17) {
            $marginTop = 130;
        } elseif ($activitiesCount >= 15) {
            $marginTop = 160;
        } elseif ($activitiesCount >= 13) {
            $marginTop = 190;
        } elseif ($activitiesCount >= 11) {
            $marginTop = 220;
        } elseif ($activitiesCount >= 9) {
            $marginTop = 235;
        } elseif ($activitiesCount >= 7) {
            $marginTop = 250;
        } else {
            $marginTop = 265;
        }

        return $marginTop;
    }

    public static function getPlayersMarginTop(int $countPlayers, int $nrOfPlayerRows): int
    {
        if ($nrOfPlayerRows == 1) {
            if ($countPlayers <= 6) {
                $marginTop = 110;
            } elseif ($countPlayers == 7) {
                $marginTop = 100;
            } elseif ($countPlayers == 8) {
                $marginTop = 90;
            } elseif ($countPlayers == 9) {
                $marginTop = 80;
            } elseif ($countPlayers == 10) {
                $marginTop = 70;
            } elseif ($countPlayers == 11) {
                $marginTop = 60;
            } elseif ($countPlayers == 12) {
                $marginTop = 50;
            } else {
                $marginTop = 40;
            }
        } elseif ($nrOfPlayerRows == 2) {
            if ($countPlayers <= 6) {
                $marginTop = 55;
            } elseif ($countPlayers == 7) {
                $marginTop = 50;
            } elseif ($countPlayers == 8) {
                $marginTop = 45;
            } elseif ($countPlayers == 9) {
                $marginTop = 40;
            } elseif ($countPlayers == 10) {
                $marginTop = 35;
            } elseif ($countPlayers == 11) {
                $marginTop = 30;
            } elseif ($countPlayers == 12) {
                $marginTop = 25;
            } else {
                $marginTop = 20;
            }
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
