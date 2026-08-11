<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class SendMessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
                'attr' => ['maxlength' => 255, 'size' => 60],
                'constraints' => [
                    new Assert\NotBlank(message: 'Please give the message a title.'),
                    new Assert\Length(max: 255),
                ],
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Message',
                'attr' => ['rows' => 15, 'cols' => 80, 'class' => 'post-editor'],
                'constraints' => [
                    new Assert\NotBlank(message: "You didn't enter a message."),
                    new Assert\Length(max: 65535),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
