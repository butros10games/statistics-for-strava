<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\Auth\AppUserRepository;
use App\Domain\Auth\FirstRegisteredUserDataAssigner;
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
            return new Response($this->twig->render('auth/register.html.twig'));
        }

        $email = trim($request->request->getString('email'));
        $password = $request->request->getString('password');
        $passwordConfirmation = $request->request->getString('passwordConfirmation');

        if ('' === $email || '' === $password) {
            return new Response($this->twig->render('auth/register.html.twig', [
                'error' => 'Email and password are required.',
                'email' => $email,
            ]), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($password !== $passwordConfirmation) {
            return new Response($this->twig->render('auth/register.html.twig', [
                'error' => 'Passwords do not match.',
                'email' => $email,
            ]), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->appUserRepository->emailExists($email)) {
            return new Response($this->twig->render('auth/register.html.twig', [
                'error' => 'An account with that email already exists.',
                'email' => $email,
            ]), Response::HTTP_CONFLICT);
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
}
