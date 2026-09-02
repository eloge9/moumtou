<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Entity\Institution;
use App\Entity\Mention;
use App\Entity\Project;
use App\Entity\ProjectProof;
use App\Entity\Specialty;
use App\Entity\Technology;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\ProofType;
use App\Form\PublishProjectType;
use App\Service\ProjectPhotoUploader;
use App\Service\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PublishController extends AbstractController
{
    /** @var array<string, ProofType> */
    private const PROOF_FIELDS = [
        'githubUrl' => ProofType::GITHUB,
        'youtubeUrl' => ProofType::YOUTUBE,
        'siteUrl' => ProofType::SITE,
        'memoireUrl' => ProofType::MEMOIRE,
    ];

    #[Route('/publier', name: 'app_publish_start')]
    #[IsGranted('ROLE_USER')]
    public function publish(
        Request $request,
        EntityManagerInterface $entityManager,
        SlugGenerator $slugGenerator,
        ProjectPhotoUploader $photoUploader,
    ): Response {
        $project = new Project();
        $form = $this->createForm(PublishProjectType::class, $project);
        $form->handleRequest($request);

        $errors = [];

        if ($form->isSubmitted() && $form->isValid()) {
            $domain = $this->resolveDomain($form, $entityManager, $errors);
            $mention = $this->resolveMention($form, $entityManager, $domain, $errors);
            $specialty = $this->resolveSpecialty($form, $entityManager, $mention, $errors);
            $institution = $this->resolveInstitution($form, $entityManager, $errors);

            $errors = array_merge($errors, $this->validateBusinessRules($form, $project, $domain, $mention, $specialty, $institution));

            if (empty($errors)) {
                /** @var \App\Entity\User $user */
                $user = $this->getUser();

                $project->setOwner($user);
                $project->setStatus(ProjectStatus::EN_ATTENTE);
                $project->setSlug($slugGenerator->generateUnique($project->getName(), Project::class));
                $project->setDomain($domain);
                $project->setMention($mention);
                $project->setSpecialty($specialty);
                $project->setInstitution($institution);

                foreach ($this->parseTechnologies($form->get('technologiesInput')->getData(), $entityManager) as $technology) {
                    $project->addTechnology($technology);
                }

                $entityManager->persist($project);
                $entityManager->flush();

                foreach (self::PROOF_FIELDS as $field => $proofType) {
                    $url = $form->get($field)->getData();
                    if ($url) {
                        $proof = new ProjectProof();
                        $proof->setType($proofType);
                        $proof->setUrl($url);
                        $project->addProof($proof);
                        $entityManager->persist($proof);
                    }
                }

                $photoUploader->upload($project, $form->get('photos')->getData() ?? []);

                $entityManager->flush();

                return $this->render('publish/success.html.twig', ['project' => $project]);
            }
        }

        return $this->render('publish/wizard.html.twig', [
            'form' => $form,
            'businessErrors' => $form->isSubmitted() ? $errors : [],
        ]);
    }

    /**
     * @param string[] $errors
     */
    private function resolveDomain(FormInterface $form, EntityManagerInterface $em, array &$errors): ?Domain
    {
        $value = $form->get('domain')->getData();
        if (!$value) {
            return null;
        }

        if ($value !== PublishProjectType::OTHER_VALUE) {
            return $em->getRepository(Domain::class)->find((int) $value);
        }

        $name = trim((string) $form->get('domainOther')->getData());
        if (!$name) {
            $errors[] = 'Précisez le nom du domaine dans le champ « Autre ».';

            return null;
        }

        $domain = $em->getRepository(Domain::class)->findOneBy(['name' => $name]);
        if (!$domain) {
            $domain = new Domain();
            $domain->setName($name);
            $em->persist($domain);
        }

        return $domain;
    }

    /**
     * @param string[] $errors
     */
    private function resolveMention(FormInterface $form, EntityManagerInterface $em, ?Domain $domain, array &$errors): ?Mention
    {
        $value = $form->get('mention')->getData();
        if (!$value) {
            return null;
        }

        if ($value !== PublishProjectType::OTHER_VALUE) {
            return $em->getRepository(Mention::class)->find((int) $value);
        }

        $name = trim((string) $form->get('mentionOther')->getData());
        if (!$name) {
            $errors[] = 'Précisez le nom de la mention dans le champ « Autre ».';

            return null;
        }
        if (!$domain) {
            $errors[] = 'Sélectionnez ou précisez d\'abord un domaine avant d\'ajouter une nouvelle mention.';

            return null;
        }

        $mention = $em->getRepository(Mention::class)->findOneBy(['name' => $name, 'domain' => $domain]);
        if (!$mention) {
            $mention = new Mention();
            $mention->setName($name);
            $mention->setDomain($domain);
            $em->persist($mention);
        }

        return $mention;
    }

    /**
     * @param string[] $errors
     */
    private function resolveSpecialty(FormInterface $form, EntityManagerInterface $em, ?Mention $mention, array &$errors): ?Specialty
    {
        $value = $form->get('specialty')->getData();
        if (!$value) {
            return null;
        }

        if ($value !== PublishProjectType::OTHER_VALUE) {
            return $em->getRepository(Specialty::class)->find((int) $value);
        }

        $name = trim((string) $form->get('specialtyOther')->getData());
        if (!$name) {
            $errors[] = 'Précisez le nom de la spécialité dans le champ « Autre ».';

            return null;
        }
        if (!$mention) {
            $errors[] = 'Sélectionnez ou précisez d\'abord une mention avant d\'ajouter une nouvelle spécialité.';

            return null;
        }

        $specialty = $em->getRepository(Specialty::class)->findOneBy(['name' => $name, 'mention' => $mention]);
        if (!$specialty) {
            $specialty = new Specialty();
            $specialty->setName($name);
            $specialty->setMention($mention);
            $em->persist($specialty);
        }

        return $specialty;
    }

    /**
     * @param string[] $errors
     */
    private function resolveInstitution(FormInterface $form, EntityManagerInterface $em, array &$errors): ?Institution
    {
        $value = $form->get('institution')->getData();
        if (!$value) {
            return null;
        }

        if ($value !== PublishProjectType::OTHER_VALUE) {
            return $em->getRepository(Institution::class)->find((int) $value);
        }

        $name = trim((string) $form->get('institutionOther')->getData());
        if (!$name) {
            $errors[] = 'Précisez le nom de l\'établissement dans le champ « Autre ».';

            return null;
        }

        $institution = $em->getRepository(Institution::class)->findOneBy(['name' => $name]);
        if (!$institution) {
            // Établissement ajouté par un utilisateur, non vérifié tant que
            // l'administrateur ne l'a pas validé (cahier des charges §12).
            $institution = new Institution();
            $institution->setName($name);
            $institution->setVerified(false);
            $em->persist($institution);
        }

        return $institution;
    }

    /**
     * @return string[]
     */
    private function validateBusinessRules(
        FormInterface $form,
        Project $project,
        ?Domain $domain,
        ?Mention $mention,
        ?Specialty $specialty,
        ?Institution $institution,
    ): array {
        $errors = [];

        if ($project->getType() === ProjectType::SOUTENANCE) {
            if (!$domain || !$mention || !$specialty || !$institution) {
                $errors[] = 'Un projet de soutenance doit être classé : domaine, mention, spécialité et établissement sont obligatoires.';
            }
        }

        $hasProof = false;
        foreach (array_keys(self::PROOF_FIELDS) as $field) {
            if ($form->get($field)->getData()) {
                $hasProof = true;
                break;
            }
        }
        if (!$hasProof) {
            $errors[] = 'Au moins une preuve de réalisation est obligatoire (GitHub, vidéo, site ou mémoire).';
        }

        return $errors;
    }

    /**
     * @return Technology[]
     */
    private function parseTechnologies(?string $raw, EntityManagerInterface $entityManager): array
    {
        if (!$raw) {
            return [];
        }

        $names = array_unique(array_filter(array_map('trim', explode(',', $raw))));
        $repository = $entityManager->getRepository(Technology::class);
        $technologies = [];

        foreach ($names as $name) {
            $technology = $repository->findOneBy(['name' => $name]);
            if (!$technology) {
                $technology = new Technology();
                $technology->setName($name);
                $entityManager->persist($technology);
            }
            $technologies[] = $technology;
        }

        return $technologies;
    }
}
