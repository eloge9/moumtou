<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903041632 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Espace public des établissements : ajoute le slug (URL propre) et rétro-remplit les établissements déjà en base.';
    }

    public function up(Schema $schema): void
    {
        // Exécutées immédiatement (executeStatement), pas addSql() qui ne
        // joue les instructions qu'après la fin de up() : le rétro-remplissage
        // ci-dessous a besoin que la colonne existe déjà pour pouvoir la lire.
        $this->connection->executeStatement('ALTER TABLE institution ADD slug VARCHAR(200) DEFAULT NULL');

        // Rétro-remplissage : les établissements déjà en base n'ont pas encore
        // de slug, indispensable pour la nouvelle page publique /etablissements/{slug}.
        $slugger = new AsciiSlugger();
        $rows = $this->connection->fetchAllAssociative('SELECT id, name FROM institution WHERE slug IS NULL');
        $used = [];
        foreach ($rows as $row) {
            $base = strtolower((string) $slugger->slug($row['name'])) ?: 'etablissement';
            $slug = $base;
            $suffix = 1;
            while (isset($used[$slug])) {
                $slug = $base.'-'.(++$suffix);
            }
            $used[$slug] = true;
            $this->connection->executeStatement('UPDATE institution SET slug = ? WHERE id = ?', [$slug, $row['id']]);
        }

        $this->connection->executeStatement('CREATE UNIQUE INDEX UNIQ_3A9F98E5989D9B62 ON institution (slug)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_3A9F98E5989D9B62 ON institution');
        $this->addSql('ALTER TABLE institution DROP slug');
    }
}
