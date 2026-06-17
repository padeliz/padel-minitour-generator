<?php

namespace Arshavinel\PadelMiniTour\Console\Command;

use Arshavinel\PadelMiniTour\Console\StatsFormatterTrait;
use Arshavinel\PadelMiniTour\Console\TemplateComboResolver;
use Arshavinel\PadelMiniTour\Service\PlayerDistributionScorer;
use Arshavinel\PadelMiniTour\Service\Progress\GenerationProgress;
use Arshavinel\PadelMiniTour\Service\Progress\OrderingProgress;
use Arshavinel\PadelMiniTour\Service\Progress\PairingProgress;
use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates committed template files into the next-version directory
 * (`resources/template-matches/v{DEFAULT_TEMPLATE_VERSION + 1}/`).
 *
 * Two mutually exclusive invocation modes:
 *
 * - **Full bulk** (no combo filters): wipes `v{DEFAULT_TEMPLATE_VERSION + 1}/` first, then
 *   regenerates every COMBINATIONS entry with defaults `repeat=1, fixedTeams=false, courts=1`.
 * - **Filtered bulk** (any subset of combo options): regenerates only matching combos and does
 *   not wipe unrelated files in the target version directory.
 *
 * The in-use `v{DEFAULT_TEMPLATE_VERSION}/` directory is never touched, so the running app keeps
 * using the old templates while the new ones are reviewed.
 */
final class RegenerateTemplatesCommand extends Command
{
    use StatsFormatterTrait;

    protected static $defaultName = 'templates:regenerate';

    private TemplateMatchesGenerator $generator;
    private TemplateMatchesRepository $repository;

    public function __construct(
        ?TemplateMatchesGenerator $generator = null,
        ?TemplateMatchesRepository $repository = null
    ) {
        parent::__construct();
        $this->generator = $generator ?? new TemplateMatchesGenerator();
        $this->repository = $repository ?? new TemplateMatchesRepository();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Regenerate template-matches JSON files into the next-version directory.')
            ->setHelp(implode("\n", [
                'With no combo filters, regenerates the entire COMBINATIONS set and wipes',
                'v{DEFAULT_TEMPLATE_VERSION+1}/ first. Any subset of --players, --partners,',
                '--repeat, --fixed-teams, --courts narrows the target set without a full wipe.',
            ]))
            ->addOption('players', null, InputOption::VALUE_REQUIRED, 'Filter by players count')
            ->addOption('partners', null, InputOption::VALUE_REQUIRED, 'Filter by opponents per player')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Filter by repeat opponents (default 1)')
            ->addOption('fixed-teams', null, InputOption::VALUE_REQUIRED, 'Filter by fixed teams (0 or 1; default 0)')
            ->addOption('courts', null, InputOption::VALUE_REQUIRED, 'Filter by court count (default 1)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // This is a long-running offline batch (up to ~16 minutes per combo: 8 min for the outer
        // pair-ordering loop + 8 min for sortMatches). The deterministic limits are the explicit
        // hrtime() wall budgets in TemplateMatchesGenerator; PHP's max_execution_time has no role
        // to play here and would only ever produce false-positive fatals partway through phase 2.
        set_time_limit(0);

        $io = new SymfonyStyle($input, $output);

        $resolver = new TemplateComboResolver();
        try {
            $resolved = $resolver->resolve($input, TemplateMatchesGenerator::COMBINATIONS, false);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return 1;
        }

        $inUseVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION;
        $writeVersion = $inUseVersion + 1;
        $combos = $resolved['combos'];

        $io->writeln(sprintf(
            '<info>Currently in use:</info> v%d   <comment>Writing to:</comment> v%d   <comment>Combos:</comment> %d',
            $inUseVersion,
            $writeVersion,
            count($combos)
        ));
        $io->writeln('<info>Base dir:</info> ' . $this->repository->getBaseDir());
        $io->newLine();

        if ($resolved['isFullBulk']) {
            $cleared = $this->repository->clearVersion($writeVersion);
            if ($cleared > 0) {
                $io->writeln(sprintf(
                    '<comment>Cleared %d stale file%s from v%d before regenerating.</comment>',
                    $cleared,
                    $cleared === 1 ? '' : 's',
                    $writeVersion
                ));
                $io->newLine();
            }
        }

        // All combos share one (repeat, fixed) per command invocation - bulk mode hard-codes
        // (repeat=1, fixed=false) and single-combo mode uses the one (--repeat, --fixed-teams)
        // input - so the TEAMS group label is constant across the whole table.
        $tableRepeat = $combos[0]['repeat'];
        $tableFixedTeams = $combos[0]['fixedTeams'];

        $table = $this->makeUnifiedTable($output);
        $table->setHeaders($this->unifiedHeaders($tableRepeat, $tableFixedTeams));

        $failures = [];
        $supportsSections = $output instanceof ConsoleOutputInterface;
        $totalCombos = count($combos);

        // Track player-group transitions so the grouped Players column renders `12 / . / . / ...`
        // across the regenerate combo list, mirroring the stats commands' output.
        $previousPlayers = null;
        $variations = [];
        $mins = [];
        $avgs = [];
        $partnersVars = [];

        foreach ($combos as $i => $combo) {
            $counterSection = null;
            $liveSection = null;

            if ($supportsSections) {
                /** @var ConsoleOutputInterface $output */
                $counterSection = $output->section();
                $liveSection = $output->section();
                $counterSection->writeln(sprintf(
                    '<info>[%d/%d]</info> (in progress)',
                    $i + 1,
                    $totalCombos
                ));
                $callback = $this->liveTableCallback(
                    $counterSection,
                    $liveSection,
                    $combo,
                    $i + 1,
                    $totalCombos
                );
            } else {
                $output->writeln(sprintf(
                    '<info>[%d/%d]</info> players=%d partners=%d repeat=%d fixedTeams=%s',
                    $i + 1,
                    $totalCombos,
                    $combo['players'],
                    $combo['partners'],
                    $combo['repeat'],
                    $combo['fixedTeams'] ? 'true' : 'false'
                ));
                $callback = $this->bufferedFallbackCallback($output);
            }

            $this->generator->setProgressCallback($callback);

            try {
                $template = $this->generator->generate(
                    $combo['players'],
                    $combo['partners'],
                    $combo['repeat'],
                    $combo['courts'],
                    $combo['fixedTeams']
                );
            } finally {
                $this->generator->setProgressCallback(null);
            }

            $this->repository->save($writeVersion, $template);

            $savedPath = $this->repository->path(
                $writeVersion,
                $combo['players'],
                $combo['partners'],
                $combo['repeat'],
                $combo['courts'],
                $combo['fixedTeams']
            );
            if ($template->isEligible()) {
                $io->writeln(sprintf('<info>Saved:</info> %s', $savedPath));
            } else {
                $io->writeln(sprintf(
                    '<comment>Saved infeasible record (matches=null):</comment> %s',
                    $savedPath
                ));
            }

            // Finalize the per-combo sections so they stay in scrollback as a permanent trail.
            // The counter line is overwritten to a final (done) / (failed) status and the live
            // mini-table is re-rendered once more with the *final* template (matches populated,
            // real generation time, etc.). The next iteration creates fresh sections below this
            // one, so each completed combo remains visible above the currently-running one.
            if ($counterSection !== null) {
                $counterSection->overwrite(sprintf(
                    '<info>[%d/%d]</info> %s',
                    $i + 1,
                    $totalCombos,
                    $template->isEligible() ? '<info>(done)</info>' : '<error>(no eligible permutation)</error>'
                ));
            }
            if ($liveSection !== null) {
                $liveSection->clear();
                // Standalone single-row mini-table: always show the actual Players number, never
                // the grouped `.` continuation marker (which only makes sense in the summary).
                $this->renderLiveSnapshotTable($liveSection, $template, true);
            }

            $firstOfGroup = ($previousPlayers !== $combo['players']);

            // Mirror the stats commands' visual grouping: insert a horizontal rule whenever we
            // cross a players-number boundary (except before the very first row, which sits flush
            // against the header). The trailing separator emitted after the loop continues to
            // serve as the divider between the final group and the AVG footer.
            if ($firstOfGroup && $previousPlayers !== null) {
                $table->addRow(array_fill(0, $this->unifiedTotalColumns(), new TableSeparator()));
            }

            $table->addRow($this->buildUnifiedRow(
                $template,
                $combo['players'],
                $combo['partners'],
                $firstOfGroup
            ));

            $variations[] = $template->getPairingMeetingsVariation();
            $mins[] = $template->getSortingMinDistribution();
            $avgs[] = $template->getSortingAvgDistribution();
            $partnersVars[] = $template->getPairingPartnersCountVariation();
            $previousPlayers = $combo['players'];

            if (!$template->isEligible()) {
                $failures[] = $combo;
            }

            // Visual separator between combos. Written to the parent output (not a section) so it
            // is permanent and the next iteration's fresh sections start below this blank line.
            $output->writeln('');
        }

        $table->addRow(array_fill(0, $this->unifiedTotalColumns(), new TableSeparator()));
        $table->addRow($this->buildAvgRow(
            $variations,
            $mins,
            $avgs,
            $partnersVars
        ));

        $io->newLine();
        $table->render();
        $io->newLine();

        if (!empty($failures)) {
            $io->writeln('<error>No eligible permutation found for the following combos:</error>');
            foreach ($failures as $combo) {
                $io->writeln(sprintf(
                    '  - players=%d partners=%d repeat=%d fixedTeams=%s',
                    $combo['players'],
                    $combo['partners'],
                    $combo['repeat'],
                    $combo['fixedTeams'] ? 'true' : 'false'
                ));
            }
            $io->newLine();
        }

        $io->writeln(sprintf(
            '<comment>Review v%d files, then bump TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION to %d and commit.</comment>',
            $writeVersion,
            $writeVersion
        ));

        return count($failures);
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
     * Builds the AVG aggregate row for the end-of-run summary table. Mirrors the buildAvgRow
     * helpers in both stats commands so the three CLI outputs share the same column-by-column
     * shape. The two per-phase Time columns and the two Min/Max Break columns are intentionally
     * left blank because the AVG footer no longer reports time or breaks aggregates.
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
     * Builds the TTY callback: every progress event captures into closure state, then re-renders
     * the single-row live table into {@code $liveSection}. The counter line is updated only when
     * the seed indicator changes (multi-seed runs) so the eye can spot seed transitions.
     *
     * @param array{players:int,partners:int,repeat:int,fixedTeams:bool} $combo
     */
    private function liveTableCallback(
        ConsoleSectionOutput $counterSection,
        ConsoleSectionOutput $liveSection,
        array $combo,
        int $counterCurrent,
        int $counterTotal
    ): callable {
        $latestPairing = null;
        $latestOrdering = null;

        return function (GenerationProgress $event) use (
            $counterSection,
            $liveSection,
            $combo,
            $counterCurrent,
            $counterTotal,
            &$latestPairing,
            &$latestOrdering
        ): void {
            if ($event instanceof PairingProgress) {
                $latestPairing = $event;
                if ($event->getTotalSeeds() > 1) {
                    $counterSection->overwrite(sprintf(
                        '<info>[%d/%d]</info> seed %d/%d (in progress)',
                        $counterCurrent,
                        $counterTotal,
                        $event->getCurrentSeed(),
                        $event->getTotalSeeds()
                    ));
                }
            } elseif ($event instanceof OrderingProgress) {
                $latestOrdering = $event;
            }

            $snapshot = TemplateMatches::fromProgress(
                $combo['players'],
                $combo['partners'],
                $combo['repeat'],
                $combo['courts'],
                $combo['fixedTeams'],
                $latestPairing,
                $latestOrdering
            );

            $liveSection->clear();
            // Standalone single-row table: always show the actual Players number, never the
            // grouped `.` continuation marker (which only makes sense in the multi-row summary).
            $this->renderLiveSnapshotTable($liveSection, $snapshot, true);
        };
    }

    /**
     * Buffered-output fallback (no `ConsoleOutputInterface`): emit one compact text line per
     * phase-final event. Rendering the unified Table here would produce N noisy mini-tables in
     * log buffers; the end-of-run summary table already provides the same data in a compact form.
     */
    private function bufferedFallbackCallback(OutputInterface $output): callable
    {
        return function (GenerationProgress $event) use ($output): void {
            if (!$event->isFinal()) {
                return;
            }
            if ($event instanceof PairingProgress) {
                $seedTag = $event->getTotalSeeds() > 1
                    ? sprintf(' seed %d/%d |', $event->getCurrentSeed(), $event->getTotalSeeds())
                    : '';
                $output->writeln(sprintf(
                    '  pairing done |%s iter %s | templates %s | best variation %s',
                    $seedTag,
                    number_format($event->getIterations(), 0, '.', ','),
                    number_format($event->getTemplatesGenerated(), 0, '.', ','),
                    $event->getBestMeetingsVariation() === null
                        ? '-'
                        : number_format($event->getBestMeetingsVariation(), 4)
                ));
            } elseif ($event instanceof OrderingProgress) {
                $output->writeln(sprintf(
                    '  ordering done [%s] | iter %s | best min=%s avg=%s',
                    TemplateMatchesGenerator::stopReasonLabel($event->getStopReason()) ?? '-',
                    number_format($event->getIterations(), 0, '.', ','),
                    $event->getBestMin() === null ? '-' : number_format($event->getBestMin(), 4),
                    $event->getBestAvg() === null ? '-' : number_format($event->getBestAvg(), 4)
                ));
            }
        };
    }

    private function parseBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $stringValue = strtolower((string) $value);
        if (in_array($stringValue, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($stringValue, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }
        throw new \InvalidArgumentException(sprintf('Invalid boolean value for --fixed-teams: %s', $stringValue));
    }
}
