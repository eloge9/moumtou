<?php

namespace App\Controller\Admin;

use App\Entity\Domain;
use App\Entity\Mention;
use App\Entity\Specialty;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/classification')]
class ClassificationController extends AbstractController
{
    #[Route('', name: 'admin_domains')]
    public function index(EntityManagerInterface $em): Response
    {
        return $this->render('admin/classification.html.twig', [
            'domains' => $em->getRepository(Domain::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/domaines/ajouter', name: 'admin_domains_add', methods: ['POST'])]
    public function addDomain(Request $request, EntityManagerInterface $em): Response
    {
        $this->assertCsrf($request, 'admin-domaine-ajouter');
        $name = trim((string) $request->request->get('name'));
        if ($name) {
            $domain = new Domain();
            $domain->setName($name);
            $em->persist($domain);
            $em->flush();
            $this->addFlash('succes', 'Domaine ajouté.');
        }

        return $this->redirectToRoute('admin_domains');
    }

    #[Route('/mentions/ajouter', name: 'admin_mentions_add', methods: ['POST'])]
    public function addMention(Request $request, EntityManagerInterface $em): Response
    {
        $this->assertCsrf($request, 'admin-mention-ajouter');
        $name = trim((string) $request->request->get('name'));
        $domain = $em->getRepository(Domain::class)->find((int) $request->request->get('domain'));
        if ($name && $domain) {
            $mention = new Mention();
            $mention->setName($name);
            $mention->setDomain($domain);
            $em->persist($mention);
            $em->flush();
            $this->addFlash('succes', 'Mention ajoutée.');
        }

        return $this->redirectToRoute('admin_domains');
    }

    #[Route('/specialites/ajouter', name: 'admin_specialties_add', methods: ['POST'])]
    public function addSpecialty(Request $request, EntityManagerInterface $em): Response
    {
        $this->assertCsrf($request, 'admin-specialite-ajouter');
        $name = trim((string) $request->request->get('name'));
        $mention = $em->getRepository(Mention::class)->find((int) $request->request->get('mention'));
        if ($name && $mention) {
            $specialty = new Specialty();
            $specialty->setName($name);
            $specialty->setMention($mention);
            $em->persist($specialty);
            $em->flush();
            $this->addFlash('succes', 'Spécialité ajoutée.');
        }

        return $this->redirectToRoute('admin_domains');
    }

    #[Route('/{type}/{id}/supprimer', name: 'admin_classification_delete', methods: ['POST'], requirements: ['type' => 'domaine|mention|specialite'])]
    public function delete(string $type, int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertCsrf($request, 'admin-classification-supprimer-'.$type.'-'.$id);

        $class = match ($type) {
            'domaine' => Domain::class,
            'mention' => Mention::class,
            'specialite' => Specialty::class,
        };
        $entity = $em->getRepository($class)->find($id);

        if ($entity) {
            try {
                $em->remove($entity);
                $em->flush();
                $this->addFlash('succes', 'Élément supprimé.');
            } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
                $this->addFlash('erreur', 'Cet élément est utilisé ailleurs (sous-catégorie ou projet) et ne peut pas être supprimé.');
            }
        }

        return $this->redirectToRoute('admin_domains');
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_csrf_token'))) {
            throw new \Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException();
        }
    }
}
