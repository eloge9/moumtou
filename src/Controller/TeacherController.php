<?php

namespace App\Controller;

use App\Entity\Institution;
use App\Entity\JuryMember;
use App\Entity\User;
use App\Entity\UserInstitution;
use App\Enum\InstitutionContext;
use App\Enum\JuryStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace enseignant / membre du jury (cahier des charges §4.4) : renseigner
 * son ou ses établissements (un enseignant peut être rattaché à plusieurs
 * — gestion des établissements §9), voir les soutenances auxquelles il est
 * associé, confirmer sa participation et confirmer qu'un étudiant a
 * effectivement soutenu.
 */
#[IsGranted('ROLE_TEACHER')]
class TeacherController extends AbstractController
{
    #[Route('/mon-espace-enseignant', name: 'app_teacher_dashboard')]
    public function dashboard(EntityManagerInterface $em): Response
    {
        /** @var User $teacher */
        $teacher = $this->getUser();

        $invitations = $em->getRepository(JuryMember::class)->createQueryBuilder('j')
            ->andWhere('j.invitedUser = :teacher')->setParameter('teacher', $teacher)
            ->orderBy('j.invitedAt', 'DESC')
            ->getQuery()->getResult();

        // Pour chaque invitation, ce membre a-t-il déjà certifié que la
        // soutenance a eu lieu ? (empêche une double validation côté UI).
        $validatedInvitationIds = [];
        foreach ($invitations as $invitation) {
            if ($em->getRepository(\App\Entity\DefenseValidation::class)->findOneBy(['juryMember' => $invitation])) {
                $validatedInvitationIds[] = $invitation->getId();
            }
        }

        $attachments = $em->getRepository(UserInstitution::class)->createQueryBuilder('ui')
            ->andWhere('ui.user = :teacher')->setParameter('teacher', $teacher)
            ->andWhere('ui.context = :context')->setParameter('context', InstitutionContext::ENSEIGNANT)
            ->andWhere('ui.active = true')
            ->getQuery()->getResult();

        return $this->render('teacher/dashboard.html.twig', [
            'teacher' => $teacher,
            'invitations' => $invitations,
            'validatedInvitationIds' => $validatedInvitationIds,
            'resultStatuses' => \App\Enum\DefenseResultStatus::cases(),
            'decisions' => \App\Enum\DefenseDecision::cases(),
            'attachments' => $attachments,
            'institutions' => $em->getRepository(Institution::class)->createQueryBuilder('i')
                ->andWhere('i.active = true')->orderBy('i.name', 'ASC')->getQuery()->getResult(),
        ]);
    }

    #[Route('/mon-espace-enseignant/institution', name: 'app_teacher_set_institution', methods: ['POST'])]
    public function setInstitution(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('enseignant-institution', $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        /** @var User $teacher */
        $teacher = $this->getUser();
        $institution = $em->getRepository(Institution::class)->find((int) $request->request->get('institution'));
        if (!$institution) {
            $this->addFlash('erreur', 'Veuillez sélectionner un établissement.');

            return $this->redirectToRoute('app_teacher_dashboard');
        }

        $existing = $em->getRepository(UserInstitution::class)->findOneBy([
            'user' => $teacher,
            'institution' => $institution,
            'context' => InstitutionContext::ENSEIGNANT,
        ]);

        if ($existing) {
            $existing->setActive(true);
            $this->addFlash('erreur', 'Vous êtes déjà rattaché à cet établissement.');
        } else {
            $attachment = new UserInstitution();
            $attachment->setInstitution($institution);
            $attachment->setContext(InstitutionContext::ENSEIGNANT);
            $teacher->addInstitutionAttachment($attachment);
            $em->persist($attachment);
            $this->addFlash('succes', 'Établissement ajouté à vos rattachements.');
        }

        // Établissement "principal" affiché par défaut ailleurs — conservé
        // pour compatibilité avec l'existant.
        $teacher->setInstitution($institution);
        $em->flush();

        return $this->redirectToRoute('app_teacher_dashboard');
    }

    #[Route('/mon-espace-enseignant/institution/{id}/retirer', name: 'app_teacher_remove_institution', methods: ['POST'])]
    public function removeInstitution(int $id, Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $teacher */
        $teacher = $this->getUser();
        $attachment = $em->getRepository(UserInstitution::class)->find($id);

        if (!$attachment || $attachment->getUser() !== $teacher) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('enseignant-institution-retirer-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $attachment->setActive(false);
        if ($teacher->getInstitution() === $attachment->getInstitution()) {
            $teacher->setInstitution(null);
        }
        $em->flush();

        $this->addFlash('succes', 'Rattachement retiré.');

        return $this->redirectToRoute('app_teacher_dashboard');
    }

    #[Route('/mon-espace-enseignant/jury/{id}/repondre', name: 'app_teacher_respond_jury', methods: ['POST'])]
    public function respond(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $juryMember = $em->getRepository(JuryMember::class)->find($id);
        if (!$juryMember) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(\App\Security\Voter\JuryMemberVoter::RESPOND, $juryMember);

        if (!$this->isCsrfTokenValid('repondre-jury-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $decision = $request->request->get('decision');
        if (!\in_array($decision, ['confirmer', 'refuser'], true)) {
            throw $this->createNotFoundException();
        }

        // Accepter/refuser l'invitation (avant la soutenance) ne certifie en
        // rien qu'elle a eu lieu — voir validateDefense() ci-dessous pour
        // l'étape distincte prévue par le cahier des charges §19/§20.
        $juryMember->setStatus('confirmer' === $decision ? JuryStatus::CONFIRME : JuryStatus::REFUSE);
        if ('confirmer' === $decision) {
            $juryMember->setConfirmedAt(new \DateTimeImmutable());
        }
        $em->flush();

        $this->addFlash('succes', 'confirmer' === $decision ? 'Votre participation est confirmée.' : 'Invitation déclinée.');

        return $this->redirectToRoute('app_teacher_dashboard');
    }

    /**
     * « Je confirme que cette soutenance a bien eu lieu » (cahier des
     * charges §20) : distinct de l'acceptation de l'invitation, disponible
     * uniquement après acceptation ET une fois la soutenance au moins
     * "réalisée". La soutenance ne devient VERIFIEE qu'à la 2ᵉ validation
     * distincte (cahier §19), jamais dès la première.
     */
    #[Route('/mon-espace-enseignant/jury/{id}/valider-soutenance', name: 'app_teacher_validate_defense', methods: ['POST'])]
    public function validateDefense(int $id, Request $request, EntityManagerInterface $em, \App\Service\DefenseValidator $defenseValidator): Response
    {
        $juryMember = $em->getRepository(JuryMember::class)->find($id);
        if (!$juryMember) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(\App\Security\Voter\JuryMemberVoter::VALIDATE, $juryMember);

        if (!$this->isCsrfTokenValid('valider-soutenance-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        if (\App\Enum\DefenseStatus::ANNONCEE === $juryMember->getDefense()->getStatus()) {
            $this->addFlash('erreur', 'Cette soutenance n\'a pas encore été marquée comme réalisée par le candidat.');

            return $this->redirectToRoute('app_teacher_dashboard');
        }

        $created = $defenseValidator->recordValidation($juryMember, $request->getClientIp());

        $this->addFlash('succes', $created
            ? 'Votre confirmation a été enregistrée. Merci.'
            : 'Vous aviez déjà confirmé la tenue de cette soutenance.');

        return $this->redirectToRoute('app_teacher_dashboard');
    }
}
