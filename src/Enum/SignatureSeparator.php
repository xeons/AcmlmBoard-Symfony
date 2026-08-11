<?php

declare(strict_types=1);

namespace App\Enum;

/** `users.signsep`, indexing the $sep/$sepn arrays in lib/layout.php. */
enum SignatureSeparator: int
{
    case Dashes = 0;
    case Line = 1;
    case HorizontalRule = 2;
    case None = 3;

    public function label(): string
    {
        return match ($this) {
            self::Dashes => 'Dashes',
            self::Line => 'Line',
            self::HorizontalRule => 'Full horizontal line',
            self::None => 'None',
        };
    }

    /** CSS modifier on the signature block, replacing the literal dashes. */
    public function cssClass(): string
    {
        return 'sigsep-'.$this->name;
    }
}
