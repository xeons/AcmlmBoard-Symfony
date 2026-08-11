<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Forum;
use App\Entity\Thread;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class EditThreadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Thread title',
                'attr' => ['maxlength' => 100, 'size' => 60],
            ])
            ->add('icon', PostIconType::class, [
                'label' => 'Post icon',
                'required' => false,
            ])
            ->add('forum', EntityType::class, [
                'label' => 'Forum',
                'class' => Forum::class,
                // Restricted to forums the acting moderator can see, so a thread
                // cannot be pushed somewhere they have no authority.
                'choices' => $options['forums'],
                'choice_label' => 'title',
            ])
            ->add('sticky', CheckboxType::class, ['label' => 'Sticky', 'required' => false])
            ->add('closed', CheckboxType::class, ['label' => 'Closed', 'required' => false]);

        if ($options['can_lock']) {
            $builder->add('locked', CheckboxType::class, [
                'label' => 'Locked (blocks moderator edits)',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Thread::class,
            'can_lock' => false,
            'forums' => [],
        ]);
        $resolver->setAllowedTypes('can_lock', 'bool');
        $resolver->setAllowedTypes('forums', 'array');
    }
}
