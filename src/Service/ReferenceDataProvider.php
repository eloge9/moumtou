<?php

namespace App\Service;

use App\Entity\Domain;
use App\Entity\Institution;
use App\Entity\Mention;
use App\Entity\Specialty;
use App\Entity\Technology;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Données de référence publiques, peu modifiées, redemandées à l'identique
 * sur plusieurs pages à fort trafic (cahier des charges — FONCTIONNALITÉ 17
 * §15) : domaines/mentions/spécialités/technologies/pays des établissements.
 * Avant cette fonctionnalité, ces mêmes 5 requêtes étaient répétées telles
 * quelles dans ExplorerController, InstitutionController, RecruiterController
 * et SearchController à chaque affichage.
 *
 * Réutilise le pool de cache "cache.app" déjà configuré par le projet
 * (adaptateur fichier par défaut, cahier §15 : "réutilise-le, n'ajoute pas
 * Redis uniquement pour dire qu'il y a du cache"). TTL court (5 minutes)
 * plutôt qu'une invalidation par tags câblée dans chaque écran
 * d'administration : un domaine/une technologie nouvellement créé(e) met au
 * plus 5 minutes à apparaître partout — compromis jugé raisonnable pour des
 * données qui changent rarement, en échange d'une implémentation simple.
 *
 * Ne met jamais en cache : notifications, favoris, contacts, statistiques
 * par utilisateur, ou toute donnée dépendant du visiteur (cahier §15/§31).
 */
class ReferenceDataProvider
{
    private const TTL_SECONDS = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function domains(): array
    {
        return $this->cached('refdata.domains', fn () => $this->toIdNameList($this->em->getRepository(Domain::class)->findBy([], ['name' => 'ASC'])));
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function mentions(): array
    {
        return $this->cached('refdata.mentions', fn () => $this->toIdNameList($this->em->getRepository(Mention::class)->findBy([], ['name' => 'ASC'])));
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function specialties(): array
    {
        return $this->cached('refdata.specialties', fn () => $this->toIdNameList($this->em->getRepository(Specialty::class)->findBy([], ['name' => 'ASC'])));
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function technologies(): array
    {
        return $this->cached('refdata.technologies', fn () => $this->toIdNameList($this->em->getRepository(Technology::class)->findBy([], ['name' => 'ASC'])));
    }

    /** @return string[] pays distincts des établissements, triés */
    public function institutionCountries(): array
    {
        return $this->cached('refdata.institution_countries', fn () => array_column(
            $this->em->getRepository(Institution::class)->createQueryBuilder('i')
                ->select('DISTINCT i.country')
                ->andWhere('i.country IS NOT NULL')
                ->orderBy('i.country', 'ASC')
                ->getQuery()->getScalarResult(),
            'country',
        ));
    }

    /**
     * @template T
     *
     * @param callable(): T $loader
     *
     * @return T
     */
    private function cached(string $key, callable $loader): mixed
    {
        return $this->cache->get($key, function (ItemInterface $item) use ($loader) {
            $item->expiresAfter(self::TTL_SECONDS);

            return $loader();
        });
    }

    /**
     * Convertit en tableaux associatifs simples avant mise en cache :
     * sérialiser une entité Doctrine dont une relation n'a pas été chargée
     * (proxy non initialisé) est fragile d'une requête à l'autre. Les
     * gabarits Twig concernés n'utilisent ici que `.id` et `.name`
     * (vérifié) — un tableau se comporte à l'identique pour cet usage.
     *
     * @param array<int, Domain|Mention|Specialty|Technology> $entities
     *
     * @return list<array{id: int, name: string}>
     */
    private function toIdNameList(array $entities): array
    {
        return array_values(array_map(
            static fn (Domain|Mention|Specialty|Technology $e) => ['id' => $e->getId(), 'name' => $e->getName()],
            $entities,
        ));
    }
}
