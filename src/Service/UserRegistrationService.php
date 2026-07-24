<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserRegistrationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{user: ?User, errors: array<string, string>}
     */
    public function register(string $email, string $password, string $confirmPassword): array
    {
        $email = trim($email);

        $errors = $this->validate($email, $password, $confirmPassword);

        if (count($errors) > 0) {
            return [
                'user' => null,
                'errors' => $errors,
            ];
        }

        $existingUser = $this->userRepository->findOneBy([
            'email' => $email,
        ]);

        if ($existingUser) {
            return [
                'user' => null,
                'errors' => [
                    'email' => 'An account already exists with this email.',
                ],
            ];
        }

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return [
            'user' => $user,
            'errors' => [],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function validate(string $email, string $password, string $confirmPassword): array
    {
        $errors = [];

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

        return $errors;
    }
}