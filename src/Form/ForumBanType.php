<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Forum;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class ForumBanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('forum', EntityType::class, [
                'label' => 'Forum',
                'class' => Forum::class,
                'choices' => $options['forums'],
                'choice_label' => 'title',
                'constraints' => [new Assert\NotNull(message: 'Pick a forum.')],
            ])
            ->add('expiresAt', DateTimeType::class, [
                'label' => 'Expires',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Leave blank for a permanent ban from this forum.',
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'Reason',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
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
