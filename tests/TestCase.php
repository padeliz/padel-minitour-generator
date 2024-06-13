<?php

namespace Tests;

use Arshwell\Monolith\StaticHandler;
use Arshwell\Monolith\Time;

abstract class TestCase
{
    public function __construct()
    {
        StaticHandler::iniSetPHP();
    }

    protected function formatMeetingsVariation(?float $meetingsVariation): string
    {
        if (null === $meetingsVariation) {
            $color = 'red';
        } elseif ($meetingsVariation < 2.2) {
            $color = 'green';
        } elseif ($meetingsVariation < 2.7) {
            $color = 'yellow';
        } else {
            $color = 'red';
        }

        return "<fg=$color>" . ($meetingsVariation !== null ? number_format($meetingsVariation, 2) : '-') . "</>";
    }

    protected function formatIndex(?int $index, int $total): string
    {
        $color = 'white';

        if (null === $index) {
            $color = 'red';
        } elseif ((0.1 * $total) >= $index) {
            $color = 'green';
        } elseif ($total > 10000) {
            if ((0.9 * $total) <= $index) {
                $color = 'yellow';
            }
        }

        return "<fg=$color>" . ($index ?: '-') . "</> / {$total}";
    }

    protected function formatBoolean(bool $value): string
    {
        $color = 'white';

        if (false === $value) {
            $color = 'red';
            $value = 'false';
        } elseif (true === $value) {
            $color = 'green';
            $value = 'true';
        }

        return "<fg=$color>" . $value . "</>";
    }

    protected function formatTime(int $actual, int $expected): string
    {
        $color = 'white';

        if ((0.5 * $expected) >= $actual) {
            $color = 'green';
        } elseif ((0.8 * $expected) <= $actual) {
            $color = 'yellow';
        } elseif ((0.95 * $expected) <= $actual || $actual == $expected) {
            $color = 'red';
        }

        return "<fg=$color>" . Time::readableTime($actual) . "</> / " . Time::readableTime($expected);
    }

    function clearScreen()
    {
        // ANSI escape code to clear the screen and move the cursor to the top left
        // echo "\033c";
        // exit;

        // Use the 'clear' command to clear the console
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('cls', 'w'));
        } else {
            pclose(popen('clear', 'w'));
        }
    }
}
