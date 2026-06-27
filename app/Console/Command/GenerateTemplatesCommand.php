<?php

namespace Arshavinel\PadelMiniTour\Console\Command;

use Arshavinel\PadelMiniTour\Console\MetricsFormatterTrait;
use Arshavinel\PadelMiniTour\Console\TemplateComboResolver;
use Arshavinel\PadelMiniTour\Service\Progress\GenerationProgress;
use Arshavinel\PadelMiniTour\Service\Progress\OrderingProgress;
use Arshavinel\PadelMiniTour\Service\Progress\MatchMakingProgress;
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
 * Additively generates template files into an explicit version directory
 * (`resources/template-matches/v{N}/`), skipping any combo whose JSON file already exists.
 * Requires `--templates-version=N`. Complement to {@see RegenerateTemplatesCommand}: never
 * wipes anything and is safe to re-run.
 */
final class GenerateTemplatesCommand extends Command
{
    use MetricsFormatterTrait;

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
            ->setDescription('Additively generate missing template-matches JSON files (requires --templates-version).')
            ->setHelp(implode("\n", [
                'Requires --templates-version=N. Only generates combos whose v{N}/ JSON file is missing;',
                'existing files are left untouched. Safe to re-run.',
            ]))
            ->addOption(
                'templates-version',
                null,
                InputOption::VALUE_REQUIRED,
                'Version directory to write (required; v{N}/ is created when missing)'
            )
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

        try {
            $writeVersion = $this->parseRequiredTemplateVersion($input->getOption('templates-version'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $resolver = new TemplateComboResolver();
        try {
            $resolved = $resolver->resolve($input, TemplateMatchesGenerator::COMBINATIONS, false);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return 1;
        }

        $this->repository->ensureVersionDirectory($writeVersion);
        try {
            $latestVersion = $this->repository->latestVersion();
        } catch (\RuntimeException $e) {
            $latestVersion = $writeVersion;
        }
        $combos = $resolved['combos'];

        $io->writeln(sprintf(
            '<info>Latest version:</info> v%d   <comment>Writing to:</comment> v%d   <comment>Combos:</comment> %d',
            $latestVersion,
            $writeVersion,
            count($combos)
        ));
        $io->writeln('<info>Base dir:</info> ' . $this->repository->getBaseDir());
        $io->newLine();

        if (count($combos) === 1) {
            $combo = $combos[0];
            if ($this->repository->hasAt(
                $writeVersion,
                $combo['players'],
                $combo['partners'],
                $combo['repeat'],
                $combo['courts'],
                $combo['fixedTeams']
            )) {
                $io->writeln(sprintf(
                    '<info>%s already exists in v%d -- skipping.</info>',
                    $this->repository->path(
                        $writeVersion,
                        $combo['players'],
                        $combo['partners'],
                        $combo['repeat'],
                        $combo['courts'],
                        $combo['fixedTeams']
                    ),
                    $writeVersion
                ));
                $io->writeln('<comment>Use templates:regenerate with the same filters to overwrite.</comment>');
                return 0;
            }
        }

        $rawCount = count($combos);
        $combos = array_values(array_filter(
            $combos,
            fn(array $c): bool => !$this->repository->hasAt(
                $writeVersion,
                $c['players'],
                $c['partners'],
                $c['repeat'],
                $c['courts'],
                $c['fixedTeams']
            )
        ));
        $skipped = $rawCount - count($combos);
        if ($skipped > 0) {
            $io->writeln(sprintf(
                '<comment>Skipped %d combo%s already present in v%d/.</comment>',
                $skipped,
                $skipped === 1 ? '' : 's',
                $writeVersion
            ));
            $io->newLine();
        }

        if ($combos === []) {
            $io->writeln('<info>Nothing to generate -- every matching combo is already present.</info>');
            return 0;
        }

        $failures = [];
        $supportsSections = $output instanceof ConsoleOutputInterface;
        $totalCombos = count($combos);
        $summaryRows = [];

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
                $this->renderLiveSnapshotTable(
                    $liveSection,
                    $template,
                    true
                );
            }

            $summaryRows[] = ['combo' => $combo, 'template' => $template];

            if (!$template->isEligible()) {
                $failures[] = $combo;
            }

            // Visual separator between combos. Written to the parent output (not a section) so it
            // is permanent and the next iteration's fresh sections start below this blank line.
            $output->writeln('');
        }

        $summaryTemplates = array_column($summaryRows, 'template');
        $layout = $this->resolveUnifiedTableLayout($combos, $summaryTemplates);
        $table = $this->makeUnifiedTable($output);
        $table->setHeaders($this->unifiedHeaders($layout));
        $this->writeTableContextLine($output, $layout);
        $output->writeln('');

        $previousPlayers = null;
        $minPartnersFairs = [];
        $avgPartnersFairs = [];
        $partnersVars = [];
        $meetingsVars = [];
        $mins = [];
        $avgs = [];

        foreach ($summaryRows as $row) {
            $combo = $row['combo'];
            $template = $row['template'];
            $firstOfGroup = ($previousPlayers !== $combo['players']);

            if ($firstOfGroup && $previousPlayers !== null) {
                $table->addRow(array_fill(0, $layout['totalColumns'], new TableSeparator()));
            }

            $table->addRow($this->buildUnifiedRow(
                $template,
                $combo['players'],
                $combo['partners'],
                $firstOfGroup,
                $layout
            ));

            $minPartnersFairs[] = $template->getPairingQualityMinPartnersFairness();
            $avgPartnersFairs[] = $template->getPairingQualityAvgPartnersFairness();
            $partnersVars[] = $template->getPairingQualityPartnersCountVariation();
            $meetingsVars[] = $template->getMatchMakingQualityMeetingsVariation();
            $mins[] = $template->getOrderingQualityMinDistribution();
            $avgs[] = $template->getOrderingQualityAvgDistribution();
            $previousPlayers = $combo['players'];
        }

        $table->addRow(array_fill(0, $layout['totalColumns'], new TableSeparator()));
        $table->addRow($this->buildAvgRow($layout, $minPartnersFairs, $avgPartnersFairs, $partnersVars, $meetingsVars, $mins, $avgs));

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
            '<comment>Generated %d combo%s into v%d/. Run `templates:metrics --templates-version=%d` to inspect.</comment>',
            $totalCombos,
            $totalCombos === 1 ? '' : 's',
            $writeVersion,
            $writeVersion
        ));

        return count($failures);
    }

    /**
     * Builds the TTY callback: every progress event captures into closure state, then re-renders
     * the single-row live table into {@code $liveSection}. The counter line is updated only when
     * the seed indicator changes (multi-seed runs) so the eye can spot seed transitions.
     *
     * @param array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool} $combo
     */
    private function liveTableCallback(
        ConsoleSectionOutput $counterSection,
        ConsoleSectionOutput $liveSection,
        array $combo,
        int $counterCurrent,
        int $counterTotal
    ): callable {
        $latestPairing = null;
        $latestMatchMaking = null;
        $latestOrdering = null;

        return function (GenerationProgress $event) use (
            $counterSection,
            $liveSection,
            $combo,
            $counterCurrent,
            $counterTotal,
            &$latestPairing,
            &$latestMatchMaking,
            &$latestOrdering
        ): void {
            if ($event instanceof PairingProgress) {
                $latestPairing = $event;
                if ($event->getTotalSeeds() > 1) {
                    $counterSection->overwrite(sprintf(
                        '<info>[%d/%d]</info> pairing seed %d/%d (in progress)',
                        $counterCurrent,
                        $counterTotal,
                        $event->getCurrentSeed(),
                        $event->getTotalSeeds()
                    ));
                }
            } elseif ($event instanceof MatchMakingProgress) {
                $latestMatchMaking = $event;
                if ($event->getTotalSeeds() > 1) {
                    $counterSection->overwrite(sprintf(
                        '<info>[%d/%d]</info> match-making seed %d/%d (in progress)',
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
                $latestMatchMaking,
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
                    '  pairing done |%s nodes %s | best minBal=%s avgBal=%s',
                    $seedTag,
                    number_format($event->getNodesExplored(), 0, '.', ','),
                    $event->getBestMinPartnersFairness() === null ? '-' : number_format($event->getBestMinPartnersFairness(), 4),
                    $event->getBestAvgPartnersFairness() === null ? '-' : number_format($event->getBestAvgPartnersFairness(), 4)
                ));
            } elseif ($event instanceof MatchMakingProgress) {
                $seedTag = $event->getTotalSeeds() > 1
                    ? sprintf(' seed %d/%d |', $event->getCurrentSeed(), $event->getTotalSeeds())
                    : '';
                $output->writeln(sprintf(
                    '  match-making done |%s iter %s | templates %s | best variation %s',
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
