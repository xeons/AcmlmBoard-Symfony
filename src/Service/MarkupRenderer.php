<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Turns what a user typed into safe HTML.
 *
 * The pipeline, in order:
 *   1. expand the board's bracket tags into HTML
 *   2. expand smileys
 *   3. convert newlines to <br>
 *   4. sanitize the whole thing against the allowlist
 *
 * Sanitising *last* is the important part. The original interleaved its filtering
 * with its markup expansion, so a payload could be assembled out of pieces that were
 * each individually harmless - e.g. writing "[b]" to inject a tag boundary after the
 * script filter had already run. Here every producer runs first and the sanitizer
 * gets the final document, so nothing can be smuggled past it by later processing.
 */
final class MarkupRenderer
{
    /**
     * The original's [red]/[green]/... tags and their exact colours, from
     * doreplace2() in lib/function.php.
     *
     * @var array<string, string>
     */
    private const COLOR_TAGS = [
        'red' => '#FFC0C0',
        'green' => '#C0FFC0',
        'blue' => '#C0C0FF',
        'orange' => '#FFC080',
        'yellow' => '#FFEE20',
        'pink' => '#FFC0FF',
        'white' => '#FFFFFF',
        'black' => '#000000',
    ];

    public function __construct(
        private readonly SmileyRepository $smileys,
        #[Autowire(service: 'html_sanitizer.sanitizer.app.post_sanitizer')]
        private readonly HtmlSanitizerInterface $postSanitizer,
        #[Autowire(service: 'html_sanitizer.sanitizer.app.title_sanitizer')]
        private readonly HtmlSanitizerInterface $titleSanitizer,
        #[Autowire(service: 'html_sanitizer.sanitizer.app.rank_sanitizer')]
        private readonly HtmlSanitizerInterface $rankSanitizer,
    ) {
    }

    /** Full pipeline for post bodies, signatures, headers and announcements. */
    public function render(?string $raw): string
    {
        if (null === $raw || '' === trim($raw)) {
            return '';
        }

        // Code blocks are lifted out before anything else runs and put back at the
        // very end. Their contents must survive verbatim: a smiley code inside a
        // code block is not a smiley, a newline is already significant, and markup
        // is the thing being demonstrated rather than something to interpret.
        [$html, $codeBlocks] = $this->extractCodeBlocks($raw);

        $html = $this->expandTags($html);
        $html = $this->expandSmileys($html);
        $html = $this->convertNewlines($html);

        $html = $this->postSanitizer->sanitize($html);

        return $this->restoreCodeBlocks($html, $codeBlocks);
    }

    /**
     * Inline-only rendering for member-authored custom titles and thread titles.
     * Uses the strictest sanitizer: no images, no links, no block boxes.
     */
    public function renderInline(?string $raw): string
    {
        if (null === $raw || '' === trim($raw)) {
            return '';
        }

        return $this->titleSanitizer->sanitize($this->expandTags($raw));
    }

    /**
     * Rank ladder labels, which are a sprite stacked over a name in every set the
     * original shipped. Same inline-only treatment as a custom title, except that
     * <img> survives and its src must be a relative path.
     */
    public function renderRank(?string $raw): string
    {
        if (null === $raw || '' === trim($raw)) {
            return '';
        }

        return $this->rankSanitizer->sanitize($this->expandTags($raw));
    }

    /**
     * Sanitises already-HTML content without re-expanding markup. Used for layout
     * fragments that were composed by the renderer itself.
     */
    public function sanitize(?string $html): string
    {
        return null === $html || '' === $html ? '' : $this->postSanitizer->sanitize($html);
    }

    /** Strips all markup down to text, for feed summaries and search excerpts. */
    public function toPlainText(?string $raw, int $maxLength = 0): string
    {
        $text = html_entity_decode(strip_tags($this->render($raw)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
            $text = rtrim(mb_substr($text, 0, $maxLength)).'...';
        }

        return $text;
    }

    /**
     * Expands the bracket tag vocabulary.
     *
     * Attribute values are escaped before being written into the HTML, so a title
     * like [url=" onmouseover="] cannot break out of the attribute - which is
     * precisely what the original's `'[url=(.*?)\](.*?)\[/url\]' -> '<a href=\1>\2'`
     * allowed, since it emitted the URL unquoted and unescaped.
     */
    private function expandTags(string $text): string
    {
        // Simple paired tags.
        $text = preg_replace('~\[(b|i|u|s)\](.*?)\[/\1\]~is', '<$1>$2</$1>', $text) ?? $text;

        // Named colours.
        foreach (self::COLOR_TAGS as $name => $hex) {
            $text = str_ireplace(
                ['['.$name.']', '[/'.$name.']'],
                ['<span style="color:'.$hex.'">', '</span>'],
                $text,
            );
        }

        // [color=...] with a validated colour, so the style attribute stays intact.
        $text = preg_replace_callback(
            '~\[color=([#\w(),.%\s-]{1,40})\](.*?)\[/color\]~is',
            function (array $m): string {
                $color = trim($m[1]);
                if (!preg_match('/^(#[0-9a-f]{3,8}|[a-z]{3,20}|rgba?\([\d\s,.%]+\))$/i', $color)) {
                    return $m[2];
                }

                return '<span style="color:'.htmlspecialchars($color, \ENT_QUOTES, 'UTF-8').'">'.$m[2].'</span>';
            },
            $text,
        ) ?? $text;
        // Bare [/color] left over from the named-colour form.
        $text = str_ireplace('[/color]', '</span>', $text);

        // Spoiler: click-to-reveal, done in CSS rather than the original's
        // black-on-black div, which leaked to anyone who selected the text anyway.
        $text = str_ireplace('[spoiler]', '<span class="spoiler"><span class="spoiler-label">Spoiler:</span><span class="spoiler-body">', $text);
        $text = str_ireplace('[/spoiler]', '</span></span>', $text);

        // Images and links.
        $text = preg_replace_callback(
            '~\[img\]\s*(.*?)\s*\[/img\]~is',
            fn (array $m): string => $this->imageTag($m[1]),
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '~\[url=([^\]\s]+)\](.*?)\[/url\]~is',
            fn (array $m): string => $this->linkTag($m[1], $m[2]),
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '~\[url\]\s*(.*?)\s*\[/url\]~is',
            fn (array $m): string => $this->linkTag($m[1], htmlspecialchars($m[1], \ENT_QUOTES, 'UTF-8')),
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '~\[quote(?:=([^\]]{1,60}))?\](.*?)\[/quote\]~is',
            static function (array $m): string {
                $who = '' !== ($m[1] ?? '')
                    ? '<cite>Originally posted by '.htmlspecialchars($m[1], \ENT_QUOTES, 'UTF-8').'</cite>'
                    : '';

                return '<blockquote class="quote">'.$who.$m[2].'</blockquote>';
            },
            $text,
        ) ?? $text;

        return $text;
    }

    /**
     * Replaces each [code] block with an opaque placeholder and returns the escaped
     * contents separately.
     *
     * The placeholder is derived from a per-call random nonce, so a post that
     * contains text resembling a placeholder cannot smuggle content back in at the
     * restore step.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function extractCodeBlocks(string $text): array
    {
        if (!str_contains(strtolower($text), '[code]')) {
            return [$text, []];
        }

        // Alphanumerics only: the sanitizer escapes punctuation such as "@" into
        // entities, which would stop the placeholder matching at restore time.
        $nonce = bin2hex(random_bytes(8));
        $blocks = [];
        $index = 0;

        $result = preg_replace_callback(
            '~\[code\](.*?)\[/code\]~is',
            static function (array $m) use (&$blocks, &$index, $nonce): string {
                $placeholder = \sprintf('xCODEBLOCK%sx%dx', $nonce, $index++);
                $blocks[$placeholder] = '<pre class="code">'
                    .htmlspecialchars($m[1], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                    .'</pre>';

                return $placeholder;
            },
            $text,
        );

        return [$result ?? $text, $blocks];
    }

    /** @param array<string, string> $blocks */
    private function restoreCodeBlocks(string $html, array $blocks): string
    {
        return [] === $blocks ? $html : strtr($html, $blocks);
    }

    private function imageTag(string $url): string
    {
        if (!$this->isSafeUrl($url)) {
            return htmlspecialchars($url, \ENT_QUOTES, 'UTF-8');
        }

        return '<img src="'.htmlspecialchars($url, \ENT_QUOTES, 'UTF-8').'" alt="">';
    }

    private function linkTag(string $url, string $label): string
    {
        if (!$this->isSafeUrl($url)) {
            return $label;
        }

        return '<a href="'.htmlspecialchars($url, \ENT_QUOTES, 'UTF-8').'">'.$label.'</a>';
    }

    /**
     * A cheap first pass; the sanitizer's scheme allowlist is the real gate. This
     * exists so a rejected URL degrades to visible text rather than a stripped
     * attribute on an empty tag.
     */
    private function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        return '' !== $url && preg_match('~^(https?://|/|mailto:)~i', $url) > 0;
    }

    private function expandSmileys(string $text): string
    {
        foreach ($this->smileys->all() as $smiley) {
            $img = '<img src="'.htmlspecialchars($smiley['image'], \ENT_QUOTES, 'UTF-8').'"'
                .' alt="'.htmlspecialchars($smiley['code'], \ENT_QUOTES, 'UTF-8').'"'
                .' class="smiley">';
            $text = str_replace($smiley['code'], $img, $text);
        }

        return $text;
    }

    /**
     * Newline to <br>, but not inside <pre>, where the whitespace is already
     * meaningful and doubling it would break code blocks.
     */
    private function convertNewlines(string $text): string
    {
        $parts = preg_split('~(<pre\b.*?</pre>)~is', $text, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        foreach ($parts as $i => $part) {
            if (!str_starts_with(strtolower(ltrim($part)), '<pre')) {
                $parts[$i] = nl2br($part, false);
            }
        }

        return implode('', $parts);
    }
}
