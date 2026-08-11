<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Post;
use App\Entity\User;
use App\Repository\PostRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Per-post edit and delete rights.
 *
 * The original decided this in the *view*, inside the render loop:
 *
 *     if(($ismod or $post[user]==$loguserid) and !$thread[closed])
 *         $edit= "<a href=editpost.php?id=...>Edit</a>" . (($i==0&&!$ismod&&$page==0)?"":" | delete")
 *
 * - so whether you could delete a post depended on its index on the current page.
 * editpost.php itself then performed no ownership check at all, meaning anyone who
 * guessed a post id could edit or delete any post on the board by visiting the URL
 * directly. That is the single worst bug in the original codebase.
 *
 * @extends Voter<string, Post>
 */
final class PostVoter extends Voter
{
    public const EDIT = 'POST_EDIT';
    public const DELETE = 'POST_DELETE';
    public const VIEW_IP = 'POST_VIEW_IP';

    public function __construct(private readonly PostRepository $posts)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Post
            && \in_array($attribute, [self::EDIT, self::DELETE, self::VIEW_IP], true);
    }

    /** @param Post $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $thread = $subject->getThread();
        $forum = $thread->getForum();

        if (!$forum->isReadableBy($user->effectivePower())) {
            return false;
        }

        if (self::VIEW_IP === $attribute) {
            return $user->isAdmin();
        }

        $isModerator = $user->isModerator() || $forum->isModeratedBy($user);
        $isAuthor = null !== $subject->getAuthor() && $subject->getAuthor() === $user;

        // A closed thread is frozen for everyone but moderators.
        if ($thread->isClosed() && !$isModerator) {
            return false;
        }

        if (!$isAuthor && !$isModerator) {
            return false;
        }

        if (self::EDIT === $attribute) {
            return true;
        }

        // Deleting the opening post would orphan the thread, so only moderators -
        // who have a "delete thread" action for that - may do it.
        if (!$isModerator) {
            $first = $this->posts->findFirstInThread($thread);
            if (null !== $first && $first->getId() === $subject->getId()) {
                return false;
            }
        }

        return true;
    }
}
