<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903143523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE analytics_event (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(255) NOT NULL, visitor_hash VARCHAR(64) DEFAULT NULL, metadata VARCHAR(40) DEFAULT NULL, created_at DATETIME NOT NULL, project_id INT NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_9CD0310A166D1F9C (project_id), INDEX IDX_9CD0310AA76ED395 (user_id), INDEX analytics_event_project_type_idx (project_id, type, created_at), INDEX analytics_event_type_created_idx (type, created_at), INDEX analytics_event_dedup_idx (project_id, type, visitor_hash, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE analytics_event ADD CONSTRAINT FK_9CD0310A166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE analytics_event ADD CONSTRAINT FK_9CD0310AA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analytics_event DROP FOREIGN KEY FK_9CD0310A166D1F9C');
        $this->addSql('ALTER TABLE analytics_event DROP FOREIGN KEY FK_9CD0310AA76ED395');
        $this->addSql('DROP TABLE analytics_event');
    }
}
