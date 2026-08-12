<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ColorScheme;
use App\Repository\BoardConfigRepository;
use App\Repository\ColorSchemeRepository;
use App\Tests\Support\BoardWebTestCase;

/**
 * Which scheme a page renders in.
 *
 * The order of precedence is the member's own choice, then the board setting,
 * then the scheme with the lowest position. That last step is the whole reason
 * the setting exists: without it, choosing a default and deciding what appears
 * first in the picker were the same decision.
 */
final class DefaultSchemeTest extends BoardWebTestCase
{
    public function testAGuestGetsTheConfiguredDefault(): void
    {
        $this->setDefault($this->scheme('matrix'));

        self::assertSame('matrix', $this->renderedScheme('/'));
    }

    /** The setting must win over position, or it is not a setting. */
    public function testTheSettingOverridesTheOrderOfThePicker(): void
    {
        $first = $this->container()->get(ColorSchemeRepository::class)->findDefault();
        self::assertNotNull($first);

        $other = $this->scheme('matrix');
        self::assertNotSame($first->getSlug(), $other->getSlug(), 'Pick a scheme that is not already first.');

        $this->setDefault($other);

        self::assertSame('matrix', $this->renderedScheme('/'));
        self::assertSame(
            $first->getSlug(),
            $this->container()->get(ColorSchemeRepository::class)->findDefault()?->getSlug(),
            'The picker order should be untouched by the setting.',
        );
    }

    public function testAMemberKeepsTheirOwnChoice(): void
    {
        $this->setDefault($this->scheme('matrix'));

        $member = $this->signInAs('Member');
        $member->setColorScheme($this->scheme('nord'));
        $this->em()->flush();

        self::assertSame('nord', $this->renderedScheme('/'));
    }

    /** An unset default keeps the behaviour every existing board already had. */
    public function testWithNoSettingItFallsBackToTheFirstScheme(): void
    {
        $this->setDefault(null);

        $expected = $this->container()->get(ColorSchemeRepository::class)->findDefault();
        self::assertNotNull($expected);

        self::assertSame($expected->getSlug(), $this->renderedScheme('/'));
    }

    public function testTheStylesheetAndBannerFollowTheSetting(): void
    {
        $xeon = $this->container()->get(ColorSchemeRepository::class)->findOneBy(['slug' => 'xeon']);
        if (null === $xeon) {
            self::markTestSkipped('The xeon scheme is not seeded here.');
        }

        $this->setDefault($xeon);
        $html = (string) $this->client->request('GET', '/')->html();

        self::assertStringContainsString('css/schemes/xeon.css', $html);
        self::assertStringContainsString('images/title-xeon.png', $html);
    }

    public function testAnAdministratorCanChangeItFromTheConfigForm(): void
    {
        $this->signInAs('Owner');
        $crawler = $this->assertPageLoads('/admin/config');

        self::assertGreaterThan(
            0,
            $crawler->filter('[name="board_config[defaultScheme]"]')->count(),
            'The config form does not offer a default scheme.',
        );
    }

    // ------------------------------------------------------------------

    /** The slug the page actually rendered in, taken from the body class. */
    private function renderedScheme(string $uri): string
    {
        $crawler = $this->assertPageLoads($uri);
        $class = (string) $crawler->filter('body')->attr('class');

        self::assertMatchesRegularExpression('/scheme-[a-z0-9-]+/', $class, 'No scheme class on the body.');
        preg_match('/scheme-([a-z0-9-]+)/', $class, $m);

        return $m[1];
    }

    private function scheme(string $slug): ColorScheme
    {
        $scheme = $this->container()->get(ColorSchemeRepository::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($scheme, \sprintf('The "%s" scheme is missing.', $slug));

        return $scheme;
    }

    private function setDefault(?ColorScheme $scheme): void
    {
        $config = $this->container()->get(BoardConfigRepository::class)->get();
        $config->setDefaultScheme($scheme);
        $this->em()->flush();
    }
}
