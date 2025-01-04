<?php

namespace Arshavinel\PadelMiniTour\Helper;

use Arshwell\Monolith\Math;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Gd\Imagine;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;

final class StaticPdfAvatarHelper
{
    const SQUARE_SIZE = 200; // Square size in pixels

    public static function resizeImage(ImageInterface $image, int $squareSize)
    {
        $size = $image->getSize();

        if ($size->getWidth() > $size->getHeight()) {
            $width = Math::resizeKeepingRatio($size->getHeight(), $size->getWidth(), $squareSize);
            $height = $squareSize;
        } elseif ($size->getWidth() == $size->getHeight()) {
            $width = $height = $squareSize;
        } else {
            $width = $squareSize;
            $height = Math::resizeKeepingRatio($size->getWidth(), $size->getHeight(), $squareSize);
        }

        $image->resize(new Box($width, $height));
    }

    /**
     * Create a rounded corner mask.
     *
     * @param int $size Size of the mask.
     *
     * @return ImageInterface Mask image.
     */
    public static function createRoundMask($size): ImageInterface
    {
        $imagine = new Imagine();
        $mask = $imagine->create(new Box($size, $size));

        $palette = new RGB();

        $mask->draw()
            ->ellipse(
                new Point($size / 2, $size / 2),
                new Box($size, $size),
                $palette->color('#000'),
                true
            );

        return $mask;
    }
}
