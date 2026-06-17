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
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only statistics over the in-use mixed-teams templates.
 *
 * Replaces the legacy `tests/Unit/TemplateMatchesGeneratorTest.php`. Never invokes generation.
 *
 * Renders the unified 18-column grouped table provided by {@see StatsFormatterTrait}; the row
 * builder pulls every field directly off the loaded {@see \Arshavinel\PadelMiniTour\Service\TemplateMatches}
 * DTO, including identity, pairing diagnostics and sorting diagnostics.
 */
final class StatsTemplatesCommand extends Command
{
    use StatsFormatterTrait;

    protected static $defaultName = 'templates:stats';

    private TemplateMatchesRepository $repository;

    public function __construct(?TemplateMatchesRepository $repository = null)
    {
        parent::__construct();
        $this->repository = $repository ?? new TemplateMatchesRepository();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Read-only stats over committed mixed-teams templates.')
            ->setHelp(implode("\n", [
                'Iterates TemplateMatchesGenerator::COMBINATIONS by default. Override with',
                '--combinations="players:partners1,partners2 players:partners1" (space-separated pairs).',
                'Any subset of --players, --partners, --repeat, --fixed-teams, --courts lists only',
                'matching JSON files on disk (defaults: repeat=1, courts=1, fixed-teams=no).',
                'Reads from the in-use version by default; pass --templates-version=N to inspect',
                'freshly regenerated v{DEFAULT_TEMPLATE_VERSION+1}/ files before bumping the constant.',
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
            )
            ->addOption('players', null, InputOption::VALUE_REQUIRED, 'Filter by players count')
            ->addOption('partners', null, InputOption::VALUE_REQUIRED, 'Filter by opponents per player')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Filter by repeat opponents (default 1)')
            ->addOption('fixed-teams', null, InputOption::VALUE_REQUIRED, 'Filter by fixed teams (0 or 1; default 0)')
            ->addOption('courts', null, InputOption::VALUE_REQUIRED, 'Filter by court count (default 1)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $combinations = $this->parseStatsCombinations($input->getOption('combinations'))
            ?? TemplateMatchesGenerator::COMBINATIONS;
        $version = $this->parseStatsVersion($input->getOption('templates-version'));
        $filteredDiscovery = $this->hasStatsComboFilters($input);

        try {
            $combos = $this->resolveStatsCombos($input, $this->repository, $version, $combinations, false);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return 1;
        }

        $versionLabel = $version === TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION
            ? sprintf('v%d <comment>(in use)</comment>', $version)
            : sprintf('v%d <comment>(in use: v%d)</comment>', $version, TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION);

        if ($combos === []) {
            $output->writeln(sprintf(
                '<info>Reading:</info> %s   <info>Base dir:</info> %s   <info>Combos:</info> 0',
                $versionLabel,
                $this->repository->getBaseDir()
            ));
            $io->warning(sprintf('No template files match the provided filters under v%d.', $version));

            return 0;
        }

        $table = $this->makeUnifiedTable($output);
        $table->setHeaders($this->unifiedHeaders($combos[0]['repeat'], $combos[0]['fixedTeams']));

        $output->writeln(sprintf(
            '<info>Reading:</info> %s   <info>Base dir:</info> %s   <info>Combos:</info> %d',
            $versionLabel,
            $this->repository->getBaseDir(),
            count($combos)
        ));
        $output->writeln('');

        $variations = [];
        $mins = [];
        $avgs = [];
        $partnersVars = [];
        $missing = 0;
        $previousPlayers = null;

        foreach ($combos as $combo) {
            $firstOfGroup = $previousPlayers !== $combo['players'];
            if ($firstOfGroup && $previousPlayers !== null) {
                $table->addRow(array_fill(0, $this->unifiedTotalColumns(), new TableSeparator()));
            }

            try {
                $template = $this->repository->findAt(
                    $version,
                    $combo['players'],
                    $combo['partners'],
                    $combo['repeat'],
                    $combo['courts'],
                    $combo['fixedTeams']
                );
                $variations[] = $template->getPairingMeetingsVariation();
                $mins[] = $template->getSortingMinDistribution();
                $avgs[] = $template->getSortingAvgDistribution();
                $partnersVars[] = $template->getPairingPartnersCountVariation();
                $table->addRow($this->buildUnifiedRow(
                    $template,
                    $combo['players'],
                    $combo['partners'],
                    $firstOfGroup
                ));
            } catch (TemplateMatchesNotFoundException $e) {
                if (!$filteredDiscovery) {
                    $missing++;
                    $table->addRow($this->buildUnifiedRow(
                        null,
                        $combo['players'],
                        $combo['partners'],
                        $firstOfGroup
                    ));
                }
            }

            $previousPlayers = $combo['players'];
        }

        $table->addRow(array_fill(0, $this->unifiedTotalColumns(), new TableSeparator()));
        $table->addRow($this->buildAvgRow(
            $variations,
            $mins,
            $avgs,
            $partnersVars
        ));

        $table->render();

        if ($missing > 0) {
            $output->writeln(sprintf('<error>%d template(s) missing under v%d.</error>', $missing, $version));
            $output->writeln('Run <comment>php bin/console templates:regenerate</comment> to (re)generate them, then bump DEFAULT_TEMPLATE_VERSION.');
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
}
