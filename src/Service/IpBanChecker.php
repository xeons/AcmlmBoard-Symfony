<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\IpBan;
use App\Repository\IpBanRepository;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Decides whether an address is banned, with the ban list cached.
 *
 * Caching this is worthwhile - it is consulted on every single request - but a
 * time-based cache alone is wrong for a moderation control: an administrator who
 * bans an address that is mid-attack should not have to wait for a TTL to lapse.
 * Every write path calls invalidate(), so the cache is a performance detail rather
 * than a correctness one, and the TTL is only a backstop for changes made outside
 * the application (a direct SQL edit, or another web node).
 */
final class IpBanChecker
{
    private const CACHE_KEY = 'security.ip_bans.active';
    private const CACHE_TTL = 60;

    public function __construct(
        private readonly IpBanRepository $bans,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * The ban covering this address, if any.
     *
     * Matching is delegated to IpUtils, which does real subnet arithmetic for IPv4
     * and IPv6. The original compared with `INSTR('$ip', ip)=1`, a prefix match on
     * the raw string, so a ban on "10.0.0.1" also caught 10.0.0.10 through
     * 10.0.0.19, and a ban on "1" caught a quarter of the internet.
     */
    public function findMatch(?string $ip): ?IpBan
    {
        if (null === $ip || '' === $ip) {
            return null;
        }

        $ranges = $this->activeRanges();
        if ([] === $ranges) {
            return null;
        }

        foreach ($ranges as $id => $range) {
            if (IpUtils::checkIp($ip, $range)) {
                // Only load the entity once something matched, so the common case
                // (no match) costs nothing beyond the cached range list.
                return $this->bans->find($id);
            }
        }

        return null;
    }

    public function isBanned(?string $ip): bool
    {
        return null !== $this->findMatch($ip);
    }

    /** Call after any change to the ban list. */
    public function invalidate(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    /**
     * @return array<int, string> ban id => CIDR range
     */
    private function activeRanges(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            $ranges = [];
            foreach ($this->bans->findActive() as $ban) {
                $ranges[(int) $ban->getId()] = $ban->getIpRange();
            }

            return $ranges;
        });
    }
}
