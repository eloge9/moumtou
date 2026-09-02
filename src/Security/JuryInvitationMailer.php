<?php

namespace App\Security;

use App\Entity\JuryMember;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Invitation d'un membre du jury par URL signée (cahier des charges §15-16),
 * sans nécessiter de compte MOUMTOU pour le juré.
 */
class JuryInvitationMailer
{
    private const LIFETIME_SECONDS = 60 * 60 * 24 * 30; // 30 jours

    public function __construct(
        private readonly UriSigner $uriSigner,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function generateSignedUrl(JuryMember $juryMember): string
    {
        return $this->sign($juryMember, null);
    }

    /**
     * URL signée incluant déjà la décision : appender un paramètre à une URL
     * signée après coup casserait la signature (elle porte sur tous les
     * paramètres), donc chaque bouton confirmer/refuser a sa propre URL.
     */
    public function generateDecisionUrl(JuryMember $juryMember, string $decision): string
    {
        return $this->sign($juryMember, $decision);
    }

    private function sign(JuryMember $juryMember, ?string $decision): string
    {
        $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', self::LIFETIME_SECONDS))->getTimestamp();

        $params = ['id' => $juryMember->getId(), 'expires' => $expiresAt];
        if ($decision) {
            $params['decision'] = $decision;
        }

        $url = $this->urlGenerator->generate('app_jury_confirm', $params, UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->uriSigner->sign($url);
    }

    public function isSignedUrlValid(string $requestUri, int $expires): bool
    {
        if ($expires < (new \DateTimeImmutable())->getTimestamp()) {
            return false;
        }

        return $this->uriSigner->check($requestUri);
    }

    public function sendInvitation(JuryMember $juryMember): string
    {
        $signedUrl = $this->generateSignedUrl($juryMember);
        $defense = $juryMember->getDefense();
        $project = $defense->getProject();

        $email = (new TemplatedEmail())
            ->from(new Address('contact@moumtou.com', 'MOUMTOU'))
            ->to($juryMember->getEmail())
            ->subject('Invitation à confirmer une soutenance')
            ->htmlTemplate('emails/jury_invitation.html.twig')
            ->context([
                'juryMember' => $juryMember,
                'project' => $project,
                'defense' => $defense,
                'signedUrl' => $signedUrl,
            ]);

        $this->mailer->send($email);

        return $signedUrl;
    }
}
