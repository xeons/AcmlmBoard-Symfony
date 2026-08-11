<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may see or change what on a user profile.
 *
 * @extends Voter<string, User>
 */
final class ProfileVoter extends Voter
{
    public const EDIT = 'PROFILE_EDIT';
    public const VIEW_EMAIL = 'PROFILE_VIEW_EMAIL';
    public const VIEW_IP = 'PROFILE_VIEW_IP';
    public const RATE = 'PROFILE_RATE';
    public const SEND_MESSAGE = 'PROFILE_SEND_MESSAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof User
            && \in_array($attribute, [
                self::EDIT, self::VIEW_EMAIL, self::VIEW_IP,
                self::RATE, self::SEND_MESSAGE,
            ], true);
    }

    /** @param User $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $viewer = $token->getUser();
        if (!$viewer instanceof User) {
            // Email addresses are never shown to guests, whatever the owner's setting.
            return false;
        }

        $isSelf = $viewer === $subject;

        return match ($attribute) {
            self::EDIT => $isSelf || $viewer->isAdmin(),

            // publicEmail false means staff only; the owner and admins always see it.
            self::VIEW_EMAIL => $isSelf
                || $viewer->isAdmin()
                || ($subject->isEmailPublic() ? !$viewer->isBanned() : $viewer->isStaff()),

            self::VIEW_IP => $viewer->isAdmin(),

            // Rating yourself was possible in the original and skewed every average.
            self::RATE => !$isSelf && !$viewer->isBanned(),

            self::SEND_MESSAGE => !$isSelf && !$viewer->isBanned(),

            default => false,
        };
    }
}
