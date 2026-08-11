<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ActionLog;
use App\Entity\Forum;
use App\Entity\ForumBan;
use App\Entity\IpBan;
use App\Entity\Punishment;
use App\Entity\SoftBan;
use App\Entity\Thread;
use App\Entity\User;
use App\Repository\BoardConfigRepository;
use App\Repository\ForumBanRepository;
use App\Repository\PunishmentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moderator actions, each of which writes an audit entry.
 *
 * In the original these were GET links - `thread.php?id=1&qmod=1&trash=1` - with no
 * token, so any page could trash a thread by embedding an image tag pointing at it.
 * The controllers behind these methods are POST-only and CSRF-protected.
 */
final class ModerationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PostManager $posts,
        private readonly BoardConfigRepository $config,
        private readonly PunishmentRepository $punishments,
        private readonly ForumBanRepository $forumBans,
        private readonly IpBanChecker $ipBans,
    ) {
    }

    public function setSticky(Thread $thread, bool $sticky, User $actor, ?string $ip = null): void
    {
        $thread->setSticky($sticky);
        $this->log($actor, ActionLog::ACTION_THREAD_STICKY, $thread, ['sticky' => $sticky], $ip);
        $this->em->flush();
    }

    public function setClosed(Thread $thread, bool $closed, User $actor, ?string $ip = null): void
    {
        $thread->setClosed($closed);
        $this->log($actor, ActionLog::ACTION_THREAD_CLOSE, $thread, ['closed' => $closed], $ip);
        $this->em->flush();
    }

    public function setLocked(Thread $thread, bool $locked, User $actor, ?string $ip = null): void
    {
        $thread->setLocked($locked);
        $this->log($actor, ActionLog::ACTION_THREAD_LOCK, $thread, ['locked' => $locked], $ip);
        $this->em->flush();
    }

    /**
     * Sends a thread to the trash forum: closed, unstuck and moved.
     *
     * @return bool false when no trash forum is configured
     */
    public function trash(Thread $thread, User $actor, ?string $ip = null): bool
    {
        $trash = $this->config->get()->getTrashForum();
        if (null === $trash || $trash === $thread->getForum()) {
            return false;
        }

        $thread->setSticky(false);
        $thread->setClosed(true);
        $this->posts->moveThread($thread, $trash);

        $this->log($actor, ActionLog::ACTION_THREAD_TRASH, $thread, ['to' => $trash->getId()], $ip);
        $this->em->flush();

        return true;
    }

    public function move(Thread $thread, Forum $destination, User $actor, ?string $ip = null): void
    {
        $from = $thread->getForum()->getId();
        $this->posts->moveThread($thread, $destination);
        $this->log($actor, ActionLog::ACTION_THREAD_MOVE, $thread, ['from' => $from, 'to' => $destination->getId()], $ip);
        $this->em->flush();
    }

    public function deleteThread(Thread $thread, User $actor, ?string $ip = null): void
    {
        $this->log($actor, ActionLog::ACTION_THREAD_DELETE, $thread, ['title' => $thread->getTitle()], $ip);
        $this->em->flush();
        $this->posts->deleteThread($thread);
    }

    public function softBan(User $target, ?\DateTimeImmutable $until, User $actor, ?string $reason = null, ?string $ip = null): SoftBan
    {
        $ban = new SoftBan($target, $until, $actor);
        $ban->setReason($reason);
        $this->em->persist($ban);

        $this->log($actor, ActionLog::ACTION_USER_SOFTBAN, null, [
            'user' => $target->getId(),
            'until' => $until?->format(\DATE_ATOM),
            'reason' => $reason,
        ], $ip);

        $this->em->flush();

        return $ban;
    }

    public function forumBan(User $target, Forum $forum, ?\DateTimeImmutable $until, User $actor, ?string $reason = null, ?string $ip = null): ForumBan
    {
        $ban = $this->forumBans->findOneFor($target, $forum) ?? new ForumBan($forum, $target);
        $ban->setExpiresAt($until);
        $ban->setIssuedBy($actor);
        $ban->setReason($reason);
        $this->em->persist($ban);

        $this->log($actor, ActionLog::ACTION_USER_FORUMBAN, null, [
            'user' => $target->getId(),
            'forum' => $forum->getId(),
            'until' => $until?->format(\DATE_ATOM),
        ], $ip);

        $this->em->flush();

        return $ban;
    }

    public function banIp(string $range, ?\DateTimeImmutable $until, User $actor, ?string $reason = null, ?string $ip = null): IpBan
    {
        $ban = new IpBan($range, $actor);
        $ban->setExpiresAt($until);
        $ban->setReason($reason);
        $this->em->persist($ban);

        $this->log($actor, ActionLog::ACTION_IP_BAN, null, ['range' => $range, 'reason' => $reason], $ip);
        $this->em->flush();

        // A ban has to bite immediately, not when a cache entry happens to lapse.
        $this->ipBans->invalidate();

        return $ban;
    }

    /**
     * The disciplinary record for a user, created with its staff-only thread on
     * first use. Creating it is an explicit act here; the original created these as
     * a side effect of a staff member merely *viewing* a page.
     */
    public function punishmentFor(User $target, ?User $actor = null): Punishment
    {
        $record = $this->punishments->findForUser($target);
        if (null !== $record) {
            return $record;
        }

        $record = new Punishment($target);

        $config = $this->config->get();
        $forum = $config->getDisciplinaryForum();
        $system = $config->getSystemAccount() ?? $actor;

        if (null !== $forum && null !== $system) {
            $thread = $this->posts->createThread(
                $forum,
                $system,
                \sprintf('Discussion of %s', $target->getUsername()),
                \sprintf('Disciplinary record opened for %s.', $target->getUsername()),
            );
            $record->setThread($thread);
        }

        $this->em->persist($record);
        $this->em->flush();

        return $record;
    }

    /** @param array<string, mixed> $context */
    private function log(User $actor, string $action, ?Thread $thread, array $context, ?string $ip): void
    {
        $this->em->persist(new ActionLog(
            $actor,
            $action,
            null !== $thread ? 'thread:'.$thread->getId() : null,
            $context,
            $ip,
        ));
    }
}
