<?php

namespace App\Command;

use App\Entity\Defense;
use App\Enum\DefenseStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fait automatiquement passer une soutenance « annoncée » à « réalisée »
 * dès que sa date est passée — le candidat n'a plus besoin de cliquer
 * manuellement « Marquer comme réalisée » (ce bouton reste disponible pour
 * une confirmation anticipée). Idempotent (ne touche qu'aux soutenances
 * encore ANNONCEE dont la date est strictement passée), peut donc être
 * exécutée aussi souvent que nécessaire.
 *
 * Exemple de tâche planifiée (à ajouter manuellement, hors du dépôt, comme
 * pour app:defense:send-reminders — voir docs/backup-restore.md §7) :
 *   30 0 * * *  php /chemin/vers/moumtou/bin/console app:defense:mark-past-realized
 *
 * En complément, {@see \App\Controller\DefenseController::manage()} effectue
 * la même vérification à chaque affichage de « Ma soutenance » par son
 * propriétaire, pour un effet immédiat même sans tâche planifiée configurée
 * (environnement de développement notamment).
 */
#[AsCommand(
    name: 'app:defense:mark-past-realized',
    description: 'Fait passer à "réalisée" toute soutenance annoncée dont la date est passée.',
)]
class MarkPastDefensesRealizedCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $defenses = $this->em->getRepository(Defense::class)->createQueryBuilder('d')
            ->andWhere('d.status = :status')->setParameter('status', DefenseStatus::ANNONCEE)
            ->andWhere('d.date < :today')->setParameter('today', (new \DateTimeImmutable())->setTime(0, 0))
            ->getQuery()->getResult();

        if (!$defenses) {
            $io->info('Aucune soutenance passée à faire évoluer pour le moment.');

            return Command::SUCCESS;
        }

        foreach ($defenses as $defense) {
            $defense->setStatus(DefenseStatus::REALISEE);
            $io->writeln(sprintf('Passée à "réalisée" — %s (%s)', $defense->getProject()->getName(), $defense->getDate()->format('d/m/Y')));
        }
        $this->em->flush();

        $io->success(sprintf('%d soutenance(s) mise(s) à jour.', \count($defenses)));

        return Command::SUCCESS;
    }
}
