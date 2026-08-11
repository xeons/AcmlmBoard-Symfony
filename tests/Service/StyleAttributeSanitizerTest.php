<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Sanitizer\StyleAttributeSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The style filter is the part of the port that decides whether user layouts stay a
 * feature or become an attack surface, so its behaviour is pinned down directly
 * rather than only through the sanitizer as a whole.
 */
final class StyleAttributeSanitizerTest extends TestCase
{
    private StyleAttributeSanitizer $sanitizer;
    private HtmlSanitizerConfig $config;

    protected function setUp(): void
    {
        $this->sanitizer = new StyleAttributeSanitizer();
        $this->config = new HtmlSanitizerConfig();
    }

    private function filter(string $style): ?string
    {
        return $this->sanitizer->sanitizeAttribute('div', 'style', $style, $this->config);
    }

    #[DataProvider('allowedDeclarations')]
    public function testAllowedPropertiesSurvive(string $input, string $expectedFragment): void
    {
        $result = $this->filter($input);

        self::assertNotNull($result);
        self::assertStringContainsString($expectedFragment, $result);
    }

    public static function allowedDeclarations(): iterable
    {
        yield 'colour' => ['color: #ffea95', 'color: #ffea95'];
        yield 'background' => ['background: #402080', 'background: #402080'];
        yield 'border' => ['border: 1px solid black', 'border: 1px solid black'];
        yield 'font shorthand' => ['font: 12px verdana', 'font: 12px verdana'];
        yield 'padding' => ['padding: 4px 8px', 'padding: 4px 8px'];
        yield 'width' => ['width: 300px', 'width: 300px'];
        yield 'background image over https' => [
            'background-image: url(https://example.com/x.png)',
            'background-image',
        ];
    }

    /**
     * Each of these is a real technique, and each defeated the original board's
     * approach of replacing the literal string "filter:" with "x:".
     */
    #[DataProvider('forbiddenDeclarations')]
    public function testDangerousDeclarationsAreRemoved(string $input, string $why): void
    {
        $result = $this->filter($input) ?? '';

        self::assertSame('', $result, $why);
    }

    public static function forbiddenDeclarations(): iterable
    {
        yield 'IE expression' => [
            'width: expression(alert(1))',
            'expression() executes JavaScript in old IE',
        ];
        yield 'expression split by a comment' => [
            'width: expr/*x*/ession(alert(1))',
            'CSS comments must be stripped before the token check, not after',
        ];
        yield 'behavior' => [
            'behavior: url(evil.htc)',
            'behavior: loads an HTC scriptlet',
        ];
        yield 'moz-binding' => [
            '-moz-binding: url(evil.xml)',
            '-moz-binding: loads XBL, which can run script',
        ];
        yield 'javascript url' => [
            'background-image: url(javascript:alert(1))',
            'javascript: in url() is script execution',
        ];
        yield 'javascript url with whitespace' => [
            "background-image: url(java\nscript:alert(1))",
            'whitespace inside the scheme must not defeat the check',
        ];
        yield 'data html' => [
            'background-image: url(data:text/html;base64,PHNjcmlwdD4=)',
            'data:text/html can carry a whole document',
        ];
        yield 'import' => [
            'background: @import "evil.css"',
            '@import pulls in a stylesheet the board never vetted',
        ];
        yield 'unlisted property' => [
            'position: absolute',
            'position is not on the allowlist',
        ];
        yield 'fixed positioning via display' => [
            'display: fixed',
            'fixed positioning lets a post cover the page and phish clicks',
        ];
    }

    public function testDroppingOneBadDeclarationKeepsTheRest(): void
    {
        // A layout with one bad property should still mostly render, rather than
        // losing its whole style attribute.
        $result = $this->filter('color: red; behavior: url(x.htc); padding: 4px');

        self::assertNotNull($result);
        self::assertStringContainsString('color: red', $result);
        self::assertStringContainsString('padding: 4px', $result);
        self::assertStringNotContainsString('behavior', $result);
    }

    public function testRelativeAndProtocolRelativeUrlsAreAccepted(): void
    {
        self::assertNotNull($this->filter('background-image: url(/images/back.gif)'));
        self::assertNotNull($this->filter('background-image: url(//cdn.example.com/x.png)'));
    }

    public function testMalformedUrlFunctionIsRejected(): void
    {
        // "url(" present but not parseable as url(...) - reject rather than guess
        // what the browser will make of it.
        self::assertSame('', $this->filter('background-image: url(') ?? '');
    }

    public function testEmptyResultReturnsNullSoTheAttributeIsDropped(): void
    {
        self::assertNull($this->filter('position: fixed'));
        self::assertNull($this->filter(''));
        self::assertNull($this->filter('   '));
    }

    public function testNonStyleAttributesPassThroughUntouched(): void
    {
        self::assertSame(
            'anything',
            $this->sanitizer->sanitizeAttribute('div', 'class', 'anything', $this->config),
        );
    }

    public function testControlCharactersAreStripped(): void
    {
        // A NUL inside the property name is skipped by some parsers.
        $result = $this->filter("width: expr\x00ession(alert(1))") ?? '';

        self::assertSame('', $result);
    }
}
