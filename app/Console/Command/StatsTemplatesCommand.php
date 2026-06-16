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
            ?? TemplateMatchesGenerator::COMBINATIONS;
        $version = $this->parseVersion($input->getOption('templates-version'));

        $table = $this->makeUnifiedTable($output);
        $table->setHeaders($this->unifiedHeaders(1, false));

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
                    $template = $this->repository->findAt($version, (int) $players, (int) $opponentsPerPlayer, 1, false);
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
     * Builds the AVG aggregate row (last row in the table). The 3 identity cells are blank; the
     * remaining cells average the per-combo values where it makes sense (Partners Var.,
     * Meetings Var., Min/Avg Distribution) and stay blank for index columns, stop reasons, the
     * two per-phase Time columns, and the two Min/Max Break columns where averaging is not
     * meaningful or no longer reported in the footer.
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
     * Parses an input like "8:2,3 12:6,7" into the same shape as
     * TemplateMatchesGenerator::COMBINATIONS.
     *
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
