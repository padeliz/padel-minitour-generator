<?php

namespace Arshavinel\PadelMiniTour\Service;

use Arshavinel\PadelMiniTour\Service\Exception\TemplateMatchesNotFoundException;

/**
 * File-backed persistence for {@see TemplateMatches}.
 *
 * Owns versioning and on-disk layout so the generator can stay pure.
 *
 * Layout:
 *
 *     <baseDir>/v{version}/players-{P}-partners-{O}-repeat-{R}-courts-{C}[-fixedteams].json
 *
 * Runtime always reads from {@see TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION} unless an
 * explicit version is requested (see {@see findAt()}). The (re)generate CLI always writes to the next
 * version (`DEFAULT_TEMPLATE_VERSION + 1`). To promote a freshly generated set, commit the new
 * `v{N+1}/` files together with a one-line bump of {@see DEFAULT_TEMPLATE_VERSION}.
 */
final class TemplateMatchesRepository
{
    /**
     * Default template version used by {@see find()} and the stats/(re)generate CLI when no explicit
     * version is supplied. Bump after committing a new `v{N+1}/` directory.
     */
    public const DEFAULT_TEMPLATE_VERSION = 3;

    private string $baseDir;

    /**
     * @param string|null $baseDir absolute path to the directory holding `v{N}/` subfolders.
     *                             Defaults to <repo>/resources/template-matches.
     */
    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir !== null
            ? rtrim($baseDir, "/\\")
            : self::defaultBaseDir();
    }

    public static function defaultBaseDir(): string
    {
        return realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'template-matches';
    }

    /**
     * Loads the template for the given combo from the in-use version directory.
     *
     * @throws TemplateMatchesNotFoundException
     */
    public function find(int $players, int $partners, int $repeat, int $courts, bool $fixedTeams = false): TemplateMatches
    {
        return $this->findAt(self::DEFAULT_TEMPLATE_VERSION, $players, $partners, $repeat, $courts, $fixedTeams);
    }

    /**
     * Loads the template for the given combo from an explicit version directory.
     *
     * @throws TemplateMatchesNotFoundException
     * @throws \RuntimeException When the on-disk identity disagrees with the lookup params.
     */
    public function findAt(
        int $version,
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams = false
    ): TemplateMatches {
        $path = $this->path($version, $players, $partners, $repeat, $courts, $fixedTeams);

        if (!is_file($path) || !is_readable($path)) {
            throw TemplateMatchesNotFoundException::forCombo($path, $players, $partners, $repeat, $courts, $fixedTeams);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw TemplateMatchesNotFoundException::forCombo($path, $players, $partners, $repeat, $courts, $fixedTeams);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw TemplateMatchesNotFoundException::forCombo($path, $players, $partners, $repeat, $courts, $fixedTeams);
        }

        $template = TemplateMatches::fromArray($decoded);

        if (
            $template->getPlayers() !== $players
            || $template->getPartners() !== $partners
            || $template->getRepeat() !== $repeat
            || $template->getCourts() !== $courts
            || $template->isFixedTeams() !== $fixedTeams
        ) {
            throw new \RuntimeException(sprintf(
                'Template identity mismatch in %s: expected players=%d/partners=%d/repeat=%d/courts=%d/fixedTeams=%s, got players=%d/partners=%d/repeat=%d/courts=%d/fixedTeams=%s',
                $path,
                $players,
                $partners,
                $repeat,
                $courts,
                $fixedTeams ? 'true' : 'false',
                $template->getPlayers(),
                $template->getPartners(),
                $template->getRepeat(),
                $template->getCourts(),
                $template->isFixedTeams() ? 'true' : 'false'
            ));
        }

        return $template;
    }

    /**
     * Writes the template under the given version directory, overwriting any existing file.
     */
    public function save(int $version, TemplateMatches $template): void
    {
        $path = $this->path(
            $version,
            $template->getPlayers(),
            $template->getPartners(),
            $template->getRepeat(),
            $template->getCourts(),
            $template->isFixedTeams()
        );

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create template directory: {$dir}");
        }

        $json = json_encode(
            $template->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if ($json === false) {
            throw new \RuntimeException(
                'Could not JSON-encode TemplateMatches: ' . json_last_error_msg()
            );
        }

        if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException("Could not write template file: {$path}");
        }
    }

    /**
     * @return int the number of template files that were removed.
     */
    public function clearVersion(int $version): int
    {
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . 'v' . $version;
        if (!is_dir($dir)) {
            return 0;
        }

        $entries = glob($dir . DIRECTORY_SEPARATOR . 'players-*.json');
        if ($entries === false) {
            return 0;
        }

        $deleted = 0;
        foreach ($entries as $file) {
            if (is_file($file) && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return list<array{version: ?int, directoryName: string, isCompatible: bool}>
     */
    public function listVersions(): array
    {
        if (!is_dir($this->baseDir)) {
            return [];
        }

        $entries = scandir($this->baseDir);
        if ($entries === false) {
            return [];
        }

        $versions = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->baseDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path)) {
                continue;
            }
            if (preg_match('/^v(\d+)$/', $entry, $matches) === 1) {
                $versions[] = [
                    'version'       => (int) $matches[1],
                    'directoryName' => $entry,
                    'isCompatible'  => true,
                ];
            } else {
                $versions[] = [
                    'version'       => null,
                    'directoryName' => $entry,
                    'isCompatible'  => false,
                ];
            }
        }

        usort($versions, static fn(array $a, array $b): int => strnatcmp($a['directoryName'], $b['directoryName']));

        return $versions;
    }

    public function hasAt(
        int $version,
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams = false
    ): bool {
        return is_file($this->path($version, $players, $partners, $repeat, $courts, $fixedTeams));
    }

    /**
     * Lists combo identities parsed from template filenames in a version directory.
     *
     * @param array{
     *     players?: int,
     *     partners?: int,
     *     repeat?: int,
     *     courts?: int,
     *     fixedTeams?: bool,
     *     playersPartners?: array<int, list<int>>
     * } $filters
     * @return list<array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool}>
     */
    public function listComboIdentitiesAt(int $version, array $filters = []): array
    {
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . 'v' . $version;
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . 'players-*.json');
        if ($files === false) {
            return [];
        }

        $pattern = '/^players-(\d+)-partners-(\d+)-repeat-(\d+)-courts-(\d+)(-fixedteams)?\.json$/';
        $combos = [];

        foreach ($files as $file) {
            $basename = basename($file);
            if (preg_match($pattern, $basename, $matches) !== 1) {
                continue;
            }

            $identity = [
                'players' => (int) $matches[1],
                'partners' => (int) $matches[2],
                'repeat' => (int) $matches[3],
                'courts' => (int) $matches[4],
                'fixedTeams' => ($matches[5] ?? '') === '-fixedteams',
            ];

            if (!$this->identityMatchesListFilters($identity, $filters)) {
                continue;
            }

            $combos[] = $identity;
        }

        usort(
            $combos,
            static fn(array $a, array $b): int => [$a['players'], $a['partners'], $a['repeat'], $a['courts'], (int) $a['fixedTeams']]
                <=> [$b['players'], $b['partners'], $b['repeat'], $b['courts'], (int) $b['fixedTeams']]
        );

        return $combos;
    }

    /**
     * @param array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool} $identity
     * @param array{
     *     players?: int,
     *     partners?: int,
     *     repeat?: int,
     *     courts?: int,
     *     fixedTeams?: bool,
     *     playersPartners?: array<int, list<int>>
     * } $filters
     */
    private function identityMatchesListFilters(array $identity, array $filters): bool
    {
        if (isset($filters['repeat']) && $identity['repeat'] !== $filters['repeat']) {
            return false;
        }
        if (isset($filters['courts']) && $identity['courts'] !== $filters['courts']) {
            return false;
        }
        if (isset($filters['fixedTeams']) && $identity['fixedTeams'] !== $filters['fixedTeams']) {
            return false;
        }
        if (isset($filters['players']) && $identity['players'] !== $filters['players']) {
            return false;
        }
        if (isset($filters['partners']) && $identity['partners'] !== $filters['partners']) {
            return false;
        }
        if (isset($filters['playersPartners'])) {
            $map = $filters['playersPartners'];
            if (!isset($map[$identity['players']])) {
                return false;
            }
            if (!in_array($identity['partners'], $map[$identity['players']], true)) {
                return false;
            }
        }

        return true;
    }

    public function path(
        int $version,
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams = false
    ): string {
        $name = sprintf(
            'players-%d-partners-%d-repeat-%d-courts-%d%s.json',
            $players,
            $partners,
            $repeat,
            $courts,
            $fixedTeams ? '-fixedteams' : ''
        );

        return $this->baseDir . DIRECTORY_SEPARATOR . 'v' . $version . DIRECTORY_SEPARATOR . $name;
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }
}
