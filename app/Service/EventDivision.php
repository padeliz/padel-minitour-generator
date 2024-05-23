<?php

namespace Arshavinel\PadelMiniTour\Service;

use DateTime;

class EventDivision
{
    private MatchesGenerator $matchesGenerator;
    private string $title;
    private string $timeStart;
    private string $timeEnd;
    private int $eventDuration;
    private array $players;
    private int $partnersPerPlayer;
    private int $playersCount;
    private int $pointsPerMatch;
    private int $pointsPerPlayer;

    public function __construct(
        string $title,
        array $players,
        int $partnersPerPlayer,
        int $repeatPartners,
        string $timeStart,
        string $timeEnd,
        bool $includeFinal
    )
    {
        $matchesGenerator = new MatchesGenerator($players, $partnersPerPlayer, $repeatPartners, $timeStart, $timeEnd, $includeFinal);

        $this->title = $title;
        $this->timeStart = $timeStart;
        $this->timeEnd = $timeEnd;
        $this->eventDuration = DateTime::createFromFormat('H:i', $timeStart)->diff(DateTime::createFromFormat('H:i', $timeEnd))->h;
        $this->players = $players;
        $this->playersCount = count($players);

        $this->pointsPerMatch = floor(ceil(110 * $this->eventDuration - 1 + (20 / 60)) / $matchesGenerator->getMatchesCount());
        $this->pointsPerPlayer = $this->pointsPerMatch * $partnersPerPlayer * $repeatPartners;
        $this->partnersPerPlayer = $partnersPerPlayer;
        $this->matchesGenerator = $matchesGenerator;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getTimeStart(): string
    {
        return $this->timeStart;
    }

    public function getTimeEnd(): string
    {
        return $this->timeEnd;
    }

    public function getDuration(): int
    {
        return $this->eventDuration;
    }

    public function getPartnersPerPlayer(): int
    {
        return $this->partnersPerPlayer;
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
