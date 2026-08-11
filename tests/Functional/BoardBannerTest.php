<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ColorScheme;
use App\Repository\ColorSchemeRepository;
use App\Tests\Support\BoardWebTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The banner across the top of every page.
 *
 * The original set $boardtitle per scheme: most inherited images/title2.jpg, a few
 * shipped their own, and "classic" printed styled text instead of a picture. The port
 * copied all fifteen title images across and then never referenced any of them, so
 * every scheme showed the board's name as plain text. These tests are what would have
 * noticed.
 */
final class BoardBannerTest extends BoardWebTestCase
{
    public function testASchemeWithABannerRendersItAsAnImage(): void
    {
        $user = $this->signInAs('Member');
        $user->setColorScheme($this->scheme('oldblue'));
        $this->em()->flush();

        $crawler = $this->assertPageLoads('/');
        $banner = $crawler->filter('.board-title img');

        self::assertCount(1, $banner, 'The scheme has a banner but none was rendered.');
        self::assertStringContainsString('title.jpg', (string) $banner->attr('src'));
    }

    /**
     * "classic" deliberately has no banner: the original printed the board's name in
     * a large font rather than showing a picture.
     */
    public function testASchemeWithoutABannerFallsBackToTheBoardName(): void
    {
        $user = $this->signInAs('Member');
        $user->setColorScheme($this->scheme('classic'));
        $this->em()->flush();

        $crawler = $this->assertPageLoads('/');

        self::assertCount(0, $crawler->filter('.board-title img'));
        self::assertStringContainsString("Acmlm's Board", $crawler->filter('.board-title')->text());
    }

    /**
     * The board has to remain identifiable with images off, and announce itself to a
     * screen reader. The original's banner was a bare <img> with no alt at all.
     */
    public function testTheBannerCarriesTheBoardNameAsAltText(): void
    {
        $user = $this->signInAs('Member');
        $user->setColorScheme($this->scheme('nes'));
        $this->em()->flush();

        $crawler = $this->assertPageLoads('/');

        self::assertSame("Acmlm's Board", $crawler->filter('.board-title img')->attr('alt'));
    }

    public function testTheBannerLinksHome(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/');

        self::assertCount(1, $crawler->filter('.board-title a'));
    }

    /**
     * A banner naming a file that is not there would show a broken image on every
     * page of the board for anyone using that scheme.
     */
    public function testEverySeededBannerPointsAtAFileThatExists(): void
    {
        $publicDir = $this->container()->getParameter('kernel.project_dir').'/public';
        $checked = 0;

        foreach ($this->container()->get(ColorSchemeRepository::class)->findAllOrdered() as $scheme) {
            $banner = $scheme->getTitleImage();
            if (null === $banner) {
                continue;
            }

            self::assertFileExists($publicDir.$banner, \sprintf('Scheme "%s" names a missing banner.', $scheme->getSlug()));
            ++$checked;
        }

        self::assertGreaterThan(15, $checked, 'Almost no scheme has a banner; the data did not load.');
    }

    public function testAtLeastOneSchemeUsesEachOfTheOriginalBanners(): void
    {
        $banners = [];
        foreach ($this->container()->get(ColorSchemeRepository::class)->findAllOrdered() as $scheme) {
            if (null !== $scheme->getTitleImage()) {
                $banners[$scheme->getTitleImage()] = true;
            }
        }

        // The five distinct banners the original schemes actually referenced.
        foreach ([
            '/images/title2.jpg',
            '/images/title.jpg',
            '/images/title2b.jpg',
            '/images/title2dani.jpg',
            '/images/title2nes.png',
            '/images/kafuka.jpg',
        ] as $expected) {
            self::assertArrayHasKey($expected, $banners, $expected.' is no longer used by any scheme.');
        }
    }

    /**
     * The banner is a path from the database rendered straight into an <img src>.
     * Constraining it to /images/ means a writable scheme row cannot point the board
     * at another site, or at a javascript: URL.
     */
    public function testABannerOutsideTheImagesDirectoryIsRejected(): void
    {
        $validator = $this->container()->get(ValidatorInterface::class);

        foreach ([
            'https://example.test/evil.png',
            'javascript:alert(1)',
            '/../../etc/passwd',
            '/images/../../secret.png',
            '/images/notanimage.php',
        ] as $bad) {
            $scheme = new ColorScheme('probe', 'Probe');
            $scheme->setTitleImage($bad);

            self::assertGreaterThan(
                0,
                $validator->validate($scheme)->count(),
                \sprintf('"%s" was accepted as a banner path.', $bad),
            );
        }
    }

    public function testAnOrdinaryBannerPathIsAccepted(): void
    {
        $validator = $this->container()->get(ValidatorInterface::class);

        $scheme = new ColorScheme('probe', 'Probe');
        $scheme->setTitleImage('/images/title2.jpg');

        self::assertCount(0, $validator->validate($scheme));
    }

    /** An empty string is stored as null, so it falls back rather than emitting <img src="">. */
    public function testAnEmptyBannerIsTreatedAsNone(): void
    {
        $scheme = new ColorScheme('probe', 'Probe');
        $scheme->setTitleImage('');

        self::assertNull($scheme->getTitleImage());
    }

    private function scheme(string $slug): ColorScheme
    {
        $scheme = $this->container()->get(ColorSchemeRepository::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($scheme, \sprintf('The "%s" scheme is missing.', $slug));

        return $scheme;
    }
}
