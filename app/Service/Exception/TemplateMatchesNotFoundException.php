<?php

namespace Arshavinel\PadelMiniTour\Service\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown by {@see \Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository::find()} when the
 * requested template JSON file does not exist or cannot be decoded.
 *
 * Carries the absolute filesystem path the repository tried to read so the message points the
 * engineer at the exact file to regenerate.
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
        bool $fixedTeams,
        ?Throwable $previous = null
    ): self {
        $combo = sprintf(
            'players=%d, partners=%d, repeat=%d, fixedTeams=%s',
            $players,
            $partners,
            $repeat,
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
