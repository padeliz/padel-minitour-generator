<?php

namespace Arshavinel\PadelMiniTour\Service;

use Arshavinel\PadelMiniTour\DTO\PdfPlayer;
use Arshavinel\PadelMiniTour\Service\Exception\TemplateMatchesNotFoundException;
use Arshavinel\PadelMiniTour\Table\Player;
use DateTime;

class EventDivision
{
    public const ORGANIZERS = [
        1 => 'ARSH Padel MiniTour',
        2 => 'Bucharest Padel Tour',
    ];

    public const PARTNERS = [
        1 => 'PadelMania',
        2 => 'Padel One',
        3 => 'Padel World',
        4 => 'Magic Padel',
        5 => 'Padel Hub',
    ];

    private MatchesGenerator $matchesGenerator;
    private string $edition;
    private int $organizerId;
    private int $partnerId;
    private string $title;
    private string $timeStart;
    private string $timeEnd;
    private int $eventDuration;

    /** @var Player[] */
    private array $players;
    private int $playersCount;
    private array $playerNamesById;

    private int $opponentsPerPlayer;
    private int $repeatPartners;
    private bool $fixedTeams;
    private int $templateVersion;
    private int $pointsPerMatch;
    private int $pointsPerPlayer;
    private bool $hasDemonstrativeMatch;
    /** @var array<int, string> */
    private array $courtNames;

    public function __construct(
        string $edition,
        int $organizerId,
        string $partnerId,
        string $title,
        array $courtNames,
        array $playerIds,
        int $opponentsPerPlayer,
        int $repeatPartners,
        string $timeStart,
        string $timeEnd,
        bool $includeFinal,
        bool $hasDemonstrativeMatch = false,
        bool $fixedTeams = false,
        ?int $templateVersion = null
    )
    {
        $this->playersCount = count($playerIds);
        $this->players = Player::select([
            'columns' => 'name',
            'where' => 'id_player IN (' . implode(',', $playerIds) . ')',
            'order' => 'FIELD(id_player, ' . implode(',', $playerIds) . ')',
            'files' => true,
        ]);

        // Create mapping of player ID to name
        $this->playerNamesById = [];
        foreach ($this->players as $player) {
            $this->playerNamesById[$player->id()] = (new PdfPlayer($player->id()))->getHtmlShortName();
        }

        $matchesGenerator = new MatchesGenerator(
            $playerIds,
            $opponentsPerPlayer,
            $repeatPartners,
            count($courtNames),
            $timeStart,
            $timeEnd,
            $includeFinal,
            $hasDemonstrativeMatch,
            $fixedTeams,
            $templateVersion
        );

        $this->edition = $edition;
        $this->organizerId = $organizerId;
        $this->partnerId = $partnerId;
        $this->title = $title;
        $this->courtNames = $courtNames;
        $this->timeStart = $timeStart;
        $this->timeEnd = $timeEnd;
        $this->hasDemonstrativeMatch = $hasDemonstrativeMatch;
        $this->eventDuration = DateTime::createFromFormat('H:i', $timeStart)->diff(DateTime::createFromFormat('H:i', $timeEnd))->h;

        if ($this->playersCount != count($this->players)) {
            throw new \Exception('Players count mismatch: ' . $this->playersCount . ' != ' . count($this->players));
        }

        $this->pointsPerMatch = $matchesGenerator->getRoundsTotal() > 0
            ? (int) floor(ceil(110 * $this->eventDuration - 1 + (20 / 60)) / $matchesGenerator->getRoundsTotal())
            : 0;
        $this->pointsPerPlayer = $this->pointsPerMatch * $opponentsPerPlayer * $repeatPartners;
        $this->opponentsPerPlayer = $opponentsPerPlayer;
        $this->repeatPartners = $repeatPartners;
        $this->fixedTeams = $fixedTeams;
        $this->templateVersion = $templateVersion ?? (new TemplateMatchesRepository())->latestVersion();
        $this->matchesGenerator = $matchesGenerator;
    }

    public function getEdition(): string
    {
        return $this->edition;
    }

    public function getOrganizerId(): int
    {
        return $this->organizerId;
    }

    public function getOrganizerName(): string
    {
        return self::ORGANIZERS[$this->organizerId] ?? self::ORGANIZERS[1];
    }

    public function getPartnerId(): int
    {
        return $this->partnerId;
    }

    public function getPartnerName(): string
    {
        return self::PARTNERS[$this->partnerId];
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return array<int, string>
     */
    public function getCourtNames(): array
    {
        return $this->courtNames;
    }

    public function getCourtsCount(): int
    {
        return count($this->courtNames);
    }

    public function getCourtName(int $courtIndex): string
    {
        return $this->courtNames[$courtIndex] ?? ('Court ' . ($courtIndex + 1));
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

    /** @return Player[] */
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

    public function countPartners(int $playerId): int
    {
        return $this->matchesGenerator->getPartnersCountBy($playerId);
    }

    public function countPlayersMet(int $playerId): int
    {
        return count($this->matchesGenerator->getPlayersMetBy($playerId));
    }

    public function partnersCountVariation(): int
    {
        return $this->matchesGenerator->partnersCountVariation();
    }

    public function getPointsPerMatch(): int
    {
        return $this->pointsPerMatch;
    }

    public function getPointsPerPlayer(): int
    {
        return $this->pointsPerPlayer;
    }

    public function getPlayerNameById(int $playerId): string
    {
        return $this->playerNamesById[$playerId];
    }

    /**
     * Returns the resolved template version actually used to load the schedule. This is the
     * explicit version passed to the constructor when present, otherwise the highest compatible
     * version directory ({@see TemplateMatchesRepository::latestVersion()}).
     */
    public function getTemplateVersion(): int
    {
        return $this->templateVersion;
    }

    /**
     * Builds the dropdown-ready list of every subdirectory under the template-matches base path.
     *
     * Each entry carries the metadata the frontend needs to render an `<option>`:
     *
     * - `version: int|null` -- captured from a bare `v{N}` directory name, or `null` otherwise.
     *   Used as the URL value for selectable rows; ignored for disabled rows.
     * - `directoryName: string` -- the on-disk directory name, shown verbatim for incompatible rows.
     * - `label: string` -- the precomputed `<option>` text (`v{N}`, `v{N} (not generated)`, or
     *   `<directoryName> (incompatible)`).
     * - `isCompatible: bool` -- `true` iff the directory name matches `/^v(\d+)$/` exactly.
     * - `hasCombo: bool` -- whether the current combo's file exists in this directory. Always
     *   `false` for incompatible rows (the runtime can't open them anyway).
     * - `isUsable: bool` -- file exists and has a valid sorted schedule for this combo.
     * - `isSelectable: bool` -- shortcut for `isCompatible && isUsable`.
     * - `isCurrent: bool` -- `true` iff this row's version equals the resolved
     *   {@see getTemplateVersion()}.
     *
     * @return list<array{
     *     version: ?int,
     *     directoryName: string,
     *     label: string,
     *     isCompatible: bool,
     *     hasCombo: bool,
     *     isUsable: bool,
     *     isSelectable: bool,
     *     isCurrent: bool,
     * }>
     */
    public function getTemplateVersionsForDropdown(): array
    {
        $repository = new TemplateMatchesRepository();
        $resolved = $this->getTemplateVersion();

        $rows = [];
        foreach ($repository->listVersions() as $entry) {
            $isCompatible = $entry['isCompatible'];
            $hasCombo = false;
            $isUsable = false;
            if ($isCompatible) {
                try {
                    $template = $repository->findAt(
                        $entry['version'],
                        $this->playersCount,
                        $this->opponentsPerPlayer,
                        $this->repeatPartners,
                        $this->getCourtsCount(),
                        $this->fixedTeams
                    );
                    $hasCombo = true;
                    $isUsable = $template->isUsable();
                } catch (TemplateMatchesNotFoundException $e) {
                    $hasCombo = false;
                    $isUsable = false;
                }
            }

            if (!$isCompatible) {
                $label = $entry['directoryName'] . ' (incompatible)';
            } elseif (!$hasCombo) {
                $label = 'v' . $entry['version'] . ' (not generated)';
            } elseif (!$isUsable) {
                $label = 'v' . $entry['version'] . ' (not feasible)';
            } else {
                $label = 'v' . $entry['version'];
            }

            $rows[] = [
                'version'       => $entry['version'],
                'directoryName' => $entry['directoryName'],
                'label'         => $label,
                'isCompatible'  => $isCompatible,
                'hasCombo'      => $hasCombo,
                'isUsable'      => $isUsable,
                'isSelectable'  => $isCompatible && $isUsable,
                'isCurrent'     => $entry['version'] !== null && $entry['version'] === $resolved,
            ];
        }

        return $rows;
    }
}
