<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Application\AppUrl;
use App\Domain\Auth\AppUserRepository;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class ResetPasswordRequestHandler
{
    public function __construct(
        private AppUrl $appUrl,
        private AppUserRepository $appUserRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private Clock $clock,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function handle(string $token, Request $request): Response
    {
        $user = $this->appUserRepository->findByPasswordResetToken($token);

        if (null === $user) {
            return $this->renderPortal(
                token: null,
                error: 'That reset link is not valid anymore.',
                status: Response::HTTP_NOT_FOUND,
            );
        }

        if ($request->isMethod('GET')) {
            return $this->renderPortal(token: $token);
        }

        $password = $request->request->getString('password');
        $passwordConfirmation = $request->request->getString('passwordConfirmation');

        if ('' === $password || $password !== $passwordConfirmation) {
            return $this->renderPortal(
                token: $token,
                error: 'Enter the same new password twice.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->appUserRepository->save($user->withPasswordHash(
            passwordHash: $this->passwordHasher->hashPassword($user, $password),
            updatedAt: $this->clock->getCurrentDateTimeImmutable(),
        ));

        return new RedirectResponse('/login?passwordReset=1', Response::HTTP_FOUND);
    }

    private function renderPortal(?string $token, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render('auth/react-portal.html.twig', [
            'pageTitle' => 'Statistics for Strava · Choose a new password',
            'reactPortalBootstrap' => Json::encode([
                'kind' => 'reset-password',
                'appName' => 'Statistics for Strava',
                'basePath' => $this->appUrl->getBasePath() ?? '',
                'error' => $error,
                'token' => $token,
                'actions' => [
                    'submitPath' => null !== $token ? sprintf('reset-password/%s', $token) : null,
                    'loginPath' => 'login',
                ],
            ]),
        ]), $status);
    }
}
