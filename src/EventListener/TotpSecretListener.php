<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\TotpService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Decrypts the stored TOTP seed onto the User as it is loaded.
 *
 * The entity holds ciphertext and has no key, so on its own it cannot answer
 * getTotpAuthenticationConfiguration(). Doing this on postLoad rather than from a
 * kernel listener means the seed is present however the user was obtained and
 * whenever it is needed: the firewall verifies two-factor codes at priority 8 on
 * kernel.request, ahead of anything a request listener could do.
 *
 * The decrypt is skipped for the great majority of members, who have no secret.
 */
#[AsEntityListener(event: Events::postLoad, entity: User::class)]
final class TotpSecretListener
{
    public function __construct(private readonly TotpService $totp)
    {
    }

    public function postLoad(User $user): void
    {
        if (null !== $user->getTotpSecret()) {
            $this->totp->attachSecret($user);
        }
    }
}
