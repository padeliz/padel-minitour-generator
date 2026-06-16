<?php

namespace Arshavinel\PadelMiniTour\Console\Command;

use Arshavinel\PadelMiniTour\Console\StatsFormatterTrait;
use Arshavinel\PadelMiniTour\Service\Exception\TemplateMatchesNotFoundException;
use Arshavinel\PadelMiniTour\Service\PlayerDistributionScorer;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only statistics over the in-use fixed-teams templates.
 *
 * Replaces the legacy `tests/Unit/TemplateMatchesGeneratorTestFixedTeams.php`. Defaults to
 * `[8 => [2, 3]]` to match the old script. Never invokes generation.
 *
 * Renders the same unified 18-column grouped table as {@see StatsTemplatesCommand}; only the
 * fallback identity differs (`fixedTeams = true`). The `Partners Nr. Var.` column always renders
 * green `0` for fixed-teams runs - that's the expected behaviour, not a regression.
 *
 * Note: until the engineer has run `templates:regenerate` in single-combo mode for
 * `--fixed-teams=1` and committed the produced files, this command will report missing files for
 * every requested combo. That is by design.
 */
final class StatsTemplatesFixedTeamsCommand extends Command
{
    use StatsFormatterTrait;

    protected static $defaultName = 'templates:stats-fixed-teams';

    private TemplateMatchesRepository $repository;

    public function __construct(?TemplateMatchesRepository $repository = null)
    {
        parent::__construct();
        $this->repository = $repository ?? new TemplateMatchesRepository();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Read-only stats over committed fixed-teams templates.')
            ->setHelp(implode("\n", [
                'Defaults to [8 => [2, 3]]. Override with --combinations="players:partners1,partners2".',
                'Reads from the in-use version by default; pass --templates-version=N to inspect',
                'freshly regenerated v{DEFAULT_TEMPLATE_VERSION+1}/ files before bumping the constant.',
                '(--version is reserved by the Symfony application for printing its own version.)',
            ]))
            ->addOption(
                'combinations',
                null,
                InputOption::VALUE_REQUIRED,
                'Space-separated "players:partners,..." pairs',
                null
            )
            ->addOption(
                'templates-version',
                null,
                InputOption::VALUE_REQUIRED,
                'Templates version directory to read (defaults to in-use DEFAULT_TEMPLATE_VERSION)',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $combinations = $this->parseCombinations($input->getOption('combinations'))
            ?? [8 => [2, 3]];
        $version = $this->parseVersion($input->getOption('templates-version'));

        $table = $this->makeUnifiedTable($output);
        $table->setHeaders($this->unifiedHeaders(1, true));

        $versionLabel = $version === TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION
            ? sprintf('v%d <comment>(in use)</comment>', $version)
            : sprintf('v%d <comment>(in use: v%d)</comment>', $version, TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION);

        $output->writeln(sprintf(
            '<info>Reading:</info> %s   <info>Base dir:</info> %s',
            $versionLabel,
            $this->repository->getBaseDir()
        ));
        $output->writeln('');

        $variations = [];
        $mins = [];
        $avgs = [];
        $partnersVars = [];
        $missing = 0;

        foreach ($combinations as $players => $partnersList) {
            foreach ($partnersList as $i => $opponentsPerPlayer) {
                try {
                    $template = $this->repository->findAt($version, (int) $players, (int) $opponentsPerPlayer, 1, true);
                    $variations[] = $template->getPairingMeetingsVariation();
                    $mins[] = $template->getSortingMinDistribution();
                    $avgs[] = $template->getSortingAvgDistribution();
                    $partnersVars[] = $template->getPairingPartnersCountVariation();
                    $table->addRow($this->buildUnifiedRow(
                        $template,
                        (int) $players,
                        (int) $opponentsPerPlayer,
                        $i === 0
                    ));
                } catch (TemplateMatchesNotFoundException $e) {
                    $missing++;
                    $table->addRow($this->buildUnifiedRow(
                        null,
                        (int) $players,
                        (int) $opponentsPerPlayer,
                        $i === 0
                    ));
                }
            }
            $table->addRow(array_fill(0, $this->unifiedTotalColumns(), new TableSeparator()));
        }

        $table->addRow($this->buildAvgRow(
            $variations,
            $mins,
            $avgs,
            $partnersVars
        ));

        $table->render();

        if ($missing > 0) {
            $output->writeln(sprintf('<error>%d template(s) missing under v%d.</error>', $missing, $version));
            $output->writeln('Run <comment>php bin/console templates:regenerate --players=N --partners=N --repeat=1 --fixed-teams=1</comment> to (re)generate them.');
        }

        return $missing;
    }

    /**
     * @param array<int, int|float|null> $values
     */
    private function average(array $values): ?float
    {
        $present = array_filter($values, static fn($v) => $v !== null);
        if (empty($present)) {
            return null;
        }

        return array_sum($present) / count($present);
    }

    /**
     * Builds the AVG aggregate row (last row in the table). Mirrors the same column-by-column
     * structure used by {@see StatsTemplatesCommand::buildAvgRow()} so both commands produce a
     * visually identical bottom row. The two per-phase Time columns and the two Min/Max Break
     * columns stay blank because the AVG footer no longer reports time or breaks aggregates.
     *
     * @param array<int, float|null> $variations
     * @param array<int, float|null> $mins
     * @param array<int, float|null> $avgs
     * @param array<int, int|null>   $partnersVars
     * @return array<int, string>
     */
    private function buildAvgRow(
        array $variations,
        array $mins,
        array $avgs,
        array $partnersVars
    ): array {
        $partnersVarAvg = $this->average($partnersVars);

        return [
            '', '', '',
            $partnersVarAvg !== null ? '<fg=white>' . number_format($partnersVarAvg, 2) . '</> (AVG)' : '',
            '',
            '',
            $this->formatMeetingsVariation($this->average($variations)) . ' (AVG)',
            $this->formatDistribution($this->average($mins), PlayerDistributionScorer::DISPLAY_GOOD, PlayerDistributionScorer::DISPLAY_FAIR) . ' (AVG)',
            $this->formatDistribution($this->average($avgs), TemplateMatchesGenerator::DISPLAY_AVG_DIST_GREEN, TemplateMatchesGenerator::DISPLAY_AVG_DIST_YELLOW) . ' (AVG)',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];
    }

    /**
     * Parses the --templates-version raw input into a positive integer, defaulting to the in-use
     * version when omitted. Rejects anything that is not a strictly positive integer literal.
     *
     * @param mixed $raw
     */
    private function parseVersion($raw): int
    {
        if ($raw === null || $raw === '') {
            return TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION;
        }
        if (!is_string($raw) && !is_int($raw)) {
            throw new \InvalidArgumentException('Invalid --templates-version value: must be a positive integer.');
        }
        $stringValue = (string) $raw;
        if (!preg_match('/^[1-9]\d*$/', $stringValue)) {
            throw new \InvalidArgumentException(sprintf('Invalid --templates-version value: "%s" is not a positive integer.', $stringValue));
        }

        return (int) $stringValue;
    }

    /**
     * @return array<int, array<int, int>>|null
     */
    private function parseCombinations(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $combinations = [];
        foreach (preg_split('/\s+/', trim($raw)) as $param) {
            if (strpos($param, ':') === false) {
                throw new \InvalidArgumentException("Invalid combination token: {$param}");
            }
            [$players, $partnersList] = explode(':', $param, 2);
            $combinations[(int) $players] = array_map('intval', explode(',', $partnersList));
        }

        return $combinations;
    }
}
