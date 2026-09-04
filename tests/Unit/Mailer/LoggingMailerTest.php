<?php

namespace App\Tests\Unit\Mailer;

use App\Mailer\LoggingMailer;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Vérifie que le décorateur de journalisation des e-mails (cahier :
 * "ne plus masquer les erreurs SMTP") reflète fidèlement ce que fait
 * réellement le transport sous-jacent : succès journalisé comme succès,
 * échec journalisé comme échec ET propagé (jamais avalé silencieusement).
 */
class LoggingMailerTest extends TestCase
{
    private function makeLogger(): array
    {
        $handler = new TestHandler();
        $logger = new Logger('app', [$handler]);

        return [$logger, $handler];
    }

    public function testSuccessfulSendIsLoggedAsAcceptedByTheServer(): void
    {
        [$logger, $handler] = $this->makeLogger();
        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::once())->method('send');

        $mailer = new LoggingMailer($inner, $logger);
        $email = (new Email())->from('a@example.com')->to('b@example.com')->subject('Sujet de test')->text('corps');

        $mailer->send($email);

        self::assertTrue($handler->hasInfoThatContains('[EMAIL] Envoi vers b@example.com'));
        self::assertTrue($handler->hasInfoThatContains('[EMAIL] Accepté par le serveur SMTP'));
        self::assertFalse($handler->hasErrorRecords(), 'Un envoi réussi ne doit jamais produire de log ERROR.');
    }

    public function testFailedSendIsLoggedAsAnErrorAndTheExceptionStillPropagates(): void
    {
        [$logger, $handler] = $this->makeLogger();
        $inner = $this->createMock(MailerInterface::class);
        $inner->method('send')->willThrowException(new TransportException('Expected response code "235" but got code "535", with message "535 5.7.8 Username and Password not accepted."'));

        $mailer = new LoggingMailer($inner, $logger);
        $email = (new Email())->from('a@example.com')->to('b@example.com')->subject('Sujet de test')->text('corps');

        $this->expectException(TransportException::class);

        try {
            $mailer->send($email);
        } finally {
            self::assertTrue($handler->hasErrorThatContains('authentification refusée'), 'L\'échec doit être journalisé en ERROR avec une catégorie lisible.');
            self::assertFalse($handler->hasInfoThatContains('[EMAIL] Accepté'), 'Un envoi en échec ne doit jamais être journalisé comme accepté.');
        }
    }

    public function testFailedSendNeverLogsRawSmtpDebugConversation(): void
    {
        [$logger, $handler] = $this->makeLogger();
        $inner = $this->createMock(MailerInterface::class);
        $exception = new TransportException('Connection could not be established with host smtp.gmail.com');
        $exception->appendDebug("AUTH LOGIN\r\n334 VXNlcm5hbWU6\r\nZWxvZ2Vnb21pbmFAZ21haWwuY29t\r\n334 UGFzc3dvcmQ6\r\nc3ZjcyBiZGZuIHJqc2IgZWttaA==\r\n");
        $inner->method('send')->willThrowException($exception);

        $mailer = new LoggingMailer($inner, $logger);
        $email = (new Email())->from('a@example.com')->to('b@example.com')->subject('Sujet de test')->text('corps');

        try {
            $mailer->send($email);
            self::fail('Une TransportException était attendue.');
        } catch (TransportException) {
            foreach ($handler->getRecords() as $record) {
                self::assertStringNotContainsString('VXNlcm5hbWU', (string) $record->message, 'Le contenu base64 de la conversation SMTP (identifiants) ne doit jamais être journalisé.');
                self::assertArrayNotHasKey('mail_debug', $record->context);
            }
        }
    }
}
