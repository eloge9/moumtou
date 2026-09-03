<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903012431 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE defense_result (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(255) NOT NULL, grade DOUBLE PRECISION DEFAULT NULL, grade_scale DOUBLE PRECISION NOT NULL, appreciation LONGTEXT DEFAULT NULL, decision VARCHAR(255) NOT NULL, result_visible TINYINT NOT NULL, grade_visible TINYINT NOT NULL, appreciation_visible TINYINT NOT NULL, validated TINYINT NOT NULL, validated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, defense_id INT NOT NULL, appreciation_author_id INT DEFAULT NULL, validated_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_58C47FC8FB0C2DCF (defense_id), INDEX IDX_58C47FC850B6C2AE (appreciation_author_id), INDEX IDX_58C47FC8C69DE5E5 (validated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE defense_validation (id INT AUTO_INCREMENT NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, validated_at DATETIME NOT NULL, defense_id INT NOT NULL, jury_member_id INT NOT NULL, INDEX IDX_F9B3BF93FB0C2DCF (defense_id), INDEX IDX_F9B3BF93BE158A6A (jury_member_id), UNIQUE INDEX defense_validation_unique (defense_id, jury_member_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE defense_result ADD CONSTRAINT FK_58C47FC8FB0C2DCF FOREIGN KEY (defense_id) REFERENCES defense (id)');
        $this->addSql('ALTER TABLE defense_result ADD CONSTRAINT FK_58C47FC850B6C2AE FOREIGN KEY (appreciation_author_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE defense_result ADD CONSTRAINT FK_58C47FC8C69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE defense_validation ADD CONSTRAINT FK_F9B3BF93FB0C2DCF FOREIGN KEY (defense_id) REFERENCES defense (id)');
        $this->addSql('ALTER TABLE defense_validation ADD CONSTRAINT FK_F9B3BF93BE158A6A FOREIGN KEY (jury_member_id) REFERENCES jury_member (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE defense_result DROP FOREIGN KEY FK_58C47FC8FB0C2DCF');
        $this->addSql('ALTER TABLE defense_result DROP FOREIGN KEY FK_58C47FC850B6C2AE');
        $this->addSql('ALTER TABLE defense_result DROP FOREIGN KEY FK_58C47FC8C69DE5E5');
        $this->addSql('ALTER TABLE defense_validation DROP FOREIGN KEY FK_F9B3BF93FB0C2DCF');
        $this->addSql('ALTER TABLE defense_validation DROP FOREIGN KEY FK_F9B3BF93BE158A6A');
        $this->addSql('DROP TABLE defense_result');
        $this->addSql('DROP TABLE defense_validation');
    }
}
