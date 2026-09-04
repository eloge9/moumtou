<?php

namespace App\Mailer;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Décore le mailer Symfony pour journaliser chaque envoi de bout en bout
 * (destinataire, sujet, résultat), sans jamais dupliquer la configuration ni
 * créer un second système d'envoi : le transport réel reste celui configuré
 * via MAILER_DSN, cette classe ne fait qu'observer chaque appel.
 *
 * Ne journalise jamais le contenu de l'e-mail, le mot de passe SMTP ni le
 * détail de la conversation SMTP (qui peut contenir les identifiants encodés
 * en base64 côté Symfony\Component\Mailer\Exception\TransportException::getDebug()) —
 * uniquement la classe d'exception et son message court.
 */
class LoggingMailer implements MailerInterface
{
    public function __construct(
        private readonly MailerInterface $decoratedMailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $recipients = method_exists($message, 'getTo')
            ? implode(', ', array_map(static fn ($address) => $address->getAddress(), $message->getTo()))
            : 'destinataire(s) inconnu(s)';
        $subject = method_exists($message, 'getSubject') ? (string) $message->getSubject() : '(sans sujet)';

        $this->logger->info(sprintf('[EMAIL] Envoi vers %s — "%s"', $recipients, $subject), [
            'mail_recipients' => $recipients,
            'mail_subject' => $subject,
        ]);

        try {
            $this->decoratedMailer->send($message, $envelope);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error(sprintf('[EMAIL] Échec de l\'envoi vers %s : %s — %s', $recipients, $this->classifyFailure($e), $e->getMessage()), [
                'mail_recipients' => $recipients,
                'mail_subject' => $subject,
                'mail_failure_category' => $this->classifyFailure($e),
                'exception_class' => $e::class,
            ]);

            throw $e;
        }

        $this->logger->info(sprintf('[EMAIL] Accepté par le serveur SMTP pour %s (ne garantit pas la livraison finale — spam/SPF/DKIM/DMARC/réputation du destinataire hors de notre contrôle)', $recipients), [
            'mail_recipients' => $recipients,
            'mail_subject' => $subject,
        ]);
    }

    /**
     * Classification lisible de l'échec, à partir du seul message court de
     * l'exception (jamais de la conversation SMTP brute) — sert à distinguer
     * rapidement, dans les journaux, une mauvaise configuration d'un
     * problème d'authentification ou de connexion.
     */
    private function classifyFailure(TransportExceptionInterface $e): string
    {
        $message = strtolower($e->getMessage());

        return match (true) {
            str_contains($message, 'could not connect') || str_contains($message, 'connection refused') || str_contains($message, 'connection could not be established') => 'serveur SMTP inaccessible',
            str_contains($message, 'authenticat') || str_contains($message, '535') => 'authentification refusée (identifiant/mot de passe)',
            str_contains($message, 'tls') || str_contains($message, 'ssl') || str_contains($message, 'starttls') => 'problème TLS/SSL',
            str_contains($message, '550') || str_contains($message, 'not authorized') || str_contains($message, 'relay') => 'expéditeur non autorisé ou rejeté par le serveur',
            default => 'erreur du fournisseur SMTP',
        };
    }
}
