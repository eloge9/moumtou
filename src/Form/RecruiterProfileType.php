<?php

namespace App\Form;

use App\Entity\RecruiterProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Url;

class RecruiterProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('organizationName', TextType::class, [
                'label' => 'Entreprise / organisation',
                'constraints' => [new NotBlank(), new Length(max: 180)],
            ])
            ->add('sector', TextType::class, [
                'label' => 'Secteur d\'activité',
                'required' => false,
                'constraints' => [new Length(max: 120)],
            ])
            ->add('country', TextType::class, ['label' => 'Pays', 'required' => false])
            ->add('city', TextType::class, ['label' => 'Ville', 'required' => false])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'constraints' => [new Length(max: 2000, maxMessage: 'La description ne doit pas dépasser {{ limit }} caractères.')],
            ])
            ->add('websiteUrl', UrlType::class, ['label' => 'Site web', 'required' => false, 'default_protocol' => 'https', 'constraints' => [new Url(requireTld: true)]])
            ->add('linkedinUrl', UrlType::class, [
                'label' => 'LinkedIn',
                'required' => false,
                'default_protocol' => 'https',
                'constraints' => [new Regex(
                    pattern: '#^https?://([a-z]{2,3}\.)?linkedin\.com/#i',
                    message: 'Ce lien doit pointer vers une page LinkedIn (linkedin.com).',
                )],
            ])
            ->add('professionalEmail', EmailType::class, ['label' => 'E-mail professionnel', 'required' => false])
            ->add('professionalPhone', TelType::class, ['label' => 'Téléphone professionnel', 'required' => false])
            ->add('logo', FileType::class, [
                'label' => 'Logo',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new File(maxSize: '3M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], mimeTypesMessage: 'Formats acceptés : JPG, PNG, WebP.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RecruiterProfile::class]);
    }
}
