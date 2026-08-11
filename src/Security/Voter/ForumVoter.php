<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Forum;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Forum-level access.
 *
 * The original checked `$power < $forum[minpower]` inline in each of forum.php,
 * thread.php, newthread.php and newreply.php, and each did it slightly differently -
 * forum.php only enforced the restriction when `$log` was true, so the guest branch
 * fell through and a logged-out visitor could read a restricted forum's thread list.
 * One voter, consulted everywhere, removes that class of gap.
 *
 * @extends Voter<string, Forum>
 */
final class ForumVoter extends Voter
{
    public const VIEW = 'FORUM_VIEW';
    public const CREATE_THREAD = 'FORUM_CREATE_THREAD';
    public const REPLY = 'FORUM_REPLY';
    public const MODERATE = 'FORUM_MODERATE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Forum
            && \in_array($attribute, [self::VIEW, self::CREATE_THREAD, self::REPLY, self::MODERATE], true);
    }

    /** @param Forum $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        $user = $user instanceof User ? $user : null;

        // Guests carry power 0, which clears any forum whose threshold is 0 or less.
        $power = $user?->effectivePower() ?? 0;

        $category = $subject->getCategory();
        if (null !== $category && $category->getMinPower() > 0 && $power < $category->getMinPower()) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => $subject->isReadableBy($power),

            self::CREATE_THREAD => null !== $user
                && !$user->isBanned()
                && $subject->isReadableBy($power)
                && ($subject->getMinPowerThread() <= 0 || $power >= $subject->getMinPowerThread()),

            self::REPLY => null !== $user
                && !$user->isBanned()
                && $subject->isReadableBy($power)
                && ($subject->getMinPowerReply() <= 0 || $power >= $subject->getMinPowerReply()),

            // Global moderators, plus anyone granted local moderation of this forum.
            self::MODERATE => null !== $user
                && ($user->isModerator() || $subject->isModeratedBy($user)),

            default => false,
        };
    }
}
