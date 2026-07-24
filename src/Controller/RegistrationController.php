<?php

namespace App\Controller;

use App\Service\UserRegistrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserRegistrationService $userRegistrationService,
    ) {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $errors = [];
        $email = '';

        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email'));
            $password = (string) $request->request->get('password');
            $confirmPassword = (string) $request->request->get('confirmPassword');

            if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_csrf_token'))) {
                $errors['global'] = 'Invalid CSRF token.';
            }

            if (count($errors) === 0) {
                $result = $this->userRegistrationService->register($email, $password, $confirmPassword);
                $errors = $result['errors'];

                if ($result['user'] !== null) {
                    $this->addFlash('success', 'Your account has been created. You can now log in.');

                    return $this->redirectToRoute('app_login');
                }
            }
        }

        return $this->render('registration/register.html.twig', [
            'email' => $email,
            'errors' => $errors,
        ]);
    }
}