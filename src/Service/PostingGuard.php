<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Forum;
use App\Entity\Thread;
use App\Entity\User;
use App\Repository\BoardConfigRepository;
use App\Repository\ForumBanRepository;
use App\Repository\PostRepository;
use App\Repository\PrivateMessageRepository;
use App\Repository\SoftBanRepository;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Every reason a user might not be allowed to post, in one place.
 *
 * The original scattered these across newreply.php and newthread.php as a chain of
 * `if(...) $error='...'` assignments - each overwriting the last, so the message the
 * user saw was whichever check happened to run last rather than the one that
 * actually applied. Each rule here returns as soon as it fires.
 */
final class PostingGuard
{
    public function __construct(
        private readonly BoardConfigRepository $config,
        private readonly PostRepository $posts,
        private readonly SoftBanRepository $softBans,
        private readonly ForumBanRepository $forumBans,
        private readonly PrivateMessageRepository $messages,
        private readonly RateLimiterFactoryInterface $postCreationLimiter,
    ) {
    }

    /**
     * @return string|null the reason posting is refused, or null if it is allowed
     */
    public function refusalReasonForReply(User $user, Thread $thread): ?string
    {
        if ($thread->isClosed()) {
            return 'This thread is closed. No more replies can be posted in it.';
        }

        if (null !== $reason = $this->generalRefusalReason($user, $thread->getForum())) {
            return $reason;
        }

        $forum = $thread->getForum();
        if ($forum->getMinPowerReply() > 0 && $user->effectivePower() < $forum->getMinPowerReply()) {
            return 'Replying in this forum is restricted, and you are not allowed to post here.';
        }

        $config = $this->config->get();

        // "You already have the last reply in this thread." Staff were exempt.
        if ($config->isPreventDoublePosting()
            && $thread->getLastPoster() === $user
            && !$user->getPowerLevel()->atLeast(\App\Enum\PowerLevel::Administrator)
        ) {
            return 'You already have the last reply in this thread.';
        }

        $todayInThread = $this->posts->countByAuthorInThreadSince(
            $user,
            $thread,
            new \DateTimeImmutable('-1 day'),
        );
        if ($todayInThread >= $config->getMaxPostsPerThreadPerDay()) {
            return 'You have posted enough in this thread today. Come back later.';
        }

        return null;
    }

    public function refusalReasonForNewThread(User $user, Forum $forum): ?string
    {
        if (null !== $reason = $this->generalRefusalReason($user, $forum)) {
            return $reason;
        }

        if ($forum->getMinPowerThread() > 0 && $user->effectivePower() < $forum->getMinPowerThread()) {
            return 'Starting threads in this forum is restricted, and you are not allowed to post here.';
        }

        return null;
    }

    /**
     * Consumes one token from the per-user posting rate limit. Call this only once
     * the post is definitely going to be written.
     *
     * @return bool false when the user is posting too fast
     */
    public function consumeRateLimit(User $user): bool
    {
        return $this->postCreationLimiter->create('post-'.$user->getId())->consume()->isAccepted();
    }

    /** Checks that apply to any kind of posting. */
    private function generalRefusalReason(User $user, Forum $forum): ?string
    {
        if ($user->isBanned()) {
            return 'You are banned from this board and cannot post.';
        }

        if (null !== $this->softBans->findActiveFor($user)) {
            return 'Your posting privileges are currently suspended.';
        }

        if ($this->forumBans->isBanned($user, $forum)) {
            return 'You are banned from posting in this forum.';
        }

        // The original's rule: unread system messages block all posting, so a
        // moderator's warning cannot be ignored.
        if ($this->messages->hasUnreadSystemMessages($user)) {
            return 'You have unread system messages. Please read them before posting again.';
        }

        return null;
    }
}
