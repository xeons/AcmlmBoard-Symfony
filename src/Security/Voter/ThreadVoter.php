<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Thread;
use App\Entity\User;
use App\Repository\BoardConfigRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Thread-level moderation.
 *
 * The `locked` flag is an admin-only escalation: once set, local and full moderators
 * lose their edit rights over the thread, but only while the board's `lockable`
 * setting is on. That interaction is the reason this is a separate voter from
 * ForumVoter rather than an extra attribute on it.
 *
 * @extends Voter<string, Thread>
 */
final class ThreadVoter extends Voter
{
    public const VIEW = 'THREAD_VIEW';
    public const REPLY = 'THREAD_REPLY';
    public const MODERATE = 'THREAD_MODERATE';
    public const EDIT = 'THREAD_EDIT';
    public const DELETE = 'THREAD_DELETE';
    public const LOCK = 'THREAD_LOCK';

    public function __construct(private readonly BoardConfigRepository $config)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Thread
            && \in_array($attribute, [
                self::VIEW, self::REPLY, self::MODERATE,
                self::EDIT, self::DELETE, self::LOCK,
            ], true);
    }

    /** @param Thread $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        $user = $user instanceof User ? $user : null;

        $forum = $subject->getForum();
        $power = $user?->effectivePower() ?? 0;

        if (!$forum->isReadableBy($power)) {
            return false;
        }

        $isAdmin = null !== $user && $user->isAdmin();
        $isModerator = null !== $user && ($user->isModerator() || $forum->isModeratedBy($user));
        $lockBlocks = $subject->isLocked() && $this->config->get()->isThreadLockingEnabled() && !$isAdmin;

        return match ($attribute) {
            self::VIEW => true,

            self::REPLY => null !== $user
                && !$user->isBanned()
                && !$subject->isClosed()
                && ($forum->getMinPowerReply() <= 0 || $power >= $forum->getMinPowerReply()),

            self::MODERATE, self::EDIT => $isModerator && !$lockBlocks,

            // Deleting a whole thread is not something a local moderator may do.
            self::DELETE => null !== $user && $user->isModerator() && !$lockBlocks,

            self::LOCK => $isAdmin && $this->config->get()->isThreadLockingEnabled(),

            default => false,
        };
    }
}
