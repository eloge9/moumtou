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
use App\Service\ForbiddenContentDetector;
use App\Service\ProjectDocumentUploader;
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
        'demoUrl' => ProofType::DEMO,
        'memoireUrl' => ProofType::MEMOIRE,
    ];

    #[Route('/publier', name: 'app_publish_start')]
    #[IsGranted('ROLE_TALENT')]
    public function publish(
        Request $request,
        EntityManagerInterface $entityManager,
        SlugGenerator $slugGenerator,
        ProjectPhotoUploader $photoUploader,
        ProjectDocumentUploader $documentUploader,
        ForbiddenContentDetector $forbiddenContentDetector,
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

            $errors = array_merge($errors, $this->validateBusinessRules($form, $project, $domain, $mention, $specialty, $institution, $forbiddenContentDetector));

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

                $this->persistProofs($form, $project, $entityManager);
                $photoUploader->upload($project, $form->get('photos')->getData() ?? []);
                $documentUploader->upload(
                    $project,
                    $form->get('documents')->getData() ?? [],
                    $form->get('documentType')->getData(),
                    $form->get('documentTitle')->getData(),
                );

                $entityManager->flush();

                return $this->render('publish/success.html.twig', ['project' => $project]);
            }
        }

        return $this->render('publish/wizard.html.twig', [
            'form' => $form,
            'businessErrors' => $form->isSubmitted() ? $errors : [],
        ]);
    }

    #[Route('/projets/{slug}/modifier', name: 'app_project_edit')]
    #[IsGranted('ROLE_TALENT')]
    public function edit(
        string $slug,
        Request $request,
        EntityManagerInterface $entityManager,
        ProjectPhotoUploader $photoUploader,
        ProjectDocumentUploader $documentUploader,
        ForbiddenContentDetector $forbiddenContentDetector,
    ): Response {
        $project = $entityManager->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(\App\Security\Voter\ProjectVoter::EDIT, $project);

        $form = $this->createForm(PublishProjectType::class, $project);

        if (!$request->isMethod('POST')) {
            $this->prefillUnmappedFields($form, $project);
        }

        $form->handleRequest($request);

        $errors = [];

        if ($form->isSubmitted() && $form->isValid()) {
            $domain = $this->resolveDomain($form, $entityManager, $errors);
            $mention = $this->resolveMention($form, $entityManager, $domain, $errors);
            $specialty = $this->resolveSpecialty($form, $entityManager, $mention, $errors);
            $institution = $this->resolveInstitution($form, $entityManager, $errors);

            $errors = array_merge($errors, $this->validateBusinessRules($form, $project, $domain, $mention, $specialty, $institution, $forbiddenContentDetector));

            if (empty($errors)) {
                $project->setDomain($domain);
                $project->setMention($mention);
                $project->setSpecialty($specialty);
                $project->setInstitution($institution);

                foreach ($project->getTechnologies()->toArray() as $technology) {
                    $project->removeTechnology($technology);
                }
                foreach ($this->parseTechnologies($form->get('technologiesInput')->getData(), $entityManager) as $technology) {
                    $project->addTechnology($technology);
                }

                foreach ($project->getProofs()->toArray() as $proof) {
                    $project->removeProof($proof);
                    $entityManager->remove($proof);
                }
                $this->persistProofs($form, $project, $entityManager);

                $photoUploader->upload($project, $form->get('photos')->getData() ?? []);

                $documentUploader->upload(
                    $project,
                    $form->get('documents')->getData() ?? [],
                    $form->get('documentType')->getData(),
                    $form->get('documentTitle')->getData(),
                );

                // Un projet vérifié doit repasser par une nouvelle vérification
                // après une modification substantielle (cahier des charges §15) :
                // la vérification est une preuve d'authenticité du contenu
                // précis qui a été contrôlé, pas un statut acquis définitivement.
                // Le projet reste visible (repasse à "publié"), il n'est jamais
                // masqué par cette réouverture.
                $wasVerified = ProjectStatus::VERIFIE === $project->getStatus();
                if ($wasVerified) {
                    $project->setStatus(ProjectStatus::PUBLIE);
                }

                $entityManager->flush();

                $this->addFlash('succes', $wasVerified
                    ? 'Votre projet a été mis à jour. Le contenu ayant changé, il redevient « publié, non vérifié » en attendant une nouvelle vérification.'
                    : 'Votre projet a été mis à jour.');

                return $this->redirectToRoute('app_project_show', ['slug' => $project->getSlug()]);
            }
        }

        return $this->render('publish/wizard.html.twig', [
            'form' => $form,
            'businessErrors' => $form->isSubmitted() ? $errors : [],
            'editingProject' => $project,
        ]);
    }

    #[Route('/projets/{slug}/supprimer', name: 'app_project_delete', methods: ['POST'])]
    #[IsGranted('ROLE_TALENT')]
    public function delete(string $slug, Request $request, EntityManagerInterface $entityManager): Response
    {
        $project = $entityManager->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(\App\Security\Voter\ProjectVoter::DELETE, $project);

        if (!$this->isCsrfTokenValid('supprimer-projet-'.$project->getId(), $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
        }

        /** @var \App\Entity\User $owner */
        $owner = $project->getOwner();
        $entityManager->remove($project);
        $entityManager->flush();

        $this->addFlash('succes', 'Le projet a été supprimé.');

        return $this->redirectToRoute('app_profile_show', ['slug' => $owner->getSlug()]);
    }

    /**
     * Enregistre les preuves-liens du formulaire (cahier des charges —
     * FONCTIONNALITÉ 10 §6) : les champs à valeur unique de
     * {@see PROOF_FIELDS}, plus l'éventuelle preuve « Autre » (titre +
     * URL), seul type pouvant porter un titre libre.
     */
    private function persistProofs(FormInterface $form, Project $project, EntityManagerInterface $entityManager): void
    {
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

        $otherUrl = $form->get('otherProofUrl')->getData();
        if ($otherUrl) {
            $proof = new ProjectProof();
            $proof->setType(ProofType::AUTRE);
            $proof->setTitle(trim((string) $form->get('otherProofTitle')->getData()) ?: null);
            $proof->setUrl($otherUrl);
            $project->addProof($proof);
            $entityManager->persist($proof);
        }
    }

    /**
     * Pré-remplit, à l'affichage du formulaire d'édition, les champs non
     * mappés directement (classification exprimée en "id ou autre",
     * technologies en texte, preuves individuelles par type).
     */
    private function prefillUnmappedFields(FormInterface $form, Project $project): void
    {
        if ($project->getDomain()) {
            $form->get('domain')->setData((string) $project->getDomain()->getId());
        }
        if ($project->getMention()) {
            $form->get('mention')->setData((string) $project->getMention()->getId());
        }
        if ($project->getSpecialty()) {
            $form->get('specialty')->setData((string) $project->getSpecialty()->getId());
        }
        if ($project->getInstitution()) {
            $form->get('institution')->setData((string) $project->getInstitution()->getId());
        }

        $form->get('technologiesInput')->setData(implode(',', array_map(
            fn (Technology $t) => $t->getName(),
            $project->getTechnologies()->toArray(),
        )));

        foreach ($project->getProofs() as $proof) {
            if (ProofType::AUTRE === $proof->getType()) {
                $form->get('otherProofTitle')->setData($proof->getTitle());
                $form->get('otherProofUrl')->setData($proof->getUrl());
                continue;
            }

            $field = array_search($proof->getType(), self::PROOF_FIELDS, true);
            if ($field) {
                $form->get($field)->setData($proof->getUrl());
            }
        }
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
        ForbiddenContentDetector $forbiddenContentDetector,
    ): array {
        $errors = [];

        if ($project->getType() === ProjectType::SOUTENANCE) {
            if (!$domain || !$mention || !$specialty || !$institution) {
                $errors[] = 'Un projet de soutenance doit être classé : domaine, mention, spécialité et établissement sont obligatoires.';
            }
        }

        // MOUMTOU n'est pas une plateforme de financement participatif
        // (cahier des charges §4/§32) : recherche de financement,
        // d'investisseurs ou de dons interdite en V1.
        if ($forbiddenContentDetector->detect([
            $project->getName(),
            $project->getTheme(),
            $project->getShortDescription(),
            $project->getDetailedDescription(),
        ])) {
            $errors[] = 'Ce projet ne peut pas être publié : MOUMTOU n\'est pas une plateforme de financement participatif. Retirez toute mention de recherche de financement, d\'investisseurs, de dons ou de crowdfunding.';
        }

        $hasProof = false;
        foreach ([...array_keys(self::PROOF_FIELDS), 'otherProofUrl'] as $field) {
            if ($form->get($field)->getData()) {
                $hasProof = true;
                break;
            }
        }
        if (!$hasProof) {
            $errors[] = 'Au moins une preuve de réalisation est obligatoire (GitHub, vidéo, site, démo, mémoire ou autre lien).';
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
