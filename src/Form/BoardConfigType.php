<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\BoardConfig;
use App\Entity\Forum;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Board settings.
 *
 * The original kept each setting's label and help text in a second database table
 * (`boardconfiginfo`), keyed by column name, so a new setting silently rendered with
 * a blank label until someone remembered to insert the matching row. They live with
 * the field here.
 */
final class BoardConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('boardName', TextType::class, ['label' => 'Board name'])
            ->add('boardUrl', UrlType::class, [
                'label' => 'Board URL',
                'required' => false,
                'help' => 'Used in emails and feeds.',
            ])
            ->add('siteName', TextType::class, ['label' => 'Parent site name', 'required' => false])
            ->add('siteUrl', UrlType::class, ['label' => 'Parent site URL', 'required' => false])

            ->add('registrationPolicy', ChoiceType::class, [
                'label' => 'Who may register',
                'choices' => [
                    'Everyone, including guests' => BoardConfig::REGISTRATION_EVERYONE,
                    'Existing members only' => BoardConfig::REGISTRATION_MEMBERS,
                    'Staff only' => BoardConfig::REGISTRATION_STAFF,
                    'Nobody - registration closed' => BoardConfig::REGISTRATION_CLOSED,
                ],
                'help' => 'Banned and IP-banned visitors can never register, whatever this is set to.',
            ])
            ->add('registrationEmail', EmailType::class, [
                'label' => 'Registration sender address',
                'required' => false,
                'help' => 'The From address on verification emails.',
            ])
            ->add('requireEmailVerification', CheckboxType::class, [
                'label' => 'Require email verification',
                'required' => false,
            ])

            ->add('defaultTimezone', \Symfony\Component\Form\Extension\Core\Type\TimezoneType::class, [
                'label' => 'Default timezone',
                // Same list the validator accepts - see EditProfileType.
                'intl' => false,
                'input' => 'string',
                'choice_label' => EditProfileType::timezoneLabel(...),
                'help' => 'Used for guests, and as the starting point for new accounts. '
                    .'Members pick their own in their profile.',
            ])

            ->add('totpEnabled', CheckboxType::class, [
                'label' => 'Allow authenticator apps',
                'required' => false,
                'help' => 'Members can protect their account with a six-digit code from an authenticator app.',
            ])

            ->add('passkeysEnabled', CheckboxType::class, [
                'label' => 'Allow passkeys',
                'required' => false,
                'help' => 'Lets members register a passkey (Face ID, Windows Hello, a security key) '
                    .'and sign in without a password. Requires HTTPS, or localhost for testing.',
            ])

            ->add('threadLockingEnabled', CheckboxType::class, [
                'label' => 'Allow admins to lock threads',
                'required' => false,
                'help' => 'A locked thread cannot be edited by local or full moderators.',
            ])
            ->add('searchMinPower', IntegerType::class, [
                'label' => 'Minimum power level to use search',
                'help' => '0 allows guests. 1 is staff, 2 moderators, 3 administrators.',
            ])
            ->add('forumBanMinPower', IntegerType::class, [
                'label' => 'Minimum power level to issue forum bans',
            ])

            ->add('preventDoublePosting', CheckboxType::class, [
                'label' => 'Prevent consecutive replies by the same member',
                'required' => false,
            ])
            ->add('maxPostsPerThreadPerDay', IntegerType::class, [
                'label' => 'Maximum posts per thread per day',
                'help' => 'Per member. The original hardcoded 50.',
            ])
            ->add('customTitlePostThreshold', IntegerType::class, [
                'label' => 'Posts needed for a custom title',
            ])
            ->add('customTitleAgePostThreshold', IntegerType::class, [
                'label' => 'Alternative: posts needed...',
            ])
            ->add('customTitleAgeDayThreshold', IntegerType::class, [
                'label' => '...combined with this many days registered',
            ])

            ->add('systemAccount', EntityType::class, [
                'label' => 'System account',
                'class' => User::class,
                'choice_label' => 'username',
                'required' => false,
                'placeholder' => 'None',
                'help' => 'Sends system messages. Should be a dedicated account.',
                'query_builder' => static fn (\App\Repository\UserRepository $r) => $r
                    ->createQueryBuilder('u')->orderBy('u.username', 'ASC')->setMaxResults(500),
            ])
            ->add('deletedUserAccount', EntityType::class, [
                'label' => 'Deleted-user account',
                'class' => User::class,
                'choice_label' => 'username',
                'required' => false,
                'placeholder' => 'None',
                'help' => 'Inherits the posts of deleted members so their threads survive.',
                'query_builder' => static fn (\App\Repository\UserRepository $r) => $r
                    ->createQueryBuilder('u')->orderBy('u.username', 'ASC')->setMaxResults(500),
            ])
            ->add('disciplinaryForum', EntityType::class, [
                'label' => 'Disciplinary forum',
                'class' => Forum::class,
                'choice_label' => 'title',
                'required' => false,
                'placeholder' => 'None',
                'help' => 'Holds staff-only discussion threads. Should be restricted.',
            ])
            ->add('trashForum', EntityType::class, [
                'label' => 'Trash forum',
                'class' => Forum::class,
                'choice_label' => 'title',
                'required' => false,
                'placeholder' => 'None',
                'help' => 'Receives trashed threads. The original hardcoded forum 20.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BoardConfig::class]);
    }
}
