<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inscription/rôles multiples — règle 5/21 : TALENT est le rôle de base de
 * TOUT compte, y compris ceux créés avant que cette règle ne soit
 * réellement appliquée à l'inscription (des comptes existants ne portaient
 * qu'un seul rôle métier, ex. `["ROLE_RECRUITER"]` sans ROLE_TALENT — ce qui
 * les privait de l'interface talent : profil, publication de projet,
 * soutenance). Purement additif : aucun rôle existant n'est retiré, aucune
 * donnée n'est perdue.
 */
final class Version20260903224028 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inscription/rôles multiples — ajoute ROLE_TALENT à tous les comptes existants qui ne l\'ont pas encore (rétroactif, purement additif).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE app_user
            SET roles = JSON_ARRAY_APPEND(roles, '$', 'ROLE_TALENT')
            WHERE NOT JSON_CONTAINS(roles, '"ROLE_TALENT"')
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Volontairement irréversible : on ne peut pas distinguer après coup
        // un ROLE_TALENT ajouté ici d'un ROLE_TALENT légitimement choisi à
        // l'inscription — revenir en arrière retirerait des comptes qui
        // avaient réellement ce rôle dès le départ.
        $this->throwIrreversibleMigrationException('Migration de données volontairement non réversible : impossible de distinguer un ROLE_TALENT préexistant de celui ajouté ici.');
    }
}
