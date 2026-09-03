<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903124731 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE project_document (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(255) NOT NULL, title VARCHAR(180) DEFAULT NULL, path VARCHAR(255) NOT NULL, original_filename VARCHAR(180) NOT NULL, size INT NOT NULL, uploaded_at DATETIME NOT NULL, project_id INT NOT NULL, INDEX project_document_project_idx (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE project_document ADD CONSTRAINT FK_E52701AD166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('CREATE INDEX project_photo_project_position_idx ON project_photo (project_id, position)');
        $this->addSql('ALTER TABLE project_proof ADD title VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project_document DROP FOREIGN KEY FK_E52701AD166D1F9C');
        $this->addSql('DROP TABLE project_document');
        $this->addSql('DROP INDEX project_photo_project_position_idx ON project_photo');
        $this->addSql('ALTER TABLE project_proof DROP title');
    }
}
