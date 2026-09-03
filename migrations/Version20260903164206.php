<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903164206 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FONCTIONNALITÉ 14 — Système de vérification et de certification (VerificationRequest/VerificationEvent + champs vérifiés sur project/app_user/defense).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE verification_event (id INT AUTO_INCREMENT NOT NULL, previous_status VARCHAR(255) DEFAULT NULL, new_status VARCHAR(255) NOT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, request_id INT NOT NULL, actor_id INT NOT NULL, INDEX IDX_A555ABF710DAF24A (actor_id), INDEX verification_event_request_idx (request_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE verification_request (id INT AUTO_INCREMENT NOT NULL, target_type VARCHAR(255) NOT NULL, target_id INT NOT NULL, status VARCHAR(255) NOT NULL, reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, decided_at DATETIME DEFAULT NULL, requester_id INT NOT NULL, reviewer_id INT DEFAULT NULL, INDEX IDX_20FDDF4EED442CF4 (requester_id), INDEX IDX_20FDDF4E70574616 (reviewer_id), INDEX verification_request_status_idx (status), INDEX verification_request_target_idx (target_type, target_id), INDEX verification_request_created_idx (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE verification_event ADD CONSTRAINT FK_A555ABF7427EB8A5 FOREIGN KEY (request_id) REFERENCES verification_request (id)');
        $this->addSql('ALTER TABLE verification_event ADD CONSTRAINT FK_A555ABF710DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE verification_request ADD CONSTRAINT FK_20FDDF4EED442CF4 FOREIGN KEY (requester_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE verification_request ADD CONSTRAINT FK_20FDDF4E70574616 FOREIGN KEY (reviewer_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE app_user ADD profile_verified TINYINT NOT NULL DEFAULT 0, ADD profile_verified_at DATETIME DEFAULT NULL, ADD profile_verified_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E9F2BE7660 FOREIGN KEY (profile_verified_by_id) REFERENCES app_user (id)');
        $this->addSql('CREATE INDEX IDX_88BDF3E9F2BE7660 ON app_user (profile_verified_by_id)');
        $this->addSql('ALTER TABLE defense ADD verified_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE project ADD verified_at DATETIME DEFAULT NULL, ADD verified_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE69F4B775 FOREIGN KEY (verified_by_id) REFERENCES app_user (id)');
        $this->addSql('CREATE INDEX IDX_2FB3D0EE69F4B775 ON project (verified_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE verification_event DROP FOREIGN KEY FK_A555ABF7427EB8A5');
        $this->addSql('ALTER TABLE verification_event DROP FOREIGN KEY FK_A555ABF710DAF24A');
        $this->addSql('ALTER TABLE verification_request DROP FOREIGN KEY FK_20FDDF4EED442CF4');
        $this->addSql('ALTER TABLE verification_request DROP FOREIGN KEY FK_20FDDF4E70574616');
        $this->addSql('DROP TABLE verification_event');
        $this->addSql('DROP TABLE verification_request');
        $this->addSql('ALTER TABLE app_user DROP FOREIGN KEY FK_88BDF3E9F2BE7660');
        $this->addSql('DROP INDEX IDX_88BDF3E9F2BE7660 ON app_user');
        $this->addSql('ALTER TABLE app_user DROP profile_verified, DROP profile_verified_at, DROP profile_verified_by_id');
        $this->addSql('ALTER TABLE defense DROP verified_at');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE69F4B775');
        $this->addSql('DROP INDEX IDX_2FB3D0EE69F4B775 ON project');
        $this->addSql('ALTER TABLE project DROP verified_at, DROP verified_by_id');
    }
}
