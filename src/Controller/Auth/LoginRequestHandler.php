<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Application\AppUrl;
use App\Infrastructure\Serialization\Json;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AsController]
final readonly class LoginRequestHandler
{
    public function __construct(
        private AppUrl $appUrl,
        private AuthenticationUtils $authenticationUtils,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function handle(Request $request): Response
    {
        $error = $this->authenticationUtils->getLastAuthenticationError();

        return new Response($this->twig->render('auth/react-portal.html.twig', [
            'pageTitle' => 'Statistics for Strava · Sign in',
            'reactPortalBootstrap' => Json::encode([
                'kind' => 'login',
                'appName' => 'Statistics for Strava',
                'basePath' => $this->appUrl->getBasePath() ?? '',
                'notices' => [
                    'registered' => $request->query->getBoolean('registered'),
                    'passwordReset' => $request->query->getBoolean('passwordReset'),
                ],
                'error' => null !== $error ? $this->translator->trans($error->getMessageKey(), $error->getMessageData(), 'security') : null,
                'lastUsername' => $this->authenticationUtils->getLastUsername(),
                'csrfToken' => $this->csrfTokenManager->getToken('authenticate')->getValue(),
                'actions' => [
                    'loginPath' => 'login',
                    'registerPath' => 'register',
                    'forgotPasswordPath' => 'reset-password',
                ],
            ]),
        ]));
    }
}
