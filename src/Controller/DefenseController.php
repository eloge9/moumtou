<?php

namespace App\Controller;

use App\Entity\Defense;
use App\Entity\JuryMember;
use App\Entity\Project;
use App\Enum\DefenseStatus;
use App\Enum\JuryStatus;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Form\DefenseAnnounceType;
use App\Form\JuryInviteType;
use App\Security\JuryInvitationMailer;
use App\Service\ProjectPhotoUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DefenseController extends AbstractController
{
    #[Route('/ma-soutenance', name: 'app_defense_manage')]
    #[IsGranted('ROLE_TALENT')]
    public function manage(EntityManagerInterface $em): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $project = $em->getRepository(Project::class)->createQueryBuilder('p')
            ->andWhere('p.owner = :user')->setParameter('user', $user)
            ->andWhere('p.type = :type')->setParameter('type', ProjectType::SOUTENANCE)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        $announceForm = null;
        $inviteForm = null;
        $rescheduleForm = null;

        if ($project && !$project->getDefense()) {
            $announceForm = $this->createForm(DefenseAnnounceType::class, new Defense());
        }
        if ($project && $project->getDefense()) {
            $inviteForm = $this->createForm(JuryInviteType::class, new JuryMember());
        }
        if ($project && $project->getDefense() && DefenseStatus::REPORTEE === $project->getDefense()->getStatus()) {
            $rescheduleForm = $this->createForm(DefenseAnnounceType::class, $project->getDefense());
        }

        return $this->render('defense/manage.html.twig', [
            'project' => $project,
            'defense' => $project?->getDefense(),
            'announceForm' => $announceForm?->createView(),
            'inviteForm' => $inviteForm?->createView(),
            'rescheduleForm' => $rescheduleForm?->createView(),
        ]);
    }

    #[Route('/ma-soutenance/{id}/annoncer', name: 'app_defense_announce', methods: ['POST'])]
    #[IsGranted('ROLE_TALENT')]
    public function announce(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $project = $this->findOwnedProject($id, $em);

        if ($project->getDefense()) {
            $this->addFlash('erreur', 'Cette soutenance est déjà annoncée.');

            return $this->redirectToRoute('app_defense_manage');
        }

        $defense = new Defense();
        $form = $this->createForm(DefenseAnnounceType::class, $defense);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $defense->setProject($project);
            $defense->setStatus(DefenseStatus::ANNONCEE);
            $project->setDefense($defense);
            $em->persist($defense);
            $em->flush();

            $this->addFlash('succes', 'Votre soutenance a été annoncée.');
        } else {
            $this->addFlash('erreur', 'Merci de renseigner une date, une heure et un lieu valides.');
        }

        return $this->redirectToRoute('app_defense_manage');
    }

    #[Route('/ma-soutenance/{id}/jury/inviter', name: 'app_defense_invite_jury', methods: ['POST'])]
    #[IsGranted('ROLE_TALENT')]
    public function inviteJury(int $id, Request $request, EntityManagerInterface $em, JuryInvitationMailer $mailer): Response
    {
        $project = $this->findOwnedProject($id, $em);
        $defense = $project->getDefense();
        if (!$defense) {
            throw $this->createNotFoundException();
        }
        if (\in_array($defense->getStatus(), [DefenseStatus::ANNULEE, DefenseStatus::REPORTEE], true)) {
            $this->addFlash('erreur', 'Impossible d\'inviter un membre du jury tant que la soutenance est annulée ou en attente d\'une nouvelle date.');

            return $this->redirectToRoute('app_defense_manage');
        }

        $juryMember = new JuryMember();
        $form = $this->createForm(JuryInviteType::class, $juryMember);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $juryMember->setStatus(JuryStatus::EN_ATTENTE);
            // Si un compte existe déjà avec cet e-mail (quel que soit son
            // rôle actuel — talent, recruteur...), on relie directement
            // l'invitation pour qu'il la voie dans son espace, et on lui
            // accorde ROLE_TEACHER en plus de ses rôles existants (multi-rôle
            // réel : sans cet ajout, un compte non-enseignant lié resterait
            // bloqué à l'accès de /mon-espace-enseignant malgré la liaison).
            $matchingUser = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => $juryMember->getEmail()]);
            if ($matchingUser) {
                $juryMember->setInvitedUser($matchingUser);
                $this->grantTeacherRole($matchingUser);
            }
            $defense->addJuryMember($juryMember);
            $em->persist($juryMember);
            $em->flush();

            $signedUrl = $mailer->sendInvitation($juryMember);

            $message = sprintf('Invitation envoyée à %s %s.', $juryMember->getFirstName(), $juryMember->getLastName());
            if ($this->getParameter('kernel.environment') === 'dev') {
                $message .= ' Lien (mode développement) : '.$signedUrl;
            }
            $this->addFlash('succes', $message);
        } else {
            $this->addFlash('erreur', 'Merci de renseigner correctement le membre du jury.');
        }

        return $this->redirectToRoute('app_defense_manage');
    }

    #[Route('/ma-soutenance/{id}/realisee', name: 'app_defense_mark_realized', methods: ['POST'])]
    #[IsGranted('ROLE_TALENT')]
    public function markRealized(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $project = $this->findOwnedProject($id, $em);
        $defense = $project->getDefense();
        if (!$defense) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('soutenance-realisee-'.$id, $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
        }

        $defense->setStatus(DefenseStatus::REALISEE);
        $em->flush();

        $this->addFlash('succes', 'Soutenance marquée comme réalisée. Les membres du jury peuvent maintenant confirmer.');

        return $this->redirectToRoute('app_defense_manage');
    }

    /**
     * Annuler une soutenance annoncée (cahier des charges §24) — action
     * définitive, réservée au propriétaire du projet ou à l'administrateur,
     * uniquement avant que la soutenance ait eu lieu.
     */
    #[Route('/ma-soutenance/{id}/annuler', name: 'app_defense_cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(int $id, Request $request, EntityManagerInterface $em, JuryInvitationMailer $mailer): Response
    {
        $project = $this->findManageableProject($id, $em);
        $defense = $project->getDefense();
        if (!$defense || DefenseStatus::ANNONCEE !== $defense->getStatus()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('soutenance-annuler-'.$id, $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
        }

        $reason = trim((string) $request->request->get('reason'));
        if (!$reason) {
            $this->addFlash('erreur', 'Merci d\'indiquer le motif de l\'annulation.');

            return $this->redirectToRoute('app_defense_manage');
        }

        $defense->setStatus(DefenseStatus::ANNULEE);
        $defense->setCancellationReason($reason);
        $em->flush();

        $mailer->notifyCancelledOrPostponed($defense, 'annulée', $reason);

        $this->addFlash('succes', 'La soutenance a été annulée. Les membres du jury ont été informés.');

        return $this->redirectToRoute('app_defense_manage');
    }

    /**
     * Reporter une soutenance annoncée : conserve l'ancienne date à titre
     * d'historique et repasse en attente d'une nouvelle date (§24).
     */
    #[Route('/ma-soutenance/{id}/reporter', name: 'app_defense_postpone', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function postpone(int $id, Request $request, EntityManagerInterface $em, JuryInvitationMailer $mailer): Response
    {
        $project = $this->findManageableProject($id, $em);
        $defense = $project->getDefense();
        if (!$defense || DefenseStatus::ANNONCEE !== $defense->getStatus()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('soutenance-reporter-'.$id, $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
        }

        $reason = trim((string) $request->request->get('reason'));
        if (!$reason) {
            $this->addFlash('erreur', 'Merci d\'indiquer le motif du report.');

            return $this->redirectToRoute('app_defense_manage');
        }

        $defense->setPreviousDate($defense->getDate());
        $defense->setStatus(DefenseStatus::REPORTEE);
        $defense->setPostponementReason($reason);
        $em->flush();

        $mailer->notifyCancelledOrPostponed($defense, 'reportée', $reason);

        $this->addFlash('succes', 'La soutenance a été marquée comme reportée. Renseignez la nouvelle date dès qu\'elle sera connue.');

        return $this->redirectToRoute('app_defense_manage');
    }

    /**
     * Fixer la nouvelle date d'une soutenance reportée — la fait repasser
     * "annoncée" et réactive les rappels par e-mail.
     */
    #[Route('/ma-soutenance/{id}/reprogrammer', name: 'app_defense_reschedule', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function reschedule(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $project = $this->findManageableProject($id, $em);
        $defense = $project->getDefense();
        if (!$defense || DefenseStatus::REPORTEE !== $defense->getStatus()) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(DefenseAnnounceType::class, $defense);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $defense->setStatus(DefenseStatus::ANNONCEE);
            $defense->setReminderSentAt(null);
            $em->flush();

            $this->addFlash('succes', 'La nouvelle date de soutenance a été enregistrée.');
        } else {
            $this->addFlash('erreur', 'Merci de renseigner une date, une heure et un lieu valides.');
        }

        return $this->redirectToRoute('app_defense_manage');
    }

    #[Route('/ma-soutenance/{id}/completer', name: 'app_defense_complete', methods: ['POST'])]
    #[IsGranted('ROLE_TALENT')]
    public function complete(int $id, Request $request, EntityManagerInterface $em, ProjectPhotoUploader $photoUploader): Response
    {
        $project = $this->findOwnedProject($id, $em);
        $defense = $project->getDefense();
        if (!$defense) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('soutenance-completer-'.$id, $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
        }

        $result = trim((string) $request->request->get('result'));
        if ($result) {
            $defense->setResult($result);
        }

        $videoUrl = trim((string) $request->request->get('video_url'));
        if ($videoUrl) {
            $proof = new \App\Entity\ProjectProof();
            $proof->setType(\App\Enum\ProofType::YOUTUBE);
            $proof->setUrl($videoUrl);
            $project->addProof($proof);
            $em->persist($proof);
        }

        $photos = $request->files->all('photos') ?: [];
        $photoUploader->upload($project, $photos);

        $em->flush();

        $this->addFlash('succes', 'Informations de la soutenance mises à jour.');

        return $this->redirectToRoute('app_defense_manage');
    }

    /**
     * Le candidat contrôle la visibilité publique de son résultat (cahier
     * des charges §23) : masqué par défaut, il doit l'activer explicitement.
     * L'administrateur garde toujours la main via la modération (§16).
     */
    #[Route('/ma-soutenance/{id}/resultat/visibilite', name: 'app_defense_result_visibility', methods: ['POST'])]
    #[IsGranted('ROLE_TALENT')]
    public function setResultVisibility(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $project = $this->findOwnedProject($id, $em);
        $defense = $project->getDefense();
        $result = $defense?->getAcademicResult();
        if (!$result) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('resultat-visibilite-'.$id, $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
        }

        $result->setResultVisible((bool) $request->request->get('result_visible'));
        $result->setGradeVisible((bool) $request->request->get('grade_visible'));
        $result->setAppreciationVisible((bool) $request->request->get('appreciation_visible'));
        $em->flush();

        $this->addFlash('succes', 'Vos préférences de confidentialité ont été enregistrées.');

        return $this->redirectToRoute('app_defense_manage');
    }

    #[Route('/jury/confirmer', name: 'app_jury_confirm')]
    public function confirmJury(
        Request $request,
        EntityManagerInterface $em,
        JuryInvitationMailer $mailer,
        \App\Service\DefenseValidator $defenseValidator,
    ): Response {
        $id = (int) $request->query->get('id');
        $expires = (int) $request->query->get('expires');
        $decision = $request->query->get('decision');

        $juryMember = $em->getRepository(JuryMember::class)->find($id);

        if (!$juryMember || !$mailer->isSignedUrlValid($request->getUri(), $expires)) {
            throw $this->createNotFoundException('Ce lien d\'invitation est invalide ou a expiré.');
        }

        $defense = $juryMember->getDefense();

        // Étape 1 (avant la soutenance) : accepter/refuser l'invitation.
        // Ceci ne certifie en rien que la soutenance a eu lieu — voir §19.
        if ('confirmer' === $decision || 'refuser' === $decision) {
            $juryMember->setStatus('confirmer' === $decision ? JuryStatus::CONFIRME : JuryStatus::REFUSE);
            if ('confirmer' === $decision) {
                $juryMember->setConfirmedAt(new \DateTimeImmutable());
            }
            $em->flush();
        }

        // Étape 2 (après la soutenance) : certifier qu'elle a réellement eu
        // lieu. N'est proposée que si l'invitation a été acceptée au
        // préalable et que la soutenance est au moins "réalisée".
        $justValidated = false;
        if ('valider' === $decision
            && JuryStatus::CONFIRME === $juryMember->getStatus()
            && DefenseStatus::ANNONCEE !== $defense->getStatus()
        ) {
            $justValidated = $defenseValidator->recordValidation($juryMember, $request->getClientIp());
        }

        $alreadyValidated = null !== $em->getRepository(\App\Entity\DefenseValidation::class)->findOneBy([
            'defense' => $defense,
            'juryMember' => $juryMember,
        ]);

        return $this->render('defense/jury_confirm.html.twig', [
            'juryMember' => $juryMember,
            'project' => $defense->getProject(),
            'defense' => $defense,
            'decided' => 'confirmer' === $decision || 'refuser' === $decision,
            'justValidated' => $justValidated,
            'alreadyValidated' => $alreadyValidated,
            'canValidateNow' => JuryStatus::CONFIRME === $juryMember->getStatus() && DefenseStatus::ANNONCEE !== $defense->getStatus(),
            'confirmUrl' => $mailer->generateDecisionUrl($juryMember, 'confirmer'),
            'declineUrl' => $mailer->generateDecisionUrl($juryMember, 'refuser'),
            'validateUrl' => $mailer->generateDecisionUrl($juryMember, 'valider'),
        ]);
    }

    /**
     * @param \App\Entity\User $user
     */
    private function grantTeacherRole(\App\Entity\User $user): void
    {
        $roles = $user->getRoles();
        if (!\in_array('ROLE_TEACHER', $roles, true)) {
            $roles[] = 'ROLE_TEACHER';
            $user->setRoles($roles);
        }
    }

    private function findOwnedProject(int $id, EntityManagerInterface $em): Project
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $project = $em->getRepository(Project::class)->find($id);

        if (!$project || $project->getOwner() !== $user) {
            throw $this->createNotFoundException();
        }

        return $project;
    }

    /**
     * Comme {@see findOwnedProject()} mais autorise également
     * l'administrateur (cahier des charges §25 : "Admin peut consulter
     * toutes les soutenances, modifier/modérer"), utilisé pour
     * annuler/reporter/reprogrammer.
     */
    private function findManageableProject(int $id, EntityManagerInterface $em): Project
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $project = $em->getRepository(Project::class)->find($id);

        if (!$project) {
            throw $this->createNotFoundException();
        }
        if ($project->getOwner() !== $user && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        return $project;
    }
}
