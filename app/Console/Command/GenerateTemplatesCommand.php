<?php

namespace Arshavinel\PadelMiniTour\Console\Command;

use Arshavinel\PadelMiniTour\Console\StatsFormatterTrait;
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
 * Additively generates template files into the next-version directory
 * (`resources/template-matches/v{DEFAULT_TEMPLATE_VERSION + 1}/`), skipping any combo whose JSON
 * file already exists in that directory. Complement to {@see RegenerateTemplatesCommand}: never
 * wipes anything and is safe to re-run.
 *
 * Two mutually exclusive invocation modes:
 *
 * - **Bulk mode** (no options): iterates every (players => partners) entry of
 *   {@see TemplateMatchesGenerator::COMBINATIONS} with `repeat = 1, fixedTeams = false`, filters
 *   out combos already present in `v{DEFAULT_TEMPLATE_VERSION + 1}/`, then generates the rest.
 *   No wipe step, so unrelated sibling files (READMEs, .gitkeep, foreign artefacts) and any
 *   already-generated combo files are preserved untouched.
 * - **Single-combo mode** (all four options provided): generates exactly that combo if its file
 *   is missing from `v{DEFAULT_TEMPLATE_VERSION + 1}/`. If the file already exists, the command
 *   logs a brief "already exists" line and exits with status 0 (no-op).
 *
 * Mixing 1, 2 or 3 of the four options is an input-validation error.
 *
 * Use this command instead of `templates:regenerate` when adding a new combo to
 * {@see TemplateMatchesGenerator::COMBINATIONS} and the existing files in
 * `v{DEFAULT_TEMPLATE_VERSION + 1}/` are still wanted; or to safely retry an interrupted bulk
 * run without redoing combos that have already succeeded.
 */
final class GenerateTemplatesCommand extends Command
{
    use StatsFormatterTrait;

    protected static $defaultName = 'templates:generate';

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
            ->setDescription('Additively generate missing template-matches JSON files into the next-version directory (no wipe).')
            ->setHelp(implode("\n", [
                'Only generates combos whose v{DEFAULT_TEMPLATE_VERSION+1}/ JSON file is missing;',
                'existing files (and unrelated sibling files) are left untouched. Safe to re-run.',
                'With no options, iterates the entire TemplateMatchesGenerator::COMBINATIONS set',
                '(fixedTeams=false, repeat=1) and fills the gaps. Provide all four options to',
                'generate a single combo if its file is missing; the command no-ops when the file',
                'already exists.',
                'Omitting some of the four options is rejected.',
            ]))
            ->addOption('players', null, InputOption::VALUE_REQUIRED, 'Players count')
            ->addOption('partners', null, InputOption::VALUE_REQUIRED, 'Opponents per player')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Repeat opponents')
            ->addOption('fixed-teams', null, InputOption::VALUE_REQUIRED, 'Fixed teams (0 or 1)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // This is a long-running offline batch (up to ~16 minutes per combo: 8 min for the outer
        // pair-ordering loop + 8 min for sortMatches). The deterministic limits are the explicit
        // hrtime() wall budgets in TemplateMatchesGenerator; PHP's max_execution_time has no role
        // to play here and would only ever produce false-positive fatals partway through phase 2.
        set_time_limit(0);

        $io = new SymfonyStyle($input, $output);

        $provided = [
            'players' => $input->getOption('players'),
            'partners' => $input->getOption('partners'),
            'repeat' => $input->getOption('repeat'),
            'fixed-teams' => $input->getOption('fixed-teams'),
        ];
        $providedCount = count(array_filter(
            $provided,
            static fn($v) => $v !== null && $v !== ''
        ));

        if ($providedCount !== 0 && $providedCount !== 4) {
            $missing = [];
            foreach ($provided as $name => $value) {
                if ($value === null || $value === '') {
                    $missing[] = '--' . $name;
                }
            }
            $io->error(sprintf(
                'All-or-nothing: provide either no options or all four (--players, --partners, --repeat, --fixed-teams). Missing: %s',
                implode(', ', $missing)
            ));
            return 1;
        }

        $inUseVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION;
        $writeVersion = $inUseVersion + 1;

        $io->writeln(sprintf(
            '<info>Currently in use:</info> v%d   <comment>Writing to:</comment> v%d',
            $inUseVersion,
            $writeVersion
        ));
        $io->writeln('<info>Base dir:</info> ' . $this->repository->getBaseDir());
        $io->newLine();

        if ($providedCount === 4) {
            $combo = [
                'players' => (int) $provided['players'],
                'partners' => (int) $provided['partners'],
                'repeat' => (int) $provided['repeat'],
                'fixedTeams' => $this->parseBool($provided['fixed-teams']),
            ];

            // Single-combo idempotency: if the target file already exists, exit cleanly without
            // overwriting it. The user can fall back to `templates:regenerate` when they
            // explicitly want to redo a combo.
            if ($this->repository->hasAt(
                $writeVersion,
                $combo['players'],
                $combo['partners'],
                $combo['repeat'],
                $combo['fixedTeams']
            )) {
                $io->writeln(sprintf(
                    '<info>%s already exists -- skipping (use templates:regenerate to overwrite).</info>',
                    $this->repository->path(
                        $writeVersion,
                        $combo['players'],
                        $combo['partners'],
                        $combo['repeat'],
                        $combo['fixedTeams']
                    )
                ));
                return 0;
            }

            $combos = [$combo];
        } else {
            // Bulk mode: enumerate COMBINATIONS then filter out combos already on disk under
            // v{writeVersion}/. No wipe step, so already-generated files and unrelated siblings
            // (READMEs, .gitkeep, foreign artefacts) survive the run.
            $rawCombos = [];
            foreach (TemplateMatchesGenerator::COMBINATIONS as $players => $partnersList) {
                foreach ($partnersList as $partners) {
                    $rawCombos[] = [
                        'players' => (int) $players,
                        'partners' => (int) $partners,
                        'repeat' => 1,
                        'fixedTeams' => false,
                    ];
                }
            }

            $combos = array_values(array_filter(
                $rawCombos,
                fn(array $c): bool => !$this->repository->hasAt(
                    $writeVersion,
                    $c['players'],
                    $c['partners'],
                    $c['repeat'],
                    $c['fixedTeams']
                )
            ));
            $skipped = count($rawCombos) - count($combos);
            if ($skipped > 0) {
                $io->writeln(sprintf(
                    '<comment>Skipped %d combo%s already present in v%d/.</comment>',
                    $skipped,
                    $skipped === 1 ? '' : 's',
                    $writeVersion
                ));
                $io->newLine();
            }

            if (empty($combos)) {
                $io->writeln('<info>Nothing to generate -- every combo is already present.</info>');
                return 0;
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
        // across the generate combo list, mirroring the stats and regenerate commands' output.
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
                    $combo['fixedTeams']
                );
            } finally {
                $this->generator->setProgressCallback(null);
            }

            $this->repository->save($writeVersion, $template);

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
            '<comment>Generated %d combo%s into v%d/. Run `templates:stats --templates-version=%d` to inspect.</comment>',
            $totalCombos,
            $totalCombos === 1 ? '' : 's',
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
     * helpers in both stats commands and {@see RegenerateTemplatesCommand} so the four CLI
     * outputs share the same column-by-column shape. The two per-phase Time columns and the two
     * Min/Max Break columns are intentionally left blank because the AVG footer no longer reports
     * time or breaks aggregates.
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
