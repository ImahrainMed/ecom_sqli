<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager
    ): Response {
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

            if ($email === '') {
                $errors['email'] = 'Email is required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Please enter a valid email address.';
            }

            if ($password === '') {
                $errors['password'] = 'Password is required.';
            } elseif (strlen($password) < 6) {
                $errors['password'] = 'Password must contain at least 6 characters.';
            }

            if ($confirmPassword === '') {
                $errors['confirmPassword'] = 'Please confirm your password.';
            } elseif ($password !== $confirmPassword) {
                $errors['confirmPassword'] = 'Passwords do not match.';
            }

            if (count($errors) === 0) {
                $existingUser = $entityManager
                    ->getRepository(User::class)
                    ->findOneBy(['email' => $email]);

                if ($existingUser) {
                    $errors['email'] = 'An account already exists with this email.';
                }
            }

            if (count($errors) === 0) {
                $user = new User();
                $user->setEmail($email);
                $user->setRoles(['ROLE_USER']);
                $user->setPassword($passwordHasher->hashPassword($user, $password));

                $entityManager->persist($user);
                $entityManager->flush();

                $this->addFlash('success', 'Your account has been created. You can now log in.');

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('registration/register.html.twig', [
            'email' => $email,
            'errors' => $errors,
        ]);
    }
}