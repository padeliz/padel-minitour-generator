<?php

namespace Arshavinel\PadelMiniTour\Service;

class EventDivision
{
    private MatchesGenerator $matchesGenerator;
    private string $title;
    private array $players;
    private int $partnersLimit;
    private int $playersCount;
    private int $pointsPerMatch;
    private int $pointsPerPlayer;

    public function __construct(string $title, int $eventHours, array $players, int $partnersLimit)
    {
        $matchesGenerator = new MatchesGenerator($players, $partnersLimit);

        $this->title = $title;
        $this->players = $players;
        $this->playersCount = count($players);

        $this->pointsPerMatch = floor(ceil(110 * $eventHours - 1 + (20 / 60)) / $matchesGenerator->getMatchesCount());
        $this->pointsPerPlayer = $this->pointsPerMatch * $partnersLimit;
        $this->partnersLimit = $partnersLimit;
        $this->matchesGenerator = $matchesGenerator;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getPartnersLimit(): int
    {
        return $this->partnersLimit;
    }

    public function getPlayers(): array
    {
        return $this->players;
    }

    public function getPlayersCount(): int
    {
        return $this->playersCount;
    }

    public function getMatches(): array
    {
        return $this->matchesGenerator->getMatches();
    }

    public function getMatchesCount(): int
    {
        return $this->matchesGenerator->getMatchesCount();
    }

    public function countPartners(string $player): int
    {
        return $this->matchesGenerator->getPartnersCountBy($player);
    }

    public function countPlayersMet(string $player): int
    {
        return count($this->matchesGenerator->getPlayersMetBy($player));
    }

    public function hasDifferentPartnersNumber(): bool
    {
        return $this->matchesGenerator->hasDifferentPartnersNumber();
    }

    public function getPointsPerMatch(): int
    {
        return $this->pointsPerMatch;
    }

    public function getPointsPerPlayer(): int
    {
        return $this->pointsPerPlayer;
    }
}
