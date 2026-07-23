<?php

namespace Arshavinel\PadelMiniTour\Console\Command;

use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only inspection of available template-matches version directories.
 */
final class TemplatesVersionsCommand extends Command
{
    protected static $defaultName = 'templates:versions';

    private TemplateMatchesRepository $repository;

    public function __construct(?TemplateMatchesRepository $repository = null)
    {
        parent::__construct();
        $this->repository = $repository ?? new TemplateMatchesRepository();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Lists available template-matches versions and reports catalog coverage.')
            ->setHelp(implode("\n", [
                'Inspects resources/template-matches/* template version directories.',
                'The command is read-only and does not require any generation to be performed.',
            ]));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $versions = $this->repository->listVersions();
        if ($versions === []) {
            $io->info('No template version directories found.');

            return 0;
        }

        $latestVersion = null;
        try {
            $latestVersion = $this->repository->latestVersion();
        } catch (\RuntimeException $e) {
            // No compatible version directories exist yet; keep command usable for diagnostics.
        }

        $latestLabel = $latestVersion === null ? '(none)' : 'v' . $latestVersion;
        $io->writeln(sprintf(
            'Base dir: %s   Latest: %s',
            $this->repository->getBaseDir(),
            $latestLabel
        ));

        if ($latestVersion === null) {
            $io->warning('No compatible v{N}/ directory found. Incompatible dirs are shown below.');
        }

        $expectedKeys = $this->expectedCatalogKeys();
        $expectedCount = count($expectedKeys);

        $table = new Table($output);
        $table->setHeaders(['Directory', 'Compatible', 'Latest', 'Files', 'Catalog', 'Missing', 'Extra', 'Demo']);

        foreach ($versions as $entry) {
            $dirName = $entry['directoryName'];
            $dirPath = $this->repository->getBaseDir() . DIRECTORY_SEPARATOR . $dirName;
            $filesCount = $this->countPlayersJsonFiles($dirPath);

            $compatibleLabel = $entry['isCompatible'] ? 'yes' : 'no';
            $latestCell = $this->latestCellForEntry($entry, $latestVersion);

            $coverageCells = $this->coverageCellsForEntry($entry, $expectedKeys, $expectedCount);
            $catalog = $coverageCells['catalog'];
            $missing = $coverageCells['missing'];
            $extra = $coverageCells['extra'];
            $demoPath = $this->demoPathForEntry($entry);

            $table->addRow([
                $dirName,
                $compatibleLabel,
                $latestCell,
                (string) $filesCount,
                $catalog,
                $missing,
                $extra,
                $demoPath,
            ]);
        }

        $table->render();

        if ($latestVersion !== null) {
            $io->writeln(sprintf(
                'Inspect metrics: php bin/console templates:metrics --templates-version=%d',
                $latestVersion
            ));
        }

        return 0;
    }

    /**
     * @return array<string, true> set of expected identity keys
     */
    private function expectedCatalogKeys(): array
    {
        $keys = [];
        foreach (TemplateMatchesGenerator::COMBINATIONS as $players => $partnersList) {
            foreach ($partnersList as $partners) {
                $identity = [
                    'players' => (int) $players,
                    'partners' => (int) $partners,
                    'repeat' => 1,
                    'courts' => 1,
                    'fixedTeams' => false,
                ];

                $keys[$this->comboKey($identity)] = true;
            }
        }

        return $keys;
    }

    /**
     * @param array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool} $identity
     */
    private function comboKey(array $identity): string
    {
        return sprintf(
            '%d|%d|%d|%d|%d',
            $identity['players'],
            $identity['partners'],
            $identity['repeat'],
            $identity['courts'],
            $identity['fixedTeams'] ? 1 : 0
        );
    }

    private function countPlayersJsonFiles(string $dirPath): int
    {
        if (!is_dir($dirPath)) {
            return 0;
        }

        $files = glob($dirPath . DIRECTORY_SEPARATOR . 'players-*.json');
        if ($files === false) {
            return 0;
        }

        return count($files);
    }

    /**
     * @param array{version:?int,isCompatible:bool} $entry
     */
    private function demoPathForEntry(array $entry): string
    {
        if (!$entry['isCompatible'] || $entry['version'] === null) {
            return '—';
        }

        return '/templates/demo/' . $entry['version'];
    }

    /**
     * @param array{version:?int,isCompatible:bool} $entry
     */
    private function latestCellForEntry(array $entry, ?int $latestVersion): string
    {
        if ($latestVersion === null) {
            return '';
        }

        if (!$entry['isCompatible'] || $entry['version'] === null) {
            return '';
        }

        return $entry['version'] === $latestVersion ? '*' : '';
    }

    /**
     * @param array{version:?int,isCompatible:bool} $entry
     * @return array{catalog:string,missing:string,extra:string}
     */
    private function coverageCellsForEntry(array $entry, array $expectedKeys, int $expectedCount): array
    {
        if (!$entry['isCompatible'] || $entry['version'] === null) {
            return ['catalog' => '—', 'missing' => '—', 'extra' => '—'];
        }

        $coverage = $this->coverageForVersion($entry['version'], $expectedKeys, $expectedCount);

        return [
            'catalog' => sprintf('%d/%d', $coverage['present'], $coverage['expected']),
            'missing' => (string) $coverage['missing'],
            'extra' => (string) $coverage['extra'],
        ];
    }

    /**
     * @param array<string, true> $expectedKeys
     * @return array{files:int,present:int,expected:int,missing:int,extra:int}
     */
    private function coverageForVersion(
        int $version,
        array $expectedKeys,
        int $expectedCount
    ): array {
        $dirPath = $this->repository->getBaseDir() . DIRECTORY_SEPARATOR . 'v' . $version;
        $filesCount = 0;
        if (is_dir($dirPath)) {
            $files = glob($dirPath . DIRECTORY_SEPARATOR . 'players-*.json');
            if ($files !== false) {
                $filesCount = count($files);
            }
        }

        $diskKeys = [];
        foreach ($this->repository->listComboIdentitiesAt($version) as $identity) {
            $diskKeys[$this->comboKey($identity)] = true;
        }

        $present = 0;
        foreach ($expectedKeys as $key => $_) {
            if (isset($diskKeys[$key])) {
                $present++;
            }
        }

        $missing = $expectedCount - $present;
        $extra = count($diskKeys) - $present;

        return [
            'files' => $filesCount,
            'present' => $present,
            'expected' => $expectedCount,
            'missing' => $missing,
            'extra' => $extra,
        ];
    }
}

