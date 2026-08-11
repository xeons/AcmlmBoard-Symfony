<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The original board kept a signed `powerlevel` column on `users` and compared it
 * numerically all over the codebase ($isstaff = $power>=1, $ismod = $power>=2, ...).
 * Forums also stored numeric thresholds (minpower, minpowerthread, minpowerreply),
 * so the ordering is load-bearing and has to be preserved.
 *
 * lib/layout.php used to *rewrite* power levels on every single page load:
 *     UPDATE users SET powerlevel=3 WHERE powerlevel>=5 AND id NOT IN (sys, 1)
 *     UPDATE users SET powerlevel=4 WHERE id=1
 *     UPDATE users SET powerlevel=5 WHERE id=sys_id
 * That is now expressed declaratively instead: OWNER and SYSTEM are assigned when
 * the account is designated in board config, not re-asserted on every request.
 */
enum PowerLevel: int
{
    case Banned = -1;
    case Member = 0;
    case LocalModerator = 1;
    case Moderator = 2;
    case Administrator = 3;
    case Owner = 4;
    case System = 5;

    public function label(): string
    {
        return match ($this) {
            self::Banned => 'Banned',
            self::Member => 'Regular',
            self::LocalModerator => 'Local moderator',
            self::Moderator => 'Moderator',
            self::Administrator => 'Administrator',
            self::Owner => 'Owner',
            self::System => 'System',
        };
    }

    /**
     * The rank line printed under a username in posts. getrank() in
     * lib/function.php emitted nothing for regular members and bolded the rest.
     */
    public function postRankLabel(): ?string
    {
        return match ($this) {
            self::Banned => 'Banned',
            self::Member => null,
            self::LocalModerator => 'Local moderator',
            self::Moderator => 'Moderator',
            self::Administrator => 'Administrator',
            self::Owner => 'Administrator',
            self::System => null,
        };
    }

    /**
     * @return list<string> Symfony roles granted at this level. role_hierarchy in
     *                      security.yaml expands these upward.
     */
    public function roles(): array
    {
        return match ($this) {
            // A banned account keeps ROLE_USER so it can still read and appeal,
            // exactly as before ($banned users could browse but not post).
            self::Banned => ['ROLE_USER', 'ROLE_BANNED'],
            self::Member => ['ROLE_USER'],
            self::LocalModerator => ['ROLE_STAFF'],
            self::Moderator => ['ROLE_MODERATOR'],
            self::Administrator => ['ROLE_ADMIN'],
            self::Owner => ['ROLE_OWNER'],
            self::System => ['ROLE_SYSTEM'],
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->value >= $other->value;
    }

    /**
     * Levels an administrator may assign. Owner and System are structural - they
     * follow the board config's designated accounts and are not hand-editable.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [
            self::Banned,
            self::Member,
            self::LocalModerator,
            self::Moderator,
            self::Administrator,
        ];
    }
}
