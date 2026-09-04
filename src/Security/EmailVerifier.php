<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Vérification d'e-mail par URL signée (Symfony\Component\HttpFoundation\UriSigner),
 * sans stockage de jeton en base ni dépendance externe.
 */
class EmailVerifier
{
    private const LIFETIME_SECONDS = 86400; // 24h

    public function __construct(
        private readonly UriSigner $uriSigner,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function generateSignedUrl(User $user): string
    {
        $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', self::LIFETIME_SECONDS))->getTimestamp();

        $url = $this->urlGenerator->generate('app_verify_email', [
            'id' => $user->getId(),
            'expires' => $expiresAt,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->uriSigner->sign($url);
    }

    public function isSignedUrlValid(string $requestUri, int $expires): bool
    {
        if ($expires < (new \DateTimeImmutable())->getTimestamp()) {
            return false;
        }

        return $this->uriSigner->check($requestUri);
    }

    public function sendVerificationEmail(User $user): string
    {
        $signedUrl = $this->generateSignedUrl($user);

        $email = (new TemplatedEmail())
            ->from(new Address('elogegomina@gmail.com', 'MOUMTOU'))
            ->to($user->getEmail())
            ->subject('Confirmez votre adresse e-mail')
            ->htmlTemplate('emails/confirmation.html.twig')
            ->context([
                'firstName' => $user->getFirstName(),
                'signedUrl' => $signedUrl,
            ]);

        $this->mailer->send($email);

        return $signedUrl;
    }
}
