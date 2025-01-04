<?php

namespace Arshavinel\PadelMiniTour\DTO;

use Arshavinel\PadelMiniTour\Helper\StaticPdfAvatarHelper;
use Arshavinel\PadelMiniTour\Table\Player;
use Arshwell\Monolith\Text;
use Exception;
use Arshwell\Monolith\Table\Files\Image;
use Imagine\Image\Box;
use Imagine\Gd\Imagine;
use Imagine\Image\Palette\RGB;
use Imagine\Image\PointSigned;

final class PdfPlayer
{
    private string $name;
    private string $shortName;
    private string $htmlShortName;
    private string $slugName;
    private string $staticPhotoPath;
    private string $avatarUrl;

    public function __construct(string $name)
    {
        $player = Player::findOrFailByName($name);

        $this->setName($player);
        $this->staticPhotoPath = $this->findStaticPhotoPath();
        $this->avatarUrl = $this->generateAvatar($player);
    }

    public function getName(): string
    {
        return $this->name;
    }
    public function getSlugName(): string
    {
        return $this->slugName;
    }
    public function getShortName(): string
    {
        return $this->shortName;
    }
    public function getHtmlShortName(): string
    {
        return $this->htmlShortName;
    }

    public function getStaticPhotoPath(): string
    {
        return $this->staticPhotoPath;
    }

    public function getAvatarUrl(): string
    {
        return $this->avatarUrl;
    }


    private function setName(Player $player)
    {
        $this->name = $player->name;
        $this->shortName = preg_replace(
            '/(\b.+?\b)([a-zA-ZăâșşȘțȚî])[a-zA-ZăâșşȘțȚî]+\b/u',
            '$1$2.',
            $this->name
        );
        $this->htmlShortName = preg_replace(
            '/([a-zA-ZăâșşȘțȚî]\.)/u',
            '<small style="font-weight: 400; color: #444;"><small>$1</small></small>',
            $this->shortName
        );
        $this->slugName = Text::slug($this->name);
    }

    private function generateAvatar(Player $player): string
    {
        if ($player->file('avatar') && $player->file('avatar')->url('medium')) {
            $last_update_at = max($player->inserted_at, $player->updated_at ?: 0);

            if (filemtime($this->getStaticPhotoPath()) < $last_update_at) {
                return $player->file('avatar')->url('medium');
            }

            /* else, a new player photo has been uploaded, so regenerate the avatar image */
        }

        ini_set('max_execution_time', ini_get('max_execution_time') + 2);

        $imagine = new Imagine(); // init


        $photo = $imagine->open($this->getStaticPhotoPath());


        StaticPdfAvatarHelper::resizeImage($photo, StaticPdfAvatarHelper::SQUARE_SIZE);

        $palette = new RGB();

        // Create a new canvas with the specified square size
        $canvas = $imagine->create(new Box(StaticPdfAvatarHelper::SQUARE_SIZE, StaticPdfAvatarHelper::SQUARE_SIZE), $palette->color('#FFFFFF'));

        // Paste the image onto the canvas
        $canvas->paste($photo, new PointSigned(0, 0)); // at (0, 0)

        // Make the corners of the canvas round
        $canvas->applyMask(StaticPdfAvatarHelper::createRoundMask(StaticPdfAvatarHelper::SQUARE_SIZE));

        Player::update([
            'set' => "updated_at = UNIX_TIMESTAMP()",
            'where' => "id_player = ?"
        ], [$player->id()]);

        $tempDirPath = sys_get_temp_dir() . '/arshpadelminitour';
        $tempFilePath = $tempDirPath . '/avatar-' . $this->getSlugName() . '.png';

        if (!file_exists($tempDirPath) && !mkdir($tempDirPath, 0777, true)) {
            throw new \RuntimeException('Failed to create directory: ' . $tempDirPath);
        }

        $canvas->save($tempFilePath);

        $avatar = new Image(Player::class, $player->id(), 'avatar');

        $avatar->update([
            'name' => basename($tempFilePath),
            'type' => mime_content_type($tempFilePath),
            'tmp_name' => $tempFilePath,
            'error' => 0,
            'size' => filesize($tempFilePath)
        ]);

        return $avatar->url('medium');
    }

    private function findStaticPhotoPath(): string
    {
        $imageFilePath = 'statics/media/MiniTour-participants/' . $this->slugName;

        if (is_file($imageFilePath . '.png')) {
            return $imageFilePath . '.png';
        } elseif (is_file($imageFilePath . '.jpg')) {
            return $imageFilePath . '.jpg';
        } elseif (is_file($imageFilePath . '.jpeg')) {
            return $imageFilePath . '.jpeg';
        }

        throw new Exception('No static photo found for: ' . $this->name . ' (' . $this->slugName . ')');
    }
}
