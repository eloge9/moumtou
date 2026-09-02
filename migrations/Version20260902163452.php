<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260902163452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, slug VARCHAR(160) DEFAULT NULL, phone VARCHAR(30) NOT NULL, whatsapp VARCHAR(30) DEFAULT NULL, whatsapp_enabled TINYINT NOT NULL, photo VARCHAR(255) DEFAULT NULL, country VARCHAR(100) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, bio LONGTEXT DEFAULT NULL, linkedin_url VARCHAR(255) DEFAULT NULL, github_url VARCHAR(255) DEFAULT NULL, website_url VARCHAR(255) DEFAULT NULL, portfolio_url VARCHAR(255) DEFAULT NULL, availability VARCHAR(255) DEFAULT NULL, google_id VARCHAR(100) DEFAULT NULL, facebook_id VARCHAR(100) DEFAULT NULL, linkedin_id VARCHAR(100) DEFAULT NULL, email_verified TINYINT NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX user_email_unique (email), UNIQUE INDEX user_slug_unique (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_skill (user_id INT NOT NULL, skill_id INT NOT NULL, INDEX IDX_BCFF1F2FA76ED395 (user_id), INDEX IDX_BCFF1F2F5585C142 (skill_id), PRIMARY KEY (user_id, skill_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_technology (user_id INT NOT NULL, technology_id INT NOT NULL, INDEX IDX_530494A1A76ED395 (user_id), INDEX IDX_530494A14235D463 (technology_id), PRIMARY KEY (user_id, technology_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE comment (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, project_id INT NOT NULL, author_id INT NOT NULL, parent_id INT DEFAULT NULL, INDEX IDX_9474526C166D1F9C (project_id), INDEX IDX_9474526CF675F31B (author_id), INDEX IDX_9474526C727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE defense (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, time VARCHAR(10) NOT NULL, place VARCHAR(180) NOT NULL, result VARCHAR(60) DEFAULT NULL, status VARCHAR(255) NOT NULL, announced_at DATETIME NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_DBA5F575166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE jury_member (id INT AUTO_INCREMENT NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, role VARCHAR(255) NOT NULL, email VARCHAR(180) NOT NULL, institution_name VARCHAR(180) DEFAULT NULL, status VARCHAR(255) NOT NULL, confirmed_at DATETIME DEFAULT NULL, invited_at DATETIME NOT NULL, defense_id INT NOT NULL, invited_user_id INT DEFAULT NULL, INDEX IDX_B06D92E1FB0C2DCF (defense_id), INDEX IDX_B06D92E1C58DAD6E (invited_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(180) NOT NULL, slug VARCHAR(160) DEFAULT NULL, theme VARCHAR(180) DEFAULT NULL, short_description VARCHAR(160) DEFAULT NULL, detailed_description LONGTEXT DEFAULT NULL, type VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, realization_date DATE DEFAULT NULL, views_count INT NOT NULL, rating_average DOUBLE PRECISION NOT NULL, ratings_count INT NOT NULL, created_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, owner_id INT NOT NULL, domain_id INT DEFAULT NULL, mention_id INT DEFAULT NULL, specialty_id INT DEFAULT NULL, institution_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_2FB3D0EE989D9B62 (slug), INDEX IDX_2FB3D0EE7E3C61F9 (owner_id), INDEX IDX_2FB3D0EE115F0EE5 (domain_id), INDEX IDX_2FB3D0EE7A4147F0 (mention_id), INDEX IDX_2FB3D0EE9A353316 (specialty_id), INDEX IDX_2FB3D0EE10405986 (institution_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_technology (project_id INT NOT NULL, technology_id INT NOT NULL, INDEX IDX_ECC5297F166D1F9C (project_id), INDEX IDX_ECC5297F4235D463 (technology_id), PRIMARY KEY (project_id, technology_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_photo (id INT AUTO_INCREMENT NOT NULL, path VARCHAR(255) NOT NULL, thumbnail_path VARCHAR(255) DEFAULT NULL, position INT NOT NULL, uploaded_at DATETIME NOT NULL, project_id INT NOT NULL, INDEX IDX_7E28D86166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_proof (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(255) NOT NULL, url VARCHAR(500) NOT NULL, project_id INT NOT NULL, INDEX IDX_E8AC4943166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE rating (id INT AUTO_INCREMENT NOT NULL, value SMALLINT NOT NULL, created_at DATETIME NOT NULL, project_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_D8892622166D1F9C (project_id), INDEX IDX_D8892622A76ED395 (user_id), UNIQUE INDEX rating_project_user_unique (project_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_skill ADD CONSTRAINT FK_BCFF1F2FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_skill ADD CONSTRAINT FK_BCFF1F2F5585C142 FOREIGN KEY (skill_id) REFERENCES skill (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_technology ADD CONSTRAINT FK_530494A1A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_technology ADD CONSTRAINT FK_530494A14235D463 FOREIGN KEY (technology_id) REFERENCES technology (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CF675F31B FOREIGN KEY (author_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C727ACA70 FOREIGN KEY (parent_id) REFERENCES comment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE defense ADD CONSTRAINT FK_DBA5F575166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE jury_member ADD CONSTRAINT FK_B06D92E1FB0C2DCF FOREIGN KEY (defense_id) REFERENCES defense (id)');
        $this->addSql('ALTER TABLE jury_member ADD CONSTRAINT FK_B06D92E1C58DAD6E FOREIGN KEY (invited_user_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id)');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE7A4147F0 FOREIGN KEY (mention_id) REFERENCES mention (id)');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE9A353316 FOREIGN KEY (specialty_id) REFERENCES specialty (id)');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE10405986 FOREIGN KEY (institution_id) REFERENCES institution (id)');
        $this->addSql('ALTER TABLE project_technology ADD CONSTRAINT FK_ECC5297F166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_technology ADD CONSTRAINT FK_ECC5297F4235D463 FOREIGN KEY (technology_id) REFERENCES technology (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_photo ADD CONSTRAINT FK_7E28D86166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE project_proof ADD CONSTRAINT FK_E8AC4943166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE rating ADD CONSTRAINT FK_D8892622166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE rating ADD CONSTRAINT FK_D8892622A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_skill DROP FOREIGN KEY FK_BCFF1F2FA76ED395');
        $this->addSql('ALTER TABLE user_skill DROP FOREIGN KEY FK_BCFF1F2F5585C142');
        $this->addSql('ALTER TABLE user_technology DROP FOREIGN KEY FK_530494A1A76ED395');
        $this->addSql('ALTER TABLE user_technology DROP FOREIGN KEY FK_530494A14235D463');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526C166D1F9C');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526CF675F31B');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526C727ACA70');
        $this->addSql('ALTER TABLE defense DROP FOREIGN KEY FK_DBA5F575166D1F9C');
        $this->addSql('ALTER TABLE jury_member DROP FOREIGN KEY FK_B06D92E1FB0C2DCF');
        $this->addSql('ALTER TABLE jury_member DROP FOREIGN KEY FK_B06D92E1C58DAD6E');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE7E3C61F9');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE115F0EE5');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE7A4147F0');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE9A353316');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE10405986');
        $this->addSql('ALTER TABLE project_technology DROP FOREIGN KEY FK_ECC5297F166D1F9C');
        $this->addSql('ALTER TABLE project_technology DROP FOREIGN KEY FK_ECC5297F4235D463');
        $this->addSql('ALTER TABLE project_photo DROP FOREIGN KEY FK_7E28D86166D1F9C');
        $this->addSql('ALTER TABLE project_proof DROP FOREIGN KEY FK_E8AC4943166D1F9C');
        $this->addSql('ALTER TABLE rating DROP FOREIGN KEY FK_D8892622166D1F9C');
        $this->addSql('ALTER TABLE rating DROP FOREIGN KEY FK_D8892622A76ED395');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE user_skill');
        $this->addSql('DROP TABLE user_technology');
        $this->addSql('DROP TABLE comment');
        $this->addSql('DROP TABLE defense');
        $this->addSql('DROP TABLE jury_member');
        $this->addSql('DROP TABLE project');
        $this->addSql('DROP TABLE project_technology');
        $this->addSql('DROP TABLE project_photo');
        $this->addSql('DROP TABLE project_proof');
        $this->addSql('DROP TABLE rating');
    }
}
