<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260902163657 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE moderation_action (id INT AUTO_INCREMENT NOT NULL, target_type VARCHAR(255) NOT NULL, target_id INT NOT NULL, action_type VARCHAR(255) NOT NULL, reason LONGTEXT NOT NULL, created_at DATETIME NOT NULL, report_id INT DEFAULT NULL, admin_id INT NOT NULL, INDEX IDX_B05D81284BD2A4C0 (report_id), INDEX IDX_B05D8128642B8210 (admin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE report (id INT AUTO_INCREMENT NOT NULL, target_type VARCHAR(255) NOT NULL, target_id INT NOT NULL, reason VARCHAR(255) NOT NULL, details LONGTEXT DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, reporter_id INT NOT NULL, INDEX IDX_C42F7784E1CFE6F5 (reporter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sanction (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(255) NOT NULL, reason LONGTEXT NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME DEFAULT NULL, user_id INT NOT NULL, admin_id INT NOT NULL, INDEX IDX_6D6491AFA76ED395 (user_id), INDEX IDX_6D6491AF642B8210 (admin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE moderation_action ADD CONSTRAINT FK_B05D81284BD2A4C0 FOREIGN KEY (report_id) REFERENCES report (id)');
        $this->addSql('ALTER TABLE moderation_action ADD CONSTRAINT FK_B05D8128642B8210 FOREIGN KEY (admin_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE report ADD CONSTRAINT FK_C42F7784E1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE sanction ADD CONSTRAINT FK_6D6491AFA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE sanction ADD CONSTRAINT FK_6D6491AF642B8210 FOREIGN KEY (admin_id) REFERENCES app_user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE moderation_action DROP FOREIGN KEY FK_B05D81284BD2A4C0');
        $this->addSql('ALTER TABLE moderation_action DROP FOREIGN KEY FK_B05D8128642B8210');
        $this->addSql('ALTER TABLE report DROP FOREIGN KEY FK_C42F7784E1CFE6F5');
        $this->addSql('ALTER TABLE sanction DROP FOREIGN KEY FK_6D6491AFA76ED395');
        $this->addSql('ALTER TABLE sanction DROP FOREIGN KEY FK_6D6491AF642B8210');
        $this->addSql('DROP TABLE moderation_action');
        $this->addSql('DROP TABLE report');
        $this->addSql('DROP TABLE sanction');
    }
}
