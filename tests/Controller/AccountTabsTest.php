<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccountTabsTest extends WebTestCase
{
    private const CUSTOMER_EMAIL = 'tab-customer@example.test';
    private const ADMIN_EMAIL = 'tab-admin@example.test';
    private const PASSWORD = 'tabPassword123';

    protected function tearDown(): void
    {
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'DELETE a FROM user_addresses a JOIN users u ON a.user_id = u.user_id WHERE u.user_email IN (?, ?)',
            [self::CUSTOMER_EMAIL, self::ADMIN_EMAIL]
        );
        $connection->executeStatement(
            'DELETE FROM users WHERE user_email IN (?, ?)',
            [self::CUSTOMER_EMAIL, self::ADMIN_EMAIL]
        );

        parent::tearDown();
    }

    public function testSidebarTabsExistForCustomerAndAdmin(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        $crawler = $client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.account-nav-tabs a[href="?tab=payment_options"]');
        self::assertSelectorExists('.account-nav-tabs a[href="?tab=addresses"]');

        $admin = $this->makeUser('admin', self::ADMIN_EMAIL);
        $client->loginUser($admin);

        $client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.account-nav-tabs a[href="?tab=payment_options"]');
        self::assertSelectorExists('.account-nav-tabs a[href="?tab=addresses"]');
    }

    public function testPaymentOptionsTabManagesGcashAndCard(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        $crawler = $client->request('GET', '/account?tab=payment_options');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-panel h2', 'GCash Account');

        $token = $crawler->filter('form input[value="update_payment_options"]')->closest('form')->filter('input[name="_csrf_token"]')->attr('value');

        // Attach GCash
        $client->request('POST', '/account', [
            'action' => 'update_payment_options',
            'payment_type' => 'gcash',
            'gcash_number' => '09187654321',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/account?tab=payment_options');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.ok', 'GCash account updated successfully');

        $connection = static::getContainer()->get(Connection::class);
        $gcash = $connection->fetchOne('SELECT gcash_number FROM users WHERE user_id = ?', [$customer->getId()]);
        self::assertSame('09187654321', $gcash);

        // Attach Card
        $crawler = $client->request('GET', '/account?tab=payment_options');
        $token = $crawler->filter('form input[value="update_payment_options"]')->closest('form')->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/account', [
            'action' => 'update_payment_options',
            'payment_type' => 'card',
            'card_number' => '4111222233334444',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/account?tab=payment_options');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.ok', 'Card details updated successfully');

        $card = $connection->fetchOne('SELECT card_number FROM users WHERE user_id = ?', [$customer->getId()]);
        self::assertSame('4111222233334444', $card);

        // Remove GCash
        $crawler = $client->request('GET', '/account?tab=payment_options');
        $token = $crawler->filter('form input[value="remove_payment_option"]')->closest('form')->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/account', [
            'action' => 'remove_payment_option',
            'payment_type' => 'gcash',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/account?tab=payment_options');
        $client->followRedirect();

        $gcash = $connection->fetchOne('SELECT gcash_number FROM users WHERE user_id = ?', [$customer->getId()]);
        self::assertNull($gcash);
    }

    public function testAddressesTabCanAddEditAndSetDefaultDeliveryAddress(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        $crawler = $client->request('GET', '/account?tab=addresses');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-panel h2', 'Delivery Addresses');

        $token = $crawler->filter('form input[value="add_address"]')->closest('form')->filter('input[name="_csrf_token"]')->attr('value');

        // Add an address
        $client->request('POST', '/account', [
            'action' => 'add_address',
            'label' => 'Home',
            'recipient_name' => 'Maria Santos',
            'phone_number' => '09191234567',
            'region' => 'CALABARZON (Region IV-A)',
            'province' => 'Rizal',
            'city' => 'Antipolo City',
            'barangay' => 'San Roque',
            'street_address' => 'Block 5 Lot 12, Dahlia St.',
            'postal_code' => '1870',
            'is_default' => '1',
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/account?tab=addresses');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.ok', 'New delivery address added successfully');
        self::assertSelectorTextContains('.account-panel', 'Maria Santos');
        self::assertSelectorTextContains('.account-panel', 'Antipolo City');
        self::assertSelectorTextContains('.badge-status.active', 'Default');

        $connection = static::getContainer()->get(Connection::class);
        $addr_id = (int) $connection->fetchOne('SELECT address_id FROM user_addresses WHERE user_id = ?', [$customer->getId()]);
        self::assertGreaterThan(0, $addr_id);

        // Edit address
        $token = $crawler->filter('#token_edit_address')->attr('value');
        $client->request('POST', '/account', [
            'action' => 'edit_address',
            'address_id' => $addr_id,
            'label' => 'Office',
            'recipient_name' => 'Maria Santos Office',
            'phone_number' => '09191234567',
            'region' => 'CALABARZON (Region IV-A)',
            'province' => 'Rizal',
            'city' => 'Antipolo City',
            'barangay' => 'San Roque',
            'street_address' => 'Floor 3, Tech Tower',
            'postal_code' => '1870',
            'is_default' => '1',
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/account?tab=addresses');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-panel', 'Maria Santos Office');
        self::assertSelectorTextContains('.account-panel', 'Floor 3, Tech Tower');

        // Delete address
        $token = $crawler->filter('form input[value="delete_address"] + input[name="address_id"] + input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/account', [
            'action' => 'delete_address',
            'address_id' => $addr_id,
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/account?tab=addresses');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.ok', 'Address deleted successfully');

        $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM user_addresses WHERE user_id = ?', [$customer->getId()]);
        self::assertSame(0, $count);
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
}
