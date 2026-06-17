<?php

namespace Arshavinel\PadelMiniTour\Console;

use Arshavinel\PadelMiniTour\Service\PlayerDistributionScorer;
use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Coloring helpers and unified-row builder for the templates:* console commands.
 *
 * The unified table uses a two-row grouped header (5 groups, 18 detail columns); every row is
 * built by {@see buildUnifiedRow()} so the three template CLI commands produce visually identical
 * output. The TEAMS group label is parameterized with the current (repeat, fixedTeams), since the
 * generator only supports a single (repeat, fixedTeams) per table render (bulk regenerate, both
 * stats commands, and single-combo regenerate each share one value for the entire table).
 *
 * Group layout (3 + 4 + 4 + 4 + 3 = 18):
 *
 *   | TEAMS (repeat: N, fixed: yes/no)      | PAIRING                                              | SORTING                                         | PAIRING STATS                       | SORTING STATS         |
 *   | Players | Partners | Matches          | Partners Nr. Var. | Min Met      | Max Met      | M. Var. | Min Dist. | Avg Dist. | Min Break | Max Break | Perm. Index | Pairing Index | Stop | Time | Sorting Index | Stop | Time |
 */
trait StatsFormatterTrait
{
    /** Total number of detail columns in the unified table. */
    protected function unifiedTotalColumns(): int
    {
        return 20;
    }

    /**
     * Constructs a Symfony {@see Table} pre-configured with the unified visual style.
     *
     * Symfony's default header format is `<info>%s</info>` (green); we override it to white so
     * that the only green text in the header is the five group keywords (`TEAMS`, `PAIRING`,
     * `SORTING`, `PAIRING STATS`, `SORTING STATS`), which {@see unifiedHeaders()} wraps in
     * explicit `<fg=green>` tags. Every other header cell stays white by default. Keeping the
     * style in a single factory means future tweaks (e.g. swap palette per command) only need to
     * touch this one method.
     */
    protected function makeUnifiedTable(OutputInterface $output): Table
    {
        $table = new Table($output);
        $table->getStyle()->setCellHeaderFormat('<fg=white>%s</>');

        return $table;
    }

    /**
     * Color-coded meetings variation: green at or below 1.0, yellow at or below 2.0, red above
     * 2.0. The bands apply equally to per-row cells and to the bottom AVG row (same helper is
     * shared by both call sites).
     */
    protected function formatMeetingsVariation(?float $meetingsVariation): string
    {
        if ($meetingsVariation === null) {
            $color = 'red';
        } elseif ($meetingsVariation <= 1.0) {
            $color = 'green';
        } elseif ($meetingsVariation <= 2.0) {
            $color = 'yellow';
        } else {
            $color = 'red';
        }

        return "<fg=$color>" . ($meetingsVariation !== null ? number_format($meetingsVariation, 2) : '-') . '</>';
    }

    protected function formatIndex(?int $index, int $total): string
    {
        $color = 'white';

        if ($index === null) {
            $color = 'red';
        } elseif ((0.1 * $total) >= $index) {
            $color = 'green';
        } elseif ($total > 10000) {
            if ((0.9 * $total) <= $index) {
                $color = 'yellow';
            }
        }

        $indexLabel = $index !== null ? (string) $index : '-';

        return "<fg=$color>{$indexLabel}</> / {$total}";
    }

    /**
     * Formats a duration with integer-only units. Picks the coarsest unit that still keeps the
     * sub-second precision: `Nms` under one second, `Ns` under one minute, `Nm Ns` under one hour,
     * `Nh Nm` beyond. Decimal points never appear, matching the table's "time as int" contract.
     */
    protected function formatTime(?float $seconds): string
    {
        if ($seconds === null) {
            return '<fg=red>-</>';
        }
        $ms = (int) round($seconds * 1000);

        return '<fg=white>' . $this->formatIntegerDuration($ms) . '</>';
    }

    private function formatIntegerDuration(int $ms): string
    {
        if ($ms < 1000) {
            return $ms . 'ms';
        }
        $totalSeconds = (int) round($ms / 1000);
        if ($totalSeconds < 60) {
            return $totalSeconds . 's';
        }
        $totalMinutes = intdiv($totalSeconds, 60);
        $remSeconds = $totalSeconds % 60;
        if ($totalMinutes < 60) {
            return $remSeconds === 0
                ? $totalMinutes . 'm'
                : $totalMinutes . 'm ' . $remSeconds . 's';
        }
        $hours = intdiv($totalMinutes, 60);
        $remMinutes = $totalMinutes % 60;

        return $remMinutes === 0
            ? $hours . 'h'
            : $hours . 'h ' . $remMinutes . 'm';
    }

    /**
     * Color-coded stop reason. Color decisions are keyed on the canonical {@see TemplateMatchesGenerator::STOP_REASON_*}
     * constants; the displayed text is the short label looked up through
     * {@see TemplateMatchesGenerator::stopReasonLabel()}, so labels can be reworded without
     * touching this switch. Unknown raw values (stale JSON) fall through verbatim in white.
     */
    protected function formatStopReason(?string $reason): string
    {
        if ($reason === null) {
            return '<fg=red>-</>';
        }

        $label = TemplateMatchesGenerator::stopReasonLabel($reason);

        switch ($reason) {
            case TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE:
                return "<fg=blue>{$label}</>";
            case TemplateMatchesGenerator::STOP_REASON_DEADLINE:
                return "<fg=yellow>{$label}</>";
            case TemplateMatchesGenerator::STOP_REASON_PRUNE_INFEASIBLE:
                return "<fg=red>{$label}</>";
            case TemplateMatchesGenerator::STOP_REASON_TRIVIAL:
                return "<fg=white>{$label}</>";
            default:
                return "<fg=white>{$label}</>";
        }
    }

    /**
     * Color-coded distribution score rendered as an integer percentage (no decimals). Green at/above
     * `$greenAt`, yellow at/above `$yellowAt`, red otherwise. Color thresholds are still compared
     * against the raw `[0, 1]` value, so existing thresholds (e.g. `0.85`, `0.75`) keep their
     * meaning. Renders null as a red dash.
     */
    protected function formatDistribution(?float $value, float $greenAt, float $yellowAt): string
    {
        if ($value === null) {
            return '<fg=red>-</>';
        }
        if ($value >= $greenAt) {
            $color = 'green';
        } elseif ($value >= $yellowAt) {
            $color = 'yellow';
        } else {
            $color = 'red';
        }

        return "<fg=$color>" . ((int) round($value * 100)) . '%</>';
    }

    /**
     * Color-coded `max - min` partner-count variation. Green when 0 (balanced), yellow when small,
     * red when larger.
     */
    protected function formatPartnersVariation(?int $value): string
    {
        if ($value === null) {
            return '<fg=red>-</>';
        }
        if ($value === 0) {
            return '<fg=green>0</>';
        }
        if ($value === 1) {
            return '<fg=yellow>1</>';
        }
        return "<fg=red>{$value}</>";
    }

    /**
     * Color-coded Min Break. Under the asymmetric semantic the value is the cross-player
     * minimum of each player's shortest INNER consecutive break run (lead and trail runs
     * excluded; a player with no inner break runs contributes `0`). Bands are centered on
     * `threshold = ceil(players / 4)` -- the per-player ideal gap:
     *   - red when `value <= threshold - 3` (some player has a tight back-to-back inner gap),
     *   - yellow when `value == threshold - 2`,
     *   - green when `value == threshold - 1` or `value == threshold`,
     *   - red when `value >= threshold + 1` (every player rests beyond the ideal -- schedule
     *     has slack to tighten).
     * Renders null as a red dash.
     */
    protected function formatMinBreak(?int $value, int $players): string
    {
        if ($value === null) {
            return '<fg=red>-</>';
        }

        $threshold = (int) ceil($players / 4);
        if ($value <= $threshold - 3 || $value >= $threshold + 1) {
            $color = 'red';
        } elseif ($value === $threshold - 2) {
            $color = 'yellow';
        } else {
            $color = 'green';
        }

        return "<fg=$color>{$value}</>";
    }

    /**
     * Color-coded Max Break. Under the asymmetric semantic the value is the cross-player
     * maximum of each player's longest consecutive break run, INCLUDING lead, inner, and trail
     * runs. Threshold is `ceil(players / 4)` (one player sits out per 4-player match, so a
     * balanced schedule naturally lands around that figure). Bands: green at or below the
     * threshold, yellow exactly one above, red two or more above (matches the sort DFS hard
     * prune ceiling). Renders null as a red dash.
     */
    protected function formatMaxBreak(?int $value, int $players): string
    {
        if ($value === null) {
            return '<fg=red>-</>';
        }

        $threshold = (int) ceil($players / 4);
        if ($value <= $threshold) {
            $color = 'green';
        } elseif ($value === $threshold + 1) {
            $color = 'yellow';
        } else {
            $color = 'red';
        }

        return "<fg=$color>{$value}</>";
    }

    protected function formatCourtSwitches(?int $value): string
    {
        if ($value === null) {
            return '<fg=red>-</>';
        }

        $color = $value === 0 ? 'green' : ($value <= 2 ? 'yellow' : 'red');

        return "<fg=$color>{$value}</>";
    }

    /**
     * Color-coded distinct-opponents-met count for a single player (used for both the Min Met
     * and Max Met cells). The input is "how many other players share at least one match with
     * this seat", not a meeting frequency. The bands are anchored on `players - 1`, which is
     * the round-robin ideal "this player has met every other player at least once": green when
     * the value reaches or exceeds that ideal, yellow one shy of it, red two or more shy.
     */
    protected function formatOpponentsMetCount(?int $value, int $players): string
    {
        if ($value === null) {
            return '<fg=red>-</>';
        }

        if ($value >= $players - 1) {
            $color = 'green';
        } elseif ($value === $players - 2) {
            $color = 'yellow';
        } else {
            $color = 'red';
        }

        return "<fg=$color>{$value}</>";
    }

    /**
     * Returns the unified two-row table header.
     *
     * Top row groups (total colspan = 20):
     *
     *   | TEAMS (repeat: N, fixed: yes/no) (3) | PAIRING (4) | SORTING (4) | PAIRING STATS (4) | SORTING STATS (3) |
     *
     * @return array<int, array<int, string|TableCell>>
     */
    protected function unifiedHeaders(int $repeat, bool $fixedTeams): array
    {
        // Only the group keyword is green; the rest of each cell (including the parenthetical
        // `TEAMS` suffix) inherits white from the table style configured by {@see makeUnifiedTable()}.
        $teamsLabel = sprintf(
            '<fg=green>TEAMS</> (repeat: %d, fixed: %s)',
            $repeat,
            $fixedTeams ? 'yes' : 'no'
        );

        return [
            [
                new TableCell($teamsLabel, ['colspan' => 4]),
                new TableCell('<fg=green>PAIRING</>', ['colspan' => 4]),
                new TableCell('<fg=green>SORTING</>', ['colspan' => 5]),
                new TableCell('<fg=green>PAIRING STATS</>', ['colspan' => 4]),
                new TableCell('<fg=green>SORTING STATS</>', ['colspan' => 3]),
            ],
            [
                'Players',
                'Partners',
                'Courts',
                'Matches',
                'Partners Var.',
                'Min Met',
                'Max Met',
                'Meets Var.',
                'Min Dist.',
                'Avg Dist.',
                'Min Break',
                'Max Break',
                'Court Sw.',
                'Perm. Idx.',
                'Pairing Idx.',
                'Stop Reason',
                'Time',
                'Sorting Idx.',
                'Stop Reason',
                'Time',
            ],
        ];
    }

    /**
     * Builds the 18-cell unified row in the order declared by {@see unifiedHeaders()}.
     *
     * When `$template === null` (missing file) the three identity cells come from the fallback
     * parameters and the remaining 15 cells render a red dash / `missing` marker.
     *
     * `$firstOfGroup` controls the grouped Players column: the first row of each player group
     * shows the integer; subsequent rows in the same group render `.` so the eye can scan the
     * grouped block at a glance.
     *
     * @return array<int, string>
     */
    protected function buildUnifiedRow(
        ?TemplateMatches $template,
        int $playersFallback,
        int $opponentsFallback,
        bool $firstOfGroup
    ): array {
        $players = $template !== null ? $template->getPlayers() : $playersFallback;
        $opponents = $template !== null ? $template->getPartners() : $opponentsFallback;

        $identityCells = [
            '<fg=blue>' . ($firstOfGroup ? $players : '.') . '</>',
            '<fg=cyan>' . $opponents . '</>',
        ];

        if ($template === null) {
            return array_merge(
                $identityCells,
                ['<fg=red>missing</>'],
                array_fill(0, $this->unifiedTotalColumns() - 3, '<fg=red>-</>')
            );
        }

        $matches = $template->getMatches();
        $matchesCell = $matches !== null
            ? '<fg=white>' . array_sum(array_map('count', $matches)) . '</>'
            : '<fg=red>-</>';

        // Min/Max Met are *distinct-opponents-per-player* counters, not meeting-frequency
        // extrema. For each of the `players` seats we count how many other players share at
        // least one match with that seat (an absent player in `playersMet` counts as 0), then
        // take the min/max across the N seats. The colour bands in
        // {@see formatOpponentsMetCount()} anchor on `players - 1` so the value reads as "did
        // every player meet everyone at least once?".
        $playersMet = $template->getPairingPlayersMet();
        $metMin = null;
        $metMax = null;
        if (is_array($playersMet)) {
            $perPlayer = [];
            for ($p = 0; $p < $players; $p++) {
                $perPlayer[] = isset($playersMet[$p]) ? count($playersMet[$p]) : 0;
            }
            if (!empty($perPlayer)) {
                $metMin = min($perPlayer);
                $metMax = max($perPlayer);
            }
        }

        return array_merge($identityCells, [
            '<fg=white>' . $template->getCourts() . '</>',
            $matchesCell,
            $this->formatPartnersVariation($template->getPairingPartnersCountVariation()),
            $this->formatOpponentsMetCount($metMin, $players),
            $this->formatOpponentsMetCount($metMax, $players),
            $this->formatMeetingsVariation($template->getPairingMeetingsVariation()),
            $this->formatDistribution(
                $template->getSortingMinDistribution(),
                PlayerDistributionScorer::DISPLAY_GOOD,
                PlayerDistributionScorer::DISPLAY_FAIR
            ),
            $this->formatDistribution(
                $template->getSortingAvgDistribution(),
                TemplateMatchesGenerator::DISPLAY_AVG_DIST_GREEN,
                TemplateMatchesGenerator::DISPLAY_AVG_DIST_YELLOW
            ),
            $this->formatMinBreak($template->getSortingMinBreak(), $players),
            $this->formatMaxBreak($template->getSortingMaxBreak(), $players),
            $this->formatCourtSwitches($template->getSortingCourtSwitches()),
            $this->formatIndex(
                $template->getPairingPermutationIndex(),
                (int) $template->getPairingPermutationsIterated()
            ),
            $this->formatIndex(
                $template->getPairingTemplateIndex(),
                (int) $template->getPairingTemplatesGenerated()
            ),
            $this->formatStopReason($template->getPairingStopReason()),
            $this->formatTime($template->getPairingTime()),
            $this->formatIndex(
                $template->getSortingPermutationIndex(),
                (int) $template->getSortingPermutationsIterated()
            ),
            $this->formatStopReason($template->getSortingStopReason()),
            $this->formatTime($template->getSortingTime()),
        ]);
    }

    /**
     * Renders the unified table with a single row.
     *
     * Used by the live progress flow (where `$template` is a partial snapshot built via
     * {@see TemplateMatches::fromProgress()}) and by any other one-shot rendering. The end-of-run
     * summary command uses {@see unifiedHeaders()} / {@see buildUnifiedRow()} directly because it
     * needs to interleave separators and an AVG row across multiple combos; this helper is for
     * the simple "one DTO, one table" case.
     *
     * Because the live progress flow overwrites a `ConsoleSectionOutput` on every event, the
     * single Table is fully rebuilt and rerendered on each call. Symfony's Table autosizes
     * columns, so widths can shift slightly between events; that is acceptable for an in-place
     * updater.
     */
    protected function renderLiveSnapshotTable(
        OutputInterface $output,
        TemplateMatches $template,
        bool $firstOfGroup
    ): void {
        $table = $this->makeUnifiedTable($output);
        $table->setHeaders($this->unifiedHeaders($template->getRepeat(), $template->isFixedTeams()));
        $table->addRow($this->buildUnifiedRow(
            $template,
            $template->getPlayers(),
            $template->getPartners(),
            $firstOfGroup
        ));
        $table->render();
    }

    /**
     * @param mixed $raw
     */
    protected function parseStatsVersion($raw): int
    {
        if ($raw === null || $raw === '') {
            return TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION;
        }
        if (!is_string($raw) && !is_int($raw)) {
            throw new \InvalidArgumentException('Invalid --templates-version value: must be a positive integer.');
        }
        $stringValue = (string) $raw;
        if (!preg_match('/^[1-9]\d*$/', $stringValue)) {
            throw new \InvalidArgumentException(sprintf('Invalid --templates-version value: "%s" is not a positive integer.', $stringValue));
        }

        return (int) $stringValue;
    }

    /**
     * @return array<int, array<int, int>>|null
     */
    protected function parseStatsCombinations(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $combinations = [];
        foreach (preg_split('/\s+/', trim($raw)) as $param) {
            if (strpos($param, ':') === false) {
                throw new \InvalidArgumentException("Invalid combination token: {$param}");
            }
            [$players, $partnersList] = explode(':', $param, 2);
            $combinations[(int) $players] = array_map('intval', explode(',', $partnersList));
        }

        return $combinations;
    }

    protected function hasStatsComboFilters(InputInterface $input): bool
    {
        $resolver = new TemplateComboResolver();
        $filters = $resolver->parseFilters($input);

        return $filters !== [] || $this->parseStatsCombinations($input->getOption('combinations')) !== null;
    }

    /**
     * @param array<int, array<int, int>> $combinations
     * @return list<array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool}>
     */
    protected function resolveStatsCombos(
        InputInterface $input,
        TemplateMatchesRepository $repository,
        int $version,
        array $combinations,
        bool $defaultFixedTeams
    ): array {
        $resolver = new TemplateComboResolver();
        $filters = $resolver->parseFilters($input);
        $customCombinations = $this->parseStatsCombinations($input->getOption('combinations'));

        if (!$this->hasStatsComboFilters($input)) {
            return $resolver->resolve($input, $combinations, $defaultFixedTeams)['combos'];
        }

        $discoveryFilters = array_merge([
            'repeat' => 1,
            'courts' => 1,
            'fixedTeams' => $defaultFixedTeams,
        ], $filters);
        if ($customCombinations !== null) {
            $discoveryFilters['playersPartners'] = $customCombinations;
        }

        return $repository->listComboIdentitiesAt($version, $discoveryFilters);
    }
}
