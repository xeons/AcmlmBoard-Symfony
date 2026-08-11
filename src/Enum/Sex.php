<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `users.sex` in the original: 0 male, 1 female, 2 N/A. Value 3 was an undocumented
 * easter egg that made getnamecolor() return a colour derived from the current
 * microsecond, so the name strobed on every page load; it is kept as Rainbow but is
 * now driven by a CSS animation rather than by a random colour per request.
 *
 * The username colour table lived in lib/colors.php as $nmcol[sex][powerlevel].
 */
enum Sex: int
{
    case Male = 0;
    case Female = 1;
    case Undisclosed = 2;
    case Rainbow = 3;

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
            self::Undisclosed => 'N/A',
            self::Rainbow => 'Rainbow',
        };
    }

    /** Selectable in the profile form; Rainbow is granted, not chosen. */
    public static function selectable(): array
    {
        return [self::Male, self::Female, self::Undisclosed];
    }

    /**
     * CSS class applied to the username link, resolved against the active colour
     * scheme. Replaces getnamecolor()'s inline `<font color=...>`.
     */
    public function cssClass(): string
    {
        return match ($this) {
            self::Male => 'name-male',
            self::Female => 'name-female',
            self::Undisclosed => 'name-na',
            self::Rainbow => 'name-rainbow',
        };
    }
}
