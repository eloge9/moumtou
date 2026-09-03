<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * E-mail générique pour les notifications qui n'ont pas déjà leur propre
 * mailer dédié (cahier des charges — FONCTIONNALITÉ 8 §23) : les
 * événements qui ont déjà un mailer riche (demande de contact, invitation
 * jury, sanction) continuent à l'utiliser — celui-ci ne fait pas doublon,
 * il couvre les nouveaux cas (projet vérifié, soutenance vérifiée,
 * commentaire, évaluation…).
 */
class NotificationMailer
{
    public function __construct(private readonly MailerInterface $mailer)
    {
    }

    public function send(User $recipient, string $title, string $message, ?string $actionUrl): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('contact@moumtou.com', 'MOUMTOU'))
            ->to($recipient->getEmail())
            ->subject($title.' — MOUMTOU')
            ->htmlTemplate('emails/notification.html.twig')
            ->context([
                'notificationTitle' => $title,
                'notificationMessage' => $message,
                'actionUrl' => $actionUrl,
            ]);

        $this->mailer->send($email);
    }
}
