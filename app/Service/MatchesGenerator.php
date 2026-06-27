<?php

namespace Arshavinel\PadelMiniTour\Service;

use DateInterval;
use DateTime;

class MatchesGenerator
{
    /** @var array<int, array<int, array<int, array<int, int|string>>>> */
    private array $matchesByCourt;
    private int $roundsTotal;
    private array $partnersCount;
    private array $playersMet;
    private int $partnersCountVariation;

  /**
     * @param array<int, int|string> $players
     */
    public function __construct(
        array $players,
        int $opponentsPerPlayer,
        int $repeatPartners,
        int $courts,
        string $timeStart,
        string $timeEnd,
        bool $includeFinal,
        bool $hasDemonstrativeMatch = false,
        bool $fixedTeams = false,
        ?int $templateVersion = null
    ) {
        $repository = new TemplateMatchesRepository();
        $templateMatches = $templateVersion === null
            ? $repository->find(count($players), $opponentsPerPlayer, $repeatPartners, $courts, $fixedTeams)
            : $repository->findAt($templateVersion, count($players), $opponentsPerPlayer, $repeatPartners, $courts, $fixedTeams);

        if (!$templateMatches->isEligible() || !TemplateMatches::hasValidRoundSchedule($templateMatches->getMatches())) {
            throw new \RuntimeException(sprintf(
                'Template for %d players, %d opponents, repeat %d, %d courts is missing or has no valid sorted schedule.',
                count($players),
                $opponentsPerPlayer,
                $repeatPartners,
                $courts
            ));
        }

        $matchesByCourt = $templateMatches->getMatches() ?? [];
        array_walk_recursive($matchesByCourt, function (&$playerIndex) use ($players) {
            if (is_int($playerIndex)) {
                $playerIndex = $players[$playerIndex];
            }
        });

        $this->roundsTotal = 0;
        foreach ($matchesByCourt as $courtRounds) {
            $this->roundsTotal = max($this->roundsTotal, count($courtRounds));
        }

        $segments = $this->roundsTotal;
        if ($includeFinal) {
            $segments++;
        }

        $dateTime1 = DateTime::createFromFormat('H:i', $timeStart);
        $dateTime2 = DateTime::createFromFormat('H:i', $timeEnd);

        if ($hasDemonstrativeMatch) {
            $dateTime2->modify('-15 minutes');
        }

        $totalMinutes = ($dateTime2->getTimestamp() - $dateTime1->getTimestamp()) / 60;
        $segmentDuration = $segments > 0 ? $totalMinutes / $segments : 0;

        for ($r = 0; $r < $this->roundsTotal; $r++) {
            $startTime = clone $dateTime1;
            $startTime->add(new DateInterval('PT' . floor($segmentDuration * $r) . 'M'));

            $endTime = clone $dateTime1;
            $endTime->add(new DateInterval('PT' . floor($segmentDuration * ($r + 1)) . 'M'));

            $startStr = $startTime->format('H:i');
            $endStr = $endTime->format('H:i');

            foreach ($matchesByCourt as $courtIdx => &$courtRounds) {
                if (!isset($courtRounds[$r])) {
                    continue;
                }
                $courtRounds[$r][2] = $startStr;
                $courtRounds[$r][3] = $endStr;
            }
            unset($courtRounds);
        }

        $countPartners = [];
        $templateCountPartners = $templateMatches->getPairingQualityPartnersCount() ?? [];
        array_walk($templateCountPartners, function (int $value, int $key) use (&$countPartners, $players) {
            $countPartners[$players[$key]] = $value;
        });

        $countPlayersMet = [];
        $templateCountPlayersMet = $templateMatches->getMatchMakingQualityPlayersMet() ?? [];
        array_walk($templateCountPlayersMet, function (array $value, int $key) use (&$countPlayersMet, $players) {
            $mapped = [];
            foreach ($value as $opponent => $count) {
                $mapped[$players[$opponent]] = $count;
            }
            $countPlayersMet[$players[$key]] = $mapped;
        });

        $this->matchesByCourt = $matchesByCourt;
        $this->partnersCountVariation = (int) $templateMatches->getPairingQualityPartnersCountVariation();
        $this->partnersCount = $countPartners;
        $this->playersMet = $countPlayersMet;
    }

    /**
     * @return array<int, array<int, array<int, array<int, int|string>>>>
     */
    public function getMatches(): array
    {
        return $this->matchesByCourt;
    }

    public function getRoundsTotal(): int
    {
        return $this->roundsTotal;
    }

    public function getMatchesCount(): int
    {
        $total = 0;
        foreach ($this->matchesByCourt as $courtRounds) {
            $total += count($courtRounds);
        }

        return $total;
    }

    public function getPartnersCount(): array
    {
        return $this->partnersCount;
    }

    public function getPartnersCountBy(string $player): int
    {
        return $this->partnersCount[$player];
    }

    public function getPlayersMet(): array
    {
        return $this->playersMet;
    }

    public function getPlayersMetBy(string $player): array
    {
        return $this->playersMet[$player] ?? [];
    }

    public function partnersCountVariation(): int
    {
        return $this->partnersCountVariation;
    }
}
