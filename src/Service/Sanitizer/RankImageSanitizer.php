<?php

declare(strict_types=1);

namespace App\Service\Sanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Confines a rank sprite to the board's own images directory.
 *
 * A rank badge renders under every post on every page, so an off-site src would
 * be an outbound request a third party controls, repeated across the whole board
 * and attached to every reader. Every sprite the original shipped lived under
 * images/ranks, images/ranksz or images/gb, so nothing is lost by refusing the
 * rest.
 *
 * This has to be an attribute sanitizer rather than configuration:
 * `allowed_media_schemes: []` reads like "permit no scheme at all" but means the
 * opposite, since an empty list leaves the URL sanitizer with no restriction to
 * apply.
 */
final class RankImageSanitizer implements AttributeSanitizerInterface
{
    /**
     * A root-relative path under /images/, no protocol, no host, no traversal.
     * Query strings are refused too: the original reached its ladder through
     * images/gb/rankimg.php?num=N, and those tiles are static files now.
     */
    private const ALLOWED_PATH = '~^/images/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9][A-Za-z0-9._-]*\.(?:png|gif|jpe?g|webp)$~';

    public function getSupportedElements(): ?array
    {
        return ['img'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if ('img' !== $element || 'src' !== $attribute) {
            return $value;
        }

        // Returning null drops the attribute, which leaves a broken <img> rather
        // than a working request to somewhere else.
        return preg_match(self::ALLOWED_PATH, $value) ? $value : null;
    }
}
