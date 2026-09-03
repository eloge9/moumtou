<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903101158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications & centre de notifications (FONCTIONNALITÉ 8) : notification interne et préférences par catégorie.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(255) NOT NULL, title VARCHAR(180) NOT NULL, message LONGTEXT NOT NULL, is_read TINYINT NOT NULL, action_url VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, recipient_id INT NOT NULL, INDEX IDX_BF5476CAE92F8F78 (recipient_id), INDEX notification_recipient_read_idx (recipient_id, is_read), INDEX notification_recipient_created_idx (recipient_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification_preference (id INT AUTO_INCREMENT NOT NULL, category VARCHAR(255) NOT NULL, in_app_enabled TINYINT NOT NULL, email_enabled TINYINT NOT NULL, user_id INT NOT NULL, INDEX IDX_A61B1571A76ED395 (user_id), UNIQUE INDEX notification_preference_unique (user_id, category), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAE92F8F78 FOREIGN KEY (recipient_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE notification_preference ADD CONSTRAINT FK_A61B1571A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAE92F8F78');
        $this->addSql('ALTER TABLE notification_preference DROP FOREIGN KEY FK_A61B1571A76ED395');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE notification_preference');
    }
}
