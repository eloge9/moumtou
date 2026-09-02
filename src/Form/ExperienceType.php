<?php

namespace App\Form;

use App\Entity\Experience;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class ExperienceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Intitulé du poste', 'constraints' => [new NotBlank()]])
            ->add('company', TextType::class, ['label' => 'Entreprise / organisation', 'constraints' => [new NotBlank()]])
            ->add('city', TextType::class, ['label' => 'Ville', 'required' => false])
            ->add('startDate', DateType::class, ['label' => 'Début', 'widget' => 'single_text', 'constraints' => [new NotBlank()]])
            ->add('endDate', DateType::class, ['label' => 'Fin (laisser vide si en cours)', 'widget' => 'single_text', 'required' => false])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Experience::class]);
    }
}
