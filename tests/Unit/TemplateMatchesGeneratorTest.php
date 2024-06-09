<?php

use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use Arshwell\Monolith\StaticHandler;
use Arshwell\Monolith\Time;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Process\Process;

require_once "vendor/autoload.php";


function formatMeetingsVariation(?float $meetingsVariation): string
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

function formatIndex(?int $index, int $total): string
{
    if (null === $index) {
        $color = 'red';
    } elseif ($index == $total) {
        $color = 'yellow';
    } else {
        $color = 'white';
    }

    return "<fg=$color>" . ($index ?: '-') . "</> / {$total}";
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


StaticHandler::iniSetPHP();

$output = new ConsoleOutput();
$meetingsVariations = [];

$table = new Table($output);

$table->setHeaders([
    '<fg=white>Players</>', '<fg=white>Partners</>', '<fg=white>Matches</>',
    '<fg=white>Meetings Variation</>', '<fg=white>Permutation Index</>', '<fg=white>Template Index</>',
    '<fg=white>Generation Time</>',
]);


clearScreen();
$table->render();

foreach (TemplateMatchesGenerator::COMBINATIONS as $players => $partners) {

    foreach ($partners as $i => $partners_per_player) {
        // start loading dots process
        $process = new Process(['php', '-r', 'while (true) { echo "."; sleep(1); }']);
        $process->start();

        $template = new TemplateMatchesGenerator($players, $partners_per_player, 1);

        $process->stop(); // stop loading dots process


        $meetingsVariations[] = $template->getMeetingsVariation();

        $table->addRow([
            '<fg=blue>' . ($i == 0 ? $players : '.') . '</>',
            '<fg=cyan>' . $partners_per_player . '</>',
            '<fg=white>' . ($template->getMatches() ? count($template->getMatches()) : '-') . '</>',
            formatMeetingsVariation($template->getMeetingsVariation()),
            formatIndex($template->getPermutationIndex(), $template->getPermutationsIterated()),
            formatIndex($template->getTemplateIndex(), $template->getTemplatesGenerated()),
            '<fg=white>' . Time::readableTime($template->getGenerationTime() * 1000) . ' / ' . Time::readableTime($template->getEstimatedGenerationTime() * 1000) . '</>',
        ]);

        clearScreen();
        $table->render();
    }

    $table->addRow([
        new TableSeparator(),
        new TableSeparator(),
        new TableSeparator(),
        new TableSeparator(),
        new TableSeparator(),
        new TableSeparator(),
        new TableSeparator(),
    ]);

    clearScreen();
    $table->render();
}

$table->addRow([
    '',
    '',
    '',
    formatMeetingsVariation(
        array_sum($meetingsVariations) / count(array_filter($meetingsVariations, fn ($value) => !is_null($value)))
    ) . ' (AVG)',
    '',
    '',
]);

clearScreen();
$table->render();

Assert::assertEmpty(
    array_filter($meetingsVariations, fn ($value) => is_null($value)),
    "Failed asserting that all combinations have been successfully generated."
);
