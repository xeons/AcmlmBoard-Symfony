<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\PollChoice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One poll option. The colour uses a native colour input and is validated against a
 * hex pattern on the entity, because the original dropped this value straight into
 * `bgcolor='$pollc[color]'` with no validation of any kind.
 */
final class PollChoiceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Choice',
                'attr' => ['maxlength' => 255],
            ])
            ->add('color', ColorType::class, [
                'label' => 'Bar colour',
                'required' => false,
                'empty_data' => null,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PollChoice::class]);
    }
}
