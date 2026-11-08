<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

// checkout page tests — cart review, quantity updates, removals and the place-order transaction
// every test seeds its own rows under the 'Shop Test' prefix and sweeps them in tearDown
final class CheckoutControllerTest extends WebTestCase
{
    private const CUSTOMER_EMAIL = 'checkout-tester@example.test';
    private const OUTSIDER_EMAIL = 'checkout-tester-outsider@example.test';
    private const ADMIN_EMAIL = 'checkout-tester-admin@example.test';
    private const BANNED_EMAIL = 'checkout-tester-banned@example.test';
    private const ALL_TEST_EMAILS = [
        self::CUSTOMER_EMAIL,
        self::OUTSIDER_EMAIL,
        self::ADMIN_EMAIL,
        self::BANNED_EMAIL,
    ];
    private const PASSWORD = 'checkoutBreeze8';

    protected function tearDown(): void
    {
        // sweep order rows, products and users out again (children first, per the FK order)
        $connection = static::getContainer()->get(Connection::class);

        $emails = self::ALL_TEST_EMAILS;
        $placeholders = implode(',', array_fill(0, count($emails), '?'));

        $connection->executeStatement(
            "DELETE oi FROM order_items oi
             JOIN orders o ON oi.order_id = o.order_id
             JOIN users u ON o.user_id = u.user_id
             WHERE u.user_email IN ($placeholders)",
            $emails,
            array_fill(0, count($emails), ParameterType::STRING)
        );
        $connection->executeStatement(
            "DELETE o FROM orders o
             JOIN users u ON o.user_id = u.user_id
             WHERE u.user_email IN ($placeholders)",
            $emails,
            array_fill(0, count($emails), ParameterType::STRING)
        );
        $connection->executeStatement("DELETE FROM products WHERE product_name LIKE 'Shop Test %'");
        $connection->executeStatement(
            "DELETE FROM users WHERE user_email IN ($placeholders)",
            $emails,
            array_fill(0, count($emails), ParameterType::STRING)
        );

        parent::tearDown();
    }

    public function testCheckoutIsLockedForGuests(): void
    {
        $client = static::createClient();
        $client->request('GET', '/checkout');

        // guests must login to checkout
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorExists('.auth-card');
    }

    public function testBannedUserGetsBouncedToShop(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('banned', self::BANNED_EMAIL));

        // banned accounts may not even see the checkout page
        $client->request('GET', '/checkout');
        self::assertResponseRedirects();
        self::assertStringContainsString('/shop', $client->getResponse()->getTargetUrl());

        // and the POST route bounces to the shop as well
        $client->request('POST', '/checkout', ['action' => 'place_order', 'product_id' => 1, 'quantity' => '1']);
        self::assertResponseRedirects();
        self::assertStringContainsString('/shop', $client->getResponse()->getTargetUrl());
    }

    public function testEmptyCartShowsEmptyPanel(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $crawler = $client->request('GET', '/checkout');
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('.checkout-container h1', 'Checkout & Review');
        self::assertSelectorTextContains('.empty-cart-view h2', 'Your cart is currently empty');
        self::assertSelectorExists('.empty-cart-view a'); // Browse Botanical Shop — back to the shop
        self::assertSelectorNotExists('.btn-place-order');
    }

    public function testCartRendersRowsWithTotals(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $serum_id = $this->createProduct('Shop Test Serum', '499.00', 10, null);
        $tonic_id = $this->createProduct('Shop Test Tonic', '150.00', 20, null);
        $this->fillCartViaShop($client, [$serum_id => 2, $tonic_id => 1]);

        $crawler = $client->request('GET', '/checkout');
        self::assertResponseIsSuccessful();

        // both rows show up with the unit price and the line total (IN() rows have no stable order — sort before comparing)
        self::assertSelectorTextContains('h2', 'Items in Your Cart (2)');
        $names = $crawler->filter('.cart-item-row .cart-item-meta h4')->each(static fn ($n) => $n->text());
        self::assertSame(['Shop Test Serum', 'Shop Test Tonic'], $names);

        $unit_prices = $crawler->filter('.unit-price')->each(static fn ($n) => $n->text());
        self::assertContains('₱499.00 each', $unit_prices);
        self::assertContains('₱150.00 each', $unit_prices);

        $line_totals = $crawler->filter('.cart-item-line-total')->each(static fn ($n) => $n->text());
        self::assertContains('₱998.00', $line_totals);
        self::assertContains('₱150.00', $line_totals);

        // the summary card totals the cart and the confirm modal carries the place_order token
        self::assertSelectorTextContains('.summary-row.total', '₱1,148.00');
        self::assertSelectorTextContains('.confirm-modal-message', 'Are you sure you want to place this botanical order for ₱1,148.00?');
        self::assertSelectorExists('#placeOrderModal form input[name="_csrf_token"]');
        self::assertSelectorExists('#placeOrderModal form input[value="place_order"]');
    }

    public function testUpdateQtyAdjustsQuantityAndTotals(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $serum_id = $this->createProduct('Shop Test Serum', '499.00', 10, null);
        $this->fillCartViaShop($client, [$serum_id => 2]);

        $crawler = $client->request('GET', '/checkout');
        $token = $crawler->filter('form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/checkout', [
            'action' => 'update_qty',
            '_csrf_token' => $token,
            'product_id' => $serum_id,
            'quantity' => '3',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('#checkoutAlertModal .confirm-modal-message', 'Cart quantity updated.');

        $cart = $client->getRequest()->getSession()->get('cart');
        self::assertSame([$serum_id => 3], $cart);
    }

    public function testUpdateQtyClampsToAvailableStock(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $serum_id = $this->createProduct('Shop Test Serum', '499.00', 10, null);
        $this->fillCartViaShop($client, [$serum_id => 2]);

        $crawler = $client->request('GET', '/checkout');
        $token = $crawler->filter('form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/checkout', [
            'action' => 'update_qty',
            '_csrf_token' => $token,
            'product_id' => $serum_id,
            'quantity' => '99',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('#checkoutAlertModal .confirm-modal-message', 'Quantity capped to available stock (10 units).');

        $cart = $client->getRequest()->getSession()->get('cart');
        self::assertSame([$serum_id => 10], $cart);
    }

    public function testUpdateQtyAtZeroRemovesItem(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $serum_id = $this->createProduct('Shop Test Serum', '499.00', 10, null);
        $this->fillCartViaShop($client, [$serum_id => 2]);

        $crawler = $client->request('GET', '/checkout');
        $token = $crawler->filter('form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/checkout', [
            'action' => 'update_qty',
            '_csrf_token' => $token,
            'product_id' => $serum_id,
            'quantity' => '0',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('#checkoutAlertModal .confirm-modal-message', 'Item removed from cart.');

        // the minus stepper never goes below zero, the cart ends up empty
        $cart = $client->getRequest()->getSession()->get('cart');
        self::assertSame([], $cart);
    }

    public function testRemoveItemDropsOneRow(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $serum_id = $this->createProduct('Shop Test Serum', '499.00', 10, null);
        $tonic_id = $this->createProduct('Shop Test Tonic', '150.00', 20, null);
        $this->fillCartViaShop($client, [$serum_id => 2, $tonic_id => 1]);

        $crawler = $client->request('GET', '/checkout');

        // the remove form is the second form inside the serum row
        $remove_form = $crawler->filter('.cart-item-row')->first()->filter('form')->eq(1);
        $token = $remove_form->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/checkout', [
            'action' => 'remove_item',
            '_csrf_token' => $token,
            'product_id' => $serum_id,
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('#checkoutAlertModal .confirm-modal-message', 'Item removed from cart.');

        $cart = $client->getRequest()->getSession()->get('cart');
        self::assertSame([$tonic_id => 1], $cart);
    }

    public function testPlaceOrderCreatesOrderItemsAndDeductsStock(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        $serum_id = $this->createProduct('Shop Test Serum', '499.00', 10, null);
        $tonic_id = $this->createProduct('Shop Test Tonic', '150.00', 5, null);
        $this->fillCartViaShop($client, [$serum_id => 2, $tonic_id => 1]);

        $crawler = $client->request('GET', '/checkout');
        $token = $crawler->filter('#placeOrderModal form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/checkout', [
            'action' => 'place_order',
            '_csrf_token' => $token,
            'product_id' => $serum_id,
            'quantity' => '1',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('order_id=', $client->getResponse()->getTargetUrl());
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        // the summary banner opens and the order number is five digits padded
        self::assertSelectorTextContains('.summary-banner h3', 'Thank You! Order Placed Successfully');
        $order_id = $this->latestOrderIdForCustomer();
        self::assertMatchesRegularExpression('~Order #[0-9]{5}~', $crawler->filter('.panel-header-row h2')->text());

        // the transaction wrote the order, the line items and deducted the stock
        $connection = static::getContainer()->get(Connection::class);
        $order = $connection->fetchAssociative('SELECT * FROM orders WHERE order_id = ?', [$order_id]);
        self::assertSame('1148.00', $order['total_amount']);
        self::assertSame('pending', $order['status']);
        self::assertSame('cash_on_delivery', $order['payment_method']);
        self::assertSame($customer->getId(), (int) $order['user_id']);

        $items = $connection->fetchAllAssociative('SELECT * FROM order_items WHERE order_id = ? ORDER BY item_id', [$order_id]);
        self::assertCount(2, $items);
        self::assertSame($serum_id, (int) $items[0]['product_id']);
        self::assertSame(2, (int) $items[0]['quantity']);
        self::assertSame('499.00', $items[0]['price_at_purchase']);
        self::assertSame($tonic_id, (int) $items[1]['product_id']);
        self::assertSame(1, (int) $items[1]['quantity']);
        self::assertSame('150.00', $items[1]['price_at_purchase']);

        self::assertSame(8, (int) $connection->fetchOne('SELECT product_stock_quantity FROM products WHERE product_id = ?', [$serum_id]));
        self::assertSame(4, (int) $connection->fetchOne('SELECT product_stock_quantity FROM products WHERE product_id = ?', [$tonic_id]));

        // the cart is emptied and the summary shows both items plus the totals
        self::assertSame([], $client->getRequest()->getSession()->get('cart'));
        self::assertSelectorTextContains('.summary-table', 'Shop Test Serum');
        self::assertSelectorTextContains('.summary-table', 'Shop Test Tonic');
        self::assertSelectorTextContains('.summary-totals-row.grand', '₱1,148.00');
        self::assertSelectorTextContains('.badge-status', 'Pending');
        self::assertSelectorTextContains('.order-meta-grid', 'Customer');
        self::assertSelectorTextContains('.order-meta-grid', 'Cash on Delivery');
    }

    public function testPlaceOrderSupportsGcashPaymentMethod(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        $serum_id = $this->createProduct('Shop Test Serum GCash', '499.00', 10, null);
        $this->fillCartViaShop($client, [$serum_id => 1]);

        $crawler = $client->request('GET', '/checkout');
        $token = $crawler->filter('#placeOrderModal form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/checkout', [
            'action' => 'place_order',
            '_csrf_token' => $token,
            'payment_method' => 'gcash',
            'gcash_number' => '09171234567',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.order-meta-grid', 'GCash');

        $order_id = $this->latestOrderIdForCustomer();
        $connection = static::getContainer()->get(Connection::class);
        $order = $connection->fetchAssociative('SELECT * FROM orders WHERE order_id = ?', [$order_id]);
        self::assertSame('gcash', $order['payment_method']);

        // Verify GCash number was attached to customer in database
        $user_row = $connection->fetchAssociative('SELECT gcash_number FROM users WHERE user_id = ?', [$customer->getId()]);
        self::assertSame('09171234567', $user_row['gcash_number']);
    }

    public function testPlaceOrderRequiresGcashNumberIfNotAttached(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        $serum_id = $this->createProduct('Shop Test Serum GCash Validation', '499.00', 10, null);
        $this->fillCartViaShop($client, [$serum_id => 1]);

        $crawler = $client->request('GET', '/checkout');
        $token = $crawler->filter('#placeOrderModal form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/checkout', [
            'action' => 'place_order',
            '_csrf_token' => $token,
            'payment_method' => 'gcash',
            'gcash_number' => '',
        ]);

        self::assertResponseRedirects('/checkout');
        $client->followRedirect();
        self::assertSelectorTextContains('#checkoutAlertModal .confirm-modal-message', 'Please enter your GCash mobile number to attach it to your account.');
    }

    public function testPlaceOrderSupportsCardPaymentMethod(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        $serum_id = $this->createProduct('Shop Test Serum Card', '499.00', 10, null);
        $this->fillCartViaShop($client, [$serum_id => 1]);

        $crawler = $client->request('GET', '/checkout');
        $token = $crawler->filter('#placeOrderModal form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/checkout', [
            'action' => 'place_order',
            '_csrf_token' => $token,
            'payment_method' => 'card',
            'card_number' => '1234567812345678',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.order-meta-grid', 'Credit / Debit Card');

        $order_id = $this->latestOrderIdForCustomer();
        $connection = static::getContainer()->get(Connection::class);
        $order = $connection->fetchAssociative('SELECT * FROM orders WHERE order_id = ?', [$order_id]);
        self::assertSame('card', $order['payment_method']);

        // Verify card number was attached to customer in database
        $user_row = $connection->fetchAssociative('SELECT card_number FROM users WHERE user_id = ?', [$customer->getId()]);
        self::assertSame('1234567812345678', $user_row['card_number']);
    }

    public function testPlaceOrderRecordsDeliveryAddressAndDisplaysOnSummary(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        $serum_id = $this->createProduct('Shop Test Serum Address', '499.00', 10, null);
        $this->fillCartViaShop($client, [$serum_id => 1]);

        $crawler = $client->request('GET', '/checkout');
        $token = $crawler->filter('#placeOrderModal form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/checkout', [
            'action' => 'place_order',
            '_csrf_token' => $token,
            'payment_method' => 'cash_on_delivery',
            'street_address' => '123 Hibiscus Lane',
            'barangay' => 'San Antonio',
            'city' => 'Pasig City',
            'region' => 'Metro Manila',
            'recipient_name' => 'Clara Reyes',
            'recipient_phone' => '09221234567',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        // Check delivery address display on summary
        self::assertSelectorTextContains('.order-meta-grid', '123 Hibiscus Lane');
        self::assertSelectorTextContains('.order-meta-grid', 'San Antonio');
        self::assertSelectorTextContains('.order-meta-grid', 'Pasig City');
        self::assertSelectorTextContains('.order-meta-grid', 'Clara Reyes');
        self::assertSelectorTextContains('.order-meta-grid', '09221234567');

        // Verify database columns
        $order_id = $this->latestOrderIdForCustomer();
        $connection = static::getContainer()->get(Connection::class);
        $order = $connection->fetchAssociative('SELECT * FROM orders WHERE order_id = ?', [$order_id]);
        self::assertStringContainsString('123 Hibiscus Lane', $order['delivery_address']);
        self::assertSame('Clara Reyes', $order['recipient_name']);
        self::assertSame('09221234567', $order['recipient_phone']);
    }

    public function testPlaceOrderViaOtherPaymentMethodsDisplaysDetailsOnSummary(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        $serum_id = $this->createProduct('Shop Test Serum Payment Details', '499.00', 10, null);
        $this->fillCartViaShop($client, [$serum_id => 1]);

        $crawler = $client->request('GET', '/checkout');
        $token = $crawler->filter('#placeOrderModal form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/checkout', [
            'action' => 'place_order',
            '_csrf_token' => $token,
            'payment_method' => 'gcash',
            'gcash_number' => '09998887777',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        // Verify payment method and payment option details on summary
        self::assertSelectorTextContains('.order-meta-grid', 'GCash');
        self::assertSelectorTextContains('.order-meta-grid', '09998887777');

        $order_id = $this->latestOrderIdForCustomer();
        $connection = static::getContainer()->get(Connection::class);
        $order = $connection->fetchAssociative('SELECT * FROM orders WHERE order_id = ?', [$order_id]);
        self::assertSame('gcash', $order['payment_method']);
        self::assertSame('09998887777', $order['payment_details']);
    }

    public function testPlaceOrderRejectsInsufficientStock(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $serum_id = $this->createProduct('Shop Test Serum', '499.00', 10, null);
        $this->fillCartViaShop($client, [$serum_id => 2]);

        $crawler = $client->request('GET', '/checkout');
        $token = $crawler->filter('#placeOrderModal form input[name="_csrf_token"]')->attr('value');

        // stock shrank while the item sat in the cart — the order must not land
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement('UPDATE products SET product_stock_quantity = 1 WHERE product_id = ?', [$serum_id]);

        $client->request('POST', '/checkout', [
            'action' => 'place_order',
            '_csrf_token' => $token,
            'product_id' => $serum_id,
            'quantity' => '1',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('#checkoutAlertModal .confirm-modal-message', "Not enough stock for 'Shop Test Serum' (Only 1 available).");

        // nothing was written and the cart survives
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM orders'));
        $cart = $client->getRequest()->getSession()->get('cart');
        self::assertSame([$serum_id => 2], $cart);
    }

    public function testPlaceOrderFailsWithEmptyCart(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $serum_id = $this->createProduct('Shop Test Serum', '499.00', 10, null);
        $this->fillCartViaShop($client, [$serum_id => 1]);

        // grab the token while the cart still has items, then empty it behind the page's back
        $crawler = $client->request('GET', '/checkout');
        $token = $crawler->filter('#placeOrderModal form input[name="_csrf_token"]')->attr('value');
        $session = $client->getRequest()->getSession();
        $session->set('cart', []);
        $session->save();

        $client->request('POST', '/checkout', [
            'action' => 'place_order',
            '_csrf_token' => $token,
            'product_id' => $serum_id,
            'quantity' => '1',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('#checkoutAlertModal .confirm-modal-message', 'Your cart is empty. Add botanical items first.');
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM orders'));
    }

    public function testCheckoutPostFailsWithoutCsrfToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $serum_id = $this->createProduct('Shop Test Serum', '499.00', 10, null);
        $this->fillCartViaShop($client, [$serum_id => 2]);
        $client->request('GET', '/checkout');

        $client->request('POST', '/checkout', [
            'action' => 'remove_item',
            'product_id' => $serum_id,
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('#checkoutAlertModal .confirm-modal-message', 'Security token invalid or expired. Please try again.');

        // the cart survived the rejected request
        $cart = $client->getRequest()->getSession()->get('cart');
        self::assertSame([$serum_id => 2], $cart);
    }

    public function testSummaryGuardsAccessAndBadIds(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $this->makeUser('customer', self::OUTSIDER_EMAIL);
        $admin = $this->makeUser('admin', self::ADMIN_EMAIL);

        $order_id = $this->makeOrder($customer->getId(), '299.00', [
            ['product_id' => $this->createProduct('Shop Test Salve', '299.00', 50, null), 'quantity' => 1, 'price_at_purchase' => '299.00'],
        ]);

        $client->loginUser($customer);

        // no order id goes straight back to the shop
        $client->request('GET', '/checkout/summary');
        self::assertResponseRedirects();
        self::assertStringContainsString('/shop', $client->getResponse()->getTargetUrl());

        // a bogus id is a 404
        $client->request('GET', '/checkout/summary?order_id=999999');
        self::assertResponseStatusCodeSame(404);

        // the owner sees their own order as a plain customer
        $client->request('GET', '/checkout/summary?order_id=' . $order_id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.order-meta-grid .order-meta-item:nth-child(4) span', 'Cash on Delivery');
        self::assertSelectorTextContains('.order-meta-grid .order-meta-item:nth-child(5) span', 'Customer');

        // a different customer gets bounced to the account page
        $client->loginUser($this->makeUser('customer', self::OUTSIDER_EMAIL));
        $client->request('GET', '/checkout/summary?order_id=' . $order_id);
        self::assertResponseRedirects();
        self::assertStringContainsString('/account', $client->getResponse()->getTargetUrl());

        // an admin may open anyone's order and sees the Administrator badge
        $client->loginUser($admin);
        $client->request('GET', '/checkout/summary?order_id=' . $order_id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.order-meta-grid .order-meta-item:nth-child(5) span', 'Administrator');
    }

    // ---------- seeding helpers ----------

    private function makeUser(string $role, string $email): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        // a crashed earlier run can leave this email behind — clear it so this run starts fresh
        static::getContainer()->get(Connection::class)->executeStatement(
            'DELETE FROM users WHERE user_email = ?',
            [$email]
        );

        $user = new User();
        $user->setUsername('checkout_'.str_replace('-', '_', explode('@', $email)[0]));
        $user->setEmail($email);
        $user->setRole($role);
        $user->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createProduct(string $name, string $price, int $stock, ?string $img, string $description = ''): int
    {
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'INSERT INTO products (product_name, product_description, product_price, product_stock_quantity, product_img)
             VALUES (?, ?, ?, ?, ?)',
            [$name, $description, $price, $stock, $img ?? '']
        );

        return (int) $connection->lastInsertId();
    }

    // walk the real flow: grab a token off the shop page and POST each add_to_cart
    private function fillCartViaShop($client, array $items): void
    {
        foreach ($items as $product_id => $qty) {
            $crawler = $client->request('GET', '/shop');
            $token = $crawler->filter('#addToCartForm input[name="_csrf_token"]')->attr('value');

            $client->request('POST', '/shop', [
                'action' => 'add_to_cart',
                '_csrf_token' => $token,
                'product_id' => $product_id,
                'quantity' => (string) $qty,
            ]);
            self::assertResponseRedirects();
        }
    }

    private function latestOrderIdForCustomer(): int
    {
        $connection = static::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne(
            'SELECT order_id FROM orders o JOIN users u ON o.user_id = u.user_id WHERE u.user_email = ? ORDER BY o.order_id DESC LIMIT 1',
            [self::CUSTOMER_EMAIL]
        );
    }

    // fabricate an order straight into the database for summary-only checks
    private function makeOrder(int $user_id, string $total, array $items): int
    {
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'INSERT INTO orders (user_id, total_amount, order_date, status) VALUES (?, ?, CURRENT_TIMESTAMP, ?)',
            [$user_id, $total, 'pending']
        );
        $order_id = (int) $connection->lastInsertId();

        foreach ($items as $item) {
            $connection->executeStatement(
                'INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase) VALUES (?, ?, ?, ?)',
                [$order_id, $item['product_id'], $item['quantity'], $item['price_at_purchase']]
            );
        }

        return $order_id;
    }
}
