<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // Le modèle de données (Project, Defense…) n'existe pas encore : ces
        // écrans restent volontairement vides tant que la phase "modèle de
        // données" n'est pas livrée. Les gabarits gèrent déjà l'état vide.
        return $this->render('home/index.html.twig', [
            'stats' => [
                'projectsCount' => 0,
                'verifiedProjectsCount' => 0,
                'institutionsCount' => 0,
            ],
            'recentVerifiedProjects' => [],
            'upcomingDefenses' => [],
        ]);
    }
}
