<?php

namespace App\Tests\Functional;

use App\Entity\Comment;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges §11 à §16, §25, §35 : modification, suppression
 * logique, longueur, sécurité et signalement des commentaires.
 */
class CommentModerationTest extends FunctionalTestCase
{
    private function makeUser(EntityManagerInterface $em, string $email, string $slug, array $roles = ['ROLE_TALENT']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail($email)->setFirstName('Test')->setLastName(ucfirst($slug))
            ->setPhone('+22890000000')->setRoles($roles)->setStatus(UserStatus::ACTIF)
            ->setSlug($slug)->setEmailVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'MotDePasse123'));
        $em->persist($user);

        return $user;
    }

    private function makeProjectWithComment(EntityManagerInterface $em, User $owner, User $commenter, string $slug, string $content = 'Bon travail !'): array
    {
        $project = new Project();
        $project->setName('Projet '.$slug);
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug($slug);
        $project->setOwner($owner);
        $em->persist($project);

        $comment = new Comment();
        $comment->setProject($project);
        $comment->setAuthor($commenter);
        $comment->setContent($content);
        $em->persist($comment);
        $em->flush();

        return [$project, $comment];
    }

    public function testAuthorCanEditTheirOwnComment(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.edit1@example.com', 'owner-edit1');
        $commenter = $this->makeUser($em, 'commenter.edit1@example.com', 'commenter-edit1');
        [$project, $comment] = $this->makeProjectWithComment($em, $owner, $commenter, 'projet-modif-commentaire');
        $commentId = $comment->getId();

        $client->loginUser($commenter);
        $crawler = $client->request('GET', '/projets/'.$project->getSlug());
        self::assertSelectorTextContains('body', 'Modifier');
        $token = $crawler->filter('#modale-modifier-'.$commentId.' input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/commentaires/'.$commentId.'/modifier', [
            'content' => 'Contenu corrigé.',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/projets/'.$project->getSlug());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Comment::class)->find($commentId);
        self::assertSame('Contenu corrigé.', $refreshed->getContent());
        self::assertTrue($refreshed->isEdited());
    }

    public function testAnotherUserCannotEditSomeoneElsesComment(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.edit2@example.com', 'owner-edit2');
        $commenter = $this->makeUser($em, 'commenter.edit2@example.com', 'commenter-edit2');
        [$project, $comment] = $this->makeProjectWithComment($em, $owner, $commenter, 'projet-modif-refuse');
        $commentId = $comment->getId();

        $intruder = $this->makeUser($em, 'intrus.edit2@example.com', 'intrus-edit2');
        $em->flush();

        $client->loginUser($intruder);
        $client->request('POST', '/commentaires/'.$commentId.'/modifier', [
            'content' => 'Je réécris ce commentaire.',
            '_csrf_token' => 'peu-importe',
        ]);
        self::assertResponseStatusCodeSame(403);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('Bon travail !', $em->getRepository(Comment::class)->find($commentId)->getContent());
    }

    public function testEmptyCommentIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.vide@example.com', 'owner-vide');
        $em->flush();

        $project = new Project();
        $project->setName('Projet vide');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-commentaire-vide');
        $project->setOwner($owner);
        $em->persist($project);
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/projet-commentaire-vide');
        $token = $crawler->filter('#modale-commentaire input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/projets/projet-commentaire-vide/commenter', ['content' => '   ', '_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(Comment::class)->findBy(['project' => $project]));
    }

    public function testExcessivelyLongCommentIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.long@example.com', 'owner-long');
        $em->flush();

        $project = new Project();
        $project->setName('Projet long');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-commentaire-long');
        $project->setOwner($owner);
        $em->persist($project);
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/projet-commentaire-long');
        $token = $crawler->filter('#modale-commentaire input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/projets/projet-commentaire-long/commenter', [
            'content' => str_repeat('a', 2001),
            '_csrf_token' => $token,
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(Comment::class)->findBy(['project' => $project]));
    }

    public function testDeletionIsLogicalAndPreservesReplies(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.suppr@example.com', 'owner-suppr');
        $commenter = $this->makeUser($em, 'commenter.suppr@example.com', 'commenter-suppr');
        [$project, $comment] = $this->makeProjectWithComment($em, $owner, $commenter, 'projet-suppr-commentaire');
        $commentId = $comment->getId();

        $reply = new Comment();
        $reply->setProject($project);
        $reply->setAuthor($owner);
        $reply->setContent('Merci !');
        $reply->setParent($comment);
        $em->persist($reply);
        $em->flush();
        $replyId = $reply->getId();

        $client->loginUser($commenter);
        $crawler = $client->request('GET', '/projets/'.$project->getSlug());
        $token = $crawler->filter('form[action="/commentaires/'.$commentId.'/supprimer"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/commentaires/'.$commentId.'/supprimer', ['_csrf_token' => $token]);
        self::assertResponseRedirects('/projets/'.$project->getSlug());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Comment::class)->find($commentId);
        self::assertNotNull($refreshed, 'La suppression doit être logique : la ligne doit toujours exister.');
        self::assertSame(CommentStatus::SUPPRIME, $refreshed->getStatus());
        self::assertNotNull($em->getRepository(Comment::class)->find($replyId), 'La réponse d\'un autre utilisateur ne doit pas être supprimée en cascade.');

        // Le commentaire supprimé ne doit plus apparaître publiquement.
        $publicCrawler = $client->request('GET', '/projets/'.$project->getSlug());
        self::assertSelectorTextNotContains('body', 'Bon travail !');
    }

    public function testCommentContentIsEscapedAgainstXss(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.xss@example.com', 'owner-xss');
        $commenter = $this->makeUser($em, 'commenter.xss@example.com', 'commenter-xss');
        [$project] = $this->makeProjectWithComment($em, $owner, $commenter, 'projet-xss', '<script>alert(1)</script>');
        $em->flush();

        $client->request('GET', '/projets/'.$project->getSlug());
        $html = $client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testUserCanReportACommentAndDuplicateIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $owner = $this->makeUser($em, 'owner.report@example.com', 'owner-report-c');
        $commenter = $this->makeUser($em, 'commenter.report@example.com', 'commenter-report-c');
        [$project, $comment] = $this->makeProjectWithComment($em, $owner, $commenter, 'projet-signal-commentaire');
        $commentId = $comment->getId();

        $reporter = $this->makeUser($em, 'reporter.report@example.com', 'reporter-report-c');
        $em->flush();

        $client->loginUser($reporter);
        $crawler = $client->request('GET', '/projets/'.$project->getSlug());
        $token = $crawler->filter('#modale-signaler-commentaire-'.$commentId.' input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/commentaires/'.$commentId.'/signaler', ['reason' => 'spam', '_csrf_token' => $token]);
        self::assertResponseRedirects('/projets/'.$project->getSlug());

        // Doublon : un second signalement du même contenu par le même utilisateur est refusé.
        $client->request('POST', '/commentaires/'.$commentId.'/signaler', ['reason' => 'spam', '_csrf_token' => $token]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $reports = $em->getRepository(\App\Entity\Report::class)->findBy(['targetId' => $commentId]);
        self::assertCount(1, $reports, 'Un signalement en doublon ne doit pas créer une seconde entrée.');
    }
}
