<?php

namespace App\Controller;

use App\Entity\Defense;
use App\Entity\DefenseResult;
use App\Entity\JuryMember;
use App\Entity\User;
use App\Enum\DefenseDecision;
use App\Enum\DefenseResultStatus;
use App\Enum\AdminAuditAction;
use App\Enum\JuryRole;
use App\Enum\JuryStatus;
use App\Service\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Résultat académique d'une soutenance (cahier des charges §12-§17) :
 * saisie réservée aux membres du jury réellement confirmés (ou à l'admin),
 * validation finale réservée au président du jury (ou à l'admin) — un
 * candidat ne peut jamais s'auto-attribuer une note (§16).
 */
#[IsGranted('ROLE_USER')]
class DefenseResultController extends AbstractController
{
    #[Route('/soutenances/{id}/resultat', name: 'app_defense_result_submit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function submit(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $defense = $em->getRepository(Defense::class)->find($id);
        if (!$defense) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $juryMember = $this->findConfirmedJuryMember($defense, $user);

        if (!$isAdmin && !$juryMember) {
            throw $this->createAccessDeniedException('Seul un membre du jury ayant confirmé sa participation peut saisir un résultat.');
        }

        if (!$this->isCsrfTokenValid('resultat-soutenance-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $result = $defense->getAcademicResult();
        if ($result && $result->isValidated() && !$isAdmin) {
            $this->addFlash('erreur', 'Ce résultat a déjà été validé : seul un administrateur peut le corriger.');

            return $this->redirectBackToJuryDashboardOrAdmin($defense, $isAdmin);
        }

        if (!$result) {
            $result = new DefenseResult();
            $defense->setAcademicResult($result);
            $em->persist($result);
        }

        $gradeRaw = $request->request->get('grade');
        if (null !== $gradeRaw && '' !== $gradeRaw) {
            $grade = (float) str_replace(',', '.', (string) $gradeRaw);
            if ($grade < 0 || $grade > $result->getGradeScale()) {
                $this->addFlash('erreur', sprintf('La note doit être comprise entre 0 et %s.', $result->getGradeScale()));

                return $this->redirectBackToJuryDashboardOrAdmin($defense, $isAdmin);
            }
            $result->setGrade($grade);
        }

        $status = DefenseResultStatus::tryFrom((string) $request->request->get('status'));
        if ($status) {
            $result->setStatus($status);
        }

        $decision = DefenseDecision::tryFrom((string) $request->request->get('decision'));
        if ($decision) {
            $result->setDecision($decision);
        }

        $appreciation = trim((string) $request->request->get('appreciation'));
        if ($appreciation) {
            $result->setAppreciation($appreciation);
            $result->setAppreciationAuthor($user);
        }

        $result->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('succes', 'Le résultat a été enregistré. Il doit encore être validé par le président du jury ou un administrateur.');

        return $this->redirectBackToJuryDashboardOrAdmin($defense, $isAdmin);
    }

    #[Route('/soutenances/{id}/resultat/valider', name: 'app_defense_result_validate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function validate(int $id, Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $defense = $em->getRepository(Defense::class)->find($id);
        if (!$defense || !$defense->getAcademicResult()) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $juryMember = $this->findConfirmedJuryMember($defense, $user);
        $isPresident = $juryMember && JuryRole::PRESIDENT === $juryMember->getRole();

        if (!$isAdmin && !$isPresident) {
            throw $this->createAccessDeniedException('Seul le président du jury ou un administrateur peut valider le résultat final.');
        }

        if (!$this->isCsrfTokenValid('valider-resultat-'.$id, $request->request->get('_csrf_token'))) {
            throw new InvalidCsrfTokenException();
        }

        $result = $defense->getAcademicResult();
        $result->setValidated(true);
        $result->setValidatedBy($user);
        $result->setValidatedAt(new \DateTimeImmutable());
        // Visible publiquement par défaut dès la validation finale (le
        // candidat garde la main : il peut toujours décocher ensuite depuis
        // « Ma soutenance » — ex. en cas d'échec) — avant la validation, rien
        // n'est encore définitif, donc rien n'est rendu public d'office.
        $result->setResultVisible(true);
        $result->setGradeVisible(true);
        $result->setAppreciationVisible(true);
        $em->flush();

        // Journalisation uniquement lorsque la validation provient d'un
        // administrateur agissant hors de son rôle de juré (cahier des
        // charges — FONCTIONNALITÉ 9 §36) : le président du jury validant
        // dans le cadre normal du processus n'a pas besoin d'être tracé ici.
        if ($isAdmin && !$isPresident) {
            $auditLogger->log(
                $user,
                AdminAuditAction::DEFENSE_RESULT_VALIDATED,
                'Defense',
                $defense->getId(),
                $defense->getProject()?->getName(),
                'Validation du résultat par un administrateur (hors président du jury).',
            );
        }

        $this->addFlash('succes', 'Le résultat de la soutenance a été validé.');

        return $this->redirectBackToJuryDashboardOrAdmin($defense, $isAdmin);
    }

    private function findConfirmedJuryMember(Defense $defense, User $user): ?JuryMember
    {
        foreach ($defense->getJuryMembers() as $member) {
            if ($member->getInvitedUser() === $user && JuryStatus::CONFIRME === $member->getStatus()) {
                return $member;
            }
        }

        return null;
    }

    private function redirectBackToJuryDashboardOrAdmin(Defense $defense, bool $isAdmin): Response
    {
        if ($isAdmin && !$this->isGranted('ROLE_TEACHER')) {
            return $this->redirectToRoute('admin_project_show', ['id' => $defense->getProject()->getId()]);
        }

        return $this->redirectToRoute('app_teacher_dashboard');
    }
}
