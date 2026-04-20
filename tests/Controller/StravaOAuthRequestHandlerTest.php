<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\StravaOAuthRequestHandler;
use App\Domain\Athlete\AthleteBirthDate;
use App\Domain\Athlete\AthleteRepository;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\Auth\AppUserRepository;
use App\Domain\Strava\Connection\AppUserStravaConnectionRepository;
use App\Domain\Strava\StravaClientId;
use App\Domain\Strava\StravaClientSecret;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class StravaOAuthRequestHandlerTest extends ContainerTestCase
{
    private StravaOAuthRequestHandler $stravaOAuthRequestHandler;
    private MockObject&Client $client;
    private AppUser $appUser;
    private AppUserStravaConnectionRepository $stravaConnectionRepository;
    private AthleteRepository $athleteRepository;
    private Clock $clock;

    public function testHandleWithoutCodeStartsAuthorization(): void
    {
        $this->client
            ->expects(self::never())
            ->method('post');

        $response = $this->stravaOAuthRequestHandler->handle(Request::create('/strava-oauth', 'GET', server: [
            'HTTP_HOST' => 'example.test',
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('client', (string) $response->getContent());
        self::assertStringContainsString('http://example.test/strava-oauth', (string) $response->getContent());
    }

    public function testHandleWithCodeLinksTheAuthenticatedUser(): void
    {
        $this->client
            ->expects(self::once())
            ->method('post')
            ->with('https://www.strava.com/oauth/token', [
                RequestOptions::FORM_PARAMS => [
                    'grant_type' => 'authorization_code',
                    'client_id' => 'client',
                    'client_secret' => 'secret',
                    'code' => 'the-code',
                ],
            ])
            ->willReturn(new PsrResponse(200, [], Json::encode([
                'refresh_token' => 'the-token',
                'expires_at' => 1_735_689_600,
                'athlete' => [
                    'id' => 12345,
                    'firstname' => 'Ada',
                    'lastname' => 'Lovelace',
                    'birthDate' => '1990-01-01',
                ],
            ])));

        $response = $this->stravaOAuthRequestHandler->handle(Request::create('/strava-oauth', 'GET', [
            'code' => 'the-code',
            'scope' => 'read,activity:read_all',
        ]));

        self::assertEquals(
            new RedirectResponse('/account/settings?stravaConnected=1', Response::HTTP_FOUND),
            $response,
        );

        $connection = $this->stravaConnectionRepository->findByUserId($this->appUser->getId());
        self::assertNotNull($connection);
        self::assertSame('12345', $connection->getStravaAthleteId());
        self::assertSame('the-token', $connection->getRefreshToken());
        self::assertSame(['read', 'activity:read_all'], $connection->getScopes());

        $athlete = $this->athleteRepository->find();
        self::assertSame('12345', $athlete->getAthleteId());
        self::assertSame('Ada Lovelace', (string) $athlete->getName());
    }

    public function testHandleWithCodeRendersErrorPageOnOauthFailure(): void
    {
        $this->client
            ->expects(self::once())
            ->method('post')
            ->willThrowException(new RequestException(
                message: 'The error',
                request: new PsrRequest('POST', 'https://www.strava.com/oauth/token'),
                response: new PsrResponse(404, [], Json::encode(['error' => 'The error'])),
            ));

        $response = $this->stravaOAuthRequestHandler->handle(Request::create('/strava-oauth', 'GET', [
            'code' => 'the-code',
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('The error', (string) $response->getContent());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = $this->getContainer()->get(Clock::class);
        $appUserRepository = $this->getContainer()->get(AppUserRepository::class);
        $this->stravaConnectionRepository = $this->getContainer()->get(AppUserStravaConnectionRepository::class);
        $this->athleteRepository = $this->getContainer()->get(AthleteRepository::class);
        $twig = $this->getContainer()->get(Environment::class);

        $this->appUser = AppUser::register(
            appUserId: AppUserId::random(),
            email: 'owner@example.test',
            passwordHash: 'password-hash',
            createdAt: $this->clock->getCurrentDateTimeImmutable(),
        );
        $appUserRepository->save($this->appUser);

        $security = $this->createStub(Security::class);
        $security
            ->method('getUser')
            ->willReturn($this->appUser);

        $this->stravaOAuthRequestHandler = new StravaOAuthRequestHandler(
            StravaClientId::fromString('client'),
            StravaClientSecret::fromString('secret'),
            $this->client = $this->createMock(Client::class),
            new CurrentAppUser($security),
            $this->stravaConnectionRepository,
            $this->athleteRepository,
            AthleteBirthDate::fromString('1990-01-01'),
            $this->clock,
            $twig,
        );
    }
}
