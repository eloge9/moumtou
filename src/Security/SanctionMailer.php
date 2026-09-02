<?php

namespace App\Security;

use App\Entity\Sanction;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Notifie un utilisateur d'un avertissement, d'une suspension ou d'un
 * bannissement (cahier des charges §34 : "notifications par e-mail...
 * bannissement, sécurité").
 */
class SanctionMailer
{
    public function __construct(private readonly MailerInterface $mailer)
    {
    }

    public function notify(Sanction $sanction): void
    {
        $user = $sanction->getUser();

        $email = (new TemplatedEmail())
            ->from(new Address('contact@moumtou.com', 'MOUMTOU'))
            ->to($user->getEmail())
            ->subject(match ($sanction->getType()->value) {
                'avertissement' => 'Avertissement concernant votre compte MOUMTOU',
                'suspension' => 'Votre compte MOUMTOU a été suspendu',
                default => 'Votre compte MOUMTOU a été banni',
            })
            ->htmlTemplate('emails/sanction.html.twig')
            ->context(['sanction' => $sanction, 'user' => $user]);

        $this->mailer->send($email);
    }
}
