<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260902235616 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE institution_request (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(180) NOT NULL, type VARCHAR(255) NOT NULL, country VARCHAR(100) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, website VARCHAR(255) DEFAULT NULL, additional_info LONGTEXT DEFAULT NULL, status VARCHAR(255) NOT NULL, admin_note LONGTEXT DEFAULT NULL, decided_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, requested_by_id INT NOT NULL, decided_by_id INT DEFAULT NULL, created_institution_id INT DEFAULT NULL, INDEX IDX_5F69790D4DA1E751 (requested_by_id), INDEX IDX_5F69790DE26B496B (decided_by_id), INDEX IDX_5F69790D1478D258 (created_institution_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_institution (id INT AUTO_INCREMENT NOT NULL, context VARCHAR(255) NOT NULL, active TINYINT NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, institution_id INT NOT NULL, INDEX IDX_93845170A76ED395 (user_id), INDEX IDX_9384517010405986 (institution_id), UNIQUE INDEX user_institution_context_unique (user_id, institution_id, context), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE institution_request ADD CONSTRAINT FK_5F69790D4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE institution_request ADD CONSTRAINT FK_5F69790DE26B496B FOREIGN KEY (decided_by_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE institution_request ADD CONSTRAINT FK_5F69790D1478D258 FOREIGN KEY (created_institution_id) REFERENCES institution (id)');
        $this->addSql('ALTER TABLE user_institution ADD CONSTRAINT FK_93845170A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE user_institution ADD CONSTRAINT FK_9384517010405986 FOREIGN KEY (institution_id) REFERENCES institution (id)');
        $this->addSql('ALTER TABLE app_user ADD domain_id INT DEFAULT NULL, ADD mention_id INT DEFAULT NULL, ADD specialty_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E9115F0EE5 FOREIGN KEY (domain_id) REFERENCES domain (id)');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E97A4147F0 FOREIGN KEY (mention_id) REFERENCES mention (id)');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E99A353316 FOREIGN KEY (specialty_id) REFERENCES specialty (id)');
        $this->addSql('CREATE INDEX IDX_88BDF3E9115F0EE5 ON app_user (domain_id)');
        $this->addSql('CREATE INDEX IDX_88BDF3E97A4147F0 ON app_user (mention_id)');
        $this->addSql('CREATE INDEX IDX_88BDF3E99A353316 ON app_user (specialty_id)');
        // DEFAULT explicite : la table institution contient déjà des lignes
        // (fixtures + établissements ajoutés en admin) qui doivent recevoir
        // une valeur valide pour ces deux nouvelles colonnes NOT NULL.
        $this->addSql("ALTER TABLE institution ADD type VARCHAR(255) NOT NULL DEFAULT 'autre', ADD active TINYINT(1) NOT NULL DEFAULT 1, ADD updated_at DATETIME DEFAULT NULL");
        $this->addSql('ALTER TABLE jury_member ADD institution_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE jury_member ADD CONSTRAINT FK_B06D92E110405986 FOREIGN KEY (institution_id) REFERENCES institution (id)');
        $this->addSql('CREATE INDEX IDX_B06D92E110405986 ON jury_member (institution_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE institution_request DROP FOREIGN KEY FK_5F69790D4DA1E751');
        $this->addSql('ALTER TABLE institution_request DROP FOREIGN KEY FK_5F69790DE26B496B');
        $this->addSql('ALTER TABLE institution_request DROP FOREIGN KEY FK_5F69790D1478D258');
        $this->addSql('ALTER TABLE user_institution DROP FOREIGN KEY FK_93845170A76ED395');
        $this->addSql('ALTER TABLE user_institution DROP FOREIGN KEY FK_9384517010405986');
        $this->addSql('DROP TABLE institution_request');
        $this->addSql('DROP TABLE user_institution');
        $this->addSql('ALTER TABLE app_user DROP FOREIGN KEY FK_88BDF3E9115F0EE5');
        $this->addSql('ALTER TABLE app_user DROP FOREIGN KEY FK_88BDF3E97A4147F0');
        $this->addSql('ALTER TABLE app_user DROP FOREIGN KEY FK_88BDF3E99A353316');
        $this->addSql('DROP INDEX IDX_88BDF3E9115F0EE5 ON app_user');
        $this->addSql('DROP INDEX IDX_88BDF3E97A4147F0 ON app_user');
        $this->addSql('DROP INDEX IDX_88BDF3E99A353316 ON app_user');
        $this->addSql('ALTER TABLE app_user DROP domain_id, DROP mention_id, DROP specialty_id');
        $this->addSql('ALTER TABLE institution DROP type, DROP active, DROP updated_at');
        $this->addSql('ALTER TABLE jury_member DROP FOREIGN KEY FK_B06D92E110405986');
        $this->addSql('DROP INDEX IDX_B06D92E110405986 ON jury_member');
        $this->addSql('ALTER TABLE jury_member DROP institution_id');
    }
}
