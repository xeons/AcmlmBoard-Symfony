<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Forum;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared plumbing for board controllers: typed access to the current user, CSRF
 * assertion for the non-form POST actions, pagination clamping, and marking which
 * forum a page belongs to so the presence tracker can group by it.
 */
abstract class AbstractBoardController extends AbstractController
{
    /** Request attribute read by ActivityListener to record "users currently in X". */
    public const CURRENT_FORUM_ATTRIBUTE = '_current_forum';

    protected function boardUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    protected function requireBoardUser(): User
    {
        $user = $this->boardUser();
        if (null === $user) {
            throw $this->createAccessDeniedException('You must be logged in.');
        }

        return $user;
    }

    /** Effective power of the viewer; guests are 0, banned users are floored to 0. */
    protected function viewerPower(): int
    {
        return $this->boardUser()?->effectivePower() ?? 0;
    }

    /**
     * Validates a CSRF token on a non-form POST (moderation buttons, toggles).
     * Forms built with the Form component validate their own token.
     *
     * Also accepts the token from a JSON body, because the passkey endpoints are
     * called with fetch() and Content-Type: application/json - for those,
     * $request->request is empty and reading only from it would reject every
     * request as untokened.
     */
    protected function assertCsrf(Request $request, string $id): void
    {
        $token = (string) ($request->request->get('_token')
            ?? $request->query->get('_token')
            ?? $this->jsonField($request, '_token')
            ?? '');

        if (!$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException('Invalid or missing CSRF token.');
        }
    }

    /** Reads a top-level scalar from a JSON request body, or null. */
    protected function jsonField(Request $request, string $field): ?string
    {
        if (!str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            return null;
        }

        $payload = json_decode($request->getContent(), true);

        return \is_array($payload) && isset($payload[$field]) && is_scalar($payload[$field])
            ? (string) $payload[$field]
            : null;
    }

    /** Page number from the query string, clamped to at least 1. */
    protected function pageFrom(Request $request, string $key = 'page'): int
    {
        return max(1, $request->query->getInt($key, 1));
    }

    /**
     * Per-page size, honouring an explicit override but clamping it so a crafted
     * URL cannot ask for every post in the board at once - the original happily
     * interpolated `LIMIT $min,$ppp` straight from the query string.
     */
    protected function perPage(Request $request, int $default, string $key = 'ppp', int $max = 100): int
    {
        $requested = $request->query->getInt($key, 0);

        return $requested > 0 ? min($max, max(5, $requested)) : $default;
    }

    /** Tags the request with the forum being viewed, for presence tracking. */
    protected function trackForum(Request $request, ?Forum $forum): void
    {
        $request->attributes->set(self::CURRENT_FORUM_ATTRIBUTE, $forum);
    }

    protected function clientIp(Request $request): ?string
    {
        return $request->getClientIp();
    }
}
