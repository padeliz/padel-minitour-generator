<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once "vendor/autoload.php";

class TemplateMatchesGeneratorTestFixedTeams extends TestCase
{
    public function run(array $combinations)
    {
        $output = new ConsoleOutput();
        $meetingsVariations = [];

        $table = new Table($output);

        $table->setHeaders([
            '<fg=white>Players</>', '<fg=white>Opponents</>', '<fg=white>Matches</>',
            '<fg=white>Meetings Variation</>', '<fg=white>Permutation Index</>', '<fg=white>Template Index</>',
            '<fg=white>Generation Time</>',
        ]);


        $this->clearScreen();
        $table->render();

        foreach ($combinations as $players => $partners) {

            foreach ($partners as $i => $opponents_per_player) {
                // start loading dots process
                $process = new Process(['php', '-r', 'while (true) { echo "."; sleep(1); }']);
                $process->start();

                $template = new TemplateMatchesGenerator($players, $opponents_per_player, 1, true);

                $process->stop(); // stop loading dots process


                $meetingsVariations[] = $template->getMeetingsVariation();

                $table->addRow([
                    '<fg=blue>' . ($i == 0 ? $players : '.') . '</>',
                    '<fg=cyan>' . $opponents_per_player . '</>',
                    '<fg=white>' . ($template->getMatches() ? count($template->getMatches()) : '-') . '</>',
                    $this->formatMeetingsVariation($template->getMeetingsVariation()),
                    $this->formatIndex($template->getPermutationIndex(), $template->getPermutationsIterated()),
                    $this->formatIndex($template->getTemplateIndex(), $template->getTemplatesGenerated()),
                    $this->formatTime($template->getGenerationTime() * 1000, $template->getEstimatedGenerationTime() * 1000),
                ]);

                $this->clearScreen();
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

            $this->clearScreen();
            $table->render();
        }

        $table->addRow([
            '',
            '',
            '',
            $this->formatMeetingsVariation(
                array_sum($meetingsVariations) / count(array_filter($meetingsVariations, fn ($value) => !is_null($value)))
            ) . ' (AVG)',
            '',
            '',
        ]);

        $this->clearScreen();
        $table->render();

        Assert::assertEmpty(
            array_filter($meetingsVariations, fn ($value) => is_null($value)),
            "Failed asserting that all combinations have been successfully generated."
        );
    }
}

$test = new TemplateMatchesGeneratorTestFixedTeams();

if ($argc > 1) {
    $combinations = [];

    // parse command line arguments
    foreach (array_slice($argv, 1) as $param) {
        [$players, $opponentsList] = explode(':', $param);

        $combinations[$players] = explode(',', $opponentsList);
    }
} else {
    $combinations = [
        8 => [2, 3]
    ];
}

$test->run($combinations);
