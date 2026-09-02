<?php

namespace App\Tests\Functional;

class ExplorerTest extends FunctionalTestCase
{
    /**
     * Régression : les <select> de filtre non renseignés soumettent une
     * chaîne vide ("domain=" etc.), et Request::getInt() lève désormais une
     * BadRequestException sur une valeur vide plutôt que de la traiter comme
     * absente (cf. incident signalé par l'utilisateur).
     */
    public function testEmptyFilterValuesDoNotCrash(): void
    {
        $client = static::createClient();

        $client->request('GET', '/explorer?domain=&mention=&specialty=&institution=&year_min=&sort=recent&page=');

        self::assertResponseIsSuccessful();
    }

    public function testFilterByRealTechnologyAndTypeWorks(): void
    {
        $client = static::createClient();

        $client->request('GET', '/explorer?'.http_build_query(['types' => ['soutenance'], 'technologies' => [1]]));

        self::assertResponseIsSuccessful();
    }
}
