<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260902162951 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE domain (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, UNIQUE INDEX UNIQ_A7A91E0B5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE institution (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(180) NOT NULL, logo VARCHAR(255) DEFAULT NULL, country VARCHAR(100) NOT NULL, city VARCHAR(100) NOT NULL, address VARCHAR(255) DEFAULT NULL, website VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, verified TINYINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_3A9F98E55E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mention (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, domain_id INT NOT NULL, INDEX IDX_E20259CD115F0EE5 (domain_id), UNIQUE INDEX mention_domain_name_unique (domain_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE skill (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, UNIQUE INDEX UNIQ_5E3DE4775E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE specialty (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, mention_id INT NOT NULL, INDEX IDX_E066A6EC7A4147F0 (mention_id), UNIQUE INDEX specialty_mention_name_unique (mention_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE technology (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, UNIQUE INDEX UNIQ_F463524D5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mention ADD CONSTRAINT FK_E20259CD115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id)');
        $this->addSql('ALTER TABLE specialty ADD CONSTRAINT FK_E066A6EC7A4147F0 FOREIGN KEY (mention_id) REFERENCES mention (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mention DROP FOREIGN KEY FK_E20259CD115F0EE5');
        $this->addSql('ALTER TABLE specialty DROP FOREIGN KEY FK_E066A6EC7A4147F0');
        $this->addSql('DROP TABLE domain');
        $this->addSql('DROP TABLE institution');
        $this->addSql('DROP TABLE mention');
        $this->addSql('DROP TABLE skill');
        $this->addSql('DROP TABLE specialty');
        $this->addSql('DROP TABLE technology');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
