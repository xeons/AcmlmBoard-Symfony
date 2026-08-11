<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\IpBan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class IpBanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ipRange', TextType::class, [
                'label' => 'IP address or CIDR range',
                'help' => 'A single address (203.0.113.7) or a block (203.0.113.0/24, 2001:db8::/32). '
                    .'Unlike the original, this is a real subnet match - a ban on 10.0.0.1 no longer '
                    .'catches 10.0.0.10.',
            ])
            ->add('reason', TextType::class, ['label' => 'Reason', 'required' => false])
            ->add('expiresAt', DateTimeType::class, [
                'label' => 'Expires',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Leave blank for a permanent ban.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => IpBan::class]);
    }
}
