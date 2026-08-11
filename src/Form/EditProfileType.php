<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ColorScheme;
use App\Entity\RankSet;
use App\Entity\ThreadLayout;
use App\Entity\User;
use App\Enum\Sex;
use App\Enum\SignatureDisplay;
use App\Enum\SignatureSeparator;
use App\Repository\ColorSchemeRepository;
use App\Repository\RankSetRepository;
use App\Repository\ThreadLayoutRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Intl\Timezones;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The profile editor.
 *
 * The original posted a hidden field named `userpass` containing the account's md5
 * hash, then used it in the UPDATE's WHERE clause as a sort of ad-hoc CSRF token.
 * Anyone who could read the form's HTML - including via any of the board's XSS
 * holes - got the password hash for free. There is no such field here.
 *
 * The AIM, ICQ and imood fields are gone: those services no longer exist.
 */
final class EditProfileType extends AbstractType
{
    /**
     * Offered at the top of the timezone list. Not a judgement about where members
     * live - just the zones that cover the most people, so the common case is one
     * click rather than a scroll through 400-odd entries.
     *
     * @var list<string>
     */
    private const COMMON_TIMEZONES = [
        'UTC',
        'America/New_York',
        'America/Chicago',
        'America/Denver',
        'America/Los_Angeles',
        'America/Sao_Paulo',
        'Europe/London',
        'Europe/Berlin',
        'Europe/Moscow',
        'Asia/Kolkata',
        'Asia/Shanghai',
        'Asia/Tokyo',
        'Australia/Sydney',
    ];

    /**
     * "America/Chicago" becomes "Central Time (Chicago)" where the intl catalogue
     * knows the zone, and stays as written where it does not - which is the case for
     * UTC and for the fixed-offset zones carried over from the old integer column.
     */
    public static function timezoneLabel(string $id): string
    {
        // Not (int) strrpos(...): for a zone with no slash, such as UTC, strrpos
        // returns false, which casts to 0 and would shear the first character off.
        $slash = strrpos($id, '/');
        $city = false === $slash ? $id : str_replace('_', ' ', substr($id, $slash + 1));

        // Two zones PHP lists have no entry in the intl catalogue at all - UTC and
        // Asia/Choibalsan - and exists() does not agree with getName() about the
        // second, so the lookup is guarded rather than tested. A member whose zone
        // happens to be one of those still gets a usable label instead of a 500.
        try {
            // Already reads "Central Time (Chicago)"; appending the city repeats it.
            return Timezones::getName($id);
        } catch (\Symfony\Component\Intl\Exception\MissingResourceException) {
            return $city;
        }
    }

    public function __construct(
        private readonly ColorSchemeRepository $schemes,
        private readonly ThreadLayoutRepository $layouts,
        private readonly RankSetRepository $rankSets,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $schemeCounts = $this->schemes->getUsageCounts();
        $layoutCounts = $this->layouts->getUsageCounts();

        $builder
            // --- Login -------------------------------------------------------
            ->add('plainPassword', PasswordType::class, [
                'label' => 'New password',
                'mapped' => false,
                'required' => false,
                'help' => 'Leave blank to keep your current password.',
                'constraints' => [
                    new Assert\Length(
                        min: 8,
                        max: 4096,
                        minMessage: 'Passwords must be at least {{ limit }} characters.',
                    ),
                    new Assert\NotCompromisedPassword(
                        message: 'That password has appeared in a known data breach. Please choose another.',
                        skipOnError: true,
                    ),
                ],
            ])

            // --- Appearance --------------------------------------------------
            ->add('rankSet', EntityType::class, [
                'label' => 'User rank',
                'class' => RankSet::class,
                'choices' => $this->rankSets->findAllOrdered(),
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'No rank',
            ])
            /*
             * TextType, not UrlType, for both of these.
             *
             * UrlType installs FixUrlProtocolListener, which prepends "http://" to
             * any submitted value without a scheme. That turns an avatar chosen from
             * the board's own gallery, "/images/avatars/kirby.png", into
             * "http:///images/avatars/kirby.png" on the way in - corrupting the value
             * and then failing validation on it. A plain text field stores what the
             * member actually has; the constraint on the entity decides what is
             * acceptable.
             */
            ->add('picture', TextType::class, [
                'label' => 'User picture',
                'required' => false,
                'help' => 'The image shown under your name in posts, resized to 60px wide. '
                    .'A full http(s) URL, or pick one from the avatar gallery.',
            ])
            ->add('miniPic', TextType::class, [
                'label' => 'Minipic',
                'required' => false,
                'help' => 'A small image shown beside your name in lists, resized to 11x11. '
                    .'A full http(s) URL, or an image on this board.',
            ])

            // --- Personal ----------------------------------------------------
            ->add('sex', EnumType::class, [
                'label' => 'Sex',
                'class' => Sex::class,
                'choices' => Sex::selectable(),
                'choice_label' => static fn (Sex $s): string => $s->label(),
                'expanded' => true,
            ])
            ->add('realName', TextType::class, ['label' => 'Real name', 'required' => false])
            ->add('location', TextType::class, ['label' => 'Location', 'required' => false])
            ->add('birthday', DateType::class, [
                'label' => 'Birthday',
                'required' => false,
                'widget' => 'choice',
                'years' => range((int) date('Y'), (int) date('Y') - 100),
                'placeholder' => ['year' => 'Year', 'month' => 'Month', 'day' => 'Day'],
            ])
            ->add('bio', TextareaType::class, [
                'label' => 'Bio',
                'required' => false,
                'attr' => ['rows' => 5, 'cols' => 60],
            ])

            // --- Contact -----------------------------------------------------
            ->add('email', EmailType::class, ['label' => 'Email address', 'required' => false])
            ->add('emailPublic', ChoiceType::class, [
                'label' => 'Who can see your email',
                'choices' => ['Staff only' => false, 'Any logged-in member' => true],
                'expanded' => true,
                'help' => 'Your email is never shown to visitors who are not logged in.',
            ])
            // A homepage really is an external link, so UrlType is right here. The
            // protocol is stated rather than left to default, which Symfony 7.1
            // deprecated: typing "example.com" should become https, not http.
            ->add('homepageUrl', UrlType::class, [
                'label' => 'Homepage URL',
                'required' => false,
                'default_protocol' => 'https',
            ])
            ->add('homepageName', TextType::class, ['label' => 'Homepage name', 'required' => false])

            // --- Options -----------------------------------------------------
            ->add('timezone', TimezoneType::class, [
                'label' => 'Timezone',
                // Grouped by continent, which is how people look for their own zone.
                //
                // Deliberately NOT 'intl' => true. The intl catalogue and PHP's own
                // list disagree: intl has no bare "UTC", which is what every new
                // account defaults to. A member whose zone is missing from the list
                // gets a select with nothing chosen, so the browser shows whichever
                // option is first and saving anything on the page silently moves
                // them to it. Assert\Timezone validates against PHP's list, so
                // that is the list the form has to offer.
                'intl' => false,
                'input' => 'string',
                'preferred_choices' => self::COMMON_TIMEZONES,
                // Friendly names where the intl catalogue has one, raw id otherwise.
                'choice_label' => self::timezoneLabel(...),
                'help' => 'Your local timezone. Daylight saving is handled for you - '
                    .'there is no offset to work out, and nothing to change twice a year.',
                'attr' => [
                    // board.js fills this in from the browser on first visit, so most
                    // members never have to touch it.
                    'data-timezone-detect' => 'true',
                ],
            ])
            ->add('postsPerPage', IntegerType::class, [
                'label' => 'Posts per page',
                'attr' => ['min' => 5, 'max' => 100],
            ])
            ->add('threadsPerPage', IntegerType::class, [
                'label' => 'Threads per page',
                'attr' => ['min' => 10, 'max' => 200],
            ])
            ->add('signatureDisplay', EnumType::class, [
                'label' => 'Signatures and post headers',
                'class' => SignatureDisplay::class,
                'choice_label' => static fn (SignatureDisplay $d): string => $d->label(),
                'expanded' => true,
            ])
            ->add('signatureSeparator', EnumType::class, [
                'label' => 'Signature separator',
                'class' => SignatureSeparator::class,
                'choice_label' => static fn (SignatureSeparator $s): string => $s->label(),
            ])
            ->add('threadLayout', EntityType::class, [
                'label' => 'Thread layout',
                'class' => ThreadLayout::class,
                'choices' => $this->layouts->findAllOrdered(),
                'choice_label' => static fn (ThreadLayout $l): string => \sprintf(
                    '%s (%d)',
                    $l->getName(),
                    $layoutCounts[$l->getId()] ?? 0,
                ),
                'required' => false,
            ])
            ->add('colorScheme', EntityType::class, [
                'label' => 'Color scheme',
                'class' => ColorScheme::class,
                'choices' => $this->schemes->findAllOrdered(),
                'choice_label' => static fn (ColorScheme $s): string => \sprintf(
                    '%s (%d)',
                    $s->getName(),
                    $schemeCounts[$s->getId()] ?? 0,
                ),
                'required' => false,
            ])
            ->add('postToolbar', ChoiceType::class, [
                'label' => 'Formatting toolbar when posting',
                'choices' => ['Enabled' => true, 'Disabled' => false],
                'expanded' => true,
            ])
            ->add('markReadOutsideMenu', ChoiceType::class, [
                'label' => 'Show "mark read" links',
                'choices' => ['In the menu' => false, 'Outside the menu' => true],
                'expanded' => true,
            ]);

        if ($options['can_set_title']) {
            $builder->add('title', TextType::class, [
                'label' => 'Custom title',
                'required' => false,
                'help' => 'Shown beneath your rank. Basic formatting is allowed.',
                'constraints' => [new Assert\Length(max: 255)],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'can_set_title' => false,
        ]);
        $resolver->setAllowedTypes('can_set_title', 'bool');
    }

}
