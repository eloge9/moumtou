<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Réinitialisation de mot de passe par URL signée (cahier des charges §5.3),
 * même mécanisme que EmailVerifier (pas de jeton stocké en base).
 */
class PasswordResetMailer
{
    private const LIFETIME_SECONDS = 3600; // 1h

    public function __construct(
        private readonly UriSigner $uriSigner,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function generateSignedUrl(User $user): string
    {
        $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', self::LIFETIME_SECONDS))->getTimestamp();

        $url = $this->urlGenerator->generate('app_reset_password', [
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

    public function sendResetEmail(User $user): string
    {
        $signedUrl = $this->generateSignedUrl($user);

        $email = (new TemplatedEmail())
            ->from(new Address('contact@moumtou.com', 'MOUMTOU'))
            ->to($user->getEmail())
            ->subject('Réinitialisation de votre mot de passe')
            ->htmlTemplate('emails/password_reset.html.twig')
            ->context(['firstName' => $user->getFirstName(), 'signedUrl' => $signedUrl]);

        $this->mailer->send($email);

        return $signedUrl;
    }
}
