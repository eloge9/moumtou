<?php

namespace App\Command;

use App\Entity\Defense;
use App\Enum\DefenseStatus;
use App\Security\DefenseReminderMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Envoie un rappel avant chaque soutenance annoncée qui a lieu bientôt
 * (cahier des charges §28/§29). Idempotent : {@see Defense::$reminderSentAt}
 * empêche tout envoi en double, donc cette commande peut être exécutée
 * aussi souvent que nécessaire (ex. via une tâche planifiée quotidienne —
 * cron sous Linux, Planificateur de tâches Windows, ou tâche planifiée
 * XAMPP — aucune n'est configurée automatiquement par ce projet).
 *
 * Exemple de tâche planifiée (à ajouter manuellement, hors du dépôt) :
 *   0 8 * * *  php /chemin/vers/moumtou/bin/console app:defense:send-reminders
 */
#[AsCommand(
    name: 'app:defense:send-reminders',
    description: 'Envoie un rappel par e-mail pour les soutenances annoncées qui ont lieu bientôt.',
)]
class SendDefenseRemindersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DefenseReminderMailer $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('within-hours', null, InputOption::VALUE_REQUIRED, 'Envoyer un rappel pour les soutenances ayant lieu dans les N prochaines heures.', 48)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $withinHours = (int) $input->getOption('within-hours');

        $now = new \DateTimeImmutable();
        $limit = $now->modify(sprintf('+%d hours', $withinHours));

        $defenses = $this->em->getRepository(Defense::class)->createQueryBuilder('d')
            ->andWhere('d.status = :status')->setParameter('status', DefenseStatus::ANNONCEE)
            ->andWhere('d.reminderSentAt IS NULL')
            ->andWhere('d.date <= :limit')->setParameter('limit', $limit)
            ->andWhere('d.date >= :today')->setParameter('today', $now->setTime(0, 0))
            ->getQuery()->getResult();

        if (!$defenses) {
            $io->info('Aucune soutenance à rappeler pour le moment.');

            return Command::SUCCESS;
        }

        foreach ($defenses as $defense) {
            $this->mailer->sendReminders($defense);
            $defense->setReminderSentAt($now);
            $io->writeln(sprintf('Rappel envoyé — %s (%s)', $defense->getProject()->getName(), $defense->getDate()->format('d/m/Y')));
        }
        $this->em->flush();

        $io->success(sprintf('%d rappel(s) envoyé(s).', \count($defenses)));

        return Command::SUCCESS;
    }
}
