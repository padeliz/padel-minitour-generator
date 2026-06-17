<?php

namespace Tests\Unit\Console;

use Arshavinel\PadelMiniTour\Console\TemplateComboResolver;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

require_once __DIR__ . '/../../../vendor/autoload.php';

final class TemplateComboResolverTest extends TestCase
{
    public function test_no_filters_expands_full_combinations_with_defaults(): void
    {
        $resolver = new TemplateComboResolver();
        $input = $this->makeInput([]);

        $resolved = $resolver->resolve($input, TemplateMatchesGenerator::COMBINATIONS, false);

        $this->assertTrue($resolved['isFullBulk']);
        $this->assertCount(22, $resolved['combos']);
        $this->assertSame(1, $resolved['combos'][0]['repeat']);
        $this->assertSame(1, $resolved['combos'][0]['courts']);
        $this->assertFalse($resolved['combos'][0]['fixedTeams']);
    }

    public function test_courts_filter_only_sets_courts_on_all_combos(): void
    {
        $resolver = new TemplateComboResolver();
        $input = $this->makeInput(['--courts' => '2']);

        $resolved = $resolver->resolve($input, [12 => [8]], false);

        $this->assertFalse($resolved['isFullBulk']);
        $this->assertCount(1, $resolved['combos']);
        $this->assertSame(2, $resolved['combos'][0]['courts']);
        $this->assertSame(12, $resolved['combos'][0]['players']);
        $this->assertSame(8, $resolved['combos'][0]['partners']);
    }

    public function test_players_and_partners_filter_resolves_single_combo(): void
    {
        $resolver = new TemplateComboResolver();
        $input = $this->makeInput([
            '--players' => '4',
            '--partners' => '1',
        ]);

        $resolved = $resolver->resolve($input, TemplateMatchesGenerator::COMBINATIONS, false);

        $this->assertCount(1, $resolved['combos']);
        $this->assertSame([4, 1, 1, 1, false], array_values([
            $resolved['combos'][0]['players'],
            $resolved['combos'][0]['partners'],
            $resolved['combos'][0]['repeat'],
            $resolved['combos'][0]['courts'],
            $resolved['combos'][0]['fixedTeams'],
        ]));
    }

    public function test_unknown_players_throws(): void
    {
        $resolver = new TemplateComboResolver();
        $input = $this->makeInput(['--players' => '99']);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolve($input, TemplateMatchesGenerator::COMBINATIONS, false);
    }

    public function test_fixed_teams_default_true_for_stats_fixed_command(): void
    {
        $resolver = new TemplateComboResolver();
        $input = $this->makeInput(['--players' => '8', '--partners' => '2']);

        $resolved = $resolver->resolve($input, [8 => [2, 3]], true);

        $this->assertTrue($resolved['combos'][0]['fixedTeams']);
    }

    /**
     * @param array<string, string> $options
     */
    private function makeInput(array $options): ArrayInput
    {
        $definition = new InputDefinition([
            new InputOption('players', null, InputOption::VALUE_REQUIRED),
            new InputOption('partners', null, InputOption::VALUE_REQUIRED),
            new InputOption('repeat', null, InputOption::VALUE_REQUIRED),
            new InputOption('fixed-teams', null, InputOption::VALUE_REQUIRED),
            new InputOption('courts', null, InputOption::VALUE_REQUIRED),
        ]);

        return new ArrayInput($options, $definition);
    }
}
