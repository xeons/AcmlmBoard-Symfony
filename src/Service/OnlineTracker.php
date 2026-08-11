<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Forum;
use App\Entity\User;
use App\Repository\GuestSessionRepository;
use App\Repository\UserRepository;

/**
 * "Who is online" for the index, the forum header and the online-users page.
 *
 * The original ran this logic three times in three files with three slightly
 * different window sizes and three different definitions of "online".
 */
final class OnlineTracker
{
    /** How long after their last request a visitor still counts as present. */
    public const WINDOW = '-5 minutes';

    public function __construct(
        private readonly UserRepository $users,
        private readonly GuestSessionRepository $guests,
    ) {
    }

    public function threshold(?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        return ($now ?? new \DateTimeImmutable())->modify(self::WINDOW);
    }

    /**
     * @return array{users: list<User>, userCount: int, guestCount: int}
     */
    public function snapshot(?Forum $forum = null, ?\DateTimeImmutable $now = null): array
    {
        $threshold = $this->threshold($now);
        $users = $this->users->findOnline($threshold, $forum);

        return [
            'users' => $users,
            'userCount' => \count($users),
            'guestCount' => $this->guests->countActive($threshold, $forum),
        ];
    }

    /** Records where a signed-in user currently is, for the per-forum listing. */
    public function trackUser(User $user, ?Forum $forum, ?string $url, \DateTimeImmutable $now): void
    {
        $user->setLastActivityAt($now);
        $user->setLastUrl($url);
        $user->setCurrentForum($forum);
    }

    public function trackGuest(string $ip, ?Forum $forum, ?string $url, \DateTimeImmutable $now): void
    {
        $this->guests->touch($ip, $url, $forum, $now);
    }

    public function purgeStaleGuests(?\DateTimeImmutable $now = null): int
    {
        return $this->guests->purgeStale($this->threshold($now));
    }
}
