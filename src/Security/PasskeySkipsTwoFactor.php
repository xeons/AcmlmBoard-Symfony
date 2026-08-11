<?php

declare(strict_types=1);

namespace App\Security;

use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Condition\TwoFactorConditionInterface;

/**
 * Signing in with a passkey does not then ask for an authenticator code.
 *
 * A passkey is already two factors: the private key never leaves the device, and
 * the device only releases it after a fingerprint, face or screen lock. Demanding a
 * TOTP code on top asks for the same assurance a second time, and the usual result
 * is that people stop using the stronger method.
 *
 * A password sign-in is still challenged, so an account with both keeps its second
 * factor on the weaker path.
 */
final class PasskeySkipsTwoFactor implements TwoFactorConditionInterface
{
    public function shouldPerformTwoFactorAuthentication(AuthenticationContextInterface $context): bool
    {
        return PasskeyAuthenticator::LOGIN_PATH !== $context->getRequest()->getPathInfo();
    }
}
