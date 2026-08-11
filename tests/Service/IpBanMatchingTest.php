<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Pins down the subnet semantics IpBanChecker relies on.
 *
 * The original matched bans with `WHERE INSTR('$userip', ip) = 1` - a prefix match
 * on the decimal string - which produced two failure modes that were both reported
 * as bugs on real boards: bans that caught far more addresses than intended, and
 * bans that could not express a range at all.
 */
final class IpBanMatchingTest extends TestCase
{
    #[DataProvider('matchCases')]
    public function testSubnetMatching(string $ip, string $range, bool $expected, string $why): void
    {
        self::assertSame($expected, IpUtils::checkIp($ip, $range), $why);
    }

    public static function matchCases(): iterable
    {
        yield 'exact address matches itself' => [
            '203.0.113.7', '203.0.113.7', true,
            'the ordinary case',
        ];

        yield 'exact address does not catch a longer one' => [
            '203.0.113.70', '203.0.113.7', false,
            'the original prefix match caught .70 through .79 when banning .7',
        ];

        yield 'a /24 covers its members' => [
            '203.0.113.70', '203.0.113.0/24', true,
            'ranges are expressible now, which the original could not do properly',
        ];

        yield 'a /24 excludes neighbours' => [
            '203.0.114.1', '203.0.113.0/24', false,
            'and they stop where they should',
        ];

        yield 'a /16 covers a wider block' => [
            '203.0.200.5', '203.0.0.0/16', true,
            '',
        ];

        yield 'a single digit does not ban the internet' => [
            '20.30.40.50', '2', false,
            'the original would have matched every address beginning with "2"',
        ];

        yield 'IPv6 exact' => [
            '2001:db8::1', '2001:db8::1', true,
            'the original had no IPv6 handling at all',
        ];

        yield 'IPv6 range' => [
            '2001:db8:1234::5', '2001:db8::/32', true,
            '',
        ];

        yield 'IPv6 outside range' => [
            '2001:dead::1', '2001:db8::/32', false,
            '',
        ];
    }

    /**
     * The address itself comes from Symfony's client-IP resolution, which ignores
     * X-Forwarded-For unless the request came from a configured trusted proxy. That
     * is what stops a visitor from choosing their own apparent address - and, in the
     * original, from injecting SQL through the header, since it was interpolated
     * into the ban query unescaped.
     */
    public function testForwardedHeadersCannotBeTrustedByDefault(): void
    {
        $request = \Symfony\Component\HttpFoundation\Request::create('/');
        $request->server->set('REMOTE_ADDR', '198.51.100.9');
        $request->headers->set('X-Forwarded-For', '203.0.113.1');

        // With no trusted proxies configured, the forwarded header is ignored.
        self::assertSame('198.51.100.9', $request->getClientIp());
    }
}
