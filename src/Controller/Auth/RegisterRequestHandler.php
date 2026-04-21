<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Application\AppUrl;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\Auth\AppUserRepository;
use App\Domain\Auth\FirstRegisteredUserDataAssigner;
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
final readonly class RegisterRequestHandler
{
    public function __construct(
        private AppUrl $appUrl,
        private AppUserRepository $appUserRepository,
        private FirstRegisteredUserDataAssigner $firstRegisteredUserDataAssigner,
        private UserPasswordHasherInterface $passwordHasher,
        private Clock $clock,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function handle(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->renderPortal();
        }

        $email = trim($request->request->getString('email'));
        $password = $request->request->getString('password');
        $passwordConfirmation = $request->request->getString('passwordConfirmation');

        if ('' === $email || '' === $password) {
            return $this->renderPortal('Email and password are required.', $email, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($password !== $passwordConfirmation) {
            return $this->renderPortal('Passwords do not match.', $email, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->appUserRepository->emailExists($email)) {
            return $this->renderPortal('An account with that email already exists.', $email, Response::HTTP_CONFLICT);
        }

        $now = $this->clock->getCurrentDateTimeImmutable();
        $appUser = AppUser::register(
            appUserId: AppUserId::random(),
            email: $email,
            passwordHash: '',
            createdAt: $now,
        );
        $appUser = $appUser->withPasswordHash(
            passwordHash: $this->passwordHasher->hashPassword($appUser, $password),
            updatedAt: $now,
        );
        $this->appUserRepository->save($appUser);
        $this->firstRegisteredUserDataAssigner->assign($appUser);

        return new RedirectResponse('/login?registered=1', Response::HTTP_FOUND);
    }

    private function renderPortal(?string $error = null, string $email = '', int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render('auth/react-portal.html.twig', [
            'pageTitle' => 'Statistics for Strava · Create account',
            'reactPortalBootstrap' => Json::encode([
                'kind' => 'register',
                'appName' => 'Statistics for Strava',
                'basePath' => $this->appUrl->getBasePath() ?? '',
                'error' => $error,
                'email' => $email,
                'actions' => [
                    'submitPath' => 'register',
                    'loginPath' => 'login',
                ],
            ]),
        ]), $status);
    }
}
