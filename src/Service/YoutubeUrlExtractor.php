<?php

namespace App\Service;

use App\Entity\Project;
use App\Enum\ProofType;

/**
 * Extrait l'identifiant d'une vidéo YouTube depuis les preuves d'un projet
 * (cahier des charges — FONCTIONNALITÉ 10 §5) : couvre watch?v=, youtu.be/,
 * /embed/ et /shorts/. Point unique de cette logique — précédemment
 * dupliquée entre {@see \App\Controller\ProjectController} et
 * {@see \App\Controller\PublicDefenseController}, ce qui avait laissé le
 * format /shorts/ non reconnu dans l'une des deux copies.
 *
 * N'affiche jamais du HTML fourni par l'utilisateur : seul l'identifiant
 * extrait (whitelist `[\w-]`) sert à reconstruire une URL d'intégration
 * YouTube de confiance.
 */
class YoutubeUrlExtractor
{
    public function extractVideoId(Project $project): ?string
    {
        foreach ($project->getProofs() as $proof) {
            if (ProofType::YOUTUBE !== $proof->getType()) {
                continue;
            }
            if (preg_match('#(?:youtu\.be/|v=|/embed/|/shorts/)([\w-]{6,})#', $proof->getUrl(), $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    public function embedUrl(string $videoId): string
    {
        return 'https://www.youtube.com/embed/'.$videoId;
    }

    public function thumbnailUrl(string $videoId): string
    {
        return 'https://i.ytimg.com/vi/'.$videoId.'/hqdefault.jpg';
    }
}
