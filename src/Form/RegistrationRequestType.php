<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Step one: ask for a verification code. */
final class RegistrationRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'User name',
                'attr' => ['maxlength' => 25, 'size' => 25, 'autocomplete' => 'username'],
                'help' => 'The name you want to use on the board.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Please choose a username.'),
                    new Assert\Length(min: 2, max: 25),
                    new Assert\Regex(
                        pattern: '/^[^\x00-\x1F\x7F<>]+$/',
                        message: 'Usernames may not contain control characters or angle brackets.',
                    ),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email address',
                'attr' => ['maxlength' => 180, 'size' => 60, 'autocomplete' => 'email'],
                'help' => 'We send a verification code here. You will pick a password once it arrives.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Please enter an email address.'),
                    new Assert\Email(message: 'That does not look like an email address.'),
                    new Assert\Length(max: 180),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
