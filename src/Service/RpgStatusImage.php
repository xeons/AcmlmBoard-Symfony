<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Item;
use App\Entity\RpgProfile;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The RPG status screen, drawn as a PNG. Ported from status.php.
 *
 * 256x224 is the SNES resolution, and the whole thing is drawn on an 8-pixel grid
 * with a bitmap font sheet rather than a TrueType face, so it looks like a console
 * menu rather than a web page. Text is blitted glyph by glyph from
 * images/rpg/font.png, a 16x16 grid of 8x8 characters indexed by ASCII code; the
 * sheet is an indexed PNG whose palette is rewritten per colour to tint the glyphs.
 */
final class RpgStatusImage
{
    private const WIDTH = 256;
    private const HEIGHT = 224;
    private const CELL = 8;

    /** Bar lengths are divided by the first of these that makes them fit. */
    private const SCALES = [1, 5, 25, 100, 250, 500, 1000, 99999999];

    /** The seven stats shown as bars; HP and MP have their own gauges. */
    private const BAR_STATS = ['Atk', 'Def', 'Int', 'MDf', 'Dex', 'Lck', 'Spd'];

    public function __construct(
        private readonly RpgCalculator $rpg,
        private readonly LevelCalculator $levels,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param array<int, Item> $equipped item id => item
     *
     * @return string PNG data
     */
    public function render(User $user, RpgProfile $profile, array $equipped = []): string
    {
        $stats = $this->rpg->statsFor($user, $equipped);
        $experience = $this->levels->experienceFor($user);
        $level = $this->levels->level($experience);
        $progress = $this->levels->levelProgress($experience);
        $coins = $this->rpg->availableCoins($user, $profile);

        $img = imagecreate(self::WIDTH, self::HEIGHT);
        if (false === $img) {
            throw new \RuntimeException('Could not create the status image.');
        }

        // The first colour allocated becomes index 0, which is then made
        // transparent so the screen sits on whatever the page background is.
        $background = imagecolorallocate($img, 40, 40, 90);
        $edge = imagecolorallocate($img, 0, 0, 0);
        $light = imagecolorallocate($img, 225, 200, 180);
        $mid = imagecolorallocate($img, 190, 160, 130);
        $dark = imagecolorallocate($img, 130, 110, 90);

        // The vertical gradient inside every box.
        $gradient = [];
        for ($i = 0; $i < 100; ++$i) {
            $gradient[$i] = imagecolorallocate($img, 10, 16, 80 + $i);
        }

        $expEmpty = imagecolorallocate($img, 30, 60, 90);
        $expFull = imagecolorallocate($img, 120, 150, 180);

        $barColours = [];
        foreach ([
            1 => [215, 91, 129], 2 => [255, 136, 154], 3 => [255, 139, 89], 4 => [255, 251, 89],
            5 => [89, 255, 139], 6 => [89, 213, 255], 7 => [196, 33, 33],
        ] as $step => [$r, $g, $b]) {
            $barColours[$step] = imagecolorallocate($img, $r, $g, $b);
        }

        imagecolortransparent($img, $background);

        $box = fn (int $x, int $y, int $w, int $h) => $this->box($img, $x, $y, $w, $h, [$edge, $light, $mid, $dark], $gradient);

        $box(0, 0, 2 + mb_strlen($user->getUsername()), 3);
        $box(0, 4, 32, 4);
        $box(0, 9, 32, 9);
        $box(0, 19, 11, 9);
        $box(12, 19, 11, 6);

        $white = $this->font(255, 255, 255, 210, 210, 210);
        $blue = $this->font(160, 240, 255, 120, 190, 240);
        $yellow = $this->font(255, 250, 240, 255, 240, 80);
        $red = $this->font(255, 230, 220, 240, 160, 150);
        $green = $this->font(190, 255, 190, 60, 220, 60);

        $this->write($img, $white, 1, 1, 0, $user->getUsername());

        $this->write($img, $blue, 1, 5, 0, 'HP:      /');
        $this->write($img, $red, 3, 5, 7, (string) $stats['HP']);
        $this->write($img, $yellow, 11, 5, 5, (string) $stats['HP']);
        $this->write($img, $blue, 1, 6, 0, 'MP:      /');
        $this->write($img, $red, 3, 6, 7, (string) $stats['MP']);
        $this->write($img, $yellow, 11, 6, 5, (string) $stats['MP']);

        foreach (self::BAR_STATS as $i => $stat) {
            $this->write($img, $blue, 1, 10 + $i, 0, $stat.':');
            $this->write($img, $yellow, 4, 10 + $i, 6, (string) $stats[$stat]);
        }

        $this->write($img, $blue, 1, 20, 0, 'Level');
        $this->write($img, $yellow, 6, 20, 4, (string) $level);
        $this->write($img, $blue, 1, 22, 0, 'EXP:');
        $this->write($img, $yellow, 1, 23, 9, (string) $experience);
        $this->write($img, $blue, 1, 24, 0, 'Next:');
        $this->write($img, $yellow, 1, 25, 9, (string) $this->levels->experienceToNextLevel($experience));

        $this->write($img, $blue, 13, 20, 0, 'Coins:');
        // Glyph 0 of the sheet is the coin icon.
        $this->write($img, $yellow, 13, 22, 0, \chr(0));
        $this->write($img, $yellow, 14, 22, 8, (string) max(0, $coins));

        $this->bars($img, $stats, $progress, $edge, $barColours, $expEmpty, $expFull);

        foreach ([$white, $blue, $yellow, $red, $green] as $font) {
            imagedestroy($font);
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    /**
     * A copy of the font sheet with its palette rewritten.
     *
     * The sheet's indices 3 to 6 are a light-to-dark ramp; recolouring them tints
     * every glyph at once, which is how the original produced five font colours
     * from one image.
     *
     * @return \GdImage
     */
    private function font(int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): \GdImage
    {
        $font = imagecreatefrompng($this->projectDir.'/public/images/rpg/font.png');
        if (false === $font) {
            throw new \RuntimeException('The RPG font sheet is missing.');
        }

        imagecolortransparent($font, 1);
        imagecolorset($font, 6, $r1, $g1, $b1);
        imagecolorset($font, 5, (int) (($r1 * 2 + $r2) / 3), (int) (($g1 * 2 + $g2) / 3), (int) (($b1 * 2 + $b2) / 3));
        imagecolorset($font, 4, (int) (($r1 + $r2 * 2) / 3), (int) (($g1 + $g2 * 2) / 3), (int) (($b1 + $b2 * 2) / 3));
        imagecolorset($font, 3, $r2, $g2, $b2);
        imagecolorset($font, 0, 0, 0, 0);

        return $font;
    }

    /**
     * Blits text from the font sheet. Coordinates are in 8-pixel cells; $pad
     * right-aligns the text within that many characters.
     */
    private function write(\GdImage $img, \GdImage $font, int $x, int $y, int $pad, string $text): void
    {
        $x *= self::CELL;
        $y *= self::CELL;

        $length = \strlen($text);
        if ($length < $pad) {
            $x += ($pad - $length) * self::CELL;
        }

        for ($i = 0; $i < $length; ++$i) {
            $code = \ord($text[$i]);
            imagecopy(
                $img, $font,
                $i * self::CELL + $x, $y,
                ($code % 16) * self::CELL, intdiv($code, 16) * self::CELL,
                self::CELL, self::CELL,
            );
        }
    }

    /**
     * A window: four nested bevel rectangles around a vertical gradient.
     *
     * @param array{int, int, int, int} $bevel edge, light, mid, dark
     * @param array<int, int>           $gradient
     */
    private function box(\GdImage $img, int $x, int $y, int $w, int $h, array $bevel, array $gradient): void
    {
        [$edge, $light, $mid, $dark] = $bevel;

        $x *= self::CELL;
        $y *= self::CELL;
        $w *= self::CELL;
        $h *= self::CELL;

        foreach ([[0, $edge], [1, $dark], [2, $light], [3, $mid], [4, $edge]] as [$inset, $colour]) {
            imagerectangle($img, $x + $inset, $y + $inset, $x + $w - 1 - $inset, $y + $h - 1 - $inset, $colour);
        }

        for ($i = 5; $i < $h - 5; ++$i) {
            $shade = (int) ((1 - $i / $h) * 100);
            imageline($img, $x + 5, $y + $i, $x + $w - 6, $y + $i, $gradient[max(0, min(99, $shade))]);
        }
    }

    /**
     * @param array<string, int> $stats
     * @param array<int, int>    $barColours
     */
    private function bars(
        \GdImage $img,
        array $stats,
        float $progress,
        int $shadow,
        array $barColours,
        int $expEmpty,
        int $expFull,
    ): void {
        // HP and MP share a scale; the stats share another.
        $poolScale = $this->scaleFor(max($stats['HP'], $stats['MP']), 113);
        $hp = intdiv($stats['HP'], self::SCALES[$poolScale]);
        $mp = intdiv($stats['MP'], self::SCALES[$poolScale]);

        imagefilledrectangle($img, 137, 41, 136 + $hp, 47, $shadow);
        imagefilledrectangle($img, 137, 49, 136 + $mp, 55, $shadow);
        imagefilledrectangle($img, 136, 40, 135 + $hp, 46, $barColours[$poolScale] ?? $barColours[7]);
        imagefilledrectangle($img, 136, 48, 135 + $mp, 54, $barColours[$poolScale] ?? $barColours[7]);

        $statScale = $this->scaleFor(max(array_map(static fn (string $s): int => $stats[$s], self::BAR_STATS)), 161);
        $colour = $barColours[$statScale] ?? $barColours[7];

        foreach (self::BAR_STATS as $i => $stat) {
            $length = intdiv($stats[$stat], self::SCALES[$statScale]);
            $row = $i + 2;
            imagefilledrectangle($img, 89, 65 + $row * 8, 88 + $length, 71 + $row * 8, $shadow);
            imagefilledrectangle($img, 88, 64 + $row * 8, 87 + $length, 70 + $row * 8, $colour);
        }

        $filled = (int) round(72 * $progress);
        imagefilledrectangle($img, 9, 209, 80, 215, $shadow);
        imagefilledrectangle($img, 8, 208, 79, 214, $expEmpty);
        if ($filled > 0) {
            imagefilledrectangle($img, 8, 208, 7 + $filled, 214, $expFull);
        }
    }

    /** The first scale step that brings a value under the available pixels. */
    private function scaleFor(int $value, int $pixels): int
    {
        foreach (self::SCALES as $step => $divisor) {
            if (0 === $step) {
                continue;
            }
            if (intdiv($value, $divisor) <= $pixels) {
                return $step;
            }
        }

        return \count(self::SCALES) - 1;
    }
}
