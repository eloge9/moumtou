<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904004541 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fonction propre à chaque rattachement établissement (UserInstitution.title) — un enseignant peut avoir un rôle différent d\'un établissement à l\'autre.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_institution ADD title VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_institution DROP title');
    }
}
