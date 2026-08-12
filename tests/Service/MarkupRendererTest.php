<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MarkupRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end tests of the markup pipeline against the real configured sanitizer.
 *
 * The payloads below are the ones that worked against the original board. Its
 * defence was a blocklist - regex out `<script`, `on\w+=` and the literal string
 * "filter:" - applied *before* markup expansion, so anything that reassembled a tag
 * afterwards sailed through.
 */
final class MarkupRendererTest extends KernelTestCase
{
    private MarkupRenderer $markup;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->markup = self::getContainer()->get(MarkupRenderer::class);
    }

    // ------------------------------------------------------------- formatting

    public function testBasicTagsExpand(): void
    {
        $html = $this->markup->render('[b]bold[/b] and [i]italic[/i]');

        self::assertStringContainsString('<b>bold</b>', $html);
        self::assertStringContainsString('<i>italic</i>', $html);
    }

    public function testNamedColoursExpand(): void
    {
        $html = $this->markup->render('[red]warning[/color]');

        self::assertStringContainsString('#FFC0C0', $html);
        self::assertStringContainsString('warning', $html);
    }

    public function testNewlinesBecomeBreaks(): void
    {
        // The sanitizer re-serialises, so the exact spelling (<br> vs <br />) is its
        // choice, not ours. Assert on the element, not the byte sequence.
        self::assertStringContainsString('<br', $this->markup->render("one\ntwo"));
    }

    public function testNewlinesInsideCodeBlocksAreLeftAlone(): void
    {
        // Doubling the line breaks inside <pre> would render code with blank lines.
        $html = $this->markup->render("[code]line one\nline two[/code]");

        self::assertStringNotContainsString('<br>', $html);
        self::assertStringContainsString('line one', $html);
    }

    public function testSmileysExpand(): void
    {
        $html = $this->markup->render('hello :)');

        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('smile.gif', $html);
    }

    public function testPlainHtmlLayoutsSurvive(): void
    {
        // This is the point of choosing an allowlist over BBCode: real layouts work.
        $html = $this->markup->render(
            '<div style="background:#402080;padding:8px">'
            .'<font color="#FFEA95" size="2">My Layout</font>'
            .'<table border="1"><tr><td>cell</td></tr></table></div>'
        );

        self::assertStringContainsString('<div', $html);
        self::assertStringContainsString('background', $html);
        self::assertStringContainsString('<font', $html);
        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('cell', $html);
    }

    // ------------------------------------------------------------------ XSS

    /**
     * Asserts the *parsed* result is inert.
     *
     * Substring matching would be the wrong test here: a payload that ends up as
     * escaped text content is harmless even though the word "onerror" still appears
     * in the output. What matters is whether a browser building a DOM from this
     * would produce an executable node or attribute, so the assertion builds that
     * DOM and inspects it.
     */
    #[DataProvider('xssPayloads')]
    public function testXssPayloadsAreNeutralised(string $payload, string $why): void
    {
        $html = $this->markup->render($payload);

        $document = new \DOMDocument();
        $loaded = @$document->loadHTML(
            '<?xml encoding="UTF-8"><body>'.$html.'</body>',
            \LIBXML_NOERROR | \LIBXML_NOWARNING,
        );
        self::assertTrue($loaded, 'Rendered output must be parseable HTML');

        $xpath = new \DOMXPath($document);

        foreach (['script', 'iframe', 'object', 'embed', 'form', 'input', 'svg', 'body//body'] as $tag) {
            self::assertSame(
                0,
                $xpath->query('//'.$tag)->length,
                \sprintf('<%s> survived: %s', $tag, $why),
            );
        }

        /** @var \DOMElement $element */
        foreach ($xpath->query('//*') as $element) {
            foreach ($element->attributes ?? [] as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = strtolower($attribute->nodeValue ?? '');

                self::assertStringStartsNotWith('on', $name, \sprintf('Event handler "%s" survived: %s', $name, $why));

                if (\in_array($name, ['href', 'src', 'action', 'formaction', 'background'], true)) {
                    foreach (['javascript:', 'vbscript:', 'data:text/html'] as $scheme) {
                        self::assertStringNotContainsString(
                            $scheme,
                            preg_replace('/\s+/', '', $value) ?? $value,
                            \sprintf('Dangerous URL scheme in %s: %s', $name, $why),
                        );
                    }
                }
            }
        }
    }

    public static function xssPayloads(): iterable
    {
        yield 'plain script' => [
            '<script>alert(1)</script>',
            'the obvious case, which the original did catch',
        ];
        yield 'img onerror' => [
            '<img src=x onerror=alert(1)>',
            'the original stripped on*= only when followed by a quoted value',
        ];
        yield 'unquoted handler' => [
            '<img src=x onerror=alert(1) >',
            'unquoted handler values slipped past the original regex',
        ];
        yield 'javascript href' => [
            '<a href="javascript:alert(1)">click</a>',
            'javascript: URLs',
        ];
        yield 'entity-encoded javascript href' => [
            '<a href="java&#115;cript:alert(1)">click</a>',
            'entity encoding defeats a regex but not a parser',
        ];
        yield 'tab-obfuscated scheme' => [
            "<a href=\"java\tscript:alert(1)\">click</a>",
            'browsers strip tabs inside the scheme; the sanitizer must too',
        ];
        yield 'svg onload' => [
            '<svg onload=alert(1)>',
            'SVG was never considered by the original filter',
        ];
        yield 'iframe' => [
            '<iframe src="https://evil.example"></iframe>',
            'the original mangled <iframe into <<z>iframe, which browsers recover from',
        ];
        yield 'body onload' => [
            '<body onload=alert(1)>',
            'body is not an allowed element at all',
        ];
        yield 'nested script split by markup' => [
            '<scr[b][/b]ipt>alert(1)</script>',
            'tags reassembled after markup expansion - the reason sanitising runs last',
        ];
        yield 'style expression' => [
            '<div style="width:expression(alert(1))">x</div>',
            'CSS expression() in old IE',
        ];
        yield 'bbcode url with quote break' => [
            '[url=" onmouseover="alert(1)]link[/url]',
            'the original emitted [url=] targets unquoted and unescaped',
        ];
        yield 'bbcode img with handler' => [
            '[img]x" onerror="alert(1)[/img]',
            'same problem in the image tag',
        ];
        yield 'form injection' => [
            '<form action="https://evil.example"><input name="password"></form>',
            'a form in a post is a credential-phishing primitive',
        ];
    }

    public function testScriptTextIsKeptButNeutralised(): void
    {
        // Someone discussing code should still see their words, so the tag is
        // stripped rather than the subtree deleted.
        $html = $this->markup->render('<script>alert("hello")</script>');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('hello', $html);
    }

    public function testLinksGetSafeRelAndTarget(): void
    {
        $html = $this->markup->render('[url=https://example.com]example[/url]');

        self::assertStringContainsString('noopener', $html);
        self::assertStringContainsString('noreferrer', $html);
        self::assertStringContainsString('nofollow', $html);
    }

    // ------------------------------------------------------------ title mode

    public function testInlineModeRejectsBlockElementsAndImages(): void
    {
        // Titles appear in tight table cells, so an unlisted element is dropped
        // whole rather than unwrapped - a stray <div> in a title would otherwise
        // break the row it sits in.
        $html = $this->markup->renderInline('<div>block</div><img src="https://example.com/x.png">');

        self::assertStringNotContainsString('<div', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testInlineModeKeepsPlainText(): void
    {
        self::assertStringContainsString('Champion of the board', $this->markup->renderInline('Champion of the board'));
    }

    public function testInlineModeKeepsBasicDecoration(): void
    {
        $html = $this->markup->renderInline('[b]Champion[/b]');

        self::assertStringContainsString('<b>Champion</b>', $html);
    }

    // ----------------------------------------------------------- rank labels

    /**
     * Rank labels are the one place a picture has to survive an inline render:
     * nearly every rung the original shipped was a sprite stacked over its name.
     * They went through the title sanitizer for a while, which strips <img> by
     * design, so the ladders rendered as bare words.
     */
    public function testRankModeKeepsASpriteWithARelativeSource(): void
    {
        $html = $this->markup->renderRank('<img src="/images/ranks/goomba.gif" width="16" height="16" alt=""><br>Goomba');

        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('/images/ranks/goomba.gif', $html);
        self::assertStringContainsString('Goomba', $html);
    }

    /**
     * A rank renders on every post of every page, so an off-site src would be an
     * outbound request a third party controls, repeated across the whole board
     * and attached to every reader. Paths under /images/ only, which is every
     * sprite the original shipped.
     *
     * Configuration alone does not get this right: `allowed_media_schemes: []`
     * reads like "permit no scheme" and behaves like "apply no restriction", so
     * the https sprite below survived until RankImageSanitizer took over.
     */
    #[DataProvider('rejectedSprites')]
    public function testRankModeConfinesSpritesToTheBoardsOwnImages(string $src): void
    {
        $html = $this->markup->renderRank(\sprintf('<img src="%s">', $src));

        self::assertStringNotContainsString($src, $html);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedSprites(): iterable
    {
        yield 'off-site https' => ['https://example.com/tracker.gif'];
        yield 'off-site http' => ['http://example.com/tracker.gif'];
        yield 'protocol relative' => ['//example.com/tracker.gif'];
        yield 'outside images' => ['/uploads/tracker.gif'];
        yield 'traversal' => ['/images/../../etc/passwd'];
        yield 'the old resizer' => ['/images/gb/rankimg.php?num=3'];
    }

    public function testRankModeKeepsEverySpriteDirectoryTheOriginalUsed(): void
    {
        foreach (['/images/ranks/goomba.gif', '/images/ranksz/Octorok.gif', '/images/gb/rank23.png'] as $src) {
            self::assertStringContainsString(
                $src,
                $this->markup->renderRank(\sprintf('<img src="%s">', $src)),
            );
        }
    }

    /** As with a title, an element that is not on the list goes with its contents. */
    public function testRankModeDropsLinks(): void
    {
        self::assertStringNotContainsString('<a ', $this->markup->renderRank('<a href="https://example.com">Rank</a>'));
    }

    public function testRankModeStillRejectsScripting(): void
    {
        $html = $this->markup->renderRank('<img src="/images/ranks/goomba.gif" onerror="alert(1)"><script>alert(2)</script>');

        self::assertStringNotContainsString('onerror', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    /** Widening the rank profile must not have widened the title one with it. */
    public function testTitlesStillCannotCarryAnImage(): void
    {
        self::assertStringNotContainsString(
            '<img',
            $this->markup->renderInline('<img src="/images/ranks/goomba.gif">Champion'),
        );
    }

    // ------------------------------------------------------------ plain text

    public function testPlainTextStripsMarkupAndTruncates(): void
    {
        $text = $this->markup->toPlainText('[b]Hello[/b] <i>there</i>, friend', 10);

        self::assertStringNotContainsString('<', $text);
        self::assertLessThanOrEqual(13, mb_strlen($text)); // 10 plus the "..."
    }

    public function testEmptyInputRendersEmpty(): void
    {
        self::assertSame('', $this->markup->render(null));
        self::assertSame('', $this->markup->render(''));
        self::assertSame('', $this->markup->render('   '));
    }

    // ----------------------------------------------------------- code blocks

    /**
     * A code block has to survive the whole pipeline untouched. It is the one place
     * where markup, smileys and layout tokens must all be inert, because showing
     * them is the entire point of the block.
     */
    public function testCodeBlockContentIsEscapedNotInterpreted(): void
    {
        $html = $this->markup->render('[code]<not html>[/code]');

        self::assertStringContainsString('<pre class="code">', $html);
        self::assertStringContainsString('&lt;not html&gt;', $html);
        self::assertStringNotContainsString('<not', $html);
    }

    public function testCodeBlockKeepsNewlinesAndSuppressesSmileys(): void
    {
        $html = $this->markup->render("[code]line one\nline two :) &numposts&[/code]");

        // No <br>: the <pre> already preserves the newline, and doubling it would
        // put a blank line between every line of code.
        self::assertStringNotContainsString('<br', $html);
        // The smiley code stays as text rather than becoming an image.
        self::assertStringNotContainsString('smile.gif', $html);
        // The layout token is displayed, not substituted.
        self::assertStringContainsString('numposts', $html);
    }

    public function testCodeBlockCannotBeSmuggledThroughThePlaceholder(): void
    {
        // Text resembling the internal placeholder must not become one, which is
        // why the placeholder carries a per-call random nonce.
        $html = $this->markup->render('xCODEBLOCK0000000000000000x0x [code]real[/code]');

        self::assertStringContainsString('<pre class="code">real</pre>', $html);
        self::assertStringContainsString('xCODEBLOCK0000000000000000x0x', $html);
    }

    public function testTextAroundCodeBlocksStillRenders(): void
    {
        $html = $this->markup->render('[b]before[/b] [code]x < y[/code] [i]after[/i]');

        self::assertStringContainsString('<b>before</b>', $html);
        self::assertStringContainsString('x &lt; y', $html);
        self::assertStringContainsString('<i>after</i>', $html);
    }

    public function testScriptInsideACodeBlockIsInert(): void
    {
        $html = $this->markup->render('[code]<script>alert(1)</script>[/code]');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
