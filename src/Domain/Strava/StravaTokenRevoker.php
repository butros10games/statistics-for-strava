<?php

declare(strict_types=1);

namespace App\Domain\Strava;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

final readonly class StravaTokenRevoker
{
    public function __construct(
        private Client $client,
        private StravaClientId $stravaClientId,
        #[\SensitiveParameter]
        private StravaClientSecret $stravaClientSecret,
    ) {
    }

    public function revokeRefreshToken(
        #[\SensitiveParameter]
        string $refreshToken,
    ): void {
        $this->client->post('https://www.strava.com/oauth/revoke', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Basic '.base64_encode(sprintf('%s:%s', $this->stravaClientId, $this->stravaClientSecret)),
            ],
            RequestOptions::FORM_PARAMS => [
                'token' => $refreshToken,
                'token_type_hint' => 'refresh_token',
            ],
        ]);
    }
}
