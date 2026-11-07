<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\SignupForm;
use App\Repository\UserRepository;
use App\Security\AppAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

final class SignupController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserAuthenticatorInterface $userAuthenticator,
        private readonly AppAuthenticator $authenticator,
    ) {
    }

    #[Route('/signup', name: 'app_signup', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        // ported from prev/functions/auth.php::require_guest() —
        // logged-in visitors get bounced to the shop
        if ($this->getUser()) {
            return $this->redirectToRoute('app_shop');
        }

        $user = new User();
        $form = $this->createForm(SignupForm::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = (string) $user->getEmail();

            // the users.user_email column has a unique index — catch the
            // duplicate warmly here instead of crashing on flush
            $duplicate = $this->userRepository->findOneBy(['email' => $email]);

            if ($duplicate !== null) {
                $form->get('email')->addError(new FormError('An account using this email already exists.'));
            } else {
                $plainPassword = (string) $form->get('password')->getData();

                $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
                $user->setEmail($email);
                $user->setRole('customer');

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                // like the old site: register then log them straight in
                // (the authenticator's own ongoing redirect is ignored on
                // purpose — signup always ends on the celebratory page)
                $this->userAuthenticator->authenticateUser($user, $this->authenticator, $request);

                return $this->redirectToRoute('app_auth_success', ['type' => 'signup']);
            }
        }

        return $this->render('signup/index.html.twig', [
            'signup_form' => $form,
        ]);
    }
}
