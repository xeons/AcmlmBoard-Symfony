<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `users.viewsig`, read in thread.php to decide which columns to select:
 *   0 -> no header/signature columns at all
 *   1 -> headtext/signtext frozen into the post at the time it was made
 *   2 -> u.postheader/u.signature, i.e. the author's *current* layout
 */
enum SignatureDisplay: int
{
    case Hidden = 0;
    case AsPosted = 1;
    case AutoUpdating = 2;

    public function label(): string
    {
        return match ($this) {
            self::Hidden => 'Disabled',
            self::AsPosted => 'Enabled',
            self::AutoUpdating => 'Auto-updating',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Hidden => 'Never show post headers or signatures. Threads load faster.',
            self::AsPosted => 'Show the layout as it was when the post was made.',
            self::AutoUpdating => "Show each author's current layout, even on old posts.",
        };
    }
}
