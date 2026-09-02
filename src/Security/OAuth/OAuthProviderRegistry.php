<?php

namespace App\Security\OAuth;

/**
 * Fournit la configuration des 3 fournisseurs attendus par le cahier des
 * charges (§5.1, §36). Les identifiants applicatifs (client_id/secret)
 * proviennent de variables d'environnement documentées dans .env.example —
 * tant qu'elles ne sont pas renseignées, le fournisseur reste "non
 * configuré" et /connexion/{provider} affiche un message clair au lieu de
 * planter (cahier des charges §48).
 */
class OAuthProviderRegistry
{
    /** @var array<string, OAuthProviderConfig> */
    private array $providers;

    public function __construct(
        ?string $googleClientId,
        ?string $googleClientSecret,
        ?string $facebookClientId,
        ?string $facebookClientSecret,
        ?string $linkedinClientId,
        ?string $linkedinClientSecret,
    ) {
        $this->providers = [
            'google' => new OAuthProviderConfig(
                name: 'google',
                label: 'Google',
                clientId: $googleClientId ?: null,
                clientSecret: $googleClientSecret ?: null,
                authorizeUrl: 'https://accounts.google.com/o/oauth2/v2/auth',
                tokenUrl: 'https://oauth2.googleapis.com/token',
                userInfoUrl: 'https://openidconnect.googleapis.com/v1/userinfo',
                scope: 'openid email profile',
            ),
            'facebook' => new OAuthProviderConfig(
                name: 'facebook',
                label: 'Facebook',
                clientId: $facebookClientId ?: null,
                clientSecret: $facebookClientSecret ?: null,
                authorizeUrl: 'https://www.facebook.com/v19.0/dialog/oauth',
                tokenUrl: 'https://graph.facebook.com/v19.0/oauth/access_token',
                userInfoUrl: 'https://graph.facebook.com/me?fields=id,name,email',
                scope: 'email public_profile',
            ),
            'linkedin' => new OAuthProviderConfig(
                name: 'linkedin',
                label: 'LinkedIn',
                clientId: $linkedinClientId ?: null,
                clientSecret: $linkedinClientSecret ?: null,
                authorizeUrl: 'https://www.linkedin.com/oauth/v2/authorization',
                tokenUrl: 'https://www.linkedin.com/oauth/v2/accessToken',
                userInfoUrl: 'https://api.linkedin.com/v2/userinfo',
                scope: 'openid email profile',
            ),
        ];
    }

    public function get(string $name): ?OAuthProviderConfig
    {
        return $this->providers[$name] ?? null;
    }

    /** @return OAuthProviderConfig[] */
    public function all(): array
    {
        return $this->providers;
    }
}
