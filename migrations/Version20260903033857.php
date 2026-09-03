<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903033857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Espace recruteur (FONCTIONNALITÉ 7) : profil recruteur, favoris, demandes de contact, historique de consultation.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE contact_request (id INT AUTO_INCREMENT NOT NULL, message LONGTEXT NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, responded_at DATETIME DEFAULT NULL, recruiter_id INT NOT NULL, talent_id INT NOT NULL, INDEX IDX_A1B8AE1E156BE243 (recruiter_id), INDEX IDX_A1B8AE1E18777CEF (talent_id), INDEX contact_request_talent_status_idx (talent_id, status), INDEX contact_request_recruiter_status_idx (recruiter_id, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE recruiter_favorite (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, recruiter_id INT NOT NULL, talent_id INT NOT NULL, INDEX IDX_216ADE91156BE243 (recruiter_id), INDEX IDX_216ADE9118777CEF (talent_id), UNIQUE INDEX recruiter_favorite_unique (recruiter_id, talent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE recruiter_profile (id INT AUTO_INCREMENT NOT NULL, organization_name VARCHAR(180) NOT NULL, logo VARCHAR(255) DEFAULT NULL, sector VARCHAR(120) DEFAULT NULL, country VARCHAR(100) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, description LONGTEXT DEFAULT NULL, website_url VARCHAR(255) DEFAULT NULL, linkedin_url VARCHAR(255) DEFAULT NULL, professional_email VARCHAR(180) DEFAULT NULL, professional_phone VARCHAR(30) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_4740AFE9A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE talent_view (id INT AUTO_INCREMENT NOT NULL, viewed_at DATETIME NOT NULL, recruiter_id INT NOT NULL, talent_id INT NOT NULL, INDEX IDX_17C5775B156BE243 (recruiter_id), INDEX IDX_17C5775B18777CEF (talent_id), INDEX talent_view_recruiter_idx (recruiter_id, viewed_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE contact_request ADD CONSTRAINT FK_A1B8AE1E156BE243 FOREIGN KEY (recruiter_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE contact_request ADD CONSTRAINT FK_A1B8AE1E18777CEF FOREIGN KEY (talent_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE recruiter_favorite ADD CONSTRAINT FK_216ADE91156BE243 FOREIGN KEY (recruiter_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE recruiter_favorite ADD CONSTRAINT FK_216ADE9118777CEF FOREIGN KEY (talent_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE recruiter_profile ADD CONSTRAINT FK_4740AFE9A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE talent_view ADD CONSTRAINT FK_17C5775B156BE243 FOREIGN KEY (recruiter_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE talent_view ADD CONSTRAINT FK_17C5775B18777CEF FOREIGN KEY (talent_id) REFERENCES app_user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contact_request DROP FOREIGN KEY FK_A1B8AE1E156BE243');
        $this->addSql('ALTER TABLE contact_request DROP FOREIGN KEY FK_A1B8AE1E18777CEF');
        $this->addSql('ALTER TABLE recruiter_favorite DROP FOREIGN KEY FK_216ADE91156BE243');
        $this->addSql('ALTER TABLE recruiter_favorite DROP FOREIGN KEY FK_216ADE9118777CEF');
        $this->addSql('ALTER TABLE recruiter_profile DROP FOREIGN KEY FK_4740AFE9A76ED395');
        $this->addSql('ALTER TABLE talent_view DROP FOREIGN KEY FK_17C5775B156BE243');
        $this->addSql('ALTER TABLE talent_view DROP FOREIGN KEY FK_17C5775B18777CEF');
        $this->addSql('DROP TABLE contact_request');
        $this->addSql('DROP TABLE recruiter_favorite');
        $this->addSql('DROP TABLE recruiter_profile');
        $this->addSql('DROP TABLE talent_view');
    }
}
