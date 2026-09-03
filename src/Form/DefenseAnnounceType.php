<?php

namespace App\Form;

use App\Entity\Defense;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class DefenseAnnounceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, ['label' => 'Date', 'widget' => 'single_text', 'constraints' => [new NotBlank()]])
            ->add('time', TimeType::class, [
                'label' => 'Heure',
                'widget' => 'single_text',
                'input' => 'string',
                'input_format' => 'H:i',
                'constraints' => [new NotBlank()],
            ])
            ->add('place', TextType::class, ['label' => 'Lieu', 'constraints' => [new NotBlank()], 'attr' => ['placeholder' => 'Ex. : Amphi B']])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Defense::class]);
    }
}
