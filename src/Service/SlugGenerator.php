<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Génère un slug unique pour une entité donnée (colonne "slug"), en ajoutant
 * un suffixe numérique en cas de collision.
 */
class SlugGenerator
{
    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param class-string $entityClass
     */
    public function generateUnique(string $text, string $entityClass, string $field = 'slug'): string
    {
        $base = strtolower((string) $this->slugger->slug($text ?: 'talent'));
        $slug = $base;
        $suffix = 1;

        while ($this->entityManager->getRepository($entityClass)->findOneBy([$field => $slug]) !== null) {
            $slug = sprintf('%s-%d', $base, ++$suffix);
        }

        return $slug;
    }
}
