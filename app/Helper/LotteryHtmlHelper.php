<?php

namespace Arshavinel\PadelMiniTour\Helper;

use Arshavinel\PadelMiniTour\Table\Edition;
use Arshwell\Monolith\File;
use Arshwell\Monolith\Web;

final class LotteryHtmlHelper
{
    public static function prizeMediaUrl(string $image): string
    {
        return Web::site() . 'statics/media/MiniTour-lottery/prizes/' . $image;
    }

    public static function isPrizeVideo(string $image): bool
    {
        return File::extension($image) == 'mp4';
    }

    public static function renderPrizeImageOrVideo(string $image, string $style = '', string $class = ''): string
    {
        $src = self::prizeMediaUrl($image);
        $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';
        $styleAttr = $style !== '' ? ' style="' . $style . '"' : '';

        if (self::isPrizeVideo($image)) {
            return '<video' . $classAttr . $styleAttr . ' autoplay loop muted playsinline>'
                . '<source src="' . htmlspecialchars($src, ENT_QUOTES) . '" type="video/mp4" />'
                . '</video>';
        }

        return '<img' . $classAttr . $styleAttr . ' src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="" />';
    }

    /**
     * @param object|null $nextEdition Object with `name` and `date` (Y-m-d), or null.
     */
    public static function replaceEditionNextPlaceholders(?string $text, ?object $nextEdition): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $nextName = self::readEditionAttribute($nextEdition, 'name') ?? '';
        $nextDate = self::readEditionAttribute($nextEdition, 'date');
        $nextDateShort = ($nextDate !== null && $nextDate !== '')
            ? date('d M Y', strtotime($nextDate))
            : '';

        return str_replace(
            ['{{edition.next.name}}', '{{edition.next.date.short}}'],
            [$nextName, $nextDateShort],
            $text
        );
    }

    /**
     * Monolith Table rows may expose columns as `name` or `editions.name` when joins are used.
     */
    public static function readEditionAttribute(?object $edition, string $attribute): ?string
    {
        if ($edition === null) {
            return null;
        }

        if (isset($edition->{$attribute})) {
            return (string) $edition->{$attribute};
        }

        $prefixed = Edition::TABLE . '.' . $attribute;
        if (isset($edition->{$prefixed})) {
            return (string) $edition->{$prefixed};
        }

        return null;
    }

    public static function getFirstTextSize(string $text, bool $hasImage): int
    {
        $size = 100 - (int) (strlen($text) * ($hasImage ? 1.1 : 0.9));

        return max(20, $size);
    }

    public static function getSecondTextSize(string $firstText, string $secondText): int
    {
        $size = min(self::getFirstTextSize($firstText, false), 100 - (int) (strlen($secondText)) * 1.2);

        return max(28, (int) $size * 0.9);
    }
}
