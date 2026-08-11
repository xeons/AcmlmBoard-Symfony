<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\ItemRepository;
use App\Repository\RpgProfileRepository;
use App\Service\RpgStatusImage;
use App\Tests\Support\BoardWebTestCase;

/**
 * The RPG status screen PNG, ported from status.php.
 *
 * The image is drawn with GD from a bitmap font sheet, so the things worth asserting
 * are that it is a real PNG of the right size, that it survives the awkward inputs
 * (no posts, no equipment, a very long name, enormous stats), and that the shop's
 * preview actually changes what is drawn.
 */
final class RpgStatusImageTest extends BoardWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!\extension_loaded('gd')) {
            self::markTestSkipped('GD is not available.');
        }
    }

    public function testTheRouteServesAPngOfTheRightSize(): void
    {
        $this->signInAs('Member');
        $this->client->request('GET', '/rpg/status/'.$this->id('user', 'Member').'.png');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));

        $image = imagecreatefromstring($response->getContent());
        self::assertNotFalse($image, 'The response is not a readable image.');
        self::assertSame(256, imagesx($image));
        self::assertSame(224, imagesy($image));
    }

    public function testTheImageIsNotCachedAcrossMembers(): void
    {
        $this->signInAs('Member');
        $this->client->request('GET', '/rpg/status/'.$this->id('user', 'Member').'.png');

        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
    }

    public function testAnUnknownMemberIsNotFound(): void
    {
        $this->signInAs('Member');
        $this->client->request('GET', '/rpg/status/999999.png');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** Equipping something has to change the picture, or the preview is pointless. */
    public function testPreviewingAnItemChangesTheImage(): void
    {
        $this->signInAs('Member');
        $base = '/rpg/status/'.$this->id('user', 'Member').'.png';

        $this->client->request('GET', $base);
        $plain = $this->client->getResponse()->getContent();

        $item = $this->container()->get(ItemRepository::class)->findAll()[0] ?? null;
        self::assertNotNull($item, 'The shop has no stock to preview.');

        $this->client->request('GET', $base.'?item='.$item->getId());
        $previewed = $this->client->getResponse()->getContent();

        self::assertNotSame($plain, $previewed, 'The preview rendered the same picture as the plain screen.');
        self::assertNotFalse(imagecreatefromstring($previewed));
    }

    public function testPreviewingDoesNotEquipTheItem(): void
    {
        $user = $this->signInAs('Member');
        $profiles = $this->container()->get(RpgProfileRepository::class);
        $before = $profiles->findOrCreateFor($user)->getLoadout();

        $item = $this->container()->get(ItemRepository::class)->findAll()[0];
        $this->client->request('GET', '/rpg/status/'.$user->getId().'.png?item='.$item->getId());

        $this->em()->clear();
        self::assertSame(
            $before,
            $profiles->findOrCreateFor($this->user('Member'))->getLoadout(),
            'Previewing an item changed what the member is wearing.',
        );
    }

    public function testAnUnknownItemIsIgnoredRatherThanFailing(): void
    {
        $this->signInAs('Member');
        $this->client->request('GET', '/rpg/status/'.$this->id('user', 'Member').'.png?item=999999');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Awkward input
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{int, int}> posts, days registered
     */
    public static function accounts(): iterable
    {
        yield 'brand new' => [0, 0];
        yield 'no posts but old' => [0, 3650];
        yield 'one post' => [1, 1];
        yield 'ordinary' => [500, 400];
        yield 'enormous' => [500000, 7300];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('accounts')]
    public function testTheImageRendersForAnyAccount(int $posts, int $days): void
    {
        $user = $this->user('Member');
        $user->setPosts($posts);
        $user->setRegisteredAt(new \DateTimeImmutable(\sprintf('-%d days', $days)));
        $this->em()->flush();

        $png = $this->container()->get(RpgStatusImage::class)->render(
            $user,
            $this->container()->get(RpgProfileRepository::class)->findOrCreateFor($user),
        );

        $image = imagecreatefromstring($png);
        self::assertNotFalse($image, \sprintf('%d posts / %d days produced no image.', $posts, $days));
        self::assertSame(256, imagesx($image));
    }

    /** The name box is sized from the name, so a long one must not overflow. */
    public function testAVeryLongNameStillRenders(): void
    {
        $user = $this->user('Member');
        $user->setUsername(str_repeat('W', 25));
        $this->em()->flush();

        $png = $this->container()->get(RpgStatusImage::class)->render(
            $user,
            $this->container()->get(RpgProfileRepository::class)->findOrCreateFor($user),
        );

        self::assertNotFalse(imagecreatefromstring($png));
    }

    public function testTheImageAppearsOnTheProfileAndInTheShop(): void
    {
        $this->signInAs('Member');

        $profile = $this->assertPageLoads('/profile/'.$this->id('user', 'Member'));
        self::assertCount(1, $profile->filter('img.rpg-status'), 'No status screen on the profile.');

        $shop = $this->assertPageLoads('/shop');
        self::assertCount(1, $shop->filter('img.rpg-status'), 'No status screen in the shop.');
    }

    public function testTheShopCategoryPageOffersThePreview(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/shop/1');

        self::assertCount(1, $crawler->filter('img[data-status-image]'));
        self::assertGreaterThan(0, $crawler->filter('tr[data-preview-item]')->count(), 'No item rows are previewable.');
    }
}
