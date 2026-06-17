<?php

namespace Arshavinel\PadelMiniTour\Console;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Resolves CLI combo filters into concrete generation/stats targets.
 *
 * All of --players, --partners, --repeat, --fixed-teams, --courts are optional filters.
 * Omitted dimensions use command-specific defaults applied during expansion.
 */
final class TemplateComboResolver
{
    /**
     * @param array<int, array<int, int>> $combinations players => [partners, ...]
     * @return array{
     *     combos: list<array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool}>,
     *     isFullBulk: bool,
     *     hasComboFilters: bool
     * }
     */
    public function resolve(InputInterface $input, array $combinations, bool $defaultFixedTeams): array
    {
        $filters = $this->parseFilters($input);
        $hasComboFilters = $filters !== [];

        if (isset($filters['players']) && !isset($combinations[$filters['players']])) {
            throw new \InvalidArgumentException(sprintf(
                'No combinations defined for players=%d.',
                $filters['players']
            ));
        }

        $combos = [];
        foreach ($combinations as $players => $partnersList) {
            if (isset($filters['players']) && (int) $players !== $filters['players']) {
                continue;
            }
            foreach ($partnersList as $partners) {
                if (isset($filters['partners']) && (int) $partners !== $filters['partners']) {
                    continue;
                }
                $combos[] = [
                    'players' => (int) $players,
                    'partners' => (int) $partners,
                    'repeat' => $filters['repeat'] ?? 1,
                    'courts' => $filters['courts'] ?? 1,
                    'fixedTeams' => $filters['fixedTeams'] ?? $defaultFixedTeams,
                ];
            }
        }

        if ($combos === []) {
            throw new \InvalidArgumentException('No combos match the provided filters.');
        }

        return [
            'combos' => $combos,
            'isFullBulk' => !$hasComboFilters,
            'hasComboFilters' => $hasComboFilters,
        ];
    }

    /**
     * @return array{
     *     players?: int,
     *     partners?: int,
     *     repeat?: int,
     *     courts?: int,
     *     fixedTeams?: bool
     * }
     */
    public function parseFilters(InputInterface $input): array
    {
        $filters = [];

        $players = $input->getOption('players');
        if ($players !== null && $players !== '') {
            $filters['players'] = (int) $players;
        }

        $partners = $input->getOption('partners');
        if ($partners !== null && $partners !== '') {
            $filters['partners'] = (int) $partners;
        }

        $repeat = $input->getOption('repeat');
        if ($repeat !== null && $repeat !== '') {
            $filters['repeat'] = (int) $repeat;
        }

        $fixedTeams = $input->getOption('fixed-teams');
        if ($fixedTeams !== null && $fixedTeams !== '') {
            $filters['fixedTeams'] = $this->parseBool((string) $fixedTeams);
        }

        if ($input->hasParameterOption('--courts', true)) {
            $courts = $input->getOption('courts');
            if ($courts !== null && $courts !== '') {
                $filters['courts'] = max(1, (int) $courts);
            }
        }

        return $filters;
    }

    private function parseBool(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new \InvalidArgumentException(sprintf('Invalid boolean value: "%s". Use 0/1 or true/false.', $value));
    }
}
