<?php

namespace App\Form;

use App\Entity\Institution;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Formulaire dédié « devenir enseignant » (multi-rôles §15) : l'établissement
 * est obligatoire (le rattachement enseignant existant, §5/§9 gestion des
 * établissements, réutilisé tel quel). La fonction est propre à CET
 * établissement (un enseignant peut avoir un rôle différent d'un
 * établissement à l'autre) : non mappée sur {@see User}, elle est reportée
 * sur le rattachement {@see \App\Entity\UserInstitution} créé par le
 * contrôleur, jamais sur le champ générique `professionalTitle`.
 */
class BecomeTeacherType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('institution', EntityType::class, [
                'class' => Institution::class,
                'choice_label' => 'name',
                'label' => 'Établissement',
                'placeholder' => 'Sélectionnez votre établissement',
                'constraints' => [new NotBlank(message: 'Sélectionnez votre établissement.')],
                'query_builder' => fn ($repository) => $repository->createQueryBuilder('i')
                    ->andWhere('i.active = true')->orderBy('i.name', 'ASC'),
            ])
            ->add('title', TextType::class, [
                'label' => 'Fonction à cet établissement',
                'mapped' => false,
                'required' => false,
                'attr' => ['placeholder' => 'Ex. : Maître de conférences'],
                'constraints' => [new Length(max: 120, maxMessage: 'La fonction ne doit pas dépasser {{ limit }} caractères.')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
