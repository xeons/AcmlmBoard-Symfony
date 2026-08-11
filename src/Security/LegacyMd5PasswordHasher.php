<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\PasswordHasher\LegacyPasswordHasherInterface;

/**
 * Verifies the raw md5 hashes the original board stored in `users.password`.
 *
 * This exists solely so accounts imported from a live AcmlmBoard can log in once,
 * at which point Symfony's migrating-hasher machinery rehashes them with the real
 * algorithm and UserRepository::upgradePassword() clears the legacy flag. It can
 * never *produce* a hash - hash() throws - so no new password can be stored this way.
 *
 * md5 is unsalted and, for the short passwords a 2005 board collected, effectively
 * reversible from a rainbow table. Treat any account still carrying this flag as
 * compromised until its owner has logged in.
 */
final class LegacyMd5PasswordHasher implements LegacyPasswordHasherInterface
{
    public function hash(string $plainPassword, ?string $salt = null): string
    {
        throw new \LogicException(
            'Refusing to create a new md5 password hash. This hasher only verifies imported legacy hashes.',
        );
    }

    public function verify(string $hashedPassword, string $plainPassword, ?string $salt = null): bool
    {
        if ('' === $plainPassword || 32 !== \strlen($hashedPassword)) {
            return false;
        }

        return hash_equals($hashedPassword, md5($plainPassword));
    }

    public function needsRehash(string $hashedPassword): bool
    {
        // Always. Any md5 hash that verifies should be replaced immediately.
        return true;
    }
}
