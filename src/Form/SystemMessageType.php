<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A system message. The recipient cannot post again until they have read it, so the
 * form makes that consequence explicit rather than leaving it as folklore.
 */
final class SystemMessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Subject',
                'attr' => ['maxlength' => 255, 'size' => 60],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Message',
                'attr' => ['rows' => 12, 'cols' => 80],
                'help' => 'The member will be blocked from posting until they read this.',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 65535)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
