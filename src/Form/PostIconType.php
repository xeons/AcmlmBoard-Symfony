<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Thread post icons.
 *
 * The original stored the icon as a *full URL* in `threads.icon` and rendered it
 * with `<img src='$thread[icon]'>` unescaped, so the icon field was a stored-XSS
 * vector on every forum listing. The value is now the basename of a file the board
 * ships, chosen from a fixed list, so nothing user-controlled reaches the src.
 */
final class PostIconType extends AbstractType
{
    /**
     * Icons under public/images/icons/. Matches the set in the original's
     * posticons.dat.
     *
     * @var list<string>
     */
    public const ICONS = [
        'smile', 'frown', 'wink', 'grin', 'tongue', 'cool', 'shocked',
        'angry', 'confused', 'question', 'exclaim', 'idea', 'star',
        'heart', 'skull', 'note', 'game', 'link',
    ];

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $choices = ['(none)' => ''];
        foreach (self::ICONS as $icon) {
            $choices[ucfirst($icon)] = $icon;
        }

        $resolver->setDefaults([
            'choices' => $choices,
            'expanded' => true,
            'multiple' => false,
            'placeholder' => false,
            'choice_attr' => static fn (?string $choice): array => [
                'data-icon' => $choice ?? '',
            ],
        ]);
    }
}
