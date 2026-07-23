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
 * Runtime loads the latest compatible `v{N}/` via {@see find()} unless an explicit version is
 * requested (see {@see findAt()}). CLI write commands require `--templates-version=N`.
 */
final class TemplateMatchesRepository
{
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
     * Loads the template for the given combo from the highest compatible version directory.
     *
     * @throws TemplateMatchesNotFoundException
     * @throws \RuntimeException When no compatible version directory exists.
     */
    public function find(int $players, int $partners, int $repeat, int $courts, bool $fixedTeams = false): TemplateMatches
    {
        return $this->findAt($this->latestVersion(), $players, $partners, $repeat, $courts, $fixedTeams);
    }

    /**
     * Highest numeric `v{N}/` directory under baseDir (compatible entries only).
     *
     * @throws \RuntimeException When no compatible version directory exists.
     */
    public function latestVersion(): int
    {
        $max = 0;
        foreach ($this->listVersions() as $entry) {
            if ($entry['isCompatible'] && $entry['version'] !== null) {
                $max = max($max, $entry['version']);
            }
        }
        if ($max === 0) {
            throw new \RuntimeException('No compatible template version directory found.');
        }

        return $max;
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
     * Ensures `v{version}/` exists under the base directory (creates it when missing).
     */
    public function ensureVersionDirectory(int $version): void
    {
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . 'v' . $version;
        if (is_dir($dir)) {
            return;
        }
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create template version directory: {$dir}");
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

    /**
     * Resolves exactly one on-disk directory for version N matching `^v{N}(-|$)`.
     *
     * @return array{directoryName: string, version: int, isClean: bool, absolutePath: string}
     * @throws \RuntimeException when 0 or 2+ dirs match, or when a suffix source would collide with clean v{N}
     */
    public function resolveVersionSourceDirectory(int $version): array
    {
        if ($version < 1) {
            throw new \InvalidArgumentException('Template version must be a positive integer.');
        }

        if (!is_dir($this->baseDir)) {
            throw new \RuntimeException(sprintf(
                'No template directory matching v%d found under %s.',
                $version,
                $this->baseDir
            ));
        }

        $entries = scandir($this->baseDir);
        if ($entries === false) {
            throw new \RuntimeException(sprintf('Could not read template base directory: %s', $this->baseDir));
        }

        $pattern = '/^v' . $version . '(-|$)/';
        $matches = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->baseDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path)) {
                continue;
            }
            if (preg_match($pattern, $entry) === 1) {
                $matches[] = $entry;
            }
        }

        if ($matches === []) {
            throw new \RuntimeException(sprintf(
                'No template directory matching v%d found under %s.',
                $version,
                $this->baseDir
            ));
        }
        if (count($matches) > 1) {
            sort($matches, SORT_STRING);
            throw new \RuntimeException(sprintf(
                'Ambiguous template directories for v%d: %s. Keep exactly one of them.',
                $version,
                implode(', ', $matches)
            ));
        }

        $directoryName = $matches[0];
        $isClean = $directoryName === 'v' . $version;
        $absolutePath = $this->baseDir . DIRECTORY_SEPARATOR . $directoryName;

        if (!$isClean) {
            $cleanPath = $this->baseDir . DIRECTORY_SEPARATOR . 'v' . $version;
            if (is_dir($cleanPath)) {
                throw new \RuntimeException(sprintf(
                    'Cannot migrate %s: clean directory v%d already exists.',
                    $directoryName,
                    $version
                ));
            }
        }

        return [
            'directoryName' => $directoryName,
            'version' => $version,
            'isClean' => $isClean,
            'absolutePath' => $absolutePath,
        ];
    }

    /**
     * Lists template JSON files in an arbitrary version directory (clean or suffixed).
     * Accepts both current (`-courts-N`) and legacy (no courts) filenames.
     *
     * @param array{
     *     players?: int,
     *     partners?: int,
     *     repeat?: int,
     *     courts?: int,
     *     fixedTeams?: bool
     * } $filters
     * @return list<array{
     *     absolutePath: string,
     *     basename: string,
     *     players: int,
     *     partners: int,
     *     repeat: int,
     *     courts: int,
     *     fixedTeams: bool,
     *     isLegacyFilename: bool
     * }>
     */
    public function listTemplateFilesInDirectory(string $directoryName, array $filters = []): array
    {
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $directoryName;
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . 'players-*.json');
        if ($files === false) {
            return [];
        }

        $currentPattern = '/^players-(\d+)-partners-(\d+)-repeat-(\d+)-courts-(\d+)(-fixedteams)?\.json$/';
        $legacyPattern = '/^players-(\d+)-partners-(\d+)-repeat-(\d+)(-fixedteams)?\.json$/';
        $out = [];

        foreach ($files as $file) {
            $basename = basename($file);
            $identity = null;
            $isLegacyFilename = false;

            if (preg_match($currentPattern, $basename, $m) === 1) {
                $identity = [
                    'players' => (int) $m[1],
                    'partners' => (int) $m[2],
                    'repeat' => (int) $m[3],
                    'courts' => (int) $m[4],
                    'fixedTeams' => ($m[5] ?? '') === '-fixedteams',
                ];
            } elseif (preg_match($legacyPattern, $basename, $m) === 1) {
                $isLegacyFilename = true;
                $identity = [
                    'players' => (int) $m[1],
                    'partners' => (int) $m[2],
                    'repeat' => (int) $m[3],
                    'courts' => 1,
                    'fixedTeams' => ($m[4] ?? '') === '-fixedteams',
                ];
            } else {
                continue;
            }

            if (!$this->identityMatchesListFilters($identity, $filters)) {
                continue;
            }

            $out[] = array_merge($identity, [
                'absolutePath' => $file,
                'basename' => $basename,
                'isLegacyFilename' => $isLegacyFilename,
            ]);
        }

        usort(
            $out,
            static fn(array $a, array $b): int => [$a['players'], $a['partners'], $a['repeat'], $a['courts'], (int) $a['fixedTeams']]
                <=> [$b['players'], $b['partners'], $b['repeat'], $b['courts'], (int) $b['fixedTeams']]
        );

        return $out;
    }

    /**
     * Canonical filename for a combo identity (current schema pattern).
     */
    public function filenameForIdentity(
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams = false
    ): string {
        return sprintf(
            'players-%d-partners-%d-repeat-%d-courts-%d%s.json',
            $players,
            $partners,
            $repeat,
            $courts,
            $fixedTeams ? '-fixedteams' : ''
        );
    }

    /**
     * Overwrites a template JSON file at an absolute path (no mkdir of version dirs).
     */
    public function writeTemplateFile(string $absolutePath, TemplateMatches $template): void
    {
        $json = json_encode(
            $template->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if ($json === false) {
            throw new \RuntimeException(
                'Could not JSON-encode TemplateMatches: ' . json_last_error_msg()
            );
        }

        if (file_put_contents($absolutePath, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException("Could not write template file: {$absolutePath}");
        }
    }

    /**
     * Renames a file within the same directory. Fails if the target already exists.
     */
    public function renameTemplateFile(string $fromAbsolutePath, string $toAbsolutePath): void
    {
        if ($fromAbsolutePath === $toAbsolutePath) {
            return;
        }
        if (!is_file($fromAbsolutePath)) {
            throw new \RuntimeException("Cannot rename missing template file: {$fromAbsolutePath}");
        }
        if (is_file($toAbsolutePath)) {
            throw new \RuntimeException(sprintf(
                'Cannot rename %s to %s: target already exists.',
                basename($fromAbsolutePath),
                basename($toAbsolutePath)
            ));
        }
        if (!@rename($fromAbsolutePath, $toAbsolutePath)) {
            throw new \RuntimeException(sprintf(
                'Failed to rename template file %s → %s.',
                $fromAbsolutePath,
                $toAbsolutePath
            ));
        }
    }

    /**
     * Renames a version directory under baseDir (e.g. v1-no-compatibility → v1).
     */
    public function renameVersionDirectory(string $fromDirectoryName, string $toDirectoryName): void
    {
        if ($fromDirectoryName === $toDirectoryName) {
            return;
        }

        $from = $this->baseDir . DIRECTORY_SEPARATOR . $fromDirectoryName;
        $to = $this->baseDir . DIRECTORY_SEPARATOR . $toDirectoryName;

        if (!is_dir($from)) {
            throw new \RuntimeException("Cannot rename missing version directory: {$from}");
        }
        if (file_exists($to)) {
            throw new \RuntimeException(sprintf(
                'Cannot rename %s to %s: target already exists.',
                $fromDirectoryName,
                $toDirectoryName
            ));
        }
        if (!@rename($from, $to)) {
            throw new \RuntimeException(sprintf(
                'Failed to rename version directory %s → %s.',
                $fromDirectoryName,
                $toDirectoryName
            ));
        }
    }
}
