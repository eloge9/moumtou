<?php

namespace App\Repository;

use App\Entity\ContactRequest;
use App\Entity\User;
use App\Enum\ContactRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactRequest>
 */
class ContactRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactRequest::class);
    }

    public function hasPendingRequest(User $recruiter, User $talent): bool
    {
        return null !== $this->findOneBy([
            'recruiter' => $recruiter,
            'talent' => $talent,
            'status' => ContactRequestStatus::PENDING,
        ]);
    }
}
