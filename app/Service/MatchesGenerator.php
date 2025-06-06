<?php

namespace Arshavinel\PadelMiniTour\Service;

use DateInterval;
use DateTime;

class MatchesGenerator
{
    private array $matches;
    private int $matchesCount;
    private array $partnersCount;
    private array $playersMet;
    private bool $hasDifferentPartnersNumber;

    public function __construct(
        array $players,
        int $opponentsPerPlayer,
        int $repeatPartners,
        string $timeStart,
        string $timeEnd,
        bool $includeFinal,
        bool $hasDemonstrativeMatch = false,
        bool $fixedTeams = false
    )
    {
        $templateMatchesGenerator = new TemplateMatchesGenerator(count($players), $opponentsPerPlayer, $repeatPartners, $fixedTeams);

        $matches = $templateMatchesGenerator->getMatches();
        array_walk_recursive($matches, function (int &$playerIndex) use ($players) {
            $playerIndex = $players[$playerIndex];
        });

        $this->matchesCount = count($matches);

        $segments = $this->matchesCount;

        if ($includeFinal) {
            $segments = $segments + 1;
        }

        $dateTime1 = DateTime::createFromFormat('H:i', $timeStart);
        $dateTime2 = DateTime::createFromFormat('H:i', $timeEnd);

        if ($hasDemonstrativeMatch) {
            $dateTime2->modify('-15 minutes');
        }

        $totalMinutes = ($dateTime2->getTimestamp() - $dateTime1->getTimestamp()) / 60;
        $segmentDuration = $totalMinutes / $segments;

        for ($i = 0; $i < $this->matchesCount; $i++) {
            $startTime = clone $dateTime1;
            $startTime->add(new DateInterval('PT' . floor($segmentDuration * $i) . 'M'));

            $endTime = clone $dateTime1;
            $endTime->add(new DateInterval('PT' . floor($segmentDuration * ($i + 1)) . 'M'));

            $matches[$i][2] = $startTime->format('H:i');
            $matches[$i][3] = $endTime->format('H:i');
        }


        $countPartners = [];
        $templateCountPartners = $templateMatchesGenerator->getPartnersCount();
        array_walk($templateCountPartners, function (int $value, int $key) use (&$countPartners, $players) {
            $countPartners[$players[$key]] = $value;
        });

        $countPlayersMet = [];
        $templateCountPlayersMet = $templateMatchesGenerator->getPlayersMet();
        array_walk($templateCountPlayersMet, function (array $value, int $key) use (&$countPlayersMet, $players) {
            $countPlayersMet[$players[$key]] = $value;
        });

        $this->matches = $matches;
        $this->hasDifferentPartnersNumber = $templateMatchesGenerator->hasDifferentPartnersNumber();
        $this->partnersCount = $countPartners;
        $this->playersMet = $countPlayersMet;
    }

    public function getMatches(): array
    {
        return $this->matches;
    }

    public function getMatchesCount(): int
    {
        return $this->matchesCount;
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

    /**
     * Returns empty array if the player doesn't have any matches.
     *
     * This is an error representing that the number of total players is invalid.
     */
    public function getPlayersMetBy(string $player): array
    {
        return $this->playersMet[$player] ?? [];
    }

    public function hasDifferentPartnersNumber(): bool
    {
        return $this->hasDifferentPartnersNumber;
    }
}
