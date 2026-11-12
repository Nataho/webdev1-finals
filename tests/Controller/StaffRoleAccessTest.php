<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StaffRoleAccessTest extends WebTestCase
{
    private const STAFF_EMAIL = 'role-staff@example.test';
    private const CUSTOMER_EMAIL = 'role-customer@example.test';
    private const ADMIN_EMAIL = 'role-admin@example.test';
    private const PASSWORD = 'rolePassword123';

    private int $product_id = 0;

    protected function tearDown(): void
    {
        $connection = static::getContainer()->get(Connection::class);
        if ($this->product_id > 0) {
            $connection->executeStatement('DELETE FROM products WHERE product_id = ?', [$this->product_id]);
        }
        $connection->executeStatement(
            'DELETE FROM users WHERE user_email IN (?, ?, ?)',
            [self::STAFF_EMAIL, self::CUSTOMER_EMAIL, self::ADMIN_EMAIL]
        );

        parent::tearDown();
    }

    public function testStaffRoleMapsThroughEntityAndRoleHierarchy(): void
    {
        $client = static::createClient();
        $staff = $this->makeUser('staff', self::STAFF_EMAIL);

        self::assertContains('ROLE_STAFF', $staff->getRoles());
        self::assertContains('ROLE_USER', $staff->getRoles());
        self::assertTrue($staff->isStaff());

        // the entity can round-trip the role string both ways
        $staff->setRoles(['ROLE_STAFF', 'ROLE_USER']);
        self::assertSame('staff', $staff->getRole());
        $staff->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        self::assertSame('admin', $staff->getRole());
        $staff->setRoles(['ROLE_USER']);
        self::assertSame('customer', $staff->getRole());
    }

    public function testStaffIsDeniedAdminTabsWithVisibleFlash(): void
    {
        $client = static::createClient();
        $staff = $this->makeUser('staff', self::STAFF_EMAIL);
        $client->loginUser($staff);

        // poking the users tab falls back to the default tab with a denial flash
        $crawler = $client->request('GET', '/account?tab=users');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-alert.err', 'You do not have permission to open that area.');
        self::assertSelectorNotExists('.account-nav-tabs a[href="?tab=users"]');
        self::assertSelectorNotExists('.account-nav-tabs a[href="?tab=dashboard"]');

        // same treatment for a customer
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);
        $client->request('GET', '/account?tab=stock');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-alert.err', 'You do not have permission to open that area.');
    }

    public function testStaffIsDeniedAdminPostActionAndStockStaysUntouched(): void
    {
        $client = static::createClient();
        $connection = static::getContainer()->get(Connection::class);

        $this->product_id = $this->makeProduct($connection, 7);
        $staff = $this->makeUser('staff', self::STAFF_EMAIL);
        $client->loginUser($staff);

        // a staff POST must be denied before any token validation runs,
        // so a bogus CSRF token still produces the permission flash
        $client->request('POST', '/account', [
            'action' => 'update_stock',
            'product_id' => (string) $this->product_id,
            'new_stock' => '99',
            '_csrf_token' => 'not-a-real-token',
        ]);

        self::assertResponseRedirects('/account');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.err', 'You do not have permission to do that.');

        $stock = (int) $connection->fetchOne('SELECT product_stock_quantity FROM products WHERE product_id = ?', [$this->product_id]);
        self::assertSame(7, $stock, 'a staff POST must not change stock');
    }

    public function testAdminStillReachesAdminTabsAndActions(): void
    {
        $client = static::createClient();
        $connection = static::getContainer()->get(Connection::class);

        $this->product_id = $this->makeProduct($connection, 7);
        $admin = $this->makeUser('admin', self::ADMIN_EMAIL);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/account?tab=stock');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.account-nav-tabs a[href="?tab=users"]');

        // admin action still works end to end after the guardrail rework
        $token = $crawler->filter('form input[value="update_stock"]')->closest('form')->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/account', [
            'action' => 'update_stock',
            'product_id' => (string) $this->product_id,
            'new_stock' => '42',
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/account?tab=stock');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.ok', 'Stock level updated successfully');

        $stock = (int) $connection->fetchOne('SELECT product_stock_quantity FROM products WHERE product_id = ?', [$this->product_id]);
        self::assertSame(42, $stock);
    }

    public function testAnonymousAccountVisitRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account');
        self::assertResponseRedirects();
    }

    private function makeUser(string $role, string $email): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $repo = $em->getRepository(User::class);

        $existing = $repo->findOneBy(['email' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }

        $user = new User();
        $prefix = explode('@', $email)[0];
        $user->setUsername($prefix);
        $user->setEmail($email);
        $user->setRole($role);
        $user->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function makeProduct(Connection $connection, int $stock): int
    {
        $connection->executeStatement(
            'INSERT INTO products (product_name, product_description, product_price, product_stock_quantity, product_img)
            VALUES (?, ?, ?, ?, ?)',
            ['Staff Role Test Product', 'temporary row for the staff access test', 10.0, $stock, '']
        );

        return (int) $connection->lastInsertId();
    }
}
