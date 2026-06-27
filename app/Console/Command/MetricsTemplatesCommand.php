<?php

namespace Arshavinel\PadelMiniTour\Console\Command;

use Arshavinel\PadelMiniTour\Console\MetricsFormatterTrait;
use Arshavinel\PadelMiniTour\Service\Exception\TemplateMatchesNotFoundException;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only metrics over the in-use mixed-teams templates.
 */
final class MetricsTemplatesCommand extends Command
{
    use MetricsFormatterTrait;

    protected static $defaultName = 'templates:metrics';

    private TemplateMatchesRepository $repository;

    public function __construct(?TemplateMatchesRepository $repository = null)
    {
        parent::__construct();
        $this->repository = $repository ?? new TemplateMatchesRepository();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Read-only metrics over committed mixed-teams templates.')
            ->setHelp(implode("\n", [
                'Iterates TemplateMatchesGenerator::COMBINATIONS by default. Override with',
                '--combinations="players:partners1,partners2 players:partners1" (space-separated pairs).',
                'Any subset of --players, --partners, --repeat, --fixed-teams, --courts lists only',
                'matching JSON files on disk (defaults: repeat=1, courts=1, fixed-teams=no).',
                'Requires --templates-version=N to select the version directory to read.',
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
                'Templates version directory to read (required)',
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
        $combinations = $this->parseMetricsCombinations($input->getOption('combinations'))
            ?? TemplateMatchesGenerator::COMBINATIONS;

        try {
            $version = $this->parseRequiredTemplateVersion($input->getOption('templates-version'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $filteredDiscovery = $this->hasMetricsComboFilters($input);

        try {
            $combos = $this->resolveMetricsCombos($input, $this->repository, $version, $combinations, false);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $latestVersion = null;
        try {
            $latestVersion = $this->repository->latestVersion();
        } catch (\RuntimeException $e) {
            // Empty or freshly created base dirs may have no v{N}/ yet.
        }

        if ($latestVersion === null) {
            $versionLabel = sprintf('v%d', $version);
        } elseif ($version === $latestVersion) {
            $versionLabel = sprintf('v%d <comment>(latest)</comment>', $version);
        } else {
            $versionLabel = sprintf('v%d <comment>(latest: v%d)</comment>', $version, $latestVersion);
        }

        if ($combos === []) {
            $output->writeln(sprintf(
                '<info>Reading:</info> %s   <info>Base dir:</info> %s   <info>Combos:</info> 0',
                $versionLabel,
                $this->repository->getBaseDir()
            ));
            $io->warning(sprintf('No template files match the provided filters under v%d.', $version));

            return 0;
        }

        $output->writeln(sprintf(
            '<info>Reading:</info> %s   <info>Base dir:</info> %s   <info>Combos:</info> %d',
            $versionLabel,
            $this->repository->getBaseDir(),
            count($combos)
        ));
        $output->writeln('');

        $loadedTemplates = [];
        $renderRows = [];
        $missing = 0;

        foreach ($combos as $combo) {
            try {
                $template = $this->repository->findAt(
                    $version,
                    $combo['players'],
                    $combo['partners'],
                    $combo['repeat'],
                    $combo['courts'],
                    $combo['fixedTeams']
                );
                $loadedTemplates[] = $template;
                $renderRows[] = ['combo' => $combo, 'template' => $template];
            } catch (TemplateMatchesNotFoundException $e) {
                if (!$filteredDiscovery) {
                    $missing++;
                    $loadedTemplates[] = null;
                    $renderRows[] = ['combo' => $combo, 'template' => null];
                }
            }
        }

        $layout = $this->resolveUnifiedTableLayout($combos, $loadedTemplates);
        $table = $this->makeUnifiedTable($output);
        $table->setHeaders($this->unifiedHeaders($layout));
        $this->writeTableContextLine($output, $layout);
        $output->writeln('');

        $minPartnersFairs = [];
        $avgPartnersFairs = [];
        $partnersVars = [];
        $meetingsVars = [];
        $mins = [];
        $avgs = [];
        $previousPlayers = null;

        foreach ($renderRows as $row) {
            $combo = $row['combo'];
            $template = $row['template'];
            $firstOfGroup = $previousPlayers !== $combo['players'];
            if ($firstOfGroup && $previousPlayers !== null) {
                $table->addRow(array_fill(0, $layout['totalColumns'], new TableSeparator()));
            }

            if ($template !== null) {
                $minPartnersFairs[] = $template->getPairingQualityMinPartnersFairness();
                $avgPartnersFairs[] = $template->getPairingQualityAvgPartnersFairness();
                $partnersVars[] = $template->getPairingQualityPartnersCountVariation();
                $meetingsVars[] = $template->getMatchMakingQualityMeetingsVariation();
                $mins[] = $template->getOrderingQualityMinDistribution();
                $avgs[] = $template->getOrderingQualityAvgDistribution();
            }

            $table->addRow($this->buildUnifiedRow(
                $template,
                $combo['players'],
                $combo['partners'],
                $firstOfGroup,
                $layout
            ));

            $previousPlayers = $combo['players'];
        }

        $table->addRow(array_fill(0, $layout['totalColumns'], new TableSeparator()));
        $table->addRow($this->buildAvgRow($layout, $minPartnersFairs, $avgPartnersFairs, $partnersVars, $meetingsVars, $mins, $avgs));

        $table->render();

        if ($missing > 0) {
            $output->writeln(sprintf(
                '<error>%d template(s) missing under v%d.</error>',
                $missing,
                $version
            ));
            $output->writeln('Run <comment>php bin/console templates:regenerate --templates-version=N</comment> to (re)generate them.');
        }

        return $missing;
    }
}
