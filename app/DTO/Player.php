<?php

namespace Arshavinel\PadelMiniTour\DTO;

use Arshwell\Monolith\Text;

final class Player
{
    private $name;
    private $shortName;
    private $slugName;
    private $imagePath;

    function __construct(string $name, string $imagePath)
    {
        $this->setName($name);
        $this->imagePath = $imagePath;
    }

    public function getName(): string {
        return $this->name;
    }
    public function setName(string $name) {
        $this->name = $name;
        $this->shortName = preg_replace('/(\b.+?\b)([a-zA-Zăăâșțî])[a-zA-Zăăâșțî]+\b/', '$1$2.', $name);
        $this->slugName = Text::slug($name);
    }

    public function getSlugName(): string {
        return $this->slugName;
    }

    public function getShortName(): string {
        return $this->shortName;
    }

    public function getImagePath(): string {
        return $this->imagePath;
    }
    public function setImagePath(string $imagePath) {
        $this->imagePath = $imagePath;
    }
}
