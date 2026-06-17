<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use PHPUnit\Framework\TestCase;

final class TemplateMatchesRoundScheduleTest extends TestCase
{
    public function test_has_valid_round_schedule_accepts_disjoint_multi_court_rounds(): void
    {
        $matchesByCourt = [
            [
                [[0, 1], [2, 3]],
                [[0, 2], [4, 6]],
            ],
            [
                [[4, 5], [6, 7]],
                [[1, 3], [5, 7]],
            ],
        ];

        $this->assertTrue(TemplateMatches::hasValidRoundSchedule($matchesByCourt));
    }

    public function test_has_valid_round_schedule_rejects_cross_court_player_overlap(): void
    {
        $matchesByCourt = [
            [
                [[0, 1], [2, 3]],
            ],
            [
                [[0, 4], [5, 6]],
            ],
        ];

        $this->assertFalse(TemplateMatches::hasValidRoundSchedule($matchesByCourt));
    }

    public function test_has_valid_round_schedule_allows_sparse_partial_final_round(): void
    {
        $matchesByCourt = [
            [
                [[0, 1], [2, 3]],
                [[0, 2], [1, 3]],
            ],
            [
                [[4, 5], [6, 7]],
            ],
        ];

        $this->assertTrue(TemplateMatches::hasValidRoundSchedule($matchesByCourt));
    }

    public function test_repository_saves_ineligible_template_with_null_matches(): void
    {
        $tempBaseDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'padel-template-round-schedule-'
            . bin2hex(random_bytes(4));
        mkdir($tempBaseDir, 0775, true);

        try {
            $repo = new TemplateMatchesRepository($tempBaseDir);
            $template = new TemplateMatches(
                12,
                8,
                1,
                2,
                false,
                null,
                0.0,
                0,
                null,
                0,
                null,
                null,
                null,
                0,
                null,
                TemplateMatchesGenerator::STOP_REASON_DEADLINE,
                0.0,
                TemplateMatchesGenerator::STOP_REASON_DEADLINE,
                null,
                null,
                null,
                null,
                null,
                null,
                0.0
            );

            $repo->save(99, $template);
            $loaded = $repo->findAt(99, 12, 8, 1, 2, false);

            $this->assertFalse($loaded->isEligible());
            $this->assertFalse($loaded->isUsable());
            $this->assertNull($loaded->getMatches());
        } finally {
            $this->removeDirRecursive($tempBaseDir);
        }
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
