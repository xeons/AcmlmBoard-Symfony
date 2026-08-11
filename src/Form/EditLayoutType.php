<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Post header, signature and background - the layout editor.
 */
final class EditLayoutType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('postHeader', TextareaType::class, [
                'label' => 'Post header',
                'required' => false,
                'attr' => ['rows' => 12, 'cols' => 80, 'class' => 'layout-editor'],
                'help' => 'HTML shown above every post you make. Scripts and event handlers are removed.',
                'constraints' => [new Assert\Length(max: 65535)],
            ])
            ->add('signature', TextareaType::class, [
                'label' => 'Signature',
                'required' => false,
                'attr' => ['rows' => 12, 'cols' => 80, 'class' => 'layout-editor'],
                'help' => 'HTML shown below every post you make.',
                'constraints' => [new Assert\Length(max: 65535)],
            ])
            ->add('postBackground', TextType::class, [
                'label' => 'Post background',
                'required' => false,
                'help' => 'A colour (#402080) or the full URL of an image.',
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('preview', SubmitType::class, ['label' => 'Preview'])
            ->add('save', SubmitType::class, ['label' => 'Save layout']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
