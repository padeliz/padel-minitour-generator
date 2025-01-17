<?php

namespace Arshavinel\PadelMiniTour\Service;

use DateTime;

class EventDivision
{
    private MatchesGenerator $matchesGenerator;
    private string $edition;
    private string $partnerId;
    private string $title;
    private string $timeStart;
    private string $timeEnd;
    private int $eventDuration;
    private array $players;
    private int $opponentsPerPlayer;
    private int $repeatPartners;
    private int $playersCount;
    private int $pointsPerMatch;
    private int $pointsPerPlayer;
    private bool $hasDemonstrativeMatch;

    public function __construct(
        string $edition,
        string $partnerId,
        string $title,
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
        $matchesGenerator = new MatchesGenerator($players, $opponentsPerPlayer, $repeatPartners, $timeStart, $timeEnd, $includeFinal, $hasDemonstrativeMatch, $fixedTeams);

        $this->edition = $edition;
        $this->partnerId = $partnerId;
        $this->title = $title;
        $this->timeStart = $timeStart;
        $this->timeEnd = $timeEnd;
        $this->hasDemonstrativeMatch = $hasDemonstrativeMatch;
        $this->eventDuration = DateTime::createFromFormat('H:i', $timeStart)->diff(DateTime::createFromFormat('H:i', $timeEnd))->h;
        $this->players = $players;
        $this->playersCount = count($players);

        $this->pointsPerMatch = floor(ceil(110 * $this->eventDuration - 1 + (20 / 60)) / $matchesGenerator->getMatchesCount());
        $this->pointsPerPlayer = $this->pointsPerMatch * $opponentsPerPlayer * $repeatPartners;
        $this->opponentsPerPlayer = $opponentsPerPlayer;
        $this->repeatPartners = $repeatPartners;
        $this->matchesGenerator = $matchesGenerator;
    }

    public function getEdition(): int
    {
        return $this->edition;
    }

    public function getPartnerId(): int
    {
        return $this->partnerId;
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

    public function hasDemonstrativeMatch(): bool
    {
        return $this->hasDemonstrativeMatch;
    }

    public function getDuration(): int
    {
        return $this->eventDuration;
    }

    public function getOpponentsPerPlayer(): int
    {
        return $this->opponentsPerPlayer;
    }

    public function getRepeatPartners(): int
    {
        return $this->repeatPartners;
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
