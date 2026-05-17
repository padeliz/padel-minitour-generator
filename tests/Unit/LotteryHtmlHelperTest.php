<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Helper\LotteryHtmlHelper;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

final class LotteryHtmlHelperTest extends TestCase
{
    public function test_is_prize_video(): void
    {
        $this->assertTrue(LotteryHtmlHelper::isPrizeVideo('clip.mp4'));
        $this->assertFalse(LotteryHtmlHelper::isPrizeVideo('photo.png'));
    }

    public function test_render_prize_image_or_video(): void
    {
        $this->assertStringContainsString('<video', LotteryHtmlHelper::renderPrizeImageOrVideo('clip.mp4'));
        $this->assertStringContainsString('type="video/mp4"', LotteryHtmlHelper::renderPrizeImageOrVideo('clip.mp4'));
        $this->assertStringContainsString('<img', LotteryHtmlHelper::renderPrizeImageOrVideo('photo.png'));
    }

    public function test_replace_edition_next_placeholders(): void
    {
        $next = (object) ['name' => 'Spring Cup', 'date' => '2026-06-15'];
        $text = 'See you at {{edition.next.name}} on {{edition.next.date.short}}';

        $this->assertSame(
            'See you at Spring Cup on 15 Jun 2026',
            LotteryHtmlHelper::replaceEditionNextPlaceholders($text, $next)
        );
        $this->assertSame('See you at  on ', LotteryHtmlHelper::replaceEditionNextPlaceholders($text, null));

        $prefixed = (object) ['editions.name' => 'Prefixed Cup', 'editions.date' => '2026-07-01'];
        $this->assertSame(
            'See you at Prefixed Cup on 01 Jul 2026',
            LotteryHtmlHelper::replaceEditionNextPlaceholders($text, $prefixed)
        );
    }

    public function test_read_edition_attribute(): void
    {
        $this->assertSame('A', LotteryHtmlHelper::readEditionAttribute((object) ['name' => 'A'], 'name'));
        $this->assertSame('B', LotteryHtmlHelper::readEditionAttribute((object) ['editions.name' => 'B'], 'name'));
        $this->assertNull(LotteryHtmlHelper::readEditionAttribute(null, 'name'));
    }
}
