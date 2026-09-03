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
 *
 * Cahier des charges — FONCTIONNALITÉ 15 §27 : "invalider le token après
 * utilisation". Un lien signé sans état en base ne peut pas être marqué
 * "consommé" directement ; le lien embarque donc une empreinte du hash de
 * mot de passe *au moment de l'envoi* (paramètre `pwv`). Après un premier
 * usage, le mot de passe change et cette empreinte ne correspond plus à
 * l'utilisateur en base : toute réutilisation du même lien est rejetée,
 * sans avoir besoin d'une table dédiée.
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
            'pwv' => $this->passwordFingerprint($user),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->uriSigner->sign($url);
    }

    public function isSignedUrlValid(string $requestUri, int $expires, User $user, string $pwv): bool
    {
        if ($expires < (new \DateTimeImmutable())->getTimestamp()) {
            return false;
        }

        if (!hash_equals($this->passwordFingerprint($user), $pwv)) {
            return false;
        }

        return $this->uriSigner->check($requestUri);
    }

    /**
     * Empreinte non réversible du hash de mot de passe courant — jamais le
     * hash lui-même, pour ne pas exposer de matière cryptographique dans un
     * lien envoyé par e-mail (cahier §26).
     */
    private function passwordFingerprint(User $user): string
    {
        return substr(hash('sha256', (string) $user->getPassword()), 0, 16);
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
