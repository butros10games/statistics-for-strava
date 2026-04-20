<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Domain\Auth\AppUserRepository;
use App\Infrastructure\Time\Clock\Clock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class ForgotPasswordRequestHandler
{
    public function __construct(
        private AppUserRepository $appUserRepository,
        private Clock $clock,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/reset-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function handle(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return new Response($this->twig->render('auth/forgot-password.html.twig'));
        }

        $email = trim($request->request->getString('email'));
        $resetLink = null;

        if ('' !== $email && ($user = $this->appUserRepository->findByEmail($email))) {
            $token = bin2hex(random_bytes(24));
            $user = $user->withPasswordResetToken(
                passwordResetToken: $token,
                requestedAt: $this->clock->getCurrentDateTimeImmutable(),
                updatedAt: $this->clock->getCurrentDateTimeImmutable(),
            );
            $this->appUserRepository->save($user);
            $resetLink = sprintf('/reset-password/%s', $token);
        }

        return new Response($this->twig->render('auth/forgot-password.html.twig', [
            'submitted' => true,
            'resetLink' => $resetLink,
        ]));
    }
}
