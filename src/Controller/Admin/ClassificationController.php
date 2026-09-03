<?php

namespace App\Controller\Admin;

use App\Entity\Domain;
use App\Entity\Mention;
use App\Entity\Specialty;
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
#[Route('/admin/classification')]
class ClassificationController extends AbstractController
{
    #[Route('', name: 'admin_domains')]
    public function index(EntityManagerInterface $em): Response
    {
        return $this->render('admin/classification.html.twig', [
            'adminNav' => 'domains',
            'domains' => $em->getRepository(Domain::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/domaines/ajouter', name: 'admin_domains_add', methods: ['POST'])]
    public function addDomain(Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $this->assertCsrf($request, 'admin-domaine-ajouter');
        $name = trim((string) $request->request->get('name'));
        if ($name) {
            $domain = new Domain();
            $domain->setName($name);
            $em->persist($domain);
            $em->flush();
            $auditLogger->log($this->admin(), AdminAuditAction::DOMAIN_CREATED, 'Domain', $domain->getId(), $domain->getName());
            $this->addFlash('succes', 'Domaine ajouté.');
        }

        return $this->redirectToRoute('admin_domains');
    }

    #[Route('/mentions/ajouter', name: 'admin_mentions_add', methods: ['POST'])]
    public function addMention(Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
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
            $auditLogger->log($this->admin(), AdminAuditAction::MENTION_CREATED, 'Mention', $mention->getId(), $mention->getName());
            $this->addFlash('succes', 'Mention ajoutée.');
        }

        return $this->redirectToRoute('admin_domains');
    }

    #[Route('/specialites/ajouter', name: 'admin_specialties_add', methods: ['POST'])]
    public function addSpecialty(Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
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
            $auditLogger->log($this->admin(), AdminAuditAction::SPECIALTY_CREATED, 'Specialty', $specialty->getId(), $specialty->getName());
            $this->addFlash('succes', 'Spécialité ajoutée.');
        }

        return $this->redirectToRoute('admin_domains');
    }

    #[Route('/{type}/{id}/renommer', name: 'admin_classification_rename', methods: ['POST'], requirements: ['type' => 'domaine|mention|specialite'])]
    public function rename(string $type, int $id, Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $this->assertCsrf($request, 'admin-classification-renommer-'.$type.'-'.$id);

        $class = match ($type) {
            'domaine' => Domain::class,
            'mention' => Mention::class,
            'specialite' => Specialty::class,
        };
        /** @var Domain|Mention|Specialty|null $entity */
        $entity = $em->getRepository($class)->find($id);
        $name = trim((string) $request->request->get('name'));

        if ($entity && $name) {
            $oldName = $entity->getName();
            $entity->setName($name);
            $em->flush();

            $auditAction = match ($type) {
                'domaine' => AdminAuditAction::DOMAIN_RENAMED,
                'mention' => AdminAuditAction::MENTION_RENAMED,
                'specialite' => AdminAuditAction::SPECIALTY_RENAMED,
            };
            $auditLogger->log($this->admin(), $auditAction, ucfirst($type), $entity->getId(), $name, \sprintf('Ancien nom : %s', $oldName));
            $this->addFlash('succes', 'Élément renommé.');
        }

        return $this->redirectToRoute('admin_domains');
    }

    #[Route('/{type}/{id}/desactiver', name: 'admin_classification_toggle_active', methods: ['POST'], requirements: ['type' => 'domaine|mention|specialite'])]
    public function toggleActive(string $type, int $id, Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $this->assertCsrf($request, 'admin-classification-desactiver-'.$type.'-'.$id);

        $class = match ($type) {
            'domaine' => Domain::class,
            'mention' => Mention::class,
            'specialite' => Specialty::class,
        };
        /** @var Domain|Mention|Specialty|null $entity */
        $entity = $em->getRepository($class)->find($id);

        if ($entity) {
            $entity->setActive(!$entity->isActive());
            $em->flush();

            $auditAction = match (true) {
                'domaine' === $type && $entity->isActive() => AdminAuditAction::DOMAIN_REACTIVATED,
                'domaine' === $type => AdminAuditAction::DOMAIN_DEACTIVATED,
                'mention' === $type && $entity->isActive() => AdminAuditAction::MENTION_REACTIVATED,
                'mention' === $type => AdminAuditAction::MENTION_DEACTIVATED,
                'specialite' === $type && $entity->isActive() => AdminAuditAction::SPECIALTY_REACTIVATED,
                default => AdminAuditAction::SPECIALTY_DEACTIVATED,
            };
            $auditLogger->log($this->admin(), $auditAction, ucfirst($type), $entity->getId(), $entity->getName());

            $this->addFlash('succes', $entity->isActive()
                ? 'Élément réactivé : il redevient sélectionnable pour un nouveau projet ou profil.'
                : 'Élément désactivé : il n\'apparaîtra plus dans les listes de sélection, mais reste affiché là où il est déjà utilisé.');
        }

        return $this->redirectToRoute('admin_domains');
    }

    #[Route('/{type}/{id}/supprimer', name: 'admin_classification_delete', methods: ['POST'], requirements: ['type' => 'domaine|mention|specialite'])]
    public function delete(string $type, int $id, Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $this->assertCsrf($request, 'admin-classification-supprimer-'.$type.'-'.$id);

        $class = match ($type) {
            'domaine' => Domain::class,
            'mention' => Mention::class,
            'specialite' => Specialty::class,
        };
        $entity = $em->getRepository($class)->find($id);

        if ($entity) {
            $entityId = $entity->getId();
            $entityName = $entity->getName();
            try {
                $em->remove($entity);
                $em->flush();

                $auditAction = match ($type) {
                    'domaine' => AdminAuditAction::DOMAIN_DELETED,
                    'mention' => AdminAuditAction::MENTION_DELETED,
                    'specialite' => AdminAuditAction::SPECIALTY_DELETED,
                };
                $auditLogger->log($this->admin(), $auditAction, ucfirst($type), $entityId, $entityName);
                $this->addFlash('succes', 'Élément supprimé.');
            } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
                $this->addFlash('erreur', 'Cet élément est utilisé ailleurs (sous-catégorie ou projet) et ne peut pas être supprimé.');
            }
        }

        return $this->redirectToRoute('admin_domains');
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
