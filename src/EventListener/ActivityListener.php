<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\BoardStatsService;
use App\Service\OnlineTracker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records "who is online" and bumps the page-view counter.
 *
 * Two deliberate choices:
 *
 *   1. This runs on kernel.terminate, after the response has been sent. The original
 *      did all of it inline in lib/layout.php before rendering, so every visitor
 *      waited on a DELETE, an INSERT, an UPDATE and a handful of COUNTs before
 *      seeing a byte of HTML.
 *   2. A user's activity row is written at most once a minute. The original wrote
 *      `UPDATE users SET lastactivity=...` on literally every request including
 *      images, which on a busy board meant the users table was the hottest write
 *      target on the server.
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final class ActivityListener
{
    private const WRITE_INTERVAL_SECONDS = 60;

    public function __construct(
        private readonly Security $security,
        private readonly OnlineTracker $online,
        private readonly BoardStatsService $stats,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();

        // Only count real page views: no sub-requests, no XHR, no asset routes.
        if ($request->isXmlHttpRequest() || !$request->isMethodCacheable()) {
            return;
        }

        $route = (string) $request->attributes->get('_route');
        if ('' === $route || str_starts_with($route, '_')) {
            return;
        }

        $now = new \DateTimeImmutable();
        $url = $request->getRequestUri();

        // The forum a page belongs to, set by controllers that know it, so the
        // per-forum "users currently in X" line has something to group by.
        $forum = $request->attributes->get('_current_forum');
        $forum = $forum instanceof \App\Entity\Forum ? $forum : null;

        try {
            $user = $this->security->getUser();

            if ($user instanceof User) {
                $last = $user->getLastActivityAt();
                $stale = null === $last
                    || ($now->getTimestamp() - $last->getTimestamp()) >= self::WRITE_INTERVAL_SECONDS
                    || $user->getCurrentForum() !== $forum;

                if ($stale) {
                    // Re-attach: the entity manager may have been cleared during the
                    // request, and terminate runs outside the request's unit of work.
                    $managed = $this->em->find(User::class, $user->getId());
                    if (null !== $managed) {
                        $this->online->trackUser($managed, $forum, $url, $now);
                        $this->em->flush();
                    }
                }
            } elseif (null !== $ip = $request->getClientIp()) {
                $this->online->trackGuest($ip, $forum, $url, $now);
            }

            $this->stats->incrementPageViews();
        } catch (\Throwable) {
            // Presence tracking is decorative. It must never turn a served page into
            // an error, and by kernel.terminate the response is already gone anyway.
        }
    }
}
