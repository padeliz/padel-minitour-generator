<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

final class TemplateMatchesGeneratorSortMatchesTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->resetSortMatchesTestHooks();
        parent::tearDown();
    }

    public function test_sort_matches_matches_brute_force_when_wall_budget_allows_full_scan_eight_players(): void
    {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        /** @var TemplateMatchesGenerator $generator */
        $generator = $reflection->newInstanceWithoutConstructor();

        $this->setSortMatchesWallBudgetNs(60_000_000_000);
        $this->setSortMatchesClock(static function (): int {
            return 0;
        });

        $matches = [
            [[7, 5], [0, 3]],
            [[5, 7], [2, 4]],
            [[2, 1], [0, 5]],
            [[0, 5], [1, 4]],
        ];

        $mockPlayers = range(0, 7);

        $sortMatches = $reflection->getMethod('sortMatches');
        $sortMatches->setAccessible(true);

        $best = $sortMatches->invoke($generator, $matches, $mockPlayers);

        $expected = $this->bruteForceBestOrder($matches, $mockPlayers, $generator, $reflection);
        $this->assertSame($expected, $best);
    }

    public function test_sort_matches_with_zero_wall_budget_returns_input_order_without_scoring(): void
    {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        /** @var TemplateMatchesGenerator $generator */
        $generator = $reflection->newInstanceWithoutConstructor();

        $this->setSortMatchesWallBudgetNs(0);
        $this->setSortMatchesClock(static function (): int {
            return 0;
        });

        $matches = [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 3]],
            [[0, 3], [1, 2]],
        ];

        $sortMatches = $reflection->getMethod('sortMatches');
        $sortMatches->setAccessible(true);

        $out = $sortMatches->invoke($generator, $matches, [0, 1, 2, 3]);

        $this->assertSame($matches, $out);
    }

    public function test_sort_matches_matches_brute_force_when_wall_budget_allows_full_scan_four_players(): void
    {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        /** @var TemplateMatchesGenerator $generator */
        $generator = $reflection->newInstanceWithoutConstructor();

        $this->setSortMatchesWallBudgetNs(60_000_000_000);
        $this->setSortMatchesClock(static function (): int {
            return 0;
        });

        $matches = [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 3]],
            [[0, 3], [1, 2]],
        ];

        $mockPlayers = [0, 1, 2, 3];

        $sortMatches = $reflection->getMethod('sortMatches');
        $sortMatches->setAccessible(true);

        $best = $sortMatches->invoke($generator, $matches, $mockPlayers);

        $expected = $this->bruteForceBestOrder($matches, $mockPlayers, $generator, $reflection);
        $this->assertSame($expected, $best);
    }

    public function test_multistart_local_search_is_deterministic_for_twelve_matches(): void
    {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        /** @var TemplateMatchesGenerator $generator */
        $generator = $reflection->newInstanceWithoutConstructor();

        $this->setSortMatchesWallBudgetNs(5_000_000_000);
        $this->setSortMatchesClock(static function (): int {
            return 0;
        });

        $matches = $this->makeSyntheticMatchesTwelve();

        $mockPlayers = range(0, 11);

        $sortMatches = $reflection->getMethod('sortMatches');
        $sortMatches->setAccessible(true);

        $first = $sortMatches->invoke($generator, $matches, $mockPlayers);
        $second = $sortMatches->invoke($generator, $matches, $mockPlayers);

        $this->assertSame($first, $second);
    }

    public function test_multistart_local_search_does_not_worsen_minimum_distribution_vs_identity(): void
    {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        /** @var TemplateMatchesGenerator $generator */
        $generator = $reflection->newInstanceWithoutConstructor();

        $this->setSortMatchesWallBudgetNs(5_000_000_000);
        $this->setSortMatchesClock(static function (): int {
            return 0;
        });

        $matches = $this->makeSyntheticMatchesTwelve();
        $mockPlayers = range(0, 11);

        $scoreDistribution = $reflection->getMethod('scoreMatchOrderDistribution');
        $scoreDistribution->setAccessible(true);

        $identityScores = $scoreDistribution->invoke($generator, $matches, $mockPlayers);

        $sortMatches = $reflection->getMethod('sortMatches');
        $sortMatches->setAccessible(true);

        $optimized = $sortMatches->invoke($generator, $matches, $mockPlayers);
        $optimizedScores = $scoreDistribution->invoke($generator, $optimized, $mockPlayers);

        $this->assertGreaterThanOrEqual($identityScores['min'], $optimizedScores['min']);
    }

    /**
     * @return array<int, array<int, array<int, int>>>
     */
    private function makeSyntheticMatchesTwelve(): array
    {
        $matches = [];
        for ($i = 0; $i < 12; ++$i) {
            $a = $i % 6;
            $b = ($i + 1) % 6;
            $c = ($i + 2) % 6 + 6;
            $d = ($i + 3) % 6 + 6;
            $matches[] = [[$a, $b], [$c, $d]];
        }

        return $matches;
    }

    private function resetSortMatchesTestHooks(): void
    {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);

        $clock = $reflection->getProperty('sortMatchesClock');
        $clock->setAccessible(true);
        $clock->setValue(null);

        $budget = $reflection->getProperty('sortMatchesWallBudgetNs');
        $budget->setAccessible(true);
        $budget->setValue(25_000_000_000);
    }

    /**
     * @param callable():int $clock
     */
    private function setSortMatchesClock(callable $clock): void
    {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $prop = $reflection->getProperty('sortMatchesClock');
        $prop->setAccessible(true);
        $prop->setValue($clock);
    }

    private function setSortMatchesWallBudgetNs(int $ns): void
    {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $prop = $reflection->getProperty('sortMatchesWallBudgetNs');
        $prop->setAccessible(true);
        $prop->setValue($ns);
    }

    /**
     * @param array<int, array<int, array<int, int>>> $matches
     * @param array<int, int> $mockPlayers
     * @return array<int, array<int, array<int, int>>>
     */
    private function bruteForceBestOrder(array $matches, array $mockPlayers, TemplateMatchesGenerator $generator, \ReflectionClass $reflection): array
    {
        $calculatePlayerDistribution = $reflection->getMethod('calculatePlayerDistribution');
        $calculatePlayerDistribution->setAccessible(true);

        $m = count($matches);
        $perm = range(0, $m - 1);
        $permCopy = $perm;
        $size = $m - 1;

        $pcNextPermutation = $reflection->getMethod('pcNextPermutation');
        $pcNextPermutation->setAccessible(true);

        $bestOrdered = $matches;
        $bestMin = null;
        $bestAvg = null;

        do {
            $ordered = [];
            foreach ($perm as $i) {
                $ordered[] = $matches[$i];
            }

            $sum = 0.0;
            $min = INF;
            foreach ($mockPlayers as $p) {
                $score = (float) $calculatePlayerDistribution->invoke($generator, (int) $p, $ordered);
                $sum += $score;
                if ($score < $min) {
                    $min = $score;
                }
            }
            $avg = $sum / count($mockPlayers);

            if ($bestMin === null || $min > $bestMin || ($min === $bestMin && $avg > $bestAvg)) {
                $bestMin = $min;
                $bestAvg = $avg;
                $bestOrdered = $ordered;
            }
        } while (($perm = $pcNextPermutation->invoke($generator, $perm, $size)) && $perm !== $permCopy);

        return $bestOrdered;
    }
}
