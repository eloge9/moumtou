<?php

namespace App\Form;

use App\Entity\Domain;
use App\Entity\Institution;
use App\Entity\Mention;
use App\Entity\Specialty;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Formulaire dédié « devenir étudiant » (multi-rôles §13/§14) : contrairement
 * aux mêmes champs sur {@see ProfileEditType} (facultatifs, ouverts à tout
 * profil), ici établissement/domaine/mention/spécialité sont OBLIGATOIRES —
 * le rôle ÉTUDIANT n'est accordé qu'une fois ce formulaire validé (§12).
 */
class BecomeStudentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User|null $user */
        $user = $builder->getData();

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
            ->add('domain', EntityType::class, [
                'class' => Domain::class,
                'choice_label' => 'name',
                'label' => 'Domaine',
                'placeholder' => 'Sélectionnez un domaine',
                'constraints' => [new NotBlank(message: 'Sélectionnez un domaine.')],
                'query_builder' => function ($repository) use ($user) {
                    $qb = $repository->createQueryBuilder('d')->orderBy('d.name', 'ASC');
                    $current = $user?->getDomain();
                    if ($current) {
                        $qb->andWhere('d.active = true OR d.id = :currentDomain')->setParameter('currentDomain', $current->getId());
                    } else {
                        $qb->andWhere('d.active = true');
                    }

                    return $qb;
                },
            ])
            ->add('mention', EntityType::class, [
                'class' => Mention::class,
                'choice_label' => 'name',
                'label' => 'Mention',
                'placeholder' => 'Sélectionnez une mention',
                'constraints' => [new NotBlank(message: 'Sélectionnez une mention.')],
                'query_builder' => function ($repository) use ($user) {
                    $qb = $repository->createQueryBuilder('m')->orderBy('m.name', 'ASC');
                    $current = $user?->getMention();
                    if ($current) {
                        $qb->andWhere('m.active = true OR m.id = :currentMention')->setParameter('currentMention', $current->getId());
                    } else {
                        $qb->andWhere('m.active = true');
                    }

                    return $qb;
                },
            ])
            ->add('specialty', EntityType::class, [
                'class' => Specialty::class,
                'choice_label' => 'name',
                'label' => 'Spécialité',
                'placeholder' => 'Sélectionnez une spécialité',
                'constraints' => [new NotBlank(message: 'Sélectionnez une spécialité.')],
                'query_builder' => function ($repository) use ($user) {
                    $qb = $repository->createQueryBuilder('s')->orderBy('s.name', 'ASC');
                    $current = $user?->getSpecialty();
                    if ($current) {
                        $qb->andWhere('s.active = true OR s.id = :currentSpecialty')->setParameter('currentSpecialty', $current->getId());
                    } else {
                        $qb->andWhere('s.active = true');
                    }

                    return $qb;
                },
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
