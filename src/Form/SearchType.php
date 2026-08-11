<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Forum;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SearchType as SymfonySearchType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Post search criteria. Every field maps to a bound query parameter; nothing here
 * reaches the SQL as text.
 */
final class SearchType extends AbstractType
{
    public function __construct(private readonly AuthorizationCheckerInterface $auth)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('text', SymfonySearchType::class, [
                'label' => 'Message contains',
                'required' => false,
                'constraints' => [new Assert\Length(max: 200)],
            ])
            ->add('author', EntityType::class, [
                'label' => 'Posted by',
                'class' => User::class,
                'choice_label' => 'username',
                'required' => false,
                'placeholder' => 'Anyone',
                // Keeps the select from loading every member on a large board.
                'query_builder' => static fn (\App\Repository\UserRepository $r) => $r
                    ->createQueryBuilder('u')
                    ->orderBy('u.username', 'ASC')
                    ->setMaxResults(500),
            ])
            ->add('forum', EntityType::class, [
                'label' => 'In forum',
                'class' => Forum::class,
                'choices' => $options['forums'],
                'choice_label' => 'title',
                'required' => false,
                'placeholder' => 'All forums',
            ])
            ->add('after', DateType::class, [
                'label' => 'Posted after',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('before', DateType::class, [
                'label' => 'Posted before',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('order', ChoiceType::class, [
                'label' => 'Order',
                'choices' => ['Newest first' => 'newest', 'Oldest first' => 'oldest'],
            ]);

        // Searching by IP is an administrator's tool, so the field is not even
        // rendered for anyone else - and the controller ignores it regardless.
        if ($this->auth->isGranted('ROLE_ADMIN')) {
            $builder->add('ip', TextType::class, [
                'label' => 'From IP',
                'required' => false,
                'help' => 'Matches addresses beginning with what you enter.',
                'constraints' => [new Assert\Length(max: 45)],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'forums' => [],
        ]);
        $resolver->setAllowedTypes('forums', 'array');
    }
}
