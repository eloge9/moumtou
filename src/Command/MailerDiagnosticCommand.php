<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Diagnostic du système d'envoi d'e-mail (config → connexion → authentification
 * → envoi → acceptation par le serveur SMTP), utilisable en développement
 * comme en production pour vérifier que MAILER_DSN est correctement chargé
 * et fonctionnel, sans jamais afficher le secret qu'il contient.
 */
#[AsCommand(name: 'app:mailer:diagnose', description: 'Vérifie la configuration SMTP et envoie un e-mail de test réel')]
class MailerDiagnosticCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $mailerDsn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('destinataire', InputArgument::REQUIRED, 'Adresse e-mail qui doit recevoir le message de test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = (string) $input->getArgument('destinataire');

        $io->section('1/4 — Configuration');
        $dsnParts = parse_url($this->mailerDsn);
        if (!$dsnParts || empty($dsnParts['host'])) {
            $io->error('[EMAIL] Configuration SMTP invalide : MAILER_DSN est vide, absent ou mal formé.');

            return Command::FAILURE;
        }
        $io->writeln(sprintf(
            '[EMAIL] Transport : %s — hôte : %s — port : %s — utilisateur : %s',
            $dsnParts['scheme'] ?? '?',
            $dsnParts['host'],
            $dsnParts['port'] ?? '(défaut)',
            isset($dsnParts['user']) ? urldecode($dsnParts['user']) : '(aucun)',
        ));
        if ('null' === ($dsnParts['scheme'] ?? '')) {
            $io->warning('[EMAIL] Transport "null" : aucun e-mail n\'est réellement envoyé dans cette configuration (normal en environnement de test).');
        }

        $io->section('2/4 — Connexion + authentification + envoi');
        $email = (new Email())
            ->from(new Address(isset($dsnParts['user']) ? urldecode($dsnParts['user']) : 'contact@moumtou.com', 'MOUMTOU'))
            ->to($to)
            ->subject('MOUMTOU — Test de diagnostic SMTP')
            ->text(sprintf("Message de diagnostic envoyé le %s.\n\nSi vous recevez ce message, la chaîne complète (connexion, authentification, envoi, acceptation par le serveur SMTP) fonctionne.", (new \DateTimeImmutable())->format('Y-m-d H:i:s')));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $io->error(sprintf('[EMAIL] Échec : %s — %s', $e::class, $e->getMessage()));

            return Command::FAILURE;
        }

        $io->section('3/4 — Résultat');
        $io->success(sprintf('[EMAIL] Message accepté par le serveur SMTP pour %s.', $to));

        $io->section('4/4 — Rappel important');
        $io->note('Un envoi "accepté" par le serveur SMTP ne garantit PAS la livraison finale dans la boîte du destinataire : le message peut encore être filtré comme spam, retardé ou rejeté silencieusement plus loin dans la chaîne (réputation de l\'expéditeur, alignement SPF/DKIM/DMARC, politique anti-spam du fournisseur destinataire). Vérifiez la boîte de réception ET le dossier spam du destinataire pour confirmer la livraison réelle.');

        return Command::SUCCESS;
    }
}
