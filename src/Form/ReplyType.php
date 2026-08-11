<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Reply composer.
 *
 * Note what is *absent*: the original's reply form carried username and password
 * fields, and re-authenticated on every post by comparing an md5 - so the password
 * was posted in cleartext with each reply and sat in the browser's form history.
 * The session identifies the author here.
 */
final class ReplyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('body', TextareaType::class, [
                'label' => 'Reply',
                'attr' => ['rows' => 20, 'cols' => 80, 'class' => 'post-editor'],
                'constraints' => [
                    new Assert\NotBlank(message: "You didn't enter anything in the post."),
                    new Assert\Length(max: 65535, maxMessage: 'Posts are limited to {{ limit }} characters.'),
                ],
            ]);

        if ($options['is_moderator']) {
            $builder->add('closeAfter', CheckboxType::class, [
                'label' => 'Close the thread after posting',
                'required' => false,
            ]);
        }

        $builder
            ->add('preview', SubmitType::class, ['label' => 'Preview reply'])
            ->add('submit', SubmitType::class, ['label' => 'Submit reply']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'is_moderator' => false,
            'data_class' => null,
        ]);
        $resolver->setAllowedTypes('is_moderator', 'bool');
    }
}
