<?php

namespace Tests\Unit\Console;

use Arshavinel\PadelMiniTour\Console\Command\GenerateTemplatesCommand;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Unit\TemplateVersionTestTrait;

final class GenerateTemplatesCommandTest extends TestCase
{
    use TemplateVersionTestTrait;

    private string $tempBaseDir;

    protected function setUp(): void
    {
        $this->resetAllocatedVersions();
        $this->tempBaseDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'padel-generate-test-'
            . bin2hex(random_bytes(4));
        if (!mkdir($this->tempBaseDir, 0775, true) && !is_dir($this->tempBaseDir)) {
            $this->fail("Could not create temp dir: {$this->tempBaseDir}");
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tempBaseDir);
    }

    public function test_missing_templates_version_exits_one(): void
    {
        $tester = $this->makeTester(new RecordingGenerator());
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--templates-version', $tester->getDisplay());
    }

    public function test_partial_players_and_partners_filter_resolves_subset(): void
    {
        $generator = new RecordingGenerator();
        $tester = $this->makeTester($generator);

        $writeVersion = $this->generateExecute($tester, [
            '--players' => '12',
            '--partners' => '8',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertCount(1, $generator->calls);
        $this->assertSame(
            ['players' => 12, 'partners' => 8, 'repeat' => 1, 'courts' => 1, 'fixedTeams' => false],
            $generator->calls[0]
        );

        $expected = $this->tempBaseDir
            . DIRECTORY_SEPARATOR . 'v' . $writeVersion
            . DIRECTORY_SEPARATOR . 'players-12-partners-8-repeat-1-courts-1.json';
        $this->assertFileExists($expected);
    }

    public function test_single_combo_mode_writes_to_explicit_version_when_target_is_missing(): void
    {
        $generator = new RecordingGenerator();
        $tester = $this->makeTester($generator);

        $writeVersion = $this->generateExecute($tester, [
            '--players' => '4',
            '--partners' => '1',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertCount(1, $generator->calls);

        $expected = $this->tempBaseDir
            . DIRECTORY_SEPARATOR . 'v' . $writeVersion
            . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1-courts-1.json';
        $this->assertFileExists($expected);
    }

    public function test_single_combo_mode_is_noop_when_target_already_exists(): void
    {
        $writeVersion = $this->allocVersion();
        $targetDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1-courts-1.json';
        $sentinel = '{"sentinel": true}';
        file_put_contents($targetPath, $sentinel);

        $generator = new RecordingGenerator();
        $tester = $this->makeTester($generator);
        $this->generateExecute($tester, [
            '--players' => '4',
            '--partners' => '1',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ], $writeVersion);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame($sentinel, file_get_contents($targetPath));
        $this->assertSame([], $generator->calls);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('already exists', $output);
        $this->assertStringContainsString('templates:regenerate with the same filters to overwrite', $output);
    }

    public function test_bulk_mode_generates_only_missing_combos(): void
    {
        $writeVersion = $this->allocVersion();
        $writeDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;
        if (!is_dir($writeDir)) {
            mkdir($writeDir, 0775, true);
        }
        $preservedPath = $writeDir . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1-courts-1.json';
        $sentinel = '{"sentinel": "preserved"}';
        file_put_contents($preservedPath, $sentinel);

        $generator = new RecordingGenerator();
        $tester = $this->makeTester($generator);
        $this->generateExecute($tester, [], $writeVersion);

        $this->assertSame($sentinel, file_get_contents($preservedPath));

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Skipped 1 combo', $output);

        $expectedTotal = 0;
        foreach (TemplateMatchesGenerator::COMBINATIONS as $partnersList) {
            $expectedTotal += count($partnersList);
        }
        $this->assertCount($expectedTotal - 1, $generator->calls);

        $produced = glob($writeDir . DIRECTORY_SEPARATOR . 'players-*.json');
        $this->assertCount($expectedTotal, $produced);
    }

    public function test_bulk_mode_is_fully_noop_when_all_combos_already_present(): void
    {
        $writeVersion = $this->allocVersion();
        $writeDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;
        if (!is_dir($writeDir)) {
            mkdir($writeDir, 0775, true);
        }

        $sentinels = [];
        foreach (TemplateMatchesGenerator::COMBINATIONS as $players => $partnersList) {
            foreach ($partnersList as $partners) {
                $path = $writeDir . DIRECTORY_SEPARATOR . sprintf(
                    'players-%d-partners-%d-repeat-1-courts-1.json',
                    $players,
                    $partners
                );
                $bytes = sprintf('{"sentinel": "p%d-q%d"}', $players, $partners);
                file_put_contents($path, $bytes);
                $sentinels[$path] = $bytes;
            }
        }

        $generator = new RecordingGenerator();
        $tester = $this->makeTester($generator);
        $this->generateExecute($tester, [], $writeVersion);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([], $generator->calls);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Nothing to generate', $output);

        foreach ($sentinels as $path => $bytes) {
            $this->assertSame($bytes, file_get_contents($path), "File mutated: {$path}");
        }
    }

    public function test_bulk_mode_does_not_wipe_unrelated_sibling_files(): void
    {
        $writeVersion = $this->allocVersion();
        $writeDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;
        if (!is_dir($writeDir)) {
            mkdir($writeDir, 0775, true);
        }
        $readmePath = $writeDir . DIRECTORY_SEPARATOR . 'README.md';
        $foreignPath = $writeDir . DIRECTORY_SEPARATOR . 'players-99-partners-3-repeat-1-courts-1.json';
        file_put_contents($readmePath, '# notes');
        file_put_contents($foreignPath, '{"foreign": true}');

        $generator = new RecordingGenerator();
        $tester = $this->makeTester($generator);
        $this->generateExecute($tester, [], $writeVersion);

        $this->assertFileExists($readmePath);
        $this->assertSame('# notes', file_get_contents($readmePath));
        $this->assertFileExists($foreignPath);
        $this->assertSame('{"foreign": true}', file_get_contents($foreignPath));
        $this->assertGreaterThan(0, count($generator->calls));
    }

    public function test_invalid_fixed_teams_value_throws(): void
    {
        $tester = $this->makeTester(new RecordingGenerator());

        $this->generateExecute($tester, [
            '--players' => '4',
            '--partners' => '1',
            '--repeat' => '1',
            '--fixed-teams' => 'maybe',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid boolean value', $tester->getDisplay());
    }

    private function makeTester(RecordingGenerator $generator): CommandTester
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $command = new GenerateTemplatesCommand($generator, $repository);
        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('templates:generate'));
    }

    private function generateExecute(CommandTester $tester, array $args = [], ?int $writeVersion = null): int
    {
        $writeVersion ??= $this->allocVersion();
        $tester->execute(array_merge(['--templates-version' => (string) $writeVersion], $args));

        return $writeVersion;
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
