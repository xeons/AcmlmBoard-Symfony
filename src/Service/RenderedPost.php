<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Post;
use App\Entity\User;

/**
 * Everything a post template needs, precomputed.
 *
 * The original's postcode() reached for two dozen globals ($tzoff, $smallfont, $ip,
 * $quote, $edit, $dateformat, $numdir, $textcolor, ...) and each of the eight layout
 * files pulled a slightly different set, so adding a field to one layout silently
 * broke another. The templates receive this object and nothing else.
 */
final class RenderedPost
{
    /**
     * @param string                                                     $body       sanitised post body
     * @param string                                                     $header     sanitised post header, or ''
     * @param string                                                     $signature  sanitised signature, or ''
     * @param array{rank: string|null, title: string|null, badge: string|null} $rankBlock
     * @param array<string, int>|null                                     $rpgStats   null unless the layout shows them
     */
    public function __construct(
        public readonly Post $post,
        public readonly ?User $author,
        public readonly string $body,
        public readonly string $header,
        public readonly string $signature,
        public readonly array $rankBlock,
        public readonly int $experience,
        public readonly int $level,
        public readonly int $experienceToNextLevel,
        public readonly float $levelProgress,
        public readonly int $postsToday,
        public readonly bool $layoutBlocked,
        public readonly ?string $postBackground = null,
        public readonly ?array $rpgStats = null,
        /**
         * True when this post does not exist yet - a compose or layout preview.
         *
         * Templates must not build routes from it: the Post and its Thread have no
         * id, so path('app_post_permalink', {id: null}) throws. Nor should they ask
         * the voters about it, since those query the database for a thread that has
         * not been written.
         */
        public readonly bool $preview = false,
    ) {
    }

    public function hasHeader(): bool
    {
        return '' !== $this->header;
    }

    public function hasSignature(): bool
    {
        return '' !== $this->signature;
    }

    public function wasEdited(): bool
    {
        return null !== $this->post->getEditedAt();
    }
}
