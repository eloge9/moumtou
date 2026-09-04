<?php

namespace App\Security;

use App\Entity\Defense;
use App\Enum\JuryStatus;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Rappel par e-mail avant une soutenance annoncée (cahier des charges §28)
 * — envoyé aux membres du jury ayant confirmé leur participation, et au
 * candidat. Réutilise le mailer Symfony déjà configuré (aucun système
 * d'e-mail parallèle).
 */
class DefenseReminderMailer
{
    public function __construct(private readonly MailerInterface $mailer)
    {
    }

    public function sendReminders(Defense $defense): void
    {
        $project = $defense->getProject();

        foreach ($defense->getJuryMembers() as $juryMember) {
            if (JuryStatus::CONFIRME !== $juryMember->getStatus()) {
                continue;
            }

            $email = (new TemplatedEmail())
                ->from(new Address('elogegomina@gmail.com', 'MOUMTOU'))
                ->to($juryMember->getEmail())
                ->subject('Rappel — Soutenance de '.$project->getOwner()->getFullName().' bientôt')
                ->htmlTemplate('emails/defense_reminder.html.twig')
                ->context(['recipientName' => $juryMember->getFirstName().' '.$juryMember->getLastName(), 'project' => $project, 'defense' => $defense]);

            $this->mailer->send($email);
        }

        $owner = $project->getOwner();
        $email = (new TemplatedEmail())
            ->from(new Address('elogegomina@gmail.com', 'MOUMTOU'))
            ->to($owner->getEmail())
            ->subject('Rappel — Votre soutenance approche')
            ->htmlTemplate('emails/defense_reminder.html.twig')
            ->context(['recipientName' => $owner->getFirstName(), 'project' => $project, 'defense' => $defense]);

        $this->mailer->send($email);
    }
}
