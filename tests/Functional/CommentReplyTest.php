<?php

namespace App\Tests\Functional;

use App\Entity\Comment;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\ProjectType;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cahier des charges §23 : "commenter, répondre, signaler, supprimer".
 */
class CommentReplyTest extends FunctionalTestCase
{
    public function testUserCanReplyToAComment(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purgeDatabase($em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $owner = (new User())->setEmail('owner@example.com')->setFirstName('O')->setLastName('Wner')
            ->setPhone('+22890000000')->setRoles(['ROLE_TALENT'])->setStatus(UserStatus::ACTIF)->setSlug('owner-reply');
        $owner->setPassword($hasher->hashPassword($owner, 'MotDePasse123'));
        $em->persist($owner);

        $commenter = (new User())->setEmail('commenter@example.com')->setFirstName('C')->setLastName('Ommenter')
            ->setPhone('+22890000001')->setRoles(['ROLE_TALENT'])->setStatus(UserStatus::ACTIF)->setSlug('commenter-reply');
        $commenter->setPassword($hasher->hashPassword($commenter, 'MotDePasse123'));
        $em->persist($commenter);

        $project = new Project();
        $project->setName('Projet avec discussion');
        $project->setType(ProjectType::PERSONNEL);
        $project->setStatus(ProjectStatus::PUBLIE);
        $project->setSlug('projet-discussion');
        $project->setOwner($owner);
        $em->persist($project);

        $comment = new Comment();
        $comment->setProject($project);
        $comment->setAuthor($commenter);
        $comment->setContent('Beau travail !');
        $em->persist($comment);
        $em->flush();
        $commentId = $comment->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/projets/projet-discussion');
        $token = $crawler->filter('#modale-reponse-'.$commentId.' input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/commentaires/'.$commentId.'/repondre', [
            'content' => 'Merci beaucoup !',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/projets/projet-discussion');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $refreshed = $em->getRepository(Comment::class)->find($commentId);
        self::assertCount(1, $refreshed->getReplies());
        self::assertSame('Merci beaucoup !', $refreshed->getReplies()->first()->getContent());
        self::assertSame($owner->getEmail(), $refreshed->getReplies()->first()->getAuthor()->getEmail());
    }
}
