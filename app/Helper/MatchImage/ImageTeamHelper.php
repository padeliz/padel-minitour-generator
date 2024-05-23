<?php

namespace Arshavinel\PadelMiniTour\Helper\MatchImage;

use Arshavinel\PadelMiniTour\DTO\Player;
use Arshavinel\PadelMiniTour\Table\Match\Team;
use Arshwell\Monolith\Math;
use Arshwell\Monolith\Table\Files\Image;
use Exception;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Gd\Imagine;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Imagine\Image\PointSigned;

final class ImageTeamHelper
{
    const SQUARE_SIZE = 200; // Square size in pixels

    static function getImageUrl(Player $playerOne, Player $playerTwo): string
    {
        $team = Team::first(
            [
                'columns' => Team::PRIMARY_KEY,
                'where' => "player_1 LIKE ? AND player_2 LIKE ?",
                'files' => true
            ],
            [$playerOne->getName(), $playerTwo->getName()]
        );

        if ($team && $team->file('avatars') && $team->file('avatars')->url('medium')) {
            return $team->file('avatars')->url('medium');
        }

        return self::generateImage($playerOne, $playerTwo, ($team ? $team->id() : null));
    }


    private static function generateImage(Player $playerOne, Player $playerTwo, int $teamId = null): string
    {
        ini_set('max_execution_time', ini_get('max_execution_time') + 2);

        $imagine = new Imagine(); // init


        $image1 = $imagine->open(self::getPlayerAvatarPath($playerOne));
        $image2 = $imagine->open(self::getPlayerAvatarPath($playerTwo));


        self::resizeImage($image1, self::SQUARE_SIZE);
        self::resizeImage($image2, self::SQUARE_SIZE);

        // Get dimensions
        $size1 = $image1->getSize();
        $size2 = $image2->getSize();


        // Calculate the center crop dimensions for the first image
        $cropWidth1 = $cropHeight1 = min($size1->getWidth(), $size1->getHeight()); // Use the minimum dimension

        // Calculate the center crop dimensions for the second image
        $cropWidth2 = $cropHeight2 = min($size2->getWidth(), $size2->getHeight()); // Use the minimum dimension

        // Crop the center parts of the first and second images
        $image1->crop(new Point(($cropWidth1 / 4), 0), new Box($cropWidth1 / 2, $cropHeight1));
        $image2->crop(new Point(($cropWidth2 / 4), 0), new Box($cropWidth2 / 2, $cropHeight2));

        $palette = new RGB();

        // Create a new canvas with the specified square size
        $canvas = $imagine->create(new Box(self::SQUARE_SIZE, self::SQUARE_SIZE), $palette->color('#FFFFFF'));

        // Paste the images onto the canvas
        $canvas->paste($image1, new PointSigned(0, 0)); //  at (0, 0)
        $canvas->paste($image2, new PointSigned(self::SQUARE_SIZE / 2, 0)); // from the middle

        // Make the corners of the canvas round
        $canvas->applyMask(self::createRoundMask(self::SQUARE_SIZE));

        // Add a vertical white line in the middle of the canvas
        $canvas->draw()->line(
            new Point(self::SQUARE_SIZE / 2, 0),
            new Point(self::SQUARE_SIZE / 2, self::SQUARE_SIZE),
            $palette->color('#fff'),
            3
        );

        if (empty($teamId)) {
            $teamId = Team::insert(
                "player_1, player_2, inserted_at",
                "?, ?, UNIX_TIMESTAMP()",
                [$playerOne->getName(), $playerTwo->getName()]
            );
        }

        $tempDirPath = sys_get_temp_dir() . '/arshpadelminitour';
        $tempFilePath = $tempDirPath . '/' . $playerOne->getSlugName() . '-' . $playerTwo->getSlugName() . '.png';

        if (!file_exists($tempDirPath)) {
            if (!mkdir($tempDirPath, 0777, true)) {
                throw new \RuntimeException('Failed to create directory: ' . $tempDirPath);
            }
        }

        $canvas->save($tempFilePath);

        $avatars = new Image(Team::class, $teamId, 'avatars');

        $avatars->update([
            'name' => basename($tempFilePath),
            'type' => mime_content_type($tempFilePath),
            'tmp_name' => $tempFilePath,
            'error' => 0,
            'size' => filesize($tempFilePath)
        ]);

        return $avatars->url('medium');
    }

    private static function getPlayerAvatarPath(Player $player): string
    {
        $imageFilePath = 'statics/media/MiniTour-participants/' . $player->getSlugName();

        if (is_file($imageFilePath . '.png')) {
            return $imageFilePath . '.png';
        } elseif (is_file($imageFilePath . '.jpg')) {
            return $imageFilePath . '.jpg';
        } elseif (is_file($imageFilePath . '.jpeg')) {
            return $imageFilePath . '.jpeg';
        }

        throw new Exception('No avatar found for: ' . $player->getName() . ' (' . $player->getSlugName() . ')');
    }

    private static function resizeImage(ImageInterface $image, int $squareSize)
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
    private static function createRoundMask($size)
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
