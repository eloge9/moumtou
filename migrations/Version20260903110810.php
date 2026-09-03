<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903110810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE admin_audit_log (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(255) NOT NULL, target_type VARCHAR(60) DEFAULT NULL, target_id INT DEFAULT NULL, target_label VARCHAR(180) DEFAULT NULL, details LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, admin_id INT NOT NULL, INDEX admin_audit_log_admin_idx (admin_id), INDEX admin_audit_log_action_idx (action), INDEX admin_audit_log_target_idx (target_type, target_id), INDEX admin_audit_log_created_idx (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE admin_audit_log ADD CONSTRAINT FK_1F16C5C7642B8210 FOREIGN KEY (admin_id) REFERENCES app_user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE admin_audit_log DROP FOREIGN KEY FK_1F16C5C7642B8210');
        $this->addSql('DROP TABLE admin_audit_log');
    }
}
