<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903193210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FONCTIONNALITÉ 17 — Index justifiés par des requêtes réelles mesurées (comment.project_id+status, report.status/target, rating.status, app_user.status/country/first_name/last_name, project.name). Aucune donnée modifiée.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX user_status_idx ON app_user (status)');
        $this->addSql('CREATE INDEX user_country_idx ON app_user (country)');
        $this->addSql('CREATE INDEX user_first_name_idx ON app_user (first_name)');
        $this->addSql('CREATE INDEX user_last_name_idx ON app_user (last_name)');
        $this->addSql('CREATE INDEX comment_project_status_idx ON comment (project_id, status)');
        $this->addSql('CREATE INDEX project_name_idx ON project (name)');
        $this->addSql('CREATE INDEX rating_status_idx ON rating (status)');
        $this->addSql('CREATE INDEX report_status_idx ON report (status)');
        $this->addSql('CREATE INDEX report_target_idx ON report (target_type, target_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX user_status_idx ON app_user');
        $this->addSql('DROP INDEX user_country_idx ON app_user');
        $this->addSql('DROP INDEX user_first_name_idx ON app_user');
        $this->addSql('DROP INDEX user_last_name_idx ON app_user');
        $this->addSql('DROP INDEX comment_project_status_idx ON comment');
        $this->addSql('DROP INDEX project_name_idx ON project');
        $this->addSql('DROP INDEX rating_status_idx ON rating');
        $this->addSql('DROP INDEX report_status_idx ON report');
        $this->addSql('DROP INDEX report_target_idx ON report');
    }
}
