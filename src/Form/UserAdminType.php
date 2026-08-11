<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\PowerLevel;
use App\Enum\Sex;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Administrative user editing.
 *
 * The assignable power levels are capped at the acting admin's own level, and an
 * admin cannot change their own - otherwise the panel is a self-promotion button.
 */
final class UserAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, ['label' => 'User name'])
            ->add('email', EmailType::class, ['label' => 'Email', 'required' => false])
            ->add('posts', IntegerType::class, [
                'label' => 'Post count',
                'help' => 'Changing this changes their level, rank and shop balance.',
            ])
            ->add('sex', EnumType::class, [
                'label' => 'Sex',
                'class' => Sex::class,
                'choice_label' => static fn (Sex $s): string => $s->label(),
            ])
            ->add('title', TextType::class, ['label' => 'Custom title', 'required' => false])
            ->add('titleOption', ChoiceType::class, [
                'label' => 'Custom title privilege',
                'choices' => [
                    'Never allowed' => 0,
                    'Earned by post count' => 1,
                    'Always allowed' => 2,
                ],
            ]);

        if (!$options['is_self']) {
            /** @var PowerLevel $max */
            $max = $options['max_power'];

            $choices = [];
            foreach (PowerLevel::assignable() as $level) {
                if ($level->value <= $max->value) {
                    $choices[$level->label()] = $level;
                }
            }

            $builder->add('powerLevel', EnumType::class, [
                'label' => 'Power level',
                'class' => PowerLevel::class,
                'choices' => $choices,
                'choice_label' => static fn (PowerLevel $p): string => $p->label(),
                'help' => 'You cannot grant a level above your own.',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'max_power' => PowerLevel::Administrator,
            'is_self' => false,
        ]);
        $resolver->setAllowedTypes('max_power', PowerLevel::class);
        $resolver->setAllowedTypes('is_self', 'bool');
    }
}
