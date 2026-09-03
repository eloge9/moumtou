<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903023614 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Évaluations et commentaires : traçabilité des modifications et détection des votes suspects.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment ADD updated_at DATETIME DEFAULT NULL');
        $this->addSql("ALTER TABLE rating ADD updated_at DATETIME DEFAULT NULL, ADD status VARCHAR(255) NOT NULL DEFAULT 'normal', ADD ip_address VARCHAR(45) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment DROP updated_at');
        $this->addSql('ALTER TABLE rating DROP updated_at, DROP status, DROP ip_address');
    }
}
