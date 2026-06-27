<?php

namespace Arshavinel\PadelMiniTour\Console;

use Arshavinel\PadelMiniTour\Service\PlayerDistributionScorer;
use Arshavinel\PadelMiniTour\Service\PartnersFairnessScorer;
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
 * The unified table uses a two-row grouped header; every row is built by
 * {@see buildUnifiedRow()} so the template CLI commands produce visually identical output.
 * Uniform combo dimensions (repeat, fixed-teams, courts) are shown on a context line above
 * the table; courts appears as a per-row column only when values differ across rows.
 */
trait MetricsFormatterTrait
{
    /**
     * @param list<array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool}> $combos
     * @param list<TemplateMatches|null> $templates
     * @return array{
     *     includeCourtsColumn: bool,
     *     includePartnersVarColumn: bool,
     *     includeCourtSwitchesColumn: bool,
     *     pairingColspan: int,
     *     orderingColspan: int,
     *     totalColumns: int,
     *     teamsColspan: int,
     *     contextParts: list<string>,
     *     avgColumns: array{pairingMinPartnersFair:int,pairingAvgPartnersFair:int,partnersVar?:int,meetingsVar:int,orderingMinDist:int,orderingAvgDist:int}
     * }
     */
    protected function resolveUnifiedTableLayout(array $combos, array $templates = []): array
    {
        $repeats = array_values(array_unique(array_column($combos, 'repeat')));
        $fixedTeamsFlags = array_values(array_unique(array_map(
            static fn (array $c): int => $c['fixedTeams'] ? 1 : 0,
            $combos
        )));
        $courtsValues = array_values(array_unique(array_column($combos, 'courts')));

        $contextParts = [];
        if (count($repeats) === 1) {
            $contextParts[] = 'repeat: ' . $repeats[0];
        }
        if (count($fixedTeamsFlags) === 1) {
            $contextParts[] = 'fixed: ' . ($fixedTeamsFlags[0] === 1 ? 'yes' : 'no');
        }

        $includeCourtsColumn = count($courtsValues) > 1;
        if (!$includeCourtsColumn && count($courtsValues) === 1) {
            $contextParts[] = 'courts: ' . $courtsValues[0];
        }

        $includePartnersVarColumn = $this->templatesNeedPartnersVarColumn($templates);
        $includeCourtSwitchesColumn = $this->combosNeedCourtSwitchesColumn($combos);

        $teamsColspan = $includeCourtsColumn ? 3 : 2;
        $pairingColspan = $includePartnersVarColumn ? 3 : 2;
        $orderingColspan = $includeCourtSwitchesColumn ? 5 : 4;
        $totalColumns = $teamsColspan + $pairingColspan + 4 + 4 + 4 + $orderingColspan + 4;

        $col = $teamsColspan;
        $avgColumns = [
            'pairingMinPartnersFair' => $col,
            'pairingAvgPartnersFair' => $col + 1,
        ];
        $col += 2;
        if ($includePartnersVarColumn) {
            $avgColumns['partnersVar'] = $col;
            $col++;
        }
        $col += 4;
        $avgColumns['meetingsVar'] = $col;
        $col += 4 + 4 + $orderingColspan;
        $avgColumns['orderingMinDist'] = $col;
        $avgColumns['orderingAvgDist'] = $col + 1;

        return [
            'includeCourtsColumn' => $includeCourtsColumn,
            'includePartnersVarColumn' => $includePartnersVarColumn,
            'includeCourtSwitchesColumn' => $includeCourtSwitchesColumn,
            'pairingColspan' => $pairingColspan,
            'orderingColspan' => $orderingColspan,
            'totalColumns' => $totalColumns,
            'teamsColspan' => $teamsColspan,
            'contextParts' => $contextParts,
            'avgColumns' => $avgColumns,
        ];
    }

    /**
     * @param list<TemplateMatches|null> $templates
     */
    protected function templatesNeedPartnersVarColumn(array $templates): bool
    {
        foreach ($templates as $template) {
            if ($template === null) {
                continue;
            }
            $variation = $template->getPairingQualityPartnersCountVariation();
            if ($variation !== null && $variation !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool}> $combos
     */
    protected function combosNeedCourtSwitchesColumn(array $combos): bool
    {
        foreach ($combos as $combo) {
            if ($combo['courts'] > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{contextParts: list<string>} $layout
     */
    protected function writeTableContextLine(OutputInterface $output, array $layout): void
    {
        if ($layout['contextParts'] === []) {
            return;
        }

        $output->writeln('<info>' . implode('   ', $layout['contextParts']) . '</info>');
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

    protected function formatPartnersFairness(?float $value): string
    {
        return $this->formatDistribution(
            $value,
            PartnersFairnessScorer::DISPLAY_GOOD,
            PartnersFairnessScorer::DISPLAY_FAIR
        );
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
     * @param array{includeCourtsColumn: bool, teamsColspan: int} $layout
     * @return array<int, array<int, string|TableCell>>
     */
    protected function unifiedHeaders(array $layout): array
    {
        $teamsDetail = ['Players', 'Partners'];
        if ($layout['includeCourtsColumn']) {
            $teamsDetail[] = 'Courts';
        }

        $pairingDetail = [
            'Min Partners Fair.',
            'Avg Partners Fair.',
        ];
        if ($layout['includePartnersVarColumn']) {
            $pairingDetail[] = 'Partners Var.';
        }

        $orderingDetail = [
            'Min Dist.',
            'Avg Dist.',
            'Min Break',
            'Max Break',
        ];
        if ($layout['includeCourtSwitchesColumn']) {
            $orderingDetail[] = 'Court Sw.';
        }

        return [
            [
                new TableCell('<fg=green>TEAMS</>', ['colspan' => $layout['teamsColspan']]),
                new TableCell('<fg=green>PAIRING</>', ['colspan' => $layout['pairingColspan']]),
                new TableCell('<fg=green>PAIRING STATS</>', ['colspan' => 4]),
                new TableCell('<fg=green>MATCH-MAKING</>', ['colspan' => 4]),
                new TableCell('<fg=green>MATCH-MAKING STATS</>', ['colspan' => 4]),
                new TableCell('<fg=green>ORDERING</>', ['colspan' => $layout['orderingColspan']]),
                new TableCell('<fg=green>ORDERING STATS</>', ['colspan' => 4]),
            ],
            array_merge($teamsDetail, $pairingDetail, [
                'Seed',
                'Nodes',
                'Stop Reason',
                'Time',
                'Meets Var.',
                'Min Opponents',
                'Max Opponents',
                'Matches',
                'Perm. Idx.',
                'Templates',
                'Stop Reason',
                'Time',
            ], $orderingDetail, [
                'Perm. Idx.',
                'Nodes',
                'Stop Reason',
                'Time',
            ]),
        ];
    }

    /**
     * Builds the unified row in the order declared by {@see unifiedHeaders()}.
     *
     * @param array{includeCourtsColumn: bool, totalColumns: int} $layout
     * @return array<int, string>
     */
    protected function buildUnifiedRow(
        ?TemplateMatches $template,
        int $playersFallback,
        int $opponentsFallback,
        bool $firstOfGroup,
        array $layout
    ): array {
        $players = $template !== null ? $template->getPlayers() : $playersFallback;
        $opponents = $template !== null ? $template->getPartners() : $opponentsFallback;

        $identityCells = [
            '<fg=blue>' . ($firstOfGroup ? $players : '.') . '</>',
            $template === null
                ? '<fg=red>missing</>'
                : '<fg=cyan>' . $opponents . '</>',
        ];

        if ($template === null) {
            $teamsCells = [];
            if ($layout['includeCourtsColumn']) {
                $teamsCells[] = '<fg=red>-</>';
            }

            return array_merge(
                $identityCells,
                $teamsCells,
                array_fill(0, $layout['totalColumns'] - count($identityCells) - count($teamsCells), '<fg=red>-</>')
            );
        }

        $teamsCells = [];
        if ($layout['includeCourtsColumn']) {
            $teamsCells[] = '<fg=white>' . $template->getCourts() . '</>';
        }

        $pairingCells = [
            $this->formatPartnersFairness($template->getPairingQualityMinPartnersFairness()),
            $this->formatPartnersFairness($template->getPairingQualityAvgPartnersFairness()),
        ];
        if ($layout['includePartnersVarColumn']) {
            $pairingCells[] = $this->formatPartnersVariation($template->getPairingQualityPartnersCountVariation());
        }

        $orderingCells = [
            $this->formatDistribution(
                $template->getOrderingQualityMinDistribution(),
                PlayerDistributionScorer::DISPLAY_GOOD,
                PlayerDistributionScorer::DISPLAY_FAIR
            ),
            $this->formatDistribution(
                $template->getOrderingQualityAvgDistribution(),
                TemplateMatchesGenerator::DISPLAY_AVG_DIST_GREEN,
                TemplateMatchesGenerator::DISPLAY_AVG_DIST_YELLOW
            ),
            $this->formatMinBreak($template->getOrderingQualityMinBreak(), $players),
            $this->formatMaxBreak($template->getOrderingQualityMaxBreak(), $players),
        ];
        if ($layout['includeCourtSwitchesColumn']) {
            $orderingCells[] = $this->formatCourtSwitches($template->getOrderingQualityCourtSwitches());
        }

        return array_merge($identityCells, $teamsCells, $pairingCells, [
            $template->getPairingStatsSeedIndex() !== null
                ? '<fg=white>' . $template->getPairingStatsSeedIndex() . ' / ' . (int) $template->getPairingStatsSeedsTotal() . '</>'
                : '<fg=red>-</>',
            $template->getPairingStatsNodesExplored() !== null
                ? '<fg=white>' . $template->getPairingStatsNodesExplored() . '</>'
                : '<fg=red>-</>',
            $this->formatStopReason($template->getPairingStatsStopReason()),
            $this->formatTime($template->getPairingStatsTime()),
            $this->formatMeetingsVariation($template->getMatchMakingQualityMeetingsVariation()),
            $this->formatOpponentsMetCount($template->getMatchMakingQualityMinOpponentsMet(), $players),
            $this->formatOpponentsMetCount($template->getMatchMakingQualityMaxOpponentsMet(), $players),
            $template->getMatchMakingQualityMatchesCount() !== null
                ? '<fg=white>' . $template->getMatchMakingQualityMatchesCount() . '</>'
                : '<fg=red>-</>',
            $this->formatIndex(
                $template->getMatchMakingStatsPermutationIndex(),
                (int) $template->getMatchMakingStatsPermutationsIterated()
            ),
            $this->formatIndex(
                $template->getMatchMakingStatsTemplateIndex(),
                (int) $template->getMatchMakingStatsTemplatesGenerated()
            ),
            $this->formatStopReason($template->getMatchMakingStatsStopReason()),
            $this->formatTime($template->getMatchMakingStatsTime()),
        ], $orderingCells, [
            $this->formatIndex(
                $template->getOrderingStatsPermutationIndex(),
                (int) $template->getOrderingStatsPermutationsIterated()
            ),
            $template->getOrderingStatsNodesExplored() !== null
                ? '<fg=white>' . $template->getOrderingStatsNodesExplored() . '</>'
                : '<fg=red>-</>',
            $this->formatStopReason($template->getOrderingStatsStopReason()),
            $this->formatTime($template->getOrderingStatsTime()),
        ]);
    }

    /**
     * @param array{
     *     totalColumns: int,
     *     avgColumns: array{pairingMinPartnersFair:int,pairingAvgPartnersFair:int,partnersVar:int,meetingsVar:int,orderingMinDist:int,orderingAvgDist:int}
     * } $layout
     * @param array<int, float|null> $minPartnersFairs
     * @param array<int, float|null> $avgPartnersFairs
     * @param array<int, int|null>   $partnersVars
     * @param array<int, float|null> $meetingsVars
     * @param array<int, float|null> $mins
     * @param array<int, float|null> $avgs
     * @return array<int, string>
     */
    protected function buildAvgRow(
        array $layout,
        array $minPartnersFairs,
        array $avgPartnersFairs,
        array $partnersVars,
        array $meetingsVars,
        array $mins,
        array $avgs
    ): array {
        $row = array_fill(0, $layout['totalColumns'], '');
        $cols = $layout['avgColumns'];
        $row[$cols['pairingMinPartnersFair']] = $this->formatPartnersFairness($this->averageMetricValues($minPartnersFairs)) . ' (AVG)';
        $row[$cols['pairingAvgPartnersFair']] = $this->formatPartnersFairness($this->averageMetricValues($avgPartnersFairs)) . ' (AVG)';
        if (isset($cols['partnersVar'])) {
            $partnersVarAvg = $this->averageMetricValues($partnersVars);
            $row[$cols['partnersVar']] = $partnersVarAvg !== null
                ? '<fg=white>' . number_format($partnersVarAvg, 2) . '</> (AVG)'
                : '';
        }
        $row[$cols['meetingsVar']] = $this->formatMeetingsVariation($this->averageMetricValues($meetingsVars)) . ' (AVG)';
        $row[$cols['orderingMinDist']] = $this->formatDistribution(
            $this->averageMetricValues($mins),
            PlayerDistributionScorer::DISPLAY_GOOD,
            PlayerDistributionScorer::DISPLAY_FAIR
        ) . ' (AVG)';
        $row[$cols['orderingAvgDist']] = $this->formatDistribution(
            $this->averageMetricValues($avgs),
            TemplateMatchesGenerator::DISPLAY_AVG_DIST_GREEN,
            TemplateMatchesGenerator::DISPLAY_AVG_DIST_YELLOW
        ) . ' (AVG)';

        return $row;
    }

    /**
     * @param array<int, int|float|null> $values
     */
    protected function averageMetricValues(array $values): ?float
    {
        $present = array_filter($values, static fn ($v) => $v !== null);
        if ($present === []) {
            return null;
        }

        return array_sum($present) / count($present);
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
        bool $firstOfGroup,
        ?array $layout = null
    ): void {
        if ($layout === null) {
            $layout = $this->resolveUnifiedTableLayout([[
                'players' => $template->getPlayers(),
                'partners' => $template->getPartners(),
                'repeat' => $template->getRepeat(),
                'courts' => $template->getCourts(),
                'fixedTeams' => $template->isFixedTeams(),
            ]], [$template]);
        }

        $table = $this->makeUnifiedTable($output);
        $table->setHeaders($this->unifiedHeaders($layout));
        $table->addRow($this->buildUnifiedRow(
            $template,
            $template->getPlayers(),
            $template->getPartners(),
            $firstOfGroup,
            $layout
        ));
        $table->render();
    }

    /**
     * @param mixed $raw
     */
    protected function parseRequiredTemplateVersion($raw): int
    {
        if ($raw === null || $raw === '') {
            throw new \InvalidArgumentException('Missing required option: --templates-version');
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
    protected function parseMetricsCombinations(?string $raw): ?array
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

    protected function hasMetricsComboFilters(InputInterface $input): bool
    {
        $resolver = new TemplateComboResolver();
        $filters = $resolver->parseFilters($input);

        return $filters !== [] || $this->parseMetricsCombinations($input->getOption('combinations')) !== null;
    }

    /**
     * @param array<int, array<int, int>> $combinations
     * @return list<array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool}>
     */
    protected function resolveMetricsCombos(
        InputInterface $input,
        TemplateMatchesRepository $repository,
        int $version,
        array $combinations,
        bool $defaultFixedTeams
    ): array {
        $resolver = new TemplateComboResolver();
        $filters = $resolver->parseFilters($input);
        $customCombinations = $this->parseMetricsCombinations($input->getOption('combinations'));

        if (!$this->hasMetricsComboFilters($input)) {
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
