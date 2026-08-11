<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class EditPostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('body', TextareaType::class, [
                'label' => 'Message',
                'attr' => ['rows' => 20, 'cols' => 80, 'class' => 'post-editor'],
                'constraints' => [
                    new Assert\NotBlank(message: 'A post cannot be empty. Delete it instead.'),
                    new Assert\Length(max: 65535),
                ],
            ])
            ->add('preview', SubmitType::class, ['label' => 'Preview'])
            ->add('submit', SubmitType::class, ['label' => 'Save changes']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
