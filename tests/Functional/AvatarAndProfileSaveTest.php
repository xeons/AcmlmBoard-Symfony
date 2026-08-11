<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\BoardWebTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Picking an avatar from the board's own gallery, and then saving the profile.
 *
 * These two disagreed. The gallery stores what it serves - "/images/avatars/kirby.png"
 * - while the profile form demanded a full http(s) URL, so the moment a member chose
 * an avatar their profile could never be saved again: every save failed validation on
 * a field they had not touched.
 *
 * It was invisible, too. Symfony re-renders a rejected form with the values that were
 * submitted, so the page came back showing the new settings and looked saved. Only a
 * reload revealed that nothing had been written.
 */
final class AvatarAndProfileSaveTest extends BoardWebTestCase
{
    /** The whole cycle, as a member performs it. */
    public function testChoosingAnAvatarThenSavingTheProfileWorks(): void
    {
        $this->signInAs('Member');

        $crawler = $this->assertPageLoads('/avatars');
        $pictureId = (int) $crawler->filter('input[name="picture"]')->first()->attr('value');
        self::assertGreaterThan(0, $pictureId, 'The avatar gallery offers nothing to choose.');

        $this->post('/avatars/choose', ['picture' => $pictureId], 'choose-avatar');
        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em()->clear();
        $stored = $this->user('Member')->getPicture();
        self::assertNotNull($stored, 'Choosing an avatar stored nothing.');
        self::assertStringStartsWith('/images/', $stored, 'The gallery no longer stores a board-relative path.');

        // Now save the profile without touching the avatar. This is what broke.
        $this->client->request('GET', '/profile/edit');
        $this->client->submitForm('Save profile', ['edit_profile[location]' => 'Somewhere']);

        self::assertTrue(
            $this->client->getResponse()->isRedirect(),
            'Saving the profile was rejected after choosing an avatar from the gallery.',
        );

        $this->em()->clear();
        self::assertSame('Somewhere', $this->user('Member')->getLocation());
        self::assertSame($stored, $this->user('Member')->getPicture(), 'Saving the profile lost the avatar.');
    }

    /**
     * UrlType prepends "http://" to anything without a scheme, which silently
     * rewrote a board-relative avatar into "http:///images/..." on the way in. The
     * field has to keep what it was given.
     */
    public function testABoardRelativeAvatarSurvivesARoundTripThroughTheForm(): void
    {
        $user = $this->signInAs('Member');
        $user->setPicture('/images/avatars/kirby.png');
        $this->em()->flush();

        $this->client->request('GET', '/profile/edit');
        $this->client->submitForm('Save profile', []);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em()->clear();
        self::assertSame(
            '/images/avatars/kirby.png',
            $this->user('Member')->getPicture(),
            'The avatar path was rewritten on the way through the form.',
        );
    }

    public function testAFullUrlAvatarStillWorks(): void
    {
        $this->signInAs('Member');

        $this->client->request('GET', '/profile/edit');
        $this->client->submitForm('Save profile', [
            'edit_profile[picture]' => 'https://example.com/avatar.png',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em()->clear();
        self::assertSame('https://example.com/avatar.png', $this->user('Member')->getPicture());
    }

    // ------------------------------------------------------------------
    // What the avatar field accepts
    // ------------------------------------------------------------------

    /** @return iterable<string, array{string, bool}> value => acceptable */
    public static function avatarValues(): iterable
    {
        yield 'board gallery path' => ['/images/avatars/kirby.png', true];
        yield 'nested board path' => ['/images/userpic/mario/sm64-08.png', true];
        yield 'full https url' => ['https://example.com/a.png', true];
        yield 'full http url' => ['http://example.com/a.gif', true];

        yield 'plain words' => ['definitely not a url', false];
        yield 'traversal' => ['/images/../../secret.png', false];
        yield 'script path' => ['/images/evil.php', false];
        yield 'javascript url' => ['javascript:alert(1)', false];
        yield 'data url' => ['data:image/png;base64,AAAA', false];
        yield 'protocol relative' => ['//example.com/a.png', false];
        yield 'no leading slash' => ['images/avatars/kirby.png', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('avatarValues')]
    public function testAvatarValuesAreAcceptedOrRejected(string $value, bool $acceptable): void
    {
        $user = new User();
        $user->setUsername('AvatarProbe');
        $user->setPicture($value);

        $violations = $this->container()->get(ValidatorInterface::class)->validate($user, groups: ['Default']);

        $onPicture = 0;
        foreach ($violations as $violation) {
            if ('picture' === $violation->getPropertyPath()) {
                ++$onPicture;
            }
        }

        $acceptable
            ? self::assertSame(0, $onPicture, \sprintf('"%s" should have been accepted.', $value))
            : self::assertGreaterThan(0, $onPicture, \sprintf('"%s" should have been rejected.', $value));
    }

    // ------------------------------------------------------------------
    // Failures have to be visible
    // ------------------------------------------------------------------

    /**
     * A rejected form is re-rendered with the submitted values, which looks exactly
     * like a successful save. The summary at the top is the only thing that tells a
     * member otherwise, and it used to list only errors attached to the root form -
     * so for a field error, which is nearly all of them, it was empty.
     */
    public function testARejectedSaveSaysSoWhereTheMemberWillSeeIt(): void
    {
        $this->signInAs('Member');

        $this->client->request('GET', '/profile/edit');
        $crawler = $this->client->submitForm('Save profile', [
            'edit_profile[picture]' => 'definitely not a url',
        ]);

        self::assertFalse($this->client->getResponse()->isRedirect(), 'An invalid avatar was accepted.');

        $summary = $crawler->filter('.flash-error');
        self::assertCount(1, $summary, 'A rejected save showed no error summary at all.');

        $text = $summary->text();
        self::assertStringContainsString('not saved', $text, 'The summary does not say the save failed.');
        self::assertStringContainsString('User picture', $text, 'The summary does not name the field at fault.');
    }

    // ------------------------------------------------------------------
    // The gallery itself
    // ------------------------------------------------------------------

    /**
     * The gallery has to show something on the first visit.
     *
     * Its categories are numbered by whoever curated it - the original's ran 1 to 5 -
     * and the page defaulted to 0, which nothing is on. The result was an empty
     * gallery that looked exactly like having no avatars at all.
     */
    public function testTheGalleryShowsPicturesWithoutBeingToldWhichPage(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/avatars');

        self::assertGreaterThan(
            0,
            $crawler->filter('input[name="picture"]')->count(),
            'The gallery offered nothing to choose on its first page.',
        );
    }

    /** Every picture the gallery offers must be a file that is actually there. */
    public function testEveryAvatarInTheGalleryExistsOnDisk(): void
    {
        $publicDir = $this->container()->getParameter('kernel.project_dir').'/public';
        $pictures = $this->container()->get(\App\Repository\UserPictureRepository::class)->findAll();

        self::assertGreaterThan(50, \count($pictures), 'The avatar gallery is nearly empty.');

        $missing = [];
        foreach ($pictures as $picture) {
            if (!is_file($publicDir.$picture->getUrl())) {
                $missing[] = $picture->getUrl();
            }
        }

        self::assertSame([], \array_slice($missing, 0, 10), \sprintf('%d gallery avatars name files that do not exist.', \count($missing)));
    }

    /**
     * The Twig helper that decides whether an avatar is renderable. It accepted only
     * absolute URLs, so a picture chosen from the board's own gallery was silently
     * dropped and the member saw no avatar at all.
     */
    public function testTheImageHelperAcceptsBoardPathsAndRejectsEverythingDangerous(): void
    {
        $twig = $this->container()->get(\App\Twig\BoardExtension::class);

        self::assertSame('/images/userpic/mario/yipic07.png', $twig->safeImageUrl('/images/userpic/mario/yipic07.png'));
        self::assertSame('https://example.com/a.png', $twig->safeImageUrl('https://example.com/a.png'));

        foreach (['javascript:alert(1)', 'data:image/png;base64,AAA', '/images/../secret.png', '//example.com/a.png', 'nonsense'] as $bad) {
            self::assertNull($twig->safeImageUrl($bad), \sprintf('"%s" should not be rendered as an image.', $bad));
        }

        self::assertNull($twig->safeImageUrl(null));
        self::assertNull($twig->safeImageUrl('  '));
    }

    /** Nothing is written when the form is rejected. */
    public function testARejectedSaveWritesNothingAtAll(): void
    {
        $user = $this->signInAs('Member');
        $user->setLocation('Original');
        $this->em()->flush();

        $this->client->request('GET', '/profile/edit');
        $this->client->submitForm('Save profile', [
            'edit_profile[location]' => 'Changed',
            'edit_profile[picture]' => 'definitely not a url',
        ]);

        $this->em()->clear();
        self::assertSame('Original', $this->user('Member')->getLocation());
    }
}
