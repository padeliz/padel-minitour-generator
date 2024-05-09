<?php

namespace Arshavinel\PadelMiniTour\DTO;

use Arshwell\Monolith\Text;

final class Player
{
    private string $name;
    private string $shortName;
    private string $htmlShortName;
    private string $slugName;
    private string $imagePath;

    public function __construct(string $name, string $imagePath)
    {
        $this->setName($name);
        $this->imagePath = $imagePath;
    }

    public function getName(): string {
        return $this->name;
    }
    public function setName(string $name) {
        $this->name = $name;
        $this->shortName = preg_replace(
            '/(\b.+?\b)([a-zA-Zăâșțî])[a-zA-Zăâșțî]+\b/u',
            '$1$2.',
            $name
        );
        $this->htmlShortName = preg_replace(
            '/([a-zA-Zăâșțî]\.)/u',
            '<small style="font-weight: 400; color: #444;"><small>$1</small></small>',
            $this->shortName
        );
        $this->slugName = Text::slug($name);
    }

    public function getSlugName(): string {
        return $this->slugName;
    }

    public function getShortName(): string {
        return $this->shortName;
    }

    public function getHtmlShortName(): string
    {
        return $this->htmlShortName;
    }

    public function getImagePath(): string {
        return $this->imagePath;
    }
    public function setImagePath(string $imagePath) {
        $this->imagePath = $imagePath;
    }
}
