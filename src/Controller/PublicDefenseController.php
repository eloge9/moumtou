<?php

namespace App\Controller;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Service\YoutubeUrlExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Page publique dédiée d'une soutenance (cahier des charges §4/§5) : une
 * URL propre et partageable, distincte de la page projet, réutilisant les
 * mêmes données (Project + Defense) — pas de nouveau stockage. Le slug
 * réutilise celui, déjà unique, de {@see Project}.
 */
class PublicDefenseController extends AbstractController
{
    #[Route('/soutenances/{slug}', name: 'app_defense_show', methods: ['GET'])]
    public function show(string $slug, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, YoutubeUrlExtractor $youtubeUrlExtractor): Response
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        $defense = $project?->getDefense();

        if (!$project || !$defense) {
            throw $this->createNotFoundException('Cette soutenance est introuvable.');
        }

        $isOwnerOrAdmin = $this->getUser() && ($project->getOwner() === $this->getUser() || $this->isGranted('ROLE_ADMIN'));
        $isPublic = \in_array($project->getStatus(), ProjectRepository::PUBLIC_STATUSES, true);
        if (!$isPublic && !$isOwnerOrAdmin) {
            throw $this->createNotFoundException('Cette soutenance n\'est pas encore publiée.');
        }

        $publicUrl = $urlGenerator->generate('app_defense_show', ['slug' => $slug], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('defense/show.html.twig', [
            'project' => $project,
            'defense' => $defense,
            'result' => $defense->getAcademicResult(),
            'publicUrl' => $publicUrl,
            'calendarUrl' => $urlGenerator->generate('app_defense_calendar', ['slug' => $slug]),
            'youtubeVideoId' => $youtubeUrlExtractor->extractVideoId($project),
        ]);
    }

    #[Route('/soutenances/{slug}/calendrier.ics', name: 'app_defense_calendar', methods: ['GET'])]
    public function calendar(string $slug, EntityManagerInterface $em): Response
    {
        $project = $em->getRepository(Project::class)->findOneBy(['slug' => $slug]);
        $defense = $project?->getDefense();
        if (!$project || !$defense || !\in_array($project->getStatus(), ProjectRepository::PUBLIC_STATUSES, true)) {
            throw $this->createNotFoundException();
        }

        // Solution simple sans dépendance externe (cahier des charges §29) :
        // un fichier .ics standard, compatible avec tous les calendriers usuels.
        [$hour, $minute] = array_pad(explode(':', $defense->getTime() ?? '00:00'), 2, '00');
        $start = $defense->getDate()->setTime((int) $hour, (int) $minute);
        $end = $start->modify('+2 hours');
        $uid = sprintf('defense-%d@moumtou', $defense->getId());

        $escape = fn (string $text): string => str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], $text);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MOUMTOU//Soutenances//FR',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.(new \DateTimeImmutable())->format('Ymd\THis\Z'),
            'DTSTART:'.$start->format('Ymd\THis'),
            'DTEND:'.$end->format('Ymd\THis'),
            'SUMMARY:'.$escape('Soutenance — '.$project->getName()),
            'LOCATION:'.$escape((string) $defense->getPlace()),
            'DESCRIPTION:'.$escape(sprintf('Soutenance de %s. %s', $project->getOwner()->getFullName(), $project->getShortDescription() ?? '')),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return new Response(implode("\r\n", $lines), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="soutenance-'.$slug.'.ics"',
        ]);
    }
}
