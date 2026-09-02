<?php

namespace App\Controller\Admin;

use App\Entity\Skill;
use App\Entity\Technology;
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
            'technologies' => $em->getRepository(Technology::class)->findBy([], ['name' => 'ASC']),
            'skills' => $em->getRepository(Skill::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/ajouter', name: 'admin_technologies_add', methods: ['POST'])]
    public function addTechnology(Request $request, EntityManagerInterface $em): Response
    {
        $this->assertCsrf($request, 'admin-technologie-ajouter');
        $name = trim((string) $request->request->get('name'));
        if ($name && !$em->getRepository(Technology::class)->findOneBy(['name' => $name])) {
            $technology = new Technology();
            $technology->setName($name);
            $em->persist($technology);
            $em->flush();
        }

        return $this->redirectToRoute('admin_technologies');
    }

    #[Route('/{id}/supprimer', name: 'admin_technologies_delete', methods: ['POST'])]
    public function deleteTechnology(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertCsrf($request, 'admin-technologie-supprimer-'.$id);
        $technology = $em->getRepository(Technology::class)->find($id);
        if ($technology) {
            try {
                $em->remove($technology);
                $em->flush();
            } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
                $this->addFlash('erreur', 'Cette technologie est utilisée et ne peut pas être supprimée.');
            }
        }

        return $this->redirectToRoute('admin_technologies');
    }

    #[Route('/competences/ajouter', name: 'admin_skills_add', methods: ['POST'])]
    public function addSkill(Request $request, EntityManagerInterface $em): Response
    {
        $this->assertCsrf($request, 'admin-competence-ajouter');
        $name = trim((string) $request->request->get('name'));
        if ($name && !$em->getRepository(Skill::class)->findOneBy(['name' => $name])) {
            $skill = new Skill();
            $skill->setName($name);
            $em->persist($skill);
            $em->flush();
        }

        return $this->redirectToRoute('admin_technologies');
    }

    #[Route('/competences/{id}/supprimer', name: 'admin_skills_delete', methods: ['POST'])]
    public function deleteSkill(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertCsrf($request, 'admin-competence-supprimer-'.$id);
        $skill = $em->getRepository(Skill::class)->find($id);
        if ($skill) {
            try {
                $em->remove($skill);
                $em->flush();
            } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
                $this->addFlash('erreur', 'Cette compétence est utilisée et ne peut pas être supprimée.');
            }
        }

        return $this->redirectToRoute('admin_technologies');
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
        }
    }
}
