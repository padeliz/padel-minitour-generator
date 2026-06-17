<?php

namespace Arshavinel\PadelMiniTour\Service\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown by {@see \Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository::find()} when the
 * requested template JSON file does not exist or cannot be decoded.
 */
final class TemplateMatchesNotFoundException extends RuntimeException
{
    private string $expectedPath;

    public function __construct(string $message, string $expectedPath, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->expectedPath = $expectedPath;
    }

    public function getExpectedPath(): string
    {
        return $this->expectedPath;
    }

    public static function forCombo(
        string $expectedPath,
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams,
        ?Throwable $previous = null
    ): self {
        $combo = sprintf(
            'players=%d, partners=%d, repeat=%d, courts=%d, fixedTeams=%s',
            $players,
            $partners,
            $repeat,
            $courts,
            $fixedTeams ? 'true' : 'false'
        );

        $message = sprintf(
            'No committed template for %s. Expected file: %s. Run "php bin/console templates:regenerate" to produce it.',
            $combo,
            $expectedPath
        );

        return new self($message, $expectedPath, $previous);
    }
}
