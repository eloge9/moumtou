<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903030607 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recherche avancée (FONCTIONNALITÉ 6) : index sur les colonnes filtrées/triées par le moteur de recherche.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX user_created_at_idx ON app_user (created_at)');
        $this->addSql('CREATE INDEX project_status_type_idx ON project (status, type)');
        $this->addSql('CREATE INDEX project_published_at_idx ON project (published_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX user_created_at_idx ON app_user');
        $this->addSql('DROP INDEX project_status_type_idx ON project');
        $this->addSql('DROP INDEX project_published_at_idx ON project');
    }
}
