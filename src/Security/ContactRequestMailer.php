<?php

namespace App\Security;

use App\Entity\ContactRequest;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Notifications e-mail des demandes de contact recruteur ↔ talent (cahier
 * des charges — FONCTIONNALITÉ 7 §13/§21), sur le même modèle que
 * {@see SanctionMailer}/{@see JuryInvitationMailer} déjà en place.
 */
class ContactRequestMailer
{
    public function __construct(private readonly MailerInterface $mailer)
    {
    }

    public function notifyTalentOfNewRequest(ContactRequest $contactRequest): void
    {
        $talent = $contactRequest->getTalent();

        $email = (new TemplatedEmail())
            ->from(new Address('contact@moumtou.com', 'MOUMTOU'))
            ->to($talent->getEmail())
            ->subject('Nouvelle demande de contact recruteur sur MOUMTOU')
            ->htmlTemplate('emails/contact_request_received.html.twig')
            ->context(['contactRequest' => $contactRequest, 'talent' => $talent]);

        $this->mailer->send($email);
    }

    public function notifyRecruiterOfDecision(ContactRequest $contactRequest): void
    {
        $recruiter = $contactRequest->getRecruiter();

        $email = (new TemplatedEmail())
            ->from(new Address('contact@moumtou.com', 'MOUMTOU'))
            ->to($recruiter->getEmail())
            ->subject('accepted' === $contactRequest->getStatus()->value ? 'Votre demande de contact a été acceptée' : 'Votre demande de contact a été refusée')
            ->htmlTemplate('emails/contact_request_decided.html.twig')
            ->context(['contactRequest' => $contactRequest, 'recruiter' => $recruiter]);

        $this->mailer->send($email);
    }
}
