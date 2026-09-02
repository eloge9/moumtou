<?php

namespace App\Form;

use App\Entity\Domain;
use App\Entity\Institution;
use App\Entity\Mention;
use App\Entity\Skill;
use App\Entity\Specialty;
use App\Entity\User;
use App\Enum\Availability;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

class ProfileEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'Prénom', 'constraints' => [new NotBlank()]])
            ->add('lastName', TextType::class, ['label' => 'Nom', 'constraints' => [new NotBlank()]])
            ->add('phone', TelType::class, ['label' => 'Téléphone', 'constraints' => [new NotBlank()]])
            ->add('whatsapp', TelType::class, ['label' => 'Numéro WhatsApp', 'required' => false, 'attr' => ['placeholder' => 'Ex. : 22890000000']])
            ->add('whatsappEnabled', ChoiceType::class, [
                'label' => 'Autoriser les contacts WhatsApp',
                'expanded' => true,
                'choices' => ['Oui' => true, 'Non' => false],
            ])
            ->add('country', TextType::class, ['label' => 'Pays', 'required' => false])
            ->add('city', TextType::class, ['label' => 'Ville', 'required' => false])
            ->add('bio', TextareaType::class, ['label' => 'Biographie', 'required' => false])
            ->add('linkedinUrl', UrlType::class, ['label' => 'LinkedIn', 'required' => false, 'default_protocol' => 'https'])
            ->add('githubUrl', UrlType::class, ['label' => 'GitHub', 'required' => false, 'default_protocol' => 'https'])
            ->add('websiteUrl', UrlType::class, ['label' => 'Site personnel', 'required' => false, 'default_protocol' => 'https'])
            ->add('portfolioUrl', UrlType::class, ['label' => 'Portfolio', 'required' => false, 'default_protocol' => 'https'])
            ->add('availability', ChoiceType::class, [
                'label' => 'Disponibilité',
                'required' => false,
                'placeholder' => 'Non précisée',
                'choices' => array_combine(
                    array_map(fn (Availability $a) => $a->label(), Availability::cases()),
                    Availability::cases(),
                ),
                'choice_value' => fn (?Availability $a) => $a?->value,
            ])
            ->add('institution', EntityType::class, [
                'class' => Institution::class,
                'choice_label' => 'name',
                'label' => 'Établissement',
                'required' => false,
                'placeholder' => 'Sélectionnez votre établissement',
                'query_builder' => fn ($repository) => $repository->createQueryBuilder('i')
                    ->andWhere('i.active = true')->orderBy('i.name', 'ASC'),
            ])
            ->add('domain', EntityType::class, [
                'class' => Domain::class,
                'choice_label' => 'name',
                'label' => 'Domaine',
                'required' => false,
                'placeholder' => 'Sélectionnez un domaine',
            ])
            ->add('mention', EntityType::class, [
                'class' => Mention::class,
                'choice_label' => 'name',
                'label' => 'Mention',
                'required' => false,
                'placeholder' => 'Sélectionnez une mention',
            ])
            ->add('specialty', EntityType::class, [
                'class' => Specialty::class,
                'choice_label' => 'name',
                'label' => 'Spécialité',
                'required' => false,
                'placeholder' => 'Sélectionnez une spécialité',
            ])
            ->add('skills', EntityType::class, [
                'class' => Skill::class,
                'choice_label' => 'name',
                'label' => 'Compétences',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('technologiesInput', TextType::class, ['label' => 'Technologies', 'mapped' => false, 'required' => false])
            ->add('photo', FileType::class, [
                'label' => 'Photo de profil',
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
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
