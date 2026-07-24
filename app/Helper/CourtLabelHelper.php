<?php

namespace Arshavinel\PadelMiniTour\Helper;

/**
 * Parses legacy edition_divisions.court labels into clean name + is_indoor.
 */
final class CourtLabelHelper
{
    /**
     * @return array{name: string, is_indoor: bool|null} is_indoor null when label is silent
     */
    public static function parse(string $rawCourt): array
    {
        $trimmed = trim($rawCourt);
        $isIndoor = null;

        if (preg_match('/\((indoor)\)/iu', $trimmed)) {
            $isIndoor = true;
            $trimmed = preg_replace('/\s*\(indoor\)/iu', '', $trimmed) ?? $trimmed;
        } elseif (preg_match('/\((outdoor)\)/iu', $trimmed)) {
            $isIndoor = false;
            $trimmed = preg_replace('/\s*\(outdoor\)/iu', '', $trimmed) ?? $trimmed;
        }

        $name = trim(preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed);

        return [
            'name' => $name,
            'is_indoor' => $isIndoor,
        ];
    }

    public static function resolveIsIndoor(?bool $fromLabel, int $totalIndoors, int $totalOutdoors): bool
    {
        if ($fromLabel !== null) {
            return $fromLabel;
        }

        return $totalIndoors >= $totalOutdoors;
    }
}
