<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Registration form bound to the User entity.
 *
 * The plain password never touches the entity: it stays unmapped and is
 * hashed in SignupController before persistence.
 */
final class SignupForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // the old site trimmed fields; validation then ran on the trimmed values
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (is_array($data)) {
                foreach (['username', 'email'] as $field) {
                    if (isset($data[$field]) && is_string($data[$field])) {
                        $data[$field] = trim($data[$field]);
                    }
                }

                $event->setData($data);
            }
        });

        $builder
            ->add('username', TextType::class, [
                'label' => 'Username',
                'attr' => [
                    'placeholder' => 'Choose a username',
                    'autofocus' => true,
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email Address',
                'attr' => [
                    'placeholder' => 'name@example.com',
                ],
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'Passwords do not match.',
                'first_options' => [
                    'label' => 'Password',
                    'attr' => [
                        'placeholder' => 'Create a strong password',
                    ],
                    'constraints' => [
                        new NotBlank(message: 'Please enter a password.'),
                        new Length(min: 8, minMessage: 'Password must be at least 8 characters.'),
                    ],
                ],
                'second_options' => [
                    'label' => 'Confirm Password',
                    'attr' => [
                        'placeholder' => 'Re-enter your password',
                    ],
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
