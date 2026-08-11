<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * private.php defined these as bare constants and mixed them into the same
 * `folderto`/`folderfrom` column as user-created folder numbers, which had to be
 * >= 10 to avoid colliding. That reserved range is preserved so folder numbering
 * behaves identically.
 */
enum MessageFolder: int
{
    case Deleted = 0;
    case Inbox = 1;
    case Sent = 2;

    /** User-created folders start here; see PrivateMessageFolder::$number. */
    public const FIRST_USER_FOLDER = 10;

    public function label(): string
    {
        return match ($this) {
            self::Deleted => 'Deleted',
            self::Inbox => 'Inbox',
            self::Sent => 'Sent',
        };
    }
}
