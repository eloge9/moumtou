<?php

namespace App\Command;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Service\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Crée le compte administrateur initial, interactivement, avec les
 * identifiants choisis par la personne qui installe MOUMTOU — jamais un
 * couple email/mot de passe fixé dans le code ou les fixtures. Le mot de
 * passe n'est jamais affiché ni journalisé.
 *
 * Bloqué par défaut dès qu'un administrateur existe déjà (utiliser
 * --force pour en créer un supplémentaire malgré tout) : au-delà du
 * premier compte, l'ajout d'un administrateur passe par l'interface
 * d'administration elle-même (bouton "Rendre administrateur"), pas par
 * cette commande.
 */
#[AsCommand(name: 'app:create-admin', description: 'Crée le compte administrateur initial de MOUMTOU')]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly SlugGenerator $slugGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Créer un administrateur même s\'il en existe déjà un');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Création du compte administrateur MOUMTOU');

        $existingAdminCount = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.roles LIKE :role')->setParameter('role', '%"ROLE_ADMIN"%')
            ->getQuery()->getSingleScalarResult();

        if ($existingAdminCount > 0 && !$input->getOption('force')) {
            $io->error([
                'Un compte administrateur existe déjà sur cette installation.',
                'Pour ajouter un administrateur supplémentaire, utilisez le bouton "Rendre administrateur" depuis /admin/utilisateurs.',
                'Pour forcer la création d\'un nouveau compte malgré tout, relancez avec --force.',
            ]);

            return Command::FAILURE;
        }

        $helper = $this->getHelper('question');

        $firstName = $helper->ask($input, $output, $this->textQuestion('Prénom : '));
        $lastName = $helper->ask($input, $output, $this->textQuestion('Nom : '));
        $email = $helper->ask($input, $output, $this->emailQuestion());
        $phone = $helper->ask($input, $output, new Question('Téléphone (optionnel) : '));
        $password = $helper->ask($input, $output, $this->passwordQuestion('Mot de passe : '));
        $confirmation = $helper->ask($input, $output, $this->passwordQuestion('Confirmation du mot de passe : '));

        if ($password !== $confirmation) {
            $io->error('Les deux mots de passe ne correspondent pas. Aucun compte n\'a été créé.');

            return Command::FAILURE;
        }

        $io->writeln('');
        $io->writeln('Création du compte administrateur...');

        $admin = new User();
        $admin->setEmail($email);
        $admin->setFirstName($firstName);
        $admin->setLastName($lastName);
        $admin->setPhone($phone ?: '');
        $admin->setSlug($this->slugGenerator->generateUnique($firstName.' '.$lastName, User::class));
        // ROLE_TALENT reste le rôle de base de tout compte, y compris admin
        // (multi-rôle purement additif, cohérent avec le reste de la plateforme).
        $admin->setRoles(['ROLE_ADMIN', 'ROLE_TALENT']);
        $admin->setStatus(UserStatus::ACTIF);
        $admin->setEmailVerified(true);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, $password));

        $this->em->persist($admin);
        $this->em->flush();

        $io->success('Compte administrateur créé avec succès.');
        $io->writeln(sprintf('Email : %s', $email));
        $io->writeln('Rôle  : ADMIN');
        $io->writeln('');
        $io->note('Connectez-vous sur /connexion avec cette adresse et le mot de passe que vous venez de choisir.');

        return Command::SUCCESS;
    }

    private function textQuestion(string $label): Question
    {
        $question = new Question($label);
        $question->setValidator(function (?string $value): string {
            $violations = $this->validator->validate(trim((string) $value), [new NotBlank(message: 'Ce champ est obligatoire.')]);
            if (\count($violations) > 0) {
                throw new \RuntimeException($violations[0]->getMessage());
            }

            return trim((string) $value);
        });

        return $question;
    }

    private function emailQuestion(): Question
    {
        $question = new Question('Email administrateur : ');
        $question->setValidator(function (?string $value): string {
            $email = trim((string) $value);
            $violations = $this->validator->validate($email, [
                new NotBlank(message: 'L\'adresse e-mail est obligatoire.'),
                new Email(message: 'Adresse e-mail invalide.'),
            ]);
            if (\count($violations) > 0) {
                throw new \RuntimeException($violations[0]->getMessage());
            }
            if ($this->em->getRepository(User::class)->findOneBy(['email' => $email])) {
                throw new \RuntimeException('Un compte existe déjà avec cette adresse e-mail.');
            }

            return $email;
        });

        return $question;
    }

    private function passwordQuestion(string $label): Question
    {
        // Saisie en clair, volontairement : le masquage de saisie
        // (Question::setHidden) s'appuie sur des mécanismes spécifiques au
        // terminal (stty / hiddeninput.exe) qui ne fonctionnent pas de
        // façon fiable sur tous les environnements (notamment Windows hors
        // terminal natif, ou tout terminal non interactif) — testé et
        // confirmé bloquant dans cet environnement. Fiabilité de
        // l'installation privilégiée sur ce détail de confort.
        $question = new Question($label);
        $question->setValidator(function (?string $value): string {
            $violations = $this->validator->validate((string) $value, [
                new NotBlank(message: 'Le mot de passe est obligatoire.'),
                new Length(min: 8, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.'),
            ]);
            if (\count($violations) > 0) {
                throw new \RuntimeException($violations[0]->getMessage());
            }

            return (string) $value;
        });

        return $question;
    }
}
