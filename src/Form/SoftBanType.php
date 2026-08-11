<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** A temporary posting suspension that leaves the account's power level alone. */
final class SoftBanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('expiresAt', DateTimeType::class, [
                'label' => 'Expires',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Leave blank to suspend until lifted by hand.',
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'Reason',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'Recorded in the disciplinary log.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
