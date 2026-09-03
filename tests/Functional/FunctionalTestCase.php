<?php

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base commune aux tests fonctionnels : purge complète de la base de test,
 * dans un ordre compatible avec les contraintes de clé étrangère, pour que
 * chaque test parte d'un état propre quel que soit l'ordre d'exécution.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected function purgeDatabase(EntityManagerInterface $em): void
    {
        $em->createQuery('DELETE FROM App\Entity\Rating')->execute();
        $em->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $em->createQuery('DELETE FROM App\Entity\Notification')->execute();
        $em->createQuery('DELETE FROM App\Entity\NotificationPreference')->execute();
        $em->createQuery('DELETE FROM App\Entity\TalentView')->execute();
        $em->createQuery('DELETE FROM App\Entity\RecruiterFavorite')->execute();
        $em->createQuery('DELETE FROM App\Entity\ContactRequest')->execute();
        $em->createQuery('DELETE FROM App\Entity\RecruiterProfile')->execute();
        $em->createQuery('DELETE FROM App\Entity\DefenseValidation')->execute();
        $em->createQuery('DELETE FROM App\Entity\DefenseResult')->execute();
        $em->createQuery('DELETE FROM App\Entity\JuryMember')->execute();
        $em->createQuery('DELETE FROM App\Entity\Defense')->execute();
        $em->createQuery('DELETE FROM App\Entity\ProjectProof')->execute();
        $em->createQuery('DELETE FROM App\Entity\ProjectPhoto')->execute();
        $em->createQuery('DELETE FROM App\Entity\ProjectDocument')->execute();
        $em->createQuery('DELETE FROM App\Entity\ModerationAction')->execute();
        $em->createQuery('DELETE FROM App\Entity\VerificationEvent')->execute();
        $em->createQuery('DELETE FROM App\Entity\VerificationRequest')->execute();
        $em->createQuery('DELETE FROM App\Entity\Report')->execute();
        $em->createQuery('DELETE FROM App\Entity\Sanction')->execute();
        $em->createQuery('DELETE FROM App\Entity\AdminAuditLog')->execute();
        $em->createQuery('DELETE FROM App\Entity\AnalyticsEvent')->execute();
        $em->createQuery('DELETE FROM App\Entity\UserInstitution')->execute();
        $em->createQuery('DELETE FROM App\Entity\InstitutionRequest')->execute();
        $em->createQuery('DELETE FROM App\Entity\Project')->execute();
        $em->createQuery('DELETE FROM App\Entity\User')->execute();
        $em->createQuery('DELETE FROM App\Entity\Specialty')->execute();
        $em->createQuery('DELETE FROM App\Entity\Mention')->execute();
        $em->createQuery('DELETE FROM App\Entity\Domain')->execute();
        $em->createQuery('DELETE FROM App\Entity\Institution')->execute();
        $em->createQuery('DELETE FROM App\Entity\Technology')->execute();
        $em->createQuery('DELETE FROM App\Entity\Skill')->execute();
    }
}
