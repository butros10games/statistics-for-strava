<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

#[AsController]
final readonly class LoginRequestHandler
{
    public function __construct(
        private AuthenticationUtils $authenticationUtils,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function handle(): Response
    {
        return new Response($this->twig->render('auth/login.html.twig', [
            'lastUsername' => $this->authenticationUtils->getLastUsername(),
            'error' => $this->authenticationUtils->getLastAuthenticationError(),
        ]));
    }
}
