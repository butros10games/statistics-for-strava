<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Athlete\Athlete;
use App\Domain\Athlete\AthleteBirthDate;
use App\Domain\Athlete\AthleteRepository;
use App\Domain\Strava\Connection\AppUserStravaConnection;
use App\Domain\Strava\Connection\AppUserStravaConnectionRepository;
use App\Domain\Strava\Strava;
use App\Domain\Strava\StravaClientId;
use App\Domain\Strava\StravaClientSecret;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class StravaOAuthRequestHandler
{
    public function __construct(
        private StravaClientId $stravaClientId,
        private StravaClientSecret $stravaClientSecret,
        private Client $client,
        private CurrentAppUser $currentAppUser,
        private AppUserStravaConnectionRepository $stravaConnectionRepository,
        private AthleteRepository $athleteRepository,
        private AthleteBirthDate $athleteBirthDate,
        private Clock $clock,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/strava-oauth', methods: ['GET'], priority: 2)]
    public function handle(Request $request): Response
    {
        $appUser = $this->currentAppUser->require();

        if ('' === $request->query->getString('code')) {
            return new Response($this->twig->render('html/oauth/start-authorization.html.twig', [
                'stravaClientId' => $this->stravaClientId,
                'returnUrl' => $request->getSchemeAndHttpHost().'/strava-oauth',
                'error' => $request->query->getString('error', ''),
            ]), Response::HTTP_OK);
        }

        try {
            $response = $this->client->post('https://www.strava.com/oauth/token', [
                RequestOptions::FORM_PARAMS => [
                    'grant_type' => 'authorization_code',
                    'client_id' => (string) $this->stravaClientId,
                    'client_secret' => (string) $this->stravaClientSecret,
                    'code' => $request->query->getString('code'),
                ],
            ]);
            $decodedResponse = Json::decode($response->getBody()->getContents());
            $now = $this->clock->getCurrentDateTimeImmutable();

            $this->stravaConnectionRepository->save(AppUserStravaConnection::connect(
                appUserId: $appUser->getId(),
                stravaAthleteId: (string) ($decodedResponse['athlete']['id'] ?? ''),
                refreshToken: (string) $decodedResponse['refresh_token'],
                scopes: array_filter(explode(',', $request->query->getString('scope'))),
                accessTokenExpiresAt: isset($decodedResponse['expires_at']) ? \App\Infrastructure\ValueObject\Time\SerializableDateTime::fromString(date('Y-m-d H:i:s', (int) $decodedResponse['expires_at'])) : null,
                updatedAt: $now,
                webhookCorrelationKey: sprintf('strava-athlete-%s', (string) ($decodedResponse['athlete']['id'] ?? '')),
            ));

            if (isset($decodedResponse['athlete']) && is_array($decodedResponse['athlete'])) {
                $this->athleteRepository->save(Athlete::create([
                    ...$decodedResponse['athlete'],
                    'birthDate' => $this->athleteBirthDate,
                ]));
            }

            return new RedirectResponse('/account/settings?stravaConnected=1', Response::HTTP_FOUND);
        } catch (ClientException|RequestException|Exception $e) {
            $error = $e->getMessage();
            if ($e instanceof ClientException || $e instanceof RequestException) {
                if (($response = $e->getResponse()) instanceof ResponseInterface) {
                    $error = $response->getBody()->getContents();
                }
            }

            return new Response($this->twig->render('html/oauth/error-page.html.twig', [
                'error' => $error,
            ]), Response::HTTP_OK);
        }
    }
}
