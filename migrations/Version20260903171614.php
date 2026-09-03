<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903171614 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FONCTIONNALITÉ 14 — Problèmes restants : ajout de project_proof.reviewed (examen individuel des preuves par l\'administrateur).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project_proof ADD reviewed TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project_proof DROP reviewed');
    }
}
