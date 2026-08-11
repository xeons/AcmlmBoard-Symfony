<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\IpBanChecker;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Blocks requests from banned addresses.
 *
 * The original ran three separate `SELECT count(*) FROM ipbans WHERE INSTR(...)`
 * queries per pageview - one each for REMOTE_ADDR, HTTP_CLIENT_IP and
 * HTTP_X_FORWARDED_FOR - and interpolated all three unescaped, so a crafted
 * X-Forwarded-For header was a direct SQL injection into every page of the board.
 *
 * Here the client address comes from Symfony's trusted-proxy resolution, which only
 * honours forwarded headers when TRUSTED_PROXIES names the proxy. With no trusted
 * proxy configured, a forwarded header cannot influence the result at all.
 *
 * Priority 6 places this after the firewall but before routing, so a banned visitor
 * is stopped before any controller resolves.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final class IpBanListener
{
    public function __construct(
        private readonly IpBanChecker $bans,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $ban = $this->bans->findMatch($event->getRequest()->getClientIp());
        if (null === $ban) {
            return;
        }

        $event->setResponse(new Response(
            $this->twig->render('error/ip_banned.html.twig', ['ban' => $ban]),
            Response::HTTP_FORBIDDEN,
        ));
        $event->stopPropagation();
    }
}
