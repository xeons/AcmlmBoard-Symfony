<?php

declare(strict_types=1);

namespace App\Service\Sanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Filters the contents of every surviving style="" attribute against a property
 * allowlist.
 *
 * Letting arbitrary CSS through would undo most of the element allowlist: CSS alone
 * can execute script in old engines (expression(), behavior:, -moz-binding), exfiltrate
 * data by URL, cover the page to phish clicks (position:fixed with a high z-index),
 * or make the whole document unreadable. The original board's answer was to
 * regex-replace the literal string "filter:" with "x:" and hope, which stopped
 * exactly one spelling of one attack.
 *
 * Declarations whose property is not listed are dropped silently; the rest of the
 * rule survives, so a layout with one bad property still renders.
 */
final class StyleAttributeSanitizer implements AttributeSanitizerInterface
{
    /**
     * Properties a user may set. Chosen to cover what real AcmlmBoard layouts did -
     * coloured boxes, background images, borders, fonts, spacing - while excluding
     * anything that can escape its own box or reach the network for scripts.
     *
     * @var list<string>
     */
    private const ALLOWED_PROPERTIES = [
        // Colour and background
        'color', 'background', 'background-color', 'background-image',
        'background-repeat', 'background-position', 'background-attachment',
        'background-size', 'opacity',
        // Text
        'font', 'font-family', 'font-size', 'font-weight', 'font-style',
        'font-variant', 'line-height', 'letter-spacing', 'word-spacing',
        'text-align', 'text-decoration', 'text-indent', 'text-transform',
        'text-shadow', 'white-space', 'word-wrap', 'overflow-wrap', 'word-break',
        'vertical-align', 'direction',
        // Box
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-color', 'border-style', 'border-width', 'border-radius',
        'border-collapse', 'border-spacing',
        'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height',
        'box-sizing', 'box-shadow',
        // Layout, limited to values that stay inside the post
        'display', 'float', 'clear', 'overflow', 'overflow-x', 'overflow-y',
        'list-style', 'list-style-type', 'list-style-position',
        'table-layout', 'caption-side', 'empty-cells',
    ];

    /**
     * Substrings that void the whole declaration wherever they appear in a value.
     * Matched after whitespace/comment normalisation so `expr/**\/ession(` and
     * `java\0script:` cannot slip past.
     *
     * @var list<string>
     */
    private const FORBIDDEN_VALUE_TOKENS = [
        'expression', 'javascript:', 'vbscript:', 'behavior', '-moz-binding',
        '-ms-behavior', '@import', 'data:text/html', 'data:application',
    ];

    /**
     * `display` and `position` values that would let a post escape its container.
     *
     * @var list<string>
     */
    private const FORBIDDEN_DISPLAY_VALUES = ['fixed', 'sticky'];

    public function getSupportedElements(): ?array
    {
        // null = apply to every element that kept a style attribute.
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if ('style' !== $attribute) {
            return $value;
        }

        // Strip CSS comments first; they are the classic way to break up a
        // forbidden token so a naive substring check misses it.
        $value = preg_replace('~/\*.*?\*/~s', '', $value) ?? '';
        // Normalise away NUL and other control characters, which some engines skip.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';

        $kept = [];
        foreach (explode(';', $value) as $declaration) {
            $declaration = trim($declaration);
            if ('' === $declaration || !str_contains($declaration, ':')) {
                continue;
            }

            [$property, $propertyValue] = explode(':', $declaration, 2);
            $property = strtolower(trim($property));
            $propertyValue = trim($propertyValue);

            if ('' === $propertyValue || !\in_array($property, self::ALLOWED_PROPERTIES, true)) {
                continue;
            }

            if ($this->hasForbiddenToken($propertyValue)) {
                continue;
            }

            if ('display' === $property && $this->mentionsForbiddenPositioning($propertyValue)) {
                continue;
            }

            // url() may only reference http(s) - no data:, no javascript:, no
            // protocol-relative trickery.
            if (str_contains(strtolower($propertyValue), 'url(') && !$this->hasOnlySafeUrls($propertyValue)) {
                continue;
            }

            $kept[] = $property.': '.$propertyValue;
        }

        return [] === $kept ? null : implode('; ', $kept);
    }

    private function hasForbiddenToken(string $value): bool
    {
        // Collapse whitespace so "expr ession" and "java\nscript:" normalise to the
        // forms the token list names.
        $normalised = strtolower(preg_replace('/\s+/', '', $value) ?? $value);

        foreach (self::FORBIDDEN_VALUE_TOKENS as $token) {
            if (str_contains($normalised, $token)) {
                return true;
            }
        }

        return false;
    }

    private function mentionsForbiddenPositioning(string $value): bool
    {
        $normalised = strtolower($value);

        foreach (self::FORBIDDEN_DISPLAY_VALUES as $forbidden) {
            if (str_contains($normalised, $forbidden)) {
                return true;
            }
        }

        return false;
    }

    private function hasOnlySafeUrls(string $value): bool
    {
        if (!preg_match_all('~url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)~i', $value, $matches)) {
            // "url(" appeared but did not parse as a url() function; reject rather
            // than guess what the browser will make of it.
            return false;
        }

        foreach ($matches[1] as $url) {
            $url = trim($url);
            if (!preg_match('~^(https?:)?//~i', $url) && !str_starts_with($url, '/')) {
                return false;
            }
        }

        return true;
    }
}
