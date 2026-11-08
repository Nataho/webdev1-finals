<?php

namespace App\Tests\Controller;

use App\Entity\Order;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

// order lifecycle: the View Summary links in the account tabs and the admin-only
// pipeline that moves an order from pending through preparing/shipped/delivering/arrived
// to delivered (or cancelled)
final class OrderStatusTest extends WebTestCase
{
    private const CUSTOMER_EMAIL = 'status-tester@example.test';
    private const OUTSIDER_EMAIL = 'status-outsider@example.test';
    private const ADMIN_EMAIL = 'status-admin@example.test';
    private const ALL_TEST_EMAILS = [
        self::CUSTOMER_EMAIL,
        self::OUTSIDER_EMAIL,
        self::ADMIN_EMAIL,
    ];
    private const PASSWORD = 'statusPassword123';

    protected function tearDown(): void
    {
        // sweep the fabricated orders and users out again (children first, per the FK order)
        $connection = static::getContainer()->get(Connection::class);
        $placeholders = implode(',', array_fill(0, count(self::ALL_TEST_EMAILS), '?'));

        $connection->executeStatement(
            "DELETE oi FROM order_items oi
             JOIN orders o ON oi.order_id = o.order_id
             JOIN users u ON o.user_id = u.user_id
             WHERE u.user_email IN ($placeholders)",
            self::ALL_TEST_EMAILS
        );
        $connection->executeStatement(
            "DELETE o FROM orders o
             JOIN users u ON o.user_id = u.user_id
             WHERE u.user_email IN ($placeholders)",
            self::ALL_TEST_EMAILS
        );
        // sweep the fresh products we seeded (after the orders are gone, per the FK-free but tidy order)
        $connection->executeStatement("DELETE FROM products WHERE product_name LIKE 'Status Test %'");
        $connection->executeStatement(
            "DELETE FROM users WHERE user_email IN ($placeholders)",
            self::ALL_TEST_EMAILS
        );

        parent::tearDown();
    }

    public function testViewSummaryLinksPointToTheSummaryPage(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $admin = $this->makeUser('admin', self::ADMIN_EMAIL);

        $order_id = $this->makeOrder($customer->getId());
        $expected_href = '/checkout/summary?order_id='.$order_id;

        // the customer's logs tab links their own order to the summary page
        $client->loginUser($customer);
        $crawler = $client->request('GET', '/account?tab=logs');
        self::assertResponseIsSuccessful();
        self::assertSame($expected_href, $crawler->filter('a[title="View Order Summary"]')->attr('href'));

        // and the admin dashboard links every recent order the same way
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        self::assertSame($expected_href, $crawler->filter('a[title="View Order Summary"]')->attr('href'));
    }

    public function testAdminCanMoveOrderThroughThePipeline(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($this->makeUser('admin', self::ADMIN_EMAIL));

        $order_id = $this->makeOrder($customer->getId());

        // the logs tab offers the admin a dropdown with every pipeline status, preselected to the current one
        $crawler = $client->request('GET', '/account?tab=logs');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form.status-update-form')->first();
        self::assertSame((string) $order_id, $form->filter('input[name="order_id"]')->attr('value'), 'the form targets the right order');
        self::assertSame(
            ['pending', 'preparing', 'shipped', 'delivering', 'arrived', 'delivered', 'cancelled'],
            $form->filter('select[name="new_status"] option')->extract(['value']),
            'the dropdown lists the full Order::STATUSES pipeline'
        );
        self::assertSame('pending', $form->filter('select[name="new_status"] option[selected]')->attr('value'));

        // the admin moves the order to Shipped
        $token = $form->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/account', [
            'action' => 'update_order_status',
            '_csrf_token' => $token,
            'order_id' => (string) $order_id,
            'new_status' => 'shipped',
        ]);

        self::assertResponseRedirects('/account?tab=logs');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        // flash confirms, the row badge flips and the dropdown remembers the new state
        self::assertSelectorTextContains('.account-alert.ok', "status updated to 'shipped'");
        self::assertSelectorTextSame('.badge-status.shipped', 'Shipped');
        self::assertSame('shipped', $crawler->filter('select[name="new_status"] option[selected]')->attr('value'));

        // the change landed in the database and on the customer-facing summary page
        $connection = static::getContainer()->get(Connection::class);
        self::assertSame('shipped', $connection->fetchOne('SELECT status FROM orders WHERE order_id = ?', [$order_id]));

        $client->request('GET', '/checkout/summary?order_id='.$order_id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.badge-status.shipped', 'Shipped');
    }

    public function testAdminCanCancelAndDeliverOrders(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($this->makeUser('admin', self::ADMIN_EMAIL));

        $order_id = $this->makeOrder($customer->getId());
        $crawler = $client->request('GET', '/account?tab=logs');
        $token = $crawler->filter('form.status-update-form input[name="_csrf_token"]')->attr('value');

        // cancelled orders get the banned badge on the logs page
        $client->request('POST', '/account', [
            'action' => 'update_order_status',
            '_csrf_token' => $token,
            'order_id' => (string) $order_id,
            'new_status' => 'cancelled',
        ]);
        self::assertResponseRedirects('/account?tab=logs');
        $crawler = $client->followRedirect();
        self::assertSelectorTextSame('.badge-status.banned', 'Cancelled');

        // and delivered orders land on the green active badge
        $client->request('POST', '/account', [
            'action' => 'update_order_status',
            '_csrf_token' => $token,
            'order_id' => (string) $order_id,
            'new_status' => 'delivered',
        ]);
        self::assertResponseRedirects('/account?tab=logs');
        $client->followRedirect();
        self::assertSelectorTextSame('.badge-status.active', 'Delivered');
    }

    public function testInvalidStatusIsRejected(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($this->makeUser('admin', self::ADMIN_EMAIL));

        $order_id = $this->makeOrder($customer->getId());
        $crawler = $client->request('GET', '/account?tab=logs');
        $token = $crawler->filter('form.status-update-form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/account', [
            'action' => 'update_order_status',
            '_csrf_token' => $token,
            'order_id' => (string) $order_id,
            'new_status' => 'teleported',
        ]);

        self::assertResponseRedirects('/account?tab=logs');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.err', 'Unknown order status.');

        // the order survived untouched
        self::assertSame(
            'pending',
            static::getContainer()->get(Connection::class)->fetchOne('SELECT status FROM orders WHERE order_id = ?', [$order_id])
        );
    }

    public function testUnknownOrderIdIsRejected(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($this->makeUser('admin', self::ADMIN_EMAIL));

        // an order must exist so the logs tab renders a status form to take the token from
        $this->makeOrder($customer->getId());
        $crawler = $client->request('GET', '/account?tab=logs');
        $token = $crawler->filter('form.status-update-form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/account', [
            'action' => 'update_order_status',
            '_csrf_token' => $token,
            'order_id' => '999999',
            'new_status' => 'delivered',
        ]);

        self::assertResponseRedirects('/account?tab=logs');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.err', 'Order not found; status not updated.');
    }

    public function testCustomersCannotUpdateOrderStatus(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $admin = $this->makeUser('admin', self::ADMIN_EMAIL);

        $order_id = $this->makeOrder($customer->getId());

        // the customer's own logs page shows the order with its badge and summary link
        // but never the admin status controls
        $client->loginUser($customer);
        $crawler = $client->request('GET', '/account?tab=logs');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.badge-status.pending');
        self::assertSelectorNotExists('form.status-update-form');
        self::assertSelectorNotExists('select[name="new_status"]');

        // even a token leaked from an admin page (same session storage) must not let a
        // non-admin move orders — the action is gated to admins only
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/account?tab=logs');
        $token = $crawler->filter('form.status-update-form input[name="_csrf_token"]')->attr('value');

        $client->loginUser($customer);
        $client->request('POST', '/account', [
            'action' => 'update_order_status',
            '_csrf_token' => $token,
            'order_id' => (string) $order_id,
            'new_status' => 'delivered',
        ]);

        self::assertResponseRedirects('/account?tab=logs');
        $crawler = $client->followRedirect();
        self::assertSelectorNotExists('.account-alert.ok');

        self::assertSame(
            'pending',
            static::getContainer()->get(Connection::class)->fetchOne('SELECT status FROM orders WHERE order_id = ?', [$order_id])
        );
    }

    public function testStatusFilterNarrowsAndValidatesForCustomers(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        // three orders across the pipeline
        $this->makeOrder($customer->getId(), 'pending');
        $this->makeOrder($customer->getId(), 'shipped');
        $this->makeOrder($customer->getId(), 'delivered');

        // the shipped filter hides the other rows and highlights its chip
        $crawler = $client->request('GET', '/account?tab=logs&status=shipped');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.badge-status.shipped', 'Shipped');
        self::assertSelectorNotExists('.badge-status.pending');
        self::assertSelectorNotExists('.badge-status.active');

        self::assertCount(1, $crawler->filter('.status-filter-chip.active'));
        self::assertStringContainsStringIgnoringCase(
            'status=shipped',
            $crawler->filter('.status-filter-chip.active')->attr('href')
        );
        self::assertSelectorTextContains('a.status-filter-chip[href$="status=pending"]', 'Pending (1)');
        self::assertSelectorTextContains('a.status-filter-chip[href$="status=preparing"]', 'Preparing (0)');
        self::assertSelectorTextContains('a.status-filter-chip[href="/account?tab=logs"]', 'All (3)');

        // a bogus status silently falls back to the full list
        $crawler = $client->request('GET', '/account?tab=logs&status=teleported');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.badge-status.pending');
        self::assertSelectorExists('.badge-status.shipped');
        self::assertSelectorExists('.badge-status.active');
        self::assertSame('All (3)', trim($crawler->filter('.status-filter-chip.active')->text()));
    }

    public function testAdminStatusFilterNarrowsStoreLogs(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $admin = $this->makeUser('admin', self::ADMIN_EMAIL);
        $client->loginUser($admin);

        $this->makeOrder($customer->getId(), 'pending');
        $this->makeOrder($customer->getId(), 'delivered');

        // the same filter works over the store-wide view
        $crawler = $client->request('GET', '/account?tab=logs&status=delivered');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.badge-status.active', 'Delivered');
        self::assertSelectorNotExists('.badge-status.pending');

        // and a status with no matching orders gets the friendly empty state
        $crawler = $client->request('GET', '/account?tab=logs&status=preparing');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-table-cell', 'No Preparing orders found.');
    }

    public function testCustomerCanCancelTheirPendingOrder(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        $order_id = $this->makeOrder($customer->getId(), 'pending');

        // pending orders offer the Cancel button and the shared confirm modal carries the CSRF token
        $crawler = $client->request('GET', '/account?tab=logs');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button[title="Cancel this pending order"]');

        $token = $crawler->filter('#cancelOrderModal input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/account', [
            'action' => 'cancel_order',
            '_csrf_token' => $token,
            'order_id' => (string) $order_id,
            'cancel_reason' => 'ordered the wrong serum by mistake.',
        ]);

        self::assertResponseRedirects('/account?tab=logs');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        // flash confirms, the cancelled badge appears and the chips recount themselves
        self::assertSelectorTextContains('.account-alert.ok', "Order #{$order_id} has been cancelled and its items are back on the shelf.");
        self::assertSelectorTextSame('.badge-status.banned', 'Cancelled');
        self::assertSelectorTextContains('a.status-filter-chip[href$="status=cancelled"]', 'Cancelled (1)');
        self::assertSelectorTextContains('a.status-filter-chip[href$="status=pending"]', 'Pending (0)');

        // the database records the withdrawal plus the typed reason — a Cancel button for it is gone too
        $connection = static::getContainer()->get(Connection::class);
        self::assertSame(
            'ordered the wrong serum by mistake.',
            $connection->fetchOne('SELECT cancel_reason FROM orders WHERE order_id = ?', [$order_id])
        );
        self::assertSelectorNotExists('button[title="Cancel this pending order"]');

        // the shop can hover the Cancelled badge to see why the order died…
        self::assertSelectorExists('[title*="Cancellation reason"]');

        // …and the summary page shows the reason in full
        $client->request('GET', '/checkout/summary?order_id='.$order_id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.order-meta-grid', 'Cancellation Reason');
        self::assertSelectorTextContains('.order-meta-grid', 'ordered the wrong serum by mistake.');
    }

    public function testCancelIsBlockedOnceTheShopStartsProcessing(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        $order_id = $this->makeOrder($customer->getId(), 'preparing');

        // a preparing order shows no Cancel button at all
        $crawler = $client->request('GET', '/account?tab=logs');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('button[title="Cancel this pending order"]');

        // and a direct request is refused — only the pipeline's cancellable statuses survive
        $token = $crawler->filter('#cancelOrderModal input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/account', [
            'action' => 'cancel_order',
            '_csrf_token' => $token,
            'order_id' => (string) $order_id,
            'cancel_reason' => 'changed my mind about this whole thing',
        ]);

        self::assertResponseRedirects('/account?tab=logs');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.err', 'can no longer be cancelled.');

        self::assertSame(
            'preparing',
            static::getContainer()->get(Connection::class)->fetchOne('SELECT status FROM orders WHERE order_id = ?', [$order_id])
        );
    }

    public function testCustomerCannotCancelSomeoneElsesOrder(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $this->makeUser('outsider', self::OUTSIDER_EMAIL);

        $order_id = $this->makeOrder($customer->getId(), 'pending');

        // an outsider grabs their own session token but aims it at someone else's order
        $client->loginUser($this->makeUser('outsider', self::OUTSIDER_EMAIL));
        $crawler = $client->request('GET', '/account');
        $token = $crawler->filter('#cancelOrderModal input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/account', [
            'action' => 'cancel_order',
            '_csrf_token' => $token,
            'order_id' => (string) $order_id,
            'cancel_reason' => 'this order was never mine',
        ]);

        self::assertResponseRedirects('/account?tab=logs');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.err', 'Order not found or not yours to cancel.');

        self::assertSame(
            'pending',
            static::getContainer()->get(Connection::class)->fetchOne('SELECT status FROM orders WHERE order_id = ?', [$order_id])
        );
    }

    public function testCancelRequiresATypedReason(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        $order_id = $this->makeOrder($customer->getId(), 'pending');
        $crawler = $client->request('GET', '/account?tab=logs');
        $token = $crawler->filter('#cancelOrderModal input[name="_csrf_token"]')->attr('value');
        $connection = static::getContainer()->get(Connection::class);

        // no reason at all gets bounced back with a form's-worth of guidance
        $client->request('POST', '/account', [
            'action' => 'cancel_order',
            '_csrf_token' => $token,
            'order_id' => (string) $order_id,
            'cancel_reason' => '',
        ]);
        self::assertResponseRedirects('/account?tab=logs');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.err', 'Please type a cancellation reason');

        // the same penalty for anything leaner than 5 characters — whitespace does not pad it out
        $client->request('POST', '/account', [
            'action' => 'cancel_order',
            '_csrf_token' => $token,
            'order_id' => (string) $order_id,
            'cancel_reason' => '  oops  ',
        ]);
        self::assertResponseRedirects('/account?tab=logs');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.err', 'Please type a cancellation reason');

        self::assertSame('pending', $connection->fetchOne('SELECT status FROM orders WHERE order_id = ?', [$order_id]));
        self::assertNull($connection->fetchOne('SELECT cancel_reason FROM orders WHERE order_id = ?', [$order_id]));

        // exactly 5 letters is enough — and the reason lands in the row
        $client->request('POST', '/account', [
            'action' => 'cancel_order',
            '_csrf_token' => $token,
            'order_id' => (string) $order_id,
            'cancel_reason' => 'wrong',
        ]);
        self::assertResponseRedirects('/account?tab=logs');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.ok', 'has been cancelled');

        self::assertSame('cancelled', $connection->fetchOne('SELECT status FROM orders WHERE order_id = ?', [$order_id]));
        self::assertSame('wrong', $connection->fetchOne('SELECT cancel_reason FROM orders WHERE order_id = ?', [$order_id]));
    }

    public function testCancellingTheOrderReturnsItsReservedStock(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        $product_id = $this->createProduct('Status Test Canceller', '199.00', 7);
        $order_id = $this->makeOrder($customer->getId(), 'pending', 'gcash', [
            ['product_id' => $product_id, 'quantity' => 2, 'price_at_purchase' => '199.00'],
        ]);

        // seeding mirrored the checkout: 7 on the shelf, 2 being held by the order
        $connection = static::getContainer()->get(Connection::class);
        self::assertSame(5, (int) $connection->fetchOne('SELECT product_stock_quantity FROM products WHERE product_id = ?', [$product_id]));

        $crawler = $client->request('GET', '/account?tab=logs');
        $token = $crawler->filter('#cancelOrderModal input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/account', [
            'action' => 'cancel_order',
            '_csrf_token' => $token,
            'order_id' => (string) $order_id,
            'cancel_reason' => 'moved to a different shop entirely.',
        ]);

        self::assertResponseRedirects('/account?tab=logs');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.ok', 'back on the shelf.');

        // the shelf got everything back and the order is cancelled
        self::assertSame(7, (int) $connection->fetchOne('SELECT product_stock_quantity FROM products WHERE product_id = ?', [$product_id]));
        self::assertSame('cancelled', $connection->fetchOne('SELECT status FROM orders WHERE order_id = ?', [$order_id]));
    }

    public function testLegacyOrdersWithoutPaymentNeverReshuffleStock(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        // a legacy row: items exist but stock never left the shelf (no payment method on file)
        $product_id = $this->createProduct('Status Test Legacy', '99.00', 4);
        $order_id = $this->makeOrder($customer->getId(), 'pending', null, [
            ['product_id' => $product_id, 'quantity' => 2, 'price_at_purchase' => '99.00'],
        ]);
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement('UPDATE products SET product_stock_quantity = 4 WHERE product_id = ?', [$product_id]);

        $crawler = $client->request('GET', '/account?tab=logs');
        $token = $crawler->filter('#cancelOrderModal input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/account', [
            'action' => 'cancel_order',
            '_csrf_token' => $token,
            'order_id' => (string) $order_id,
            'cancel_reason' => 'no reason matters for legacy rows.',
        ]);

        self::assertResponseRedirects('/account?tab=logs');
        $client->followRedirect();

        // the shelf was NOT inflated by quantities that were never reserved
        self::assertSame(4, (int) $connection->fetchOne('SELECT product_stock_quantity FROM products WHERE product_id = ?', [$product_id]));
        self::assertSame('cancelled', $connection->fetchOne('SELECT status FROM orders WHERE order_id = ?', [$order_id]));
    }

    public function testAdminReopeningCancelledOrdersReReservesAndStallsOnShortage(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($this->makeUser('admin', self::ADMIN_EMAIL));

        $product_id = $this->createProduct('Status Test Reopener', '50.00', 9);

        // two cancelled orders, both seeded to the post-restock state: shelf holds everything
        $openable_id = $this->makeOrder($customer->getId(), 'cancelled', 'card', [
            ['product_id' => $product_id, 'quantity' => 3, 'price_at_purchase' => '50.00'],
        ]);
        $stuck_id = $this->makeOrder($customer->getId(), 'cancelled', 'gcash', [
            ['product_id' => $product_id, 'quantity' => 9, 'price_at_purchase' => '50.00'],
        ]);
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement('UPDATE products SET product_stock_quantity = 9 WHERE product_id = ?', [$product_id]);

        $crawler = $client->request('GET', '/account?tab=logs');
        $token = $crawler->filter('form.status-update-form input[name="_csrf_token"]')->attr('value');

        // re-opening the 3-item order takes its reservation back out of the shelf
        $client->request('POST', '/account', [
            'action' => 'update_order_status',
            '_csrf_token' => $token,
            'order_id' => (string) $openable_id,
            'new_status' => 'preparing',
        ]);
        self::assertResponseRedirects('/account?tab=logs');
        $client->followRedirect();
        self::assertSame(6, (int) $connection->fetchOne('SELECT product_stock_quantity FROM products WHERE product_id = ?', [$product_id]));
        self::assertSame('preparing', $connection->fetchOne('SELECT status FROM orders WHERE order_id = ?', [$openable_id]));

        // the 9-item re-open cannot be covered by the 2 left on the shelf — refused wholesale
        $connection->executeStatement('UPDATE products SET product_stock_quantity = 2 WHERE product_id = ?', [$product_id]);

        $client->request('POST', '/account', [
            'action' => 'update_order_status',
            '_csrf_token' => $token,
            'order_id' => (string) $stuck_id,
            'new_status' => 'pending',
        ]);
        self::assertResponseRedirects('/account?tab=logs');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.err', 'Not enough stock on the shelf');

        // no partial write: the order is still cancelled and the shelf still at 2
        self::assertSame('cancelled', $connection->fetchOne('SELECT status FROM orders WHERE order_id = ?', [$stuck_id]));
        self::assertSame(2, (int) $connection->fetchOne('SELECT product_stock_quantity FROM products WHERE product_id = ?', [$product_id]));
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
        $user->setUsername('status_'.str_replace('-', '_', explode('@', $email)[0]));
        $user->setEmail($email);
        $user->setRole($role);
        $user->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    // fabricate an order straight into the database for link/status checks;
    // optional line items mirror the checkout reservation (each insert deducts the shelf)
    private function makeOrder(int $user_id, string $status = 'pending', ?string $payment_method = null, array $items = []): int
    {
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'INSERT INTO orders (user_id, total_amount, order_date, status, payment_method) VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?)',
            [$user_id, '299.00', $status, $payment_method]
        );
        $order_id = (int) $connection->lastInsertId();

        foreach ($items as $item) {
            $connection->executeStatement(
                'INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase) VALUES (?, ?, ?, ?)',
                [$order_id, $item['product_id'], $item['quantity'], $item['price_at_purchase']]
            );
            $connection->executeStatement(
                'UPDATE products SET product_stock_quantity = product_stock_quantity - ? WHERE product_id = ?',
                [$item['quantity'], $item['product_id']]
            );
        }

        return $order_id;
    }

    private function createProduct(string $name, string $price, int $stock): int
    {
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'INSERT INTO products (product_name, product_description, product_price, product_stock_quantity, product_img) VALUES (?, ?, ?, ?, ?)',
            [$name, '', $price, $stock, '']
        );

        return (int) $connection->lastInsertId();
    }
}
