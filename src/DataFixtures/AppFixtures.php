<?php

namespace App\DataFixtures;

use App\Entity\Domain;
use App\Entity\Institution;
use App\Entity\Mention;
use App\Entity\Skill;
use App\Entity\Specialty;
use App\Entity\Technology;
use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Données de référence de démonstration, reprises telles quelles des exemples
 * déjà présents dans la maquette (code/README.md : « les données affichées
 * sont des exemples »). Ce sont des référentiels, pas des comptes ni des
 * projets fictifs — à l'exception du compte administrateur de démonstration,
 * clairement identifié comme tel, nécessaire pour accéder à /admin.
 */
class AppFixtures extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $this->loadClassification($manager);
        $this->loadInstitutions($manager);
        $this->loadTechnologies($manager);
        $this->loadSkills($manager);
        $this->loadAdmin($manager);

        $manager->flush();
    }

    private function loadAdmin(ObjectManager $manager): void
    {
        $admin = new User();
        $admin->setEmail('admin@moumtou.com');
        $admin->setFirstName('Admin');
        $admin->setLastName('MOUMTOU');
        $admin->setPhone('+22890000000');
        $admin->setSlug('admin-moumtou');
        // TALENT reste le rôle de base de tout compte, y compris admin
        // (inscription/rôles multiples, règle 5/21) — purement additif.
        $admin->setRoles(['ROLE_ADMIN', 'ROLE_TALENT']);
        $admin->setStatus(UserStatus::ACTIF);
        $admin->setEmailVerified(true);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'AdminMoumtou123'));
        $manager->persist($admin);
    }

    private function loadClassification(ObjectManager $manager): void
    {
        $tree = [
            'Sciences et Technologies' => [
                'Informatique' => ['Génie Logiciel', 'Cybersécurité', 'Intelligence artificielle'],
                'Mathématiques' => ['Statistiques', 'Mathématiques appliquées'],
                'Génie Civil' => ['Bâtiment et travaux publics'],
            ],
            'Sciences Économiques' => [
                'Gestion' => ['Finance', 'Marketing'],
            ],
            'Lettres et Sciences Humaines' => [
                'Sciences de l\'éducation' => ['Pédagogie numérique'],
            ],
            'Santé' => [
                'Médecine' => ['Santé publique'],
            ],
        ];

        foreach ($tree as $domainName => $mentions) {
            $domain = new Domain();
            $domain->setName($domainName);
            $manager->persist($domain);

            foreach ($mentions as $mentionName => $specialties) {
                $mention = new Mention();
                $mention->setName($mentionName);
                $mention->setDomain($domain);
                $manager->persist($mention);

                foreach ($specialties as $specialtyName) {
                    $specialty = new Specialty();
                    $specialty->setName($specialtyName);
                    $specialty->setMention($mention);
                    $manager->persist($specialty);
                }
            }
        }
    }

    private function loadInstitutions(ObjectManager $manager): void
    {
        $institutions = [
            ['name' => 'Université de Lomé', 'country' => 'Togo', 'city' => 'Lomé'],
            ['name' => 'Institut Y', 'country' => 'Togo', 'city' => 'Lomé'],
            ['name' => 'Université Z', 'country' => 'Togo', 'city' => 'Kara'],
            ['name' => 'Institut des Sciences', 'country' => 'Togo', 'city' => 'Lomé'],
        ];

        foreach ($institutions as $data) {
            $institution = new Institution();
            $institution->setName($data['name']);
            $institution->setCountry($data['country']);
            $institution->setCity($data['city']);
            $institution->setVerified(true);
            $manager->persist($institution);
        }
    }

    private function loadTechnologies(ObjectManager $manager): void
    {
        $technologies = [
            'Angular', 'Java', 'Python', 'Spring Boot', 'Docker', 'PostgreSQL', 'MySQL',
            'Flutter', 'Firebase', 'Node', 'JWT', 'Scikit-learn', 'FastAPI', 'PHP', 'Symfony',
        ];

        foreach ($technologies as $name) {
            $technology = new Technology();
            $technology->setName($name);
            $manager->persist($technology);
        }
    }

    private function loadSkills(ObjectManager $manager): void
    {
        $skills = ['Développement web', 'Cybersécurité', 'Intelligence artificielle', 'Gestion de projet'];

        foreach ($skills as $name) {
            $skill = new Skill();
            $skill->setName($name);
            $manager->persist($skill);
        }
    }
}
