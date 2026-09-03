<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903114123 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE domain ADD active TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE mention ADD active TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE specialty ADD active TINYINT(1) NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE domain DROP active');
        $this->addSql('ALTER TABLE mention DROP active');
        $this->addSql('ALTER TABLE specialty DROP active');
    }
}
