<?php

namespace Arshavinel\PadelMiniTour\Helper;

final class CourtNamesHelper
{
    public const MAX_COURTS = 4;

    /**
     * @param mixed $raw Typically `$_GET['court-names']`.
     * @return array<int, string>
     */
    public static function normalizeFromRequest($raw): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('court-names must be a non-empty array.');
        }

        $names = [];
        foreach ($raw as $name) {
            $trimmed = trim((string) $name);
            if ($trimmed !== '') {
                $names[] = $trimmed;
            }
        }

        if ($names === []) {
            throw new \InvalidArgumentException('At least one court name is required.');
        }

        if (count($names) > self::MAX_COURTS) {
            throw new \InvalidArgumentException(sprintf('At most %d courts are allowed.', self::MAX_COURTS));
        }

        $lower = array_map(static fn(string $n): string => mb_strtolower($n), $names);
        if (count($lower) !== count(array_unique($lower))) {
            throw new \InvalidArgumentException('Court names must be unique (case-insensitive).');
        }

        return $names;
    }
}
