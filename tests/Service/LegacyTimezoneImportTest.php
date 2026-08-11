<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Command\ImportLegacyCommand;
use PHPUnit\Framework\TestCase;

/**
 * The legacy importer's offset-to-timezone mapping.
 *
 * The old board stored a float number of hours from board time. Every value it can
 * produce has to become a zone that DateTimeZone::ALL contains - that is the set
 * Assert\Timezone accepts and the set the profile form is built from. Get this wrong
 * and imported members cannot save their profile, which is not something an import
 * would report; it would surface months later as one person saying the settings page
 * is broken.
 *
 * Etc/GMT+/-N is the trap: those identifiers construct fine and keep correct time, but
 * they are in PHP's backward-compatible set rather than ALL.
 */
final class LegacyTimezoneImportTest extends TestCase
{
    /** @return iterable<string, array{float}> every offset the legacy column plausibly held */
    public static function legacyOffsets(): iterable
    {
        // Whole hours across the entire range, plus the half- and quarter-hour
        // offsets that real places use.
        for ($hours = -14.0; $hours <= 14.0; $hours += 0.5) {
            yield (string) $hours => [$hours];
        }

        // The quarter-hour offsets; the halves are already covered by the loop.
        foreach ([5.75, 8.75, 12.75] as $odd) {
            yield (string) $odd => [$odd];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyOffsets')]
    public function testEveryOffsetMapsToAZoneTheBoardAccepts(float $hours): void
    {
        $zone = $this->map($hours);

        self::assertContains(
            $zone,
            \DateTimeZone::listIdentifiers(\DateTimeZone::ALL),
            \sprintf('Offset %.2f produced "%s", which the validator rejects.', $hours, $zone),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyOffsets')]
    public function testEveryOffsetProducesAConstructibleZone(float $hours): void
    {
        // Redundant with the list check only until someone "fixes" that list.
        new \DateTimeZone($this->map($hours));

        $this->expectNotToPerformAssertions();
    }

    public function testRecognisedOffsetsKeepTheMembersClockRight(): void
    {
        // Compared in January, when the northern hemisphere is on standard time and
        // the mapping's offsets are the ones the old column recorded.
        $january = new \DateTimeImmutable('2026-01-15 12:00:00', new \DateTimeZone('UTC'));

        // Pairs, not a keyed map. PHP casts float array keys to int, so 5.0 and 5.5
        // would collide on key 5 and the case would silently disappear - which is
        // the exact fault that left the percentile rank ladder with one rung.
        $cases = [
            [-8.0, -480],   // America/Los_Angeles
            [-6.0, -360],   // America/Chicago
            [-5.0, -300],   // America/New_York
            [0.0, 0],       // UTC
            [1.0, 60],      // Europe/Berlin
            [5.0, 300],     // Asia/Karachi
            [5.5, 330],     // Asia/Kolkata
            [9.0, 540],     // Asia/Tokyo
        ];

        foreach ($cases as [$hours, $expectedMinutes]) {
            $zone = new \DateTimeZone($this->map((float) $hours));

            self::assertSame(
                $expectedMinutes,
                intdiv($zone->getOffset($january), 60),
                \sprintf('Offset %.1f mapped to %s, which is at the wrong offset.', $hours, $zone->getName()),
            );
        }
    }

    public function testNoOffsetProducesAnEtcZone(): void
    {
        foreach (range(-14, 14) as $hours) {
            self::assertStringStartsNotWith(
                'Etc/',
                $this->map((float) $hours),
                'Etc zones are not in DateTimeZone::ALL, so the board cannot validate or offer them.',
            );
        }
    }

    public function testAnUnrecognisableOffsetFallsBackToUtcRatherThanGuessing(): void
    {
        foreach ([99.0, -99.0, 0.17] as $nonsense) {
            self::assertSame('UTC', $this->map($nonsense));
        }
    }

    /** The mapping is private; it is reached the way the importer reaches it. */
    private function map(float $hours): string
    {
        $method = new \ReflectionMethod(ImportLegacyCommand::class, 'offsetToTimezone');

        return $method->invoke(
            (new \ReflectionClass(ImportLegacyCommand::class))->newInstanceWithoutConstructor(),
            $hours,
        );
    }
}
