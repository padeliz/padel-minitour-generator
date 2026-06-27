<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\Progress\GenerationProgress;
use Arshavinel\PadelMiniTour\Service\Progress\ProgressReporter;
use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Reflection helpers for {@see TemplateMatchesGenerator} unit tests.
 *
 * Never call public generate() from PHPUnit — use invokePipeline* or invokePairingPhase.
 */
abstract class GeneratorTestCase extends TestCase
{
    use TemplateVersionTestTrait;

    /** 10s wall budget per phase for fast pipeline tests. */
    protected const TEST_PHASE_BUDGET_NS = 10_000_000_000;

    protected function setUp(): void
    {
        $this->resetAllocatedVersions();
    }

    protected function invokePipelineMixed(
        TemplateMatchesGenerator $generator,
        int $players,
        int $partners,
        int $repeat = 1,
        int $courts = 1,
        ?int $pairingBudgetNs = null,
        ?int $matchMakingBudgetNs = null,
        ?int $orderingBudgetNs = null,
        ?callable $progressCallback = null
    ): TemplateMatches {
        return $this->invokePipeline(
            $generator,
            $players,
            $partners,
            $repeat,
            $courts,
            false,
            $pairingBudgetNs,
            $matchMakingBudgetNs,
            $orderingBudgetNs,
            $progressCallback
        );
    }

    protected function invokePipelineFixed(
        TemplateMatchesGenerator $generator,
        int $players,
        int $partners,
        int $repeat = 1,
        int $courts = 1,
        ?int $pairingBudgetNs = null,
        ?int $matchMakingBudgetNs = null,
        ?int $orderingBudgetNs = null,
        ?callable $progressCallback = null
    ): TemplateMatches {
        return $this->invokePipeline(
            $generator,
            $players,
            $partners,
            $repeat,
            $courts,
            true,
            $pairingBudgetNs,
            $matchMakingBudgetNs,
            $orderingBudgetNs,
            $progressCallback
        );
    }

    protected function invokePipeline(
        TemplateMatchesGenerator $generator,
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams,
        ?int $pairingBudgetNs,
        ?int $matchMakingBudgetNs,
        ?int $orderingBudgetNs,
        ?callable $progressCallback
    ): TemplateMatches {
        $pairingBudgetNs ??= self::TEST_PHASE_BUDGET_NS;
        $matchMakingBudgetNs ??= self::TEST_PHASE_BUDGET_NS;
        $orderingBudgetNs ??= self::TEST_PHASE_BUDGET_NS;

        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);

        $activeCourts = $reflection->getProperty('activeCourts');
        $activeCourts->setAccessible(true);
        $activeCourts->setValue($generator, max(1, $courts));

        foreach ([
            'effectivePairingBudgetNs' => $pairingBudgetNs,
            'effectiveMatchMakingBudgetNs' => $matchMakingBudgetNs,
            'effectiveOrderingBudgetNs' => $orderingBudgetNs,
        ] as $property => $value) {
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($generator, $value);
        }

        $reporter = $this->makeReporter(
            $generator,
            $players,
            $partners,
            $repeat,
            $fixedTeams,
            $progressCallback
        );

        $method = $reflection->getMethod($fixedTeams ? 'generateFixed' : 'generateMixed');
        $method->setAccessible(true);

        return $method->invoke($generator, $players, $partners, $repeat, $reporter);
    }

    /**
     * @return array{
     *     pairs: list<array{0:int,1:int}>,
     *     partnersCount: list<int>,
     *     partnersCountVariation: int,
     *     minPartnersFairness: float|null,
     *     avgPartnersFairness: float|null,
     *     pairCount: int,
     *     stopReason: string,
     *     time: float,
     *     nodesExplored: int,
     *     seedIndex: int|null,
     *     seedsTotal: int
     * }
     */
    protected function invokePairingPhase(
        TemplateMatchesGenerator $generator,
        int $players,
        int $partners,
        ?int $pairingBudgetNs = null,
        ?callable $progressCallback = null
    ): array {
        $pairingBudgetNs ??= self::TEST_PHASE_BUDGET_NS;

        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);

        $prop = $reflection->getProperty('effectivePairingBudgetNs');
        $prop->setAccessible(true);
        $prop->setValue($generator, $pairingBudgetNs);

        $reporter = $this->makeReporter(
            $generator,
            $players,
            $partners,
            1,
            false,
            $progressCallback
        );

        $method = $reflection->getMethod('runPairingPhase');
        $method->setAccessible(true);

        return $method->invoke($generator, $players, $partners, $reporter);
    }

    protected function makeReporter(
        TemplateMatchesGenerator $generator,
        int $players,
        int $partners,
        int $repeat,
        bool $fixedTeams,
        ?callable $progressCallback = null
    ): ProgressReporter {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $clock = $reflection->getMethod('monotonicNow');
        $clock->setAccessible(true);

        return new ProgressReporter(
            $progressCallback,
            250_000_000,
            $players,
            $partners,
            $repeat,
            $fixedTeams,
            $clock->invoke($generator)
        );
    }

    /**
     * @return array<int, GenerationProgress>
     */
    protected function capturePipelineEvents(
        TemplateMatchesGenerator $generator,
        int $players,
        int $partners,
        int $repeat,
        bool $fixedTeams,
        ?int $pairingBudgetNs = null,
        ?int $matchMakingBudgetNs = null,
        ?int $orderingBudgetNs = null
    ): array {
        $events = [];
        $callback = static function (GenerationProgress $event) use (&$events): void {
            $events[] = $event;
        };
        $generator->setProgressCallback($callback);

        if ($fixedTeams) {
            $this->invokePipelineFixed(
                $generator,
                $players,
                $partners,
                $repeat,
                1,
                $pairingBudgetNs,
                $matchMakingBudgetNs,
                $orderingBudgetNs,
                $callback
            );
        } else {
            $this->invokePipelineMixed(
                $generator,
                $players,
                $partners,
                $repeat,
                1,
                $pairingBudgetNs,
                $matchMakingBudgetNs,
                $orderingBudgetNs,
                $callback
            );
        }

        return $events;
    }
}
