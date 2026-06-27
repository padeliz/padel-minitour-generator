<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Pairing-phase behaviour for large partner pools (phase reflection only).
 */
final class TemplateMatchesGeneratorPairingTest extends GeneratorTestCase
{
    public function test_eleven_eight_pool_is_complete_with_uniform_partner_counts(): void
    {
        $generator = new TemplateMatchesGenerator();
        $result = $this->invokePairingPhase($generator, 11, 8);

        $this->assertSame(44, $result['pairCount']);
        $this->assertSame(0, $result['partnersCountVariation']);
        $this->assertCount(11, $result['partnersCount']);
        foreach ($result['partnersCount'] as $count) {
            $this->assertSame(8, $count);
        }
    }
}
