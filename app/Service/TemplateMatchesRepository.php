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
 *     <baseDir>/v{version}/players-{P}-partners-{O}-repeat-{R}[-fixedteams].json
 *
 * Runtime always reads from {@see TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION} unless an
 * explicit version is requested (see {@see findAt()}). The regenerate CLI always writes to the next
 * version (`DEFAULT_TEMPLATE_VERSION + 1`). To promote a freshly generated set, commit the new
 * `v{N+1}/` files together with a one-line bump of {@see DEFAULT_TEMPLATE_VERSION}.
 */
final class TemplateMatchesRepository
{
    /**
     * Default template version used by {@see find()} and the stats/regenerate CLI when no explicit
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
    public function find(int $players, int $partners, int $repeat, bool $fixedTeams): TemplateMatches
    {
        return $this->findAt(self::DEFAULT_TEMPLATE_VERSION, $players, $partners, $repeat, $fixedTeams);
    }

    /**
     * Loads the template for the given combo from an explicit version directory.
     *
     * Useful for the stats commands and tests that target specific versions without bumping the
     * class constant.
     *
     * Strict on identity: the JSON's `players`/`partners`/`repeat`/`fixedTeams` MUST match the
     * lookup parameters, otherwise a `RuntimeException` is thrown. There is no forward-fill or
     * silent coercion - if the file has the wrong identity it's treated as a broken artifact.
     *
     * @throws TemplateMatchesNotFoundException
     * @throws \RuntimeException When the on-disk identity disagrees with the lookup params.
     */
    public function findAt(int $version, int $players, int $partners, int $repeat, bool $fixedTeams): TemplateMatches
    {
        $path = $this->path($version, $players, $partners, $repeat, $fixedTeams);

        if (!is_file($path) || !is_readable($path)) {
            throw TemplateMatchesNotFoundException::forCombo($path, $players, $partners, $repeat, $fixedTeams);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw TemplateMatchesNotFoundException::forCombo($path, $players, $partners, $repeat, $fixedTeams);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw TemplateMatchesNotFoundException::forCombo($path, $players, $partners, $repeat, $fixedTeams);
        }

        $template = TemplateMatches::fromArray($decoded);

        if (
            $template->getPlayers() !== $players
            || $template->getPartners() !== $partners
            || $template->getRepeat() !== $repeat
            || $template->isFixedTeams() !== $fixedTeams
        ) {
            throw new \RuntimeException(sprintf(
                'Template identity mismatch in %s: expected players=%d/partners=%d/repeat=%d/fixedTeams=%s, got players=%d/partners=%d/repeat=%d/fixedTeams=%s',
                $path,
                $players,
                $partners,
                $repeat,
                $fixedTeams ? 'true' : 'false',
                $template->getPlayers(),
                $template->getPartners(),
                $template->getRepeat(),
                $template->isFixedTeams() ? 'true' : 'false'
            ));
        }

        return $template;
    }

    /**
     * Writes the template under the given version directory, overwriting any existing file.
     *
     * The filename is derived from the template's own identity (players/partners/repeat/fixedTeams),
     * so the DTO is the single source of truth - no chance for the caller to write a template into
     * a misnamed file.
     *
     * Creates intermediate directories as needed.
     */
    public function save(int $version, TemplateMatches $template): void
    {
        $path = $this->path(
            $version,
            $template->getPlayers(),
            $template->getPartners(),
            $template->getRepeat(),
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
     * Deletes every `players-*.json` file in the given version directory.
     *
     * Used by the regenerate CLI's bulk mode so a re-run produces a clean v{N+1}/ directory: stale
     * files from a prior run (e.g. combos no longer in {@see TemplateMatchesGenerator::COMBINATIONS},
     * or files from an interrupted run) are removed before the new ones are written.
     *
     * Only files matching the `players-*.json` glob are deleted; sibling files (READMEs, .gitkeep,
     * etc.) and subdirectories are left alone. A missing directory is treated as a no-op (returns 0)
     * rather than an error so the caller does not need to guard the first-ever run.
     *
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
     * Enumerates every subdirectory under the base path, regardless of name. Non-directory entries
     * (files, symlinks) are skipped.
     *
     * `isCompatible = true` iff `directoryName` matches `/^v(\d+)$/` exactly -- the runtime
     * {@see path()} builder only constructs `v{N}/...` paths, so any other directory name (e.g.
     * `v1-no-compatibility/`, `v2-experimental/`, `foo/`) is unreachable for {@see find()} /
     * {@see findAt()} and reported with `isCompatible = false`. The `version` field is the integer
     * captured from the bare `v{N}` form, or `null` when the directory name doesn't match that
     * shape -- callers use it as the URL value for selectable rows; disabled rows ignore it.
     *
     * Natural-sorted by `directoryName` (so `v2` precedes `v10`, and incompatible rows interleave
     * deterministically).
     *
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

    /**
     * Probe (without throwing) whether the bare `v{N}/` directory has the requested combo on disk.
     *
     * Always returns `false` for any directory layout other than the bare `v{N}` form, because
     * {@see path()} only constructs `v{N}/...` paths -- non-bare directories (e.g.
     * `v1-no-compatibility/`, `v2-experimental/`) are simply unreachable through this method.
     */
    public function hasAt(int $version, int $players, int $partners, int $repeat, bool $fixedTeams): bool
    {
        return is_file($this->path($version, $players, $partners, $repeat, $fixedTeams));
    }

    public function path(int $version, int $players, int $partners, int $repeat, bool $fixedTeams): string
    {
        $name = sprintf(
            'players-%d-partners-%d-repeat-%d%s.json',
            $players,
            $partners,
            $repeat,
            $fixedTeams ? '-fixedteams' : ''
        );

        return $this->baseDir . DIRECTORY_SEPARATOR . 'v' . $version . DIRECTORY_SEPARATOR . $name;
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }
}
