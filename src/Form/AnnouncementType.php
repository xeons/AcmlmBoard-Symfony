<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Announcement;
use App\Entity\Forum;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AnnouncementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
                'attr' => ['maxlength' => 250, 'size' => 60],
            ])
            ->add('forum', EntityType::class, [
                'label' => 'Forum',
                'class' => Forum::class,
                'choices' => $options['forums'],
                'choice_label' => 'title',
                'required' => false,
                // Null means board-wide. The original used forum id 0 for this,
                // which collided with "no forum" wherever the value was read.
                'placeholder' => 'Board-wide announcement',
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Announcement',
                'attr' => ['rows' => 15, 'cols' => 80, 'class' => 'post-editor'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Announcement::class,
            'forums' => [],
        ]);
        $resolver->setAllowedTypes('forums', 'array');
    }
}
