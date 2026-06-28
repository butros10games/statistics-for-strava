<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Auth\DisconnectStravaRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\Auth\AppUserRepository;
use App\Domain\Strava\Connection\AppUserStravaConnection;
use App\Domain\Strava\Connection\AppUserStravaConnectionRepository;
use App\Domain\Strava\StravaClientId;
use App\Domain\Strava\StravaClientSecret;
use App\Domain\Strava\StravaTokenRevoker;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Tests\ContainerTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class DisconnectStravaRequestHandlerTest extends ContainerTestCase
{
    private AppUser $appUser;
    private AppUserStravaConnectionRepository $stravaConnectionRepository;
    private MockObject&Client $client;
    private DisconnectStravaRequestHandler $requestHandler;

    public function testHandleRevokesRefreshTokenBeforeDeletingLocalConnection(): void
    {
        $this->stravaConnectionRepository->save(AppUserStravaConnection::connect(
            appUserId: $this->appUser->getId(),
            stravaAthleteId: '12345',
            refreshToken: 'refresh-token',
            scopes: ['activity:read_all'],
            accessTokenExpiresAt: null,
            updatedAt: $this->getContainer()->get(Clock::class)->getCurrentDateTimeImmutable(),
        ));

        $this->client
            ->expects(self::once())
            ->method('post')
            ->with('https://www.strava.com/oauth/revoke', [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Basic '.base64_encode('client:secret'),
                ],
                RequestOptions::FORM_PARAMS => [
                    'token' => 'refresh-token',
                    'token_type_hint' => 'refresh_token',
                ],
            ])
            ->willReturn(new PsrResponse(Response::HTTP_OK));

        $response = $this->requestHandler->handle();

        self::assertEquals(new RedirectResponse('/account/settings', Response::HTTP_FOUND), $response);
        self::assertNull($this->stravaConnectionRepository->findByUserId($this->appUser->getId()));
    }

    public function testHandleWithoutLinkedConnectionRemainsIdempotent(): void
    {
        $this->client
            ->expects(self::never())
            ->method('post');

        $response = $this->requestHandler->handle();

        self::assertEquals(new RedirectResponse('/account/settings', Response::HTTP_FOUND), $response);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $clock = $this->getContainer()->get(Clock::class);
        $appUserRepository = $this->getContainer()->get(AppUserRepository::class);
        $this->stravaConnectionRepository = $this->getContainer()->get(AppUserStravaConnectionRepository::class);

        $this->appUser = AppUser::register(
            appUserId: AppUserId::random(),
            email: 'owner@example.test',
            passwordHash: 'password-hash',
            createdAt: $clock->getCurrentDateTimeImmutable(),
        );
        $appUserRepository->save($this->appUser);

        $security = $this->createStub(Security::class);
        $security
            ->method('getUser')
            ->willReturn($this->appUser);

        $this->client = $this->createMock(Client::class);
        $this->requestHandler = new DisconnectStravaRequestHandler(
            new CurrentAppUser($security),
            $this->stravaConnectionRepository,
            new StravaTokenRevoker(
                $this->client,
                StravaClientId::fromString('client'),
                StravaClientSecret::fromString('secret'),
            ),
        );
    }
}
