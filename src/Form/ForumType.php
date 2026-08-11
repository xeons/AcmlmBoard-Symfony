<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Category;
use App\Entity\Forum;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ForumType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Title'])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('category', EntityType::class, [
                'label' => 'Category',
                'class' => Category::class,
                'choices' => $options['categories'],
                'choice_label' => 'name',
            ])
            ->add('minPower', IntegerType::class, [
                'label' => 'Minimum power to read',
                'help' => '0 or less means anyone, including guests.',
            ])
            ->add('minPowerThread', IntegerType::class, [
                'label' => 'Minimum power to start threads',
            ])
            ->add('minPowerReply', IntegerType::class, [
                'label' => 'Minimum power to reply',
            ])
            ->add('position', IntegerType::class, ['label' => 'Sort position'])
            ->add('trash', CheckboxType::class, [
                'label' => 'This is the trash forum',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Forum::class,
            'categories' => [],
        ]);
        $resolver->setAllowedTypes('categories', 'array');
    }
}
