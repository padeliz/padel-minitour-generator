<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;

trait TemplateVersionTestTrait
{
    private static int $allocVersionCounter = 0;

    protected function resetAllocatedVersions(): void
    {
        self::$allocVersionCounter = random_int(100_000, 900_000);
    }

    protected function allocVersion(): int
    {
        self::$allocVersionCounter++;

        return self::$allocVersionCounter;
    }

    protected function productionRepository(): TemplateMatchesRepository
    {
        return new TemplateMatchesRepository();
    }

    protected function latestProductionVersion(): int
    {
        return $this->productionRepository()->latestVersion();
    }

    protected function loadLatestCommittedTemplate(
        int $players,
        int $partners,
        int $repeat = 1,
        int $courts = 1,
        bool $fixedTeams = false
    ): TemplateMatches {
        return $this->productionRepository()->find($players, $partners, $repeat, $courts, $fixedTeams);
    }
}
