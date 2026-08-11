<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Post;
use App\Entity\User;
use App\Enum\SignatureDisplay;

/**
 * Assembles a Post into a RenderedPost.
 *
 * Whose layout is shown, and in what form, depends on the *viewer*:
 *   - viewer has blocked this author  -> no header, no signature
 *   - viewer's SignatureDisplay::Hidden -> no header, no signature
 *   - ::AsPosted                      -> the snapshot frozen at post time
 *   - ::AutoUpdating                  -> the author's current layout, with tokens
 *                                        recomputed against their current stats
 *
 * The original expressed this by *changing the SELECT column list* in thread.php
 * ($sfields), so the branch was spread across the query, setlayout() and threadpost().
 */
final class PostRenderer
{
    public function __construct(
        private readonly MarkupRenderer $markup,
        private readonly LayoutTokenExpander $tokens,
        private readonly RankResolver $ranks,
        private readonly LevelCalculator $levels,
    ) {
    }

    /**
     * @param array<int, int> $activityByUser user id => posts in the last 24h
     */
    public function render(Post $post, ?User $viewer, array $activityByUser = []): RenderedPost
    {
        $author = $post->getAuthor();
        $display = $viewer?->getSignatureDisplay() ?? SignatureDisplay::AsPosted;

        $blocked = null !== $viewer && null !== $author && $viewer->hasBlockedLayoutOf($author);
        $showLayout = !$blocked && SignatureDisplay::Hidden !== $display;

        [$header, $signature] = $showLayout
            ? $this->resolveLayout($post, $author, $display)
            : ['', ''];

        $posts = null !== $author ? $author->getPosts() : 0;
        $days = $author?->daysRegistered() ?? 0.0;
        $experience = $this->levels->experience($posts, $days);

        return new RenderedPost(
            post: $post,
            author: $author,
            body: $this->markup->render($post->getBody()),
            header: $header,
            signature: $signature,
            rankBlock: null !== $author
                ? $this->ranks->resolveBlock($author)
                : ['rank' => null, 'title' => null, 'badge' => null],
            experience: $experience,
            level: $this->levels->level($experience),
            experienceToNextLevel: $this->levels->experienceToNextLevel($experience),
            levelProgress: $this->levels->levelProgress($experience),
            postsToday: $activityByUser[$author?->getId()] ?? 0,
            layoutBlocked: $blocked,
            postBackground: $showLayout ? $this->safeBackground($author?->getPostBackground()) : null,
        );
    }

    /**
     * @param list<Post>      $posts
     * @param array<int, int> $activityByUser
     *
     * @return list<RenderedPost>
     */
    public function renderAll(array $posts, ?User $viewer, array $activityByUser = []): array
    {
        return array_map(
            fn (Post $post): RenderedPost => $this->render($post, $viewer, $activityByUser),
            $posts,
        );
    }

    /**
     * Renders a post that does not exist yet, for the compose preview.
     *
     * The preview goes through the identical pipeline as a stored post - same token
     * expansion, same sanitizer - so a layout that survives the preview is exactly
     * what gets saved. The original previewed by calling threadpost() on a
     * hand-assembled array with a different set of fields, so previews and reality
     * routinely disagreed.
     */
    public function renderPreview(User $author, string $body, ?User $viewer = null): RenderedPost
    {
        $postNumber = $author->getPosts() + 1;
        $values = $this->tokens->computeValues($author, $postNumber);

        $post = new Post(
            new \App\Entity\Thread(
                new \App\Entity\Forum(),
                $author,
                'Preview',
            ),
            $author,
            $body,
        );
        $post->setAuthorPostNumber($postNumber);
        $post->setTagValues($values);

        $display = $viewer?->getSignatureDisplay() ?? SignatureDisplay::AsPosted;
        $showLayout = SignatureDisplay::Hidden !== $display;

        $header = $showLayout
            ? $this->markup->render($this->tokens->apply($author->getPostHeader(), $values, $author))
            : '';
        $signature = $showLayout
            ? $this->markup->render($this->tokens->apply($author->getSignature(), $values, $author))
            : '';

        $experience = $this->levels->experience($postNumber, $author->daysRegistered());

        return new RenderedPost(
            post: $post,
            author: $author,
            body: $this->markup->render($body),
            header: $header,
            signature: $signature,
            rankBlock: $this->ranks->resolveBlock($author),
            experience: $experience,
            level: $this->levels->level($experience),
            experienceToNextLevel: $this->levels->experienceToNextLevel($experience),
            levelProgress: $this->levels->levelProgress($experience),
            postsToday: 0,
            layoutBlocked: false,
            postBackground: $showLayout ? $this->safeBackground($author->getPostBackground()) : null,
            preview: true,
        );
    }

    /**
     * @return array{0: string, 1: string} header and signature HTML
     */
    private function resolveLayout(Post $post, ?User $author, SignatureDisplay $display): array
    {
        if (SignatureDisplay::AutoUpdating === $display && null !== $author) {
            // Recompute tokens against the author's stats as they are now, which is
            // the whole point of the auto-updating mode.
            $values = $this->tokens->computeValues($author);
            $header = $this->tokens->apply($author->getPostHeader(), $values, $author);
            $signature = $this->tokens->apply($author->getSignature(), $values, $author);
        } else {
            // Frozen snapshot: substitute the values recorded when the post was made,
            // so an old post keeps saying what it said.
            $values = $post->getTagValues();
            $header = $this->tokens->apply($post->getHeaderLayout()?->getBody(), $values, $author);
            $signature = $this->tokens->apply($post->getSignatureLayout()?->getBody(), $values, $author);
        }

        return [$this->markup->render($header), $this->markup->render($signature)];
    }

    /**
     * The post-background field is a raw CSS background shorthand that gets written
     * into a style attribute, so it is validated the same way a style declaration
     * would be rather than trusted, as the original did when it built
     * `<div style=background:url($user[postbg])>` by concatenation.
     */
    private function safeBackground(?string $background): ?string
    {
        if (null === $background || '' === trim($background)) {
            return null;
        }

        $background = trim($background);

        if (preg_match('~[<>"\'{}();]~', $background)) {
            return null;
        }

        $lower = strtolower(preg_replace('/\s+/', '', $background) ?? $background);
        foreach (['javascript:', 'expression', 'behavior', 'data:text', '@import', 'url('] as $forbidden) {
            if (str_contains($lower, $forbidden)) {
                // A bare URL is accepted and wrapped safely below; url() syntax is
                // rejected because it can carry a second, unbalanced payload.
                if ('url(' !== $forbidden) {
                    return null;
                }
            }
        }

        // A plain http(s) URL becomes a well-formed url() reference.
        if (preg_match('~^https?://[^\s]+$~i', $background)) {
            return 'url('.$background.')';
        }

        // Otherwise accept only a colour keyword or hex triplet.
        return preg_match('/^(#[0-9a-f]{3,8}|[a-z]{3,20})$/i', $background) ? $background : null;
    }
}
