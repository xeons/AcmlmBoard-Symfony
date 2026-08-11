<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Poll;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PollType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('question', TextType::class, [
                'label' => 'Question',
                'attr' => ['maxlength' => 255, 'size' => 60],
            ])
            ->add('briefing', TextareaType::class, [
                'label' => 'Briefing',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('choices', CollectionType::class, [
                'label' => 'Choices',
                'entry_type' => PollChoiceType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
            ])
            ->add('multiVote', CheckboxType::class, [
                'label' => 'Allow voting for more than one choice',
                'required' => false,
            ])
            ->add('closed', CheckboxType::class, [
                'label' => 'Poll is closed',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Poll::class]);
    }
}
