<?php

namespace Tests\Unit\Console;

use Arshavinel\PadelMiniTour\Service\Progress\MatchMakingProgress;
use Arshavinel\PadelMiniTour\Service\Progress\OrderingProgress;
use Arshavinel\PadelMiniTour\Service\Progress\PairingProgress;
use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;

/**
 * Test double for console command tests: records generate() calls and returns canned templates
 * without running pairing or ordering DFS.
 */
final class RecordingGenerator extends TemplateMatchesGenerator
{
    /** @var list<array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool}> */
    public array $calls = [];

    /** @var callable|null */
    private $progressCallback = null;

    private bool $eligible = true;

    private int $pairingSeedCurrent = 1;

    private int $pairingSeedsTotal = 1;

    public function setEligible(bool $eligible): self
    {
        $this->eligible = $eligible;

        return $this;
    }

    public function setPairingSeedContext(int $current, int $total): self
    {
        $this->pairingSeedCurrent = $current;
        $this->pairingSeedsTotal = $total;

        return $this;
    }

    public function setProgressCallback(?callable $callback): void
    {
        parent::setProgressCallback($callback);
        $this->progressCallback = $callback;
    }

    public function generate(
        int $players,
        int $partners,
        int $repeat,
        int $courts = 1,
        bool $fixedTeams = false
    ): TemplateMatches {
        $this->calls[] = [
            'players' => $players,
            'partners' => $partners,
            'repeat' => $repeat,
            'courts' => $courts,
            'fixedTeams' => $fixedTeams,
        ];

        $this->emitProgressEvents($players, $partners, $repeat, $fixedTeams);

        return $this->buildTemplate($players, $partners, $repeat, $courts, $fixedTeams);
    }

    private function emitProgressEvents(int $players, int $partners, int $repeat, bool $fixedTeams): void
    {
        if ($this->progressCallback === null) {
            return;
        }

        $partnerCounts = [];
        for ($i = 0; $i < $players; $i++) {
            $partnerCounts[$i] = 1;
        }

        ($this->progressCallback)(new PairingProgress(
            $players,
            $partners,
            $repeat,
            $fixedTeams,
            1_000_000,
            1_000_000_000,
            true,
            24,
            0.95,
            0.97,
            $this->pairingSeedCurrent,
            $this->pairingSeedsTotal,
            $partnerCounts,
            0,
            max(1, $partners),
            TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE
        ));

        ($this->progressCallback)(new MatchMakingProgress(
            $players,
            $partners,
            $repeat,
            $fixedTeams,
            1_500_000,
            1_000_000_000,
            true,
            12,
            1,
            0.5,
            1,
            1,
            1,
            1,
            $partners,
            $partnerCounts,
            [],
            0,
            TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE,
            1
        ));

        ($this->progressCallback)(new OrderingProgress(
            $players,
            $partners,
            $repeat,
            $fixedTeams,
            2_000_000,
            1_000_000_000,
            true,
            6,
            0.95,
            0.97,
            TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE,
            1,
            0,
            0
        ));
    }

    private function buildTemplate(
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams
    ): TemplateMatches {
        $matchCount = max(1, $partners);
        $matches = $this->eligible
            ? $this->syntheticMatches($players, $matchCount, $courts)
            : null;

        $partnerCounts = [];
        for ($i = 0; $i < $players; $i++) {
            $partnerCounts[$i] = 1;
        }

        return new TemplateMatches(
            $players,
            $partners,
            $repeat,
            $courts,
            $fixedTeams,
            $matches,
            0.95,
            0.97,
            $partnerCounts,
            0,
            max(1, $partners),
            TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE,
            0.04,
            24,
            1,
            1,
            0.5,
            1,
            3,
            [],
            $matchCount,
            12,
            1,
            1,
            1,
            $matches === null
                ? TemplateMatchesGenerator::STOP_REASON_DEADLINE
                : TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE,
            0.04,
            1,
            null,
            0.95,
            0.97,
            0,
            0,
            0,
            null,
            $matches === null ? null : $matchCount,
            $matches === null
                ? TemplateMatchesGenerator::STOP_REASON_DEADLINE
                : TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE,
            5,
            1,
            6,
            1,
            1,
            0.08
        );
    }

    /**
     * @return array<int, array<int, array<int, array<int, int>>>>
     */
    private function syntheticMatches(int $players, int $matchCount, int $courts): array
    {
        if ($courts <= 1) {
            $round = [];
            for ($m = 0; $m < $matchCount; $m++) {
                $round[] = [[$m % $players, ($m + 1) % $players], [($m + 2) % $players, ($m + 3) % $players]];
            }

            return [$round];
        }

        $perCourt = (int) ceil($matchCount / $courts);
        $matchesByCourt = [];
        for ($c = 0; $c < $courts; $c++) {
            $courtRounds = [];
            for ($r = 0; $r < $perCourt; $r++) {
                $idx = $c * $perCourt + $r;
                if ($idx >= $matchCount) {
                    break;
                }
                $courtRounds[] = [
                    [$idx % $players, ($idx + 1) % $players],
                    [($idx + 2) % $players, ($idx + 3) % $players],
                ];
            }
            $matchesByCourt[] = $courtRounds;
        }

        return $matchesByCourt;
    }
}
