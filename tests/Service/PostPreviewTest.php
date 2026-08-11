<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\PostRenderer;
use App\Service\RenderedPost;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Compose and layout previews.
 *
 * A preview renders a Post that has never been written, so its id - and its thread's
 * id - are null. Templates that build a route from either (`path('app_post_permalink',
 * {id: null})`) throw, and voters asked about it go looking for a thread row that does
 * not exist. RenderedPost::$preview is what tells the templates to render the post
 * body without any of that furniture.
 *
 * This is regression cover: all four preview paths (new thread, reply, edit post,
 * edit layout) were broken at once, because they share one macro.
 */
final class PostPreviewTest extends KernelTestCase
{
    private PostRenderer $renderer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->renderer = self::getContainer()->get(PostRenderer::class);
    }

    private function author(): User
    {
        $user = new User();
        $user->setUsername('PreviewUser');
        $user->setPosts(41);
        $user->setRegisteredAt(new \DateTimeImmutable('-400 days'));

        return $user;
    }

    public function testPreviewIsFlaggedAsSuch(): void
    {
        $rendered = $this->renderer->renderPreview($this->author(), 'Hello.');

        self::assertInstanceOf(RenderedPost::class, $rendered);
        self::assertTrue(
            $rendered->preview,
            'Templates rely on this flag to avoid building routes from a null id',
        );
    }

    /**
     * The precondition that makes the flag necessary. If these ever become non-null,
     * the preview is being persisted, which it must not be.
     */
    public function testPreviewedPostAndThreadHaveNoIdentity(): void
    {
        $rendered = $this->renderer->renderPreview($this->author(), 'Hello.');

        self::assertNull($rendered->post->getId(), 'A preview must not be persisted');
        self::assertNull($rendered->post->getThread()->getId(), 'Its thread must not be persisted either');
    }

    public function testPreviewRendersMarkup(): void
    {
        $rendered = $this->renderer->renderPreview($this->author(), '[b]bold[/b] and a :) smiley');

        self::assertStringContainsString('<b>bold</b>', $rendered->body);
        self::assertStringContainsString('smile.gif', $rendered->body);
    }

    public function testPreviewExpandsLayoutTokens(): void
    {
        $author = $this->author();
        $author->setPostHeader('You have &numposts& posts and are level &level&.');
        $author->setSignature('EXP: &exp&');

        $rendered = $this->renderer->renderPreview($author, 'Body.');

        self::assertStringNotContainsString('&numposts&', $rendered->header);
        self::assertStringNotContainsString('&level&', $rendered->header);
        self::assertStringNotContainsString('&exp&', $rendered->signature);

        // The count shown is the one the post *would* carry, i.e. one more than now.
        self::assertStringContainsString('42', $rendered->header);
    }

    public function testPreviewSanitisesTheLayout(): void
    {
        $author = $this->author();
        $author->setPostHeader('<div onclick="alert(1)">head</div><script>alert(2)</script>');
        $author->setSignature('<a href="javascript:alert(3)">sig</a>');

        $rendered = $this->renderer->renderPreview($author, 'Body.');

        // What the preview shows is what would be stored, filtered identically - the
        // whole point of previewing through the real renderer.
        self::assertStringNotContainsString('onclick', $rendered->header);
        self::assertStringNotContainsString('<script', $rendered->header);
        self::assertStringNotContainsString('javascript:', $rendered->signature);
        self::assertStringContainsString('head', $rendered->header);
    }

    public function testPreviewCarriesTheAuthorsStats(): void
    {
        $rendered = $this->renderer->renderPreview($this->author(), 'Body.');

        self::assertGreaterThan(0, $rendered->level);
        self::assertGreaterThan(0, $rendered->experience);
        self::assertSame(42, $rendered->post->getAuthorPostNumber());
    }

    /**
     * A member who has turned signatures off should not be shown one in their own
     * preview either.
     */
    public function testPreviewHonoursTheViewersSignatureSetting(): void
    {
        $author = $this->author();
        $author->setPostHeader('<b>header</b>');
        $author->setSignature('<i>signature</i>');

        $viewer = $this->author();
        $viewer->setSignatureDisplay(\App\Enum\SignatureDisplay::Hidden);

        $rendered = $this->renderer->renderPreview($author, 'Body.', $viewer);

        self::assertSame('', $rendered->header);
        self::assertSame('', $rendered->signature);
    }
}
