<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * New-thread composer, with an optional poll attached.
 */
final class NewThreadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Thread title',
                'attr' => ['maxlength' => 100, 'size' => 60],
                'constraints' => [
                    new Assert\NotBlank(message: 'Please enter a thread title.'),
                    new Assert\Length(max: 100),
                ],
            ])
            ->add('icon', PostIconType::class, [
                'label' => 'Post icon',
                'required' => false,
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Message',
                'attr' => ['rows' => 20, 'cols' => 80, 'class' => 'post-editor'],
                'constraints' => [
                    new Assert\NotBlank(message: "You didn't enter anything in the post."),
                    new Assert\Length(max: 65535),
                ],
            ]);

        if ($options['allow_poll']) {
            $builder
                ->add('withPoll', CheckboxType::class, [
                    'label' => 'Attach a poll',
                    'required' => false,
                ])
                ->add('pollQuestion', TextType::class, [
                    'label' => 'Poll question',
                    'required' => false,
                    'constraints' => [new Assert\Length(max: 255)],
                ])
                ->add('pollBriefing', TextareaType::class, [
                    'label' => 'Poll briefing',
                    'required' => false,
                    'attr' => ['rows' => 3],
                ])
                ->add('pollChoices', CollectionType::class, [
                    'label' => 'Choices',
                    'entry_type' => TextType::class,
                    'entry_options' => ['label' => false, 'required' => false],
                    'allow_add' => true,
                    'allow_delete' => true,
                    'required' => false,
                    'data' => ['', '', '', ''],
                ])
                ->add('pollMultiVote', CheckboxType::class, [
                    'label' => 'Allow voting for more than one choice',
                    'required' => false,
                ]);
        }

        if ($options['is_moderator']) {
            $builder->add('sticky', CheckboxType::class, [
                'label' => 'Stick this thread',
                'required' => false,
            ]);
        }

        $builder
            ->add('preview', SubmitType::class, ['label' => 'Preview thread'])
            ->add('submit', SubmitType::class, ['label' => 'Post thread']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'allow_poll' => true,
            'is_moderator' => false,
            'data_class' => null,
        ]);
        $resolver->setAllowedTypes('allow_poll', 'bool');
        $resolver->setAllowedTypes('is_moderator', 'bool');
    }
}
