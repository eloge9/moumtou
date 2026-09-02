<?php

namespace App\Security\OAuth;

/**
 * Décrit un fournisseur OAuth2 "Sign in with…" (cahier des charges §5.1 :
 * Google, Facebook, LinkedIn). Implémentation générique au flux
 * "Authorization Code" via symfony/http-client, sans bundle tiers.
 */
final class OAuthProviderConfig
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly ?string $clientId,
        public readonly ?string $clientSecret,
        public readonly string $authorizeUrl,
        public readonly string $tokenUrl,
        public readonly string $userInfoUrl,
        public readonly string $scope,
    ) {
    }

    public function isConfigured(): bool
    {
        return (bool) $this->clientId && (bool) $this->clientSecret;
    }

    /**
     * Extrait (id, e-mail, prénom, nom) de la réponse JSON "userinfo" du
     * fournisseur — chaque API a un format différent.
     *
     * @param array<string, mixed> $userInfo
     *
     * @return array{id: string, email: ?string, firstName: string, lastName: string}
     */
    public function mapUserInfo(array $userInfo): array
    {
        return match ($this->name) {
            'google' => [
                'id' => (string) $userInfo['sub'],
                'email' => $userInfo['email'] ?? null,
                'firstName' => $userInfo['given_name'] ?? ($userInfo['name'] ?? 'Talent'),
                'lastName' => $userInfo['family_name'] ?? 'MOUMTOU',
            ],
            'facebook' => [
                'id' => (string) $userInfo['id'],
                'email' => $userInfo['email'] ?? null,
                'firstName' => explode(' ', (string) ($userInfo['name'] ?? 'Talent'))[0],
                'lastName' => implode(' ', \array_slice(explode(' ', (string) ($userInfo['name'] ?? 'MOUMTOU')), 1)) ?: 'MOUMTOU',
            ],
            'linkedin' => [
                'id' => (string) $userInfo['sub'],
                'email' => $userInfo['email'] ?? null,
                'firstName' => $userInfo['given_name'] ?? 'Talent',
                'lastName' => $userInfo['family_name'] ?? 'MOUMTOU',
            ],
            default => throw new \LogicException('Fournisseur OAuth inconnu.'),
        };
    }
}
