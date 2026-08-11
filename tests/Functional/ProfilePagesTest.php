<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\BoardWebTestCase;

/**
 * The two pages a member manages their own account from.
 *
 * Both had the same kind of fault: something the member needed was present in the
 * code but not reachable on the page. The passkey form was rendered with `hidden` and
 * only revealed by JavaScript, so a browser that could not do WebAuthn showed "you
 * have no passkeys yet" and no way to add one and no reason why. The profile form was
 * one flat table of twenty-odd fields with no grouping.
 */
final class ProfilePagesTest extends BoardWebTestCase
{
    // ------------------------------------------------------------------
    // Passkeys
    // ------------------------------------------------------------------

    /**
     * The registration form has to be in the served HTML, visible, whether or not
     * any script runs. JavaScript enables the button; it does not conjure the form.
     */
    public function testTheAddPasskeyFormIsServedVisibleEvenWithoutJavaScript(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/profile/passkeys');

        $block = $crawler->filter('[data-passkey-block]');
        self::assertCount(1, $block, 'The "Add a passkey" section is missing entirely.');
        self::assertNull($block->attr('hidden'), 'The section is hidden, so nobody can reach it without JS.');

        $button = $crawler->filter('button[data-passkey-register]');
        self::assertCount(1, $button, 'There is no button to register a passkey.');
        self::assertNotEmpty($button->attr('data-csrf'), 'The register button carries no CSRF token.');

        self::assertCount(1, $crawler->filter('input[data-passkey-name]'), 'There is nowhere to name the passkey.');
    }

    /**
     * Disabled to begin with: the button only works once a script has confirmed the
     * browser can do WebAuthn, and a button that silently does nothing is worse than
     * one that visibly cannot be pressed yet.
     */
    public function testTheRegisterButtonStartsDisabledAndSaysWhy(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/profile/passkeys');

        self::assertNotNull(
            $crawler->filter('button[data-passkey-register]')->attr('disabled'),
            'The button is enabled in the HTML, so it can be pressed before support is known.',
        );

        // Whatever the member's situation, the page has to account for the button.
        self::assertStringContainsString('secure connection', $crawler->text());
    }

    public function testAMemberWithNoPasskeysIsToldSoAndStillOfferedTheForm(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/profile/passkeys');

        self::assertStringContainsString('no passkeys yet', $crawler->text());
        self::assertCount(1, $crawler->filter('button[data-passkey-register]'));
    }

    /** With passkeys switched off board-wide, the form is genuinely absent. */
    public function testTheFormIsAbsentWhenPasskeysAreDisabledBoardWide(): void
    {
        $this->signInAs('Member');

        $config = $this->container()->get(\App\Repository\BoardConfigRepository::class)->get();
        $config->setPasskeysEnabled(false);
        $this->em()->flush();

        $crawler = $this->assertPageLoads('/profile/passkeys');

        self::assertCount(0, $crawler->filter('button[data-passkey-register]'));
        self::assertStringContainsString('disabled on this board', $crawler->text());
    }

    // ------------------------------------------------------------------
    // Edit profile
    // ------------------------------------------------------------------

    public function testTheProfileFormIsGroupedIntoSections(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/profile/edit');

        $headings = $crawler->filter('th')->each(static fn ($n): string => trim($n->text()));

        foreach (['Account', 'About you', 'Your posts', 'Reading the board', 'Conveniences'] as $section) {
            self::assertContains($section, $headings, \sprintf('The "%s" section is missing.', $section));
        }
    }

    /**
     * The section list is written in the template, so a field added to the form type
     * could easily be left out of it. Anything unaccounted for falls into "Other
     * settings" rather than disappearing - this asserts there is nothing there,
     * which is the signal to go and file the new field properly.
     */
    public function testNoFieldHasFallenThroughIntoTheLeftoverSection(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/profile/edit');

        $headings = $crawler->filter('th')->each(static fn ($n): string => trim($n->text()));

        self::assertNotContains(
            'Other settings',
            $headings,
            'A profile field is not assigned to a section in profile/edit.html.twig.',
        );
    }

    /** Grouping must not lose a field: every one the form defines still renders. */
    public function testEveryFieldTheFormDefinesIsStillRendered(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/profile/edit');

        $rendered = [];
        foreach ($crawler->filter('input[name^="edit_profile"], select[name^="edit_profile"], textarea[name^="edit_profile"]') as $node) {
            if (preg_match('~^edit_profile\[([^\]]+)\]~', (string) $node->getAttribute('name'), $m)) {
                $rendered[$m[1]] = true;
            }
        }
        unset($rendered['_token']);

        foreach ([
            'plainPassword', 'email', 'emailPublic', 'realName', 'location', 'birthday',
            'sex', 'bio', 'homepageUrl', 'homepageName', 'picture', 'miniPic', 'rankSet',
            'signatureDisplay', 'signatureSeparator', 'timezone', 'colorScheme',
            'threadLayout', 'postsPerPage', 'threadsPerPage', 'postToolbar',
            'markReadOutsideMenu',
        ] as $field) {
            self::assertArrayHasKey($field, $rendered, $field.' is no longer rendered on the profile form.');
        }
    }

    public function testThePasskeyLinkSitsInsideTheAccountSection(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/profile/edit');

        $link = $crawler->filter('td a[href$="/profile/passkeys"]');

        self::assertCount(1, $link, 'The passkey link is not in a table cell on the profile form.');
        self::assertStringContainsString('passkey', strtolower($link->text()));
    }

    /** One button, so there is no doubt that saving covers every section. */
    public function testOneSaveButtonCoversTheWholeForm(): void
    {
        $this->signInAs('Member');
        $crawler = $this->assertPageLoads('/profile/edit');

        self::assertCount(1, $crawler->filter('form.board-form button[type=submit]'));
    }

    /** The regrouping is presentation only: saving still works end to end. */
    public function testTheGroupedFormStillSaves(): void
    {
        $this->signInAs('Member');

        $this->client->request('GET', '/profile/edit');
        $this->client->submitForm('Save profile', [
            'edit_profile[location]' => 'Testville',
            'edit_profile[realName]' => 'A Tester',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em()->clear();
        self::assertSame('Testville', $this->user('Member')->getLocation());
        self::assertSame('A Tester', $this->user('Member')->getRealName());
    }
}
