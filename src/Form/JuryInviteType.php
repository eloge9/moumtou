<?php

namespace App\Form;

use App\Entity\Institution;
use App\Entity\JuryMember;
use App\Enum\JuryRole;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class JuryInviteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'Prénom', 'constraints' => [new NotBlank()]])
            ->add('lastName', TextType::class, ['label' => 'Nom', 'constraints' => [new NotBlank()]])
            ->add('role', ChoiceType::class, [
                'label' => 'Rôle',
                'choices' => array_combine(
                    array_map(fn (JuryRole $r) => $r->label(), JuryRole::cases()),
                    JuryRole::cases(),
                ),
                'choice_value' => fn (?JuryRole $r) => $r?->value,
                'constraints' => [new NotBlank()],
            ])
            // Établissement du catalogue officiel, préféré au texte libre
            // ci-dessous (gestion des établissements §10/§11 : "éviter les
            // champs texte libres lorsque l'information existe déjà").
            ->add('institution', EntityType::class, [
                'class' => Institution::class,
                'choice_label' => 'name',
                'label' => 'Établissement (catalogue)',
                'required' => false,
                'placeholder' => 'Sélectionnez un établissement',
                'query_builder' => fn ($repository) => $repository->createQueryBuilder('i')
                    ->andWhere('i.active = true')->orderBy('i.name', 'ASC'),
            ])
            ->add('institutionName', TextType::class, [
                'label' => 'Ou établissement absent du catalogue (texte libre)',
                'required' => false,
            ])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'constraints' => [new NotBlank()]])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => JuryMember::class]);
    }
}
