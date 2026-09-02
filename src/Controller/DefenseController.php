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

        if ($project && !$project->getDefense()) {
            $announceForm = $this->createForm(DefenseAnnounceType::class, new Defense());
        }
        if ($project && $project->getDefense()) {
            $inviteForm = $this->createForm(JuryInviteType::class, new JuryMember());
        }

        return $this->render('defense/manage.html.twig', [
            'project' => $project,
            'defense' => $project?->getDefense(),
            'announceForm' => $announceForm?->createView(),
            'inviteForm' => $inviteForm?->createView(),
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

    #[Route('/jury/confirmer', name: 'app_jury_confirm')]
    public function confirmJury(Request $request, EntityManagerInterface $em, JuryInvitationMailer $mailer): Response
    {
        $id = (int) $request->query->get('id');
        $expires = (int) $request->query->get('expires');
        $decision = $request->query->get('decision');

        $juryMember = $em->getRepository(JuryMember::class)->find($id);

        if (!$juryMember || !$mailer->isSignedUrlValid($request->getUri(), $expires)) {
            throw $this->createNotFoundException('Ce lien d\'invitation est invalide ou a expiré.');
        }

        if ($decision === 'confirmer' || $decision === 'refuser') {
            $juryMember->setStatus($decision === 'confirmer' ? JuryStatus::CONFIRME : JuryStatus::REFUSE);
            if ($decision === 'confirmer') {
                $juryMember->setConfirmedAt(new \DateTimeImmutable());
            }
            $em->flush();

            if ($decision === 'confirmer') {
                $defense = $juryMember->getDefense();
                $defense->setStatus(DefenseStatus::VERIFIEE);
                $defense->getProject()->setStatus(ProjectStatus::VERIFIE);
                $em->flush();
            }
        }

        return $this->render('defense/jury_confirm.html.twig', [
            'juryMember' => $juryMember,
            'project' => $juryMember->getDefense()->getProject(),
            'defense' => $juryMember->getDefense(),
            'decided' => $decision === 'confirmer' || $decision === 'refuser',
            'confirmUrl' => $mailer->generateDecisionUrl($juryMember, 'confirmer'),
            'declineUrl' => $mailer->generateDecisionUrl($juryMember, 'refuser'),
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
}
