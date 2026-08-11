<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Step two: redeem the code and set a password. */
final class VerifyRegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'User name',
                'attr' => ['maxlength' => 25, 'autocomplete' => 'username'],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('code', TextType::class, [
                'label' => 'Verification code',
                'attr' => ['maxlength' => 64, 'size' => 40, 'autocomplete' => 'one-time-code'],
                'constraints' => [new Assert\NotBlank(message: 'Please enter the code from your email.')],
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'Password',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Repeat password',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'The two passwords do not match.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Please choose a password.'),
                    new Assert\Length(
                        min: 8,
                        max: 4096,
                        minMessage: 'Passwords must be at least {{ limit }} characters.',
                    ),
                    // Checks the password against Have I Been Pwned's k-anonymity
                    // API - only the first five characters of the SHA-1 hash ever
                    // leave the server. The original had no password rules at all.
                    //
                    // skipOnError lets registration proceed when the API cannot be
                    // reached, which matters on a board running without outbound
                    // internet access. A breach check is worth having, but not at
                    // the price of nobody being able to sign up when it is down.
                    new Assert\NotCompromisedPassword(
                        message: 'That password has appeared in a known data breach. Please choose another.',
                        skipOnError: true,
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
