<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Repository\SoftBanRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Keeps banned and suspended accounts out of the write paths.
 *
 * The voters already refuse each individual action, but a banned user reaching a
 * compose form only to be rejected on submit is a poor experience and leaves room
 * for a path that forgot to ask. This is the belt to the voters' braces.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class BannedUserListener
{
    /**
     * Route prefixes a banned user may not reach at all.
     *
     * @var list<string>
     */
    private const BLOCKED_ROUTE_PREFIXES = [
        'app_thread_new',
        'app_thread_reply',
        'app_post_edit',
        'app_message_send',
        'app_profile_edit',
        'app_layout_edit',
        'app_shop',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly SoftBanRepository $softBans,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route');
        if ('' === $route) {
            return;
        }

        $blocked = false;
        foreach (self::BLOCKED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                $blocked = true;
                break;
            }
        }

        if (!$blocked) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        if ($user->isBanned() || null !== $this->softBans->findActiveFor($user)) {
            $event->setResponse(new RedirectResponse($this->urls->generate('app_banned')));
        }
    }
}
