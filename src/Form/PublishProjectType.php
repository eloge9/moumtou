<?php

namespace App\Form;

use App\Entity\Domain;
use App\Entity\Institution;
use App\Entity\Mention;
use App\Entity\Project;
use App\Entity\Specialty;
use App\Enum\ProjectType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Url;

class PublishProjectType extends AbstractType
{
    public const OTHER_VALUE = 'autre';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => false,
                'expanded' => true,
                'multiple' => false,
                'choices' => array_combine(
                    array_map(fn (ProjectType $t) => $t->value, ProjectType::cases()),
                    ProjectType::cases(),
                ),
                'choice_value' => fn (?ProjectType $t) => $t?->value,
                'constraints' => [new NotBlank(message: 'Sélectionnez le type de projet.')],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nom du projet',
                'constraints' => [new NotBlank(message: 'Le nom du projet est obligatoire.')],
            ])
            ->add('theme', TextType::class, ['label' => 'Thème', 'required' => false])
            ->add('shortDescription', TextType::class, [
                'label' => 'Description courte',
                'required' => false,
                'constraints' => [new Length(max: 160, maxMessage: '160 caractères maximum.')],
            ])
            ->add('detailedDescription', TextareaType::class, ['label' => 'Description détaillée', 'required' => false])
            ->add('realizationDate', DateType::class, ['label' => 'Date de réalisation', 'required' => false, 'widget' => 'single_text'])
            ->add('technologiesInput', TextType::class, ['label' => 'Technologies utilisées', 'mapped' => false, 'required' => false])
            ->add('githubUrl', UrlType::class, [
                'label' => 'Lien GitHub',
                'mapped' => false,
                'required' => false,
                'default_protocol' => 'https',
                'constraints' => [new Regex(
                    pattern: '#^https?://(www\.)?github\.com/#i',
                    message: 'Ce lien doit pointer vers un dépôt GitHub (github.com).',
                )],
            ])
            ->add('youtubeUrl', UrlType::class, [
                'label' => 'Vidéo YouTube',
                'mapped' => false,
                'required' => false,
                'default_protocol' => 'https',
                'constraints' => [new Regex(
                    // Cahier des charges §19 : "Ne jamais accepter n'importe quelle URL comme vidéo YouTube."
                    pattern: '#^https?://((www\.)?youtube\.com/(watch\?v=|embed/|shorts/)|youtu\.be/)#i',
                    message: 'Ce lien doit être une URL YouTube valide (youtube.com ou youtu.be).',
                )],
            ])
            ->add('siteUrl', UrlType::class, ['label' => 'Site web ou démo', 'mapped' => false, 'required' => false, 'default_protocol' => 'https', 'constraints' => [new Url(requireTld: true)]])
            ->add('memoireUrl', UrlType::class, ['label' => 'Lien du mémoire', 'mapped' => false, 'required' => false, 'default_protocol' => 'https', 'constraints' => [new Url(requireTld: true)]])
            ->add('photos', FileType::class, [
                'label' => 'Photos (JPG, PNG, WebP — 8 maximum)',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'constraints' => [
                    new Count(max: 8, maxMessage: '8 photos maximum.'),
                    new All([
                        new File(
                            maxSize: '5M',
                            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                            mimeTypesMessage: 'Formats acceptés : JPG, PNG, WebP.',
                        ),
                    ]),
                ],
            ])
        ;

        /** @var Project|null $project */
        $project = $builder->getData();
        $this->addReferentialField($builder, 'domain', Domain::class, 'Domaine', 'Sélectionnez un domaine', 'Ex. : Sciences Juridiques', $project?->getDomain());
        $this->addReferentialField($builder, 'mention', Mention::class, 'Mention', 'Sélectionnez une mention', 'Ex. : Droit privé', $project?->getMention());
        $this->addReferentialField($builder, 'specialty', Specialty::class, 'Spécialité', 'Sélectionnez une spécialité', 'Ex. : Droit des affaires', $project?->getSpecialty());
        $this->addReferentialField($builder, 'institution', Institution::class, 'Établissement', 'Sélectionnez un établissement', 'Ex. : Université catholique de l\'Afrique de l\'Ouest', $project?->getInstitution());
    }

    /**
     * Ajoute un champ de classification (domaine/mention/spécialité/établissement)
     * avec une option « Autre (à préciser) » : si le référentiel ne contient pas
     * la bonne valeur, l'utilisateur peut la saisir librement plutôt que d'être
     * bloqué (l'administrateur pourra ensuite la nettoyer/fusionner).
     *
     * Un domaine/mention/spécialité désactivé (cahier §29) n'apparaît plus
     * dans la liste pour un nouveau choix, mais reste proposé si le projet
     * en cours d'édition l'utilise déjà — pour ne jamais faire disparaître
     * silencieusement la classification d'un projet existant.
     *
     * @param class-string $entityClass
     */
    private function addReferentialField(
        FormBuilderInterface $builder,
        string $field,
        string $entityClass,
        string $label,
        string $placeholder,
        string $otherPlaceholder,
        ?object $currentValue = null,
    ): void {
        $activeOnly = \in_array($entityClass, [Domain::class, Mention::class, Specialty::class], true);
        $criteria = $activeOnly ? ['active' => true] : [];
        $entities = $this->entityManager->getRepository($entityClass)->findBy($criteria, ['name' => 'ASC']);

        if ($currentValue && !\in_array($currentValue, $entities, true)) {
            $entities[] = $currentValue;
        }

        $choices = [];
        foreach ($entities as $entity) {
            $choices[$entity->getName()] = (string) $entity->getId();
        }
        $choices['Autre (à préciser)'] = self::OTHER_VALUE;

        $builder
            ->add($field, ChoiceType::class, [
                'label' => $label,
                'choices' => $choices,
                'placeholder' => $placeholder,
                'required' => false,
                'mapped' => false,
            ])
            ->add($field.'Other', TextType::class, [
                'label' => false,
                'required' => false,
                'mapped' => false,
                'attr' => ['placeholder' => $otherPlaceholder],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Project::class,
        ]);
    }
}
