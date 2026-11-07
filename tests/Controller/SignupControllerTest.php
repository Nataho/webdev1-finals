<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SignupControllerTest extends WebTestCase
{
    private const EMAIL = 'fresh-botanist@example.test';
    private const USERNAME = 'fresh_botanist';
    private const DUPLICATE_EMAIL = 'taken-botanist@example.test';
    private const PASSWORD = 'botanicalBreeze9';

    protected function tearDown(): void
    {
        // tests write to the real database — sweep the test rows out again
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQueryBuilder()
            ->delete('App\Entity\User', 'u')
            ->where('u.email IN (:emails) OR u.username IN (:usernames)')
            ->setParameter('emails', [self::EMAIL, self::DUPLICATE_EMAIL])
            ->setParameter('usernames', [self::USERNAME, self::USERNAME.'_old'])
            ->getQuery()
            ->execute();

        parent::tearDown();
    }

    public function testSignupPageIsReachableForGuests(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/signup');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form.auth-form');
        self::assertSelectorExists('input[name="signup_form[username]"]');
        self::assertSelectorExists('input[name="signup_form[email]"]');
        self::assertSelectorExists('input[name="signup_form[password][first]"]');
        self::assertSelectorExists('input[name="signup_form[password][second]"]');

        // reuse the live form object for the POSTs below (it carries the CSRF token)
        self::assertNotNull($crawler->selectButton('Register Account')->form());
    }

    public function testSignupRejectsMismatchedPasswords(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/signup');
        $form = $crawler->selectButton('Register Account')->form();
        $form['signup_form[username]'] = self::USERNAME;
        $form['signup_form[email]'] = self::EMAIL;
        $form['signup_form[password][first]'] = 'growsGracefully8';
        $form['signup_form[password][second]'] = 'growsGracefully9';
        $client->submit($form);

        // invalid form submissions bounce back as HTTP 422 in modern Symfony
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.auth-alert-error', 'Passwords do not match.');

        // nothing was persisted
        $repository = static::getContainer()->get(UserRepository::class);
        self::assertNull($repository->findOneBy(['email' => self::EMAIL]));
    }

    public function testSignupRejectsShortPassword(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/signup');
        $form = $crawler->selectButton('Register Account')->form();
        $form['signup_form[username]'] = self::USERNAME;
        $form['signup_form[email]'] = self::EMAIL;
        $form['signup_form[password][first]'] = 'short';
        $form['signup_form[password][second]'] = 'short';
        $client->submit($form);

        // invalid form submissions bounce back as HTTP 422 in modern Symfony
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.auth-alert-error', 'Password must be at least 8 characters.');
    }

    public function testSignupCreatesUserLogsInAndLandsOnSuccessPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/signup');
        $form = $crawler->selectButton('Register Account')->form();
        $form['signup_form[username]'] = self::USERNAME;
        $form['signup_form[email]'] = self::EMAIL;
        $form['signup_form[password][first]'] = self::PASSWORD;
        $form['signup_form[password][second]'] = self::PASSWORD;
        $client->submit($form);

        self::assertResponseRedirects();

        $crawler = $client->followRedirect();
        self::assertSame('/auth-success?type=signup', $client->getRequest()->getRequestUri());
        // the header partial has its own h1 — scope to the card
        self::assertSelectorTextContains('.auth-card h1', 'Account Created!');
        self::assertSelectorTextContains('.auth-header', self::USERNAME);

        // the new session is genuinely logged in — the account page answers directly
        $client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        // and the row exists with a hashed password
        $repository = static::getContainer()->get(UserRepository::class);
        $user = $repository->findOneBy(['email' => self::EMAIL]);
        self::assertNotNull($user);
        self::assertSame(self::USERNAME, $user->getUsername());
        self::assertSame('customer', $user->getRole());
        self::assertNotSame(self::PASSWORD, $user->getPassword());
    }

    public function testSignupRejectsDuplicateEmail(): void
    {
        $client = static::createClient();
        $this->createUser(self::USERNAME.'_old', self::DUPLICATE_EMAIL);

        $crawler = $client->request('GET', '/signup');
        $form = $crawler->selectButton('Register Account')->form();
        $form['signup_form[username]'] = self::USERNAME;
        $form['signup_form[email]'] = self::DUPLICATE_EMAIL;
        $form['signup_form[password][first]'] = self::PASSWORD;
        $form['signup_form[password][second]'] = self::PASSWORD;
        $client->submit($form);

        // invalid form submissions bounce back as HTTP 422 in modern Symfony
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.auth-alert-error', 'An account using this email already exists.');

        // exactly one account still exists for that email
        $repository = static::getContainer()->get(UserRepository::class);
        self::assertCount(1, $repository->findBy(['email' => self::DUPLICATE_EMAIL]));
    }

    private function createUser(string $username, string $email): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setRole('customer');
        $user->setPassword('not-a-real-hash');

        $entityManager->persist($user);
        $entityManager->flush();
    }
}
