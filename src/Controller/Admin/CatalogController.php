<?php

namespace App\Controller\Admin;

use App\Entity\Skill;
use App\Entity\Technology;
use App\Entity\User;
use App\Enum\AdminAuditAction;
use App\Service\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/technologies')]
class CatalogController extends AbstractController
{
    #[Route('', name: 'admin_technologies')]
    public function index(EntityManagerInterface $em): Response
    {
        return $this->render('admin/catalog.html.twig', [
            'adminNav' => 'technologies',
            'technologies' => $em->getRepository(Technology::class)->findBy([], ['name' => 'ASC']),
            'skills' => $em->getRepository(Skill::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/ajouter', name: 'admin_technologies_add', methods: ['POST'])]
    public function addTechnology(Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $this->assertCsrf($request, 'admin-technologie-ajouter');
        $name = trim((string) $request->request->get('name'));
        if (!$name) {
            return $this->redirectToRoute('admin_technologies');
        }

        if ($this->findByNameCaseInsensitive($em, Technology::class, $name)) {
            $this->addFlash('erreur', 'Cette technologie existe déjà (recherche insensible à la casse).');

            return $this->redirectToRoute('admin_technologies');
        }

        $technology = new Technology();
        $technology->setName($name);
        $em->persist($technology);
        $em->flush();
        $auditLogger->log($this->admin(), AdminAuditAction::TECHNOLOGY_CREATED, 'Technology', $technology->getId(), $technology->getName());

        return $this->redirectToRoute('admin_technologies');
    }

    #[Route('/{id}/renommer', name: 'admin_technologies_rename', methods: ['POST'])]
    public function renameTechnology(int $id, Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $this->assertCsrf($request, 'admin-technologie-renommer-'.$id);
        $technology = $em->getRepository(Technology::class)->find($id);
        $name = trim((string) $request->request->get('name'));

        if (!$technology || !$name) {
            return $this->redirectToRoute('admin_technologies');
        }

        $existing = $this->findByNameCaseInsensitive($em, Technology::class, $name);
        if ($existing && $existing->getId() !== $technology->getId()) {
            $this->addFlash('erreur', 'Une autre technologie porte déjà ce nom.');

            return $this->redirectToRoute('admin_technologies');
        }

        $oldName = $technology->getName();
        $technology->setName($name);
        $em->flush();
        $auditLogger->log($this->admin(), AdminAuditAction::TECHNOLOGY_RENAMED, 'Technology', $technology->getId(), $name, \sprintf('Ancien nom : %s', $oldName));

        return $this->redirectToRoute('admin_technologies');
    }

    /**
     * Fusionne deux technologies en doublon (cahier des charges §33) :
     * réaffecte les projets et profils liés à la technologie source vers la
     * cible, puis supprime la source. Doctrine n'a pas d'opération de
     * fusion native pour une relation ManyToMany ; on réaffecte donc les
     * lignes des tables de jointure en SQL, en ignorant les collisions de
     * clé composite (un projet ou un profil ayant déjà les deux
     * technologies ne doit pas provoquer d'erreur).
     */
    #[Route('/{id}/fusionner', name: 'admin_technologies_merge', methods: ['POST'])]
    public function mergeTechnology(int $id, Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $this->assertCsrf($request, 'admin-technologie-fusionner-'.$id);
        $source = $em->getRepository(Technology::class)->find($id);
        $target = $em->getRepository(Technology::class)->find((int) $request->request->get('target'));

        if (!$source || !$target || $source->getId() === $target->getId()) {
            $this->addFlash('erreur', 'Sélectionnez une technologie cible différente de la source.');

            return $this->redirectToRoute('admin_technologies');
        }

        $connection = $em->getConnection();
        $connection->beginTransaction();
        try {
            $connection->executeStatement('UPDATE IGNORE project_technology SET technology_id = ? WHERE technology_id = ?', [$target->getId(), $source->getId()]);
            $connection->executeStatement('DELETE FROM project_technology WHERE technology_id = ?', [$source->getId()]);
            $connection->executeStatement('UPDATE IGNORE user_technology SET technology_id = ? WHERE technology_id = ?', [$target->getId(), $source->getId()]);
            $connection->executeStatement('DELETE FROM user_technology WHERE technology_id = ?', [$source->getId()]);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $sourceName = $source->getName();
        $sourceId = $source->getId();
        $em->remove($source);
        $em->flush();

        $auditLogger->log(
            $this->admin(),
            AdminAuditAction::TECHNOLOGY_MERGED,
            'Technology',
            $target->getId(),
            $target->getName(),
            \sprintf('Fusion de « %s » (#%d) vers « %s ».', $sourceName, $sourceId, $target->getName()),
        );

        $this->addFlash('succes', \sprintf('« %s » a été fusionnée dans « %s ».', $sourceName, $target->getName()));

        return $this->redirectToRoute('admin_technologies');
    }

    #[Route('/{id}/supprimer', name: 'admin_technologies_delete', methods: ['POST'])]
    public function deleteTechnology(int $id, Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $this->assertCsrf($request, 'admin-technologie-supprimer-'.$id);
        $technology = $em->getRepository(Technology::class)->find($id);
        if ($technology) {
            $technologyId = $technology->getId();
            $technologyName = $technology->getName();
            try {
                $em->remove($technology);
                $em->flush();
                $auditLogger->log($this->admin(), AdminAuditAction::TECHNOLOGY_DELETED, 'Technology', $technologyId, $technologyName);
            } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
                $this->addFlash('erreur', 'Cette technologie est utilisée et ne peut pas être supprimée.');
            }
        }

        return $this->redirectToRoute('admin_technologies');
    }

    #[Route('/competences/ajouter', name: 'admin_skills_add', methods: ['POST'])]
    public function addSkill(Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $this->assertCsrf($request, 'admin-competence-ajouter');
        $name = trim((string) $request->request->get('name'));
        if ($name && !$this->findByNameCaseInsensitive($em, Skill::class, $name)) {
            $skill = new Skill();
            $skill->setName($name);
            $em->persist($skill);
            $em->flush();
            $auditLogger->log($this->admin(), AdminAuditAction::SKILL_CREATED, 'Skill', $skill->getId(), $skill->getName());
        } elseif ($name) {
            $this->addFlash('erreur', 'Cette compétence existe déjà (recherche insensible à la casse).');
        }

        return $this->redirectToRoute('admin_technologies');
    }

    #[Route('/competences/{id}/supprimer', name: 'admin_skills_delete', methods: ['POST'])]
    public function deleteSkill(int $id, Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $this->assertCsrf($request, 'admin-competence-supprimer-'.$id);
        $skill = $em->getRepository(Skill::class)->find($id);
        if ($skill) {
            $skillId = $skill->getId();
            $skillName = $skill->getName();
            try {
                $em->remove($skill);
                $em->flush();
                $auditLogger->log($this->admin(), AdminAuditAction::SKILL_DELETED, 'Skill', $skillId, $skillName);
            } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
                $this->addFlash('erreur', 'Cette compétence est utilisée et ne peut pas être supprimée.');
            }
        }

        return $this->redirectToRoute('admin_technologies');
    }

    /**
     * @param class-string<Technology|Skill> $class
     */
    private function findByNameCaseInsensitive(EntityManagerInterface $em, string $class, string $name): Technology|Skill|null
    {
        return $em->getRepository($class)->createQueryBuilder('e')
            ->andWhere('LOWER(e.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->getQuery()->getOneOrNullResult();
    }

    private function admin(): User
    {
        /** @var User $admin */
        $admin = $this->getUser();

        return $admin;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
        }
    }
}
