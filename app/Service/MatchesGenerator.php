<?php

namespace Arshavinel\PadelMiniTour\Service;

class MatchesGenerator
{
    private TemplateMatchesGenerator $templateMatchesGenerator;
    private array $matches;
    private int $matchesCount;
    private array $partnersCount;
    private array $playersMet;
    private bool $hasDifferentPartnersNumber;

    public function __construct(array $players, int $partnersLimit)
    {
        $templateMatchesGenerator = new TemplateMatchesGenerator(count($players), $partnersLimit);

        $matches = $templateMatchesGenerator->getMatches();
        array_walk_recursive($matches, function (&$value) use ($players) {
            $value = $players[$value];
        });

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
        $this->matchesCount = count($matches);
        $this->hasDifferentPartnersNumber = $templateMatchesGenerator->hasDifferentPartnersNumber();
        $this->partnersCount = $countPartners;
        $this->playersMet = $countPlayersMet;

        // _vd($countPartners, '$countPartners');
        // _vd($countPlayersMet, '$countPlayersMet');
        // exit;
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

    public function getPlayersMetBy(string $player): array
    {
        return $this->playersMet[$player];
    }

    public function hasDifferentPartnersNumber(): bool
    {
        return $this->hasDifferentPartnersNumber;
    }
}
