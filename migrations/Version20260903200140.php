<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903200140 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FONCTIONNALITÉ 18 — Journal technique des erreurs serveur (ErrorLog), distinct de AdminAuditLog. Nouvelle table uniquement.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE error_log (id INT AUTO_INCREMENT NOT NULL, request_id VARCHAR(32) NOT NULL, level VARCHAR(20) NOT NULL, status_code INT NOT NULL, method VARCHAR(10) NOT NULL, path VARCHAR(255) NOT NULL, exception_class VARCHAR(255) NOT NULL, message LONGTEXT NOT NULL, created_at DATETIME NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_FCDF27A9A76ED395 (user_id), INDEX error_log_created_at_idx (created_at), INDEX error_log_status_code_idx (status_code), INDEX error_log_path_idx (path), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE error_log ADD CONSTRAINT FK_FCDF27A9A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE error_log DROP FOREIGN KEY FK_FCDF27A9A76ED395');
        $this->addSql('DROP TABLE error_log');
    }
}
