<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

// shop page tests — catalog, best sellers and cart additions
// every test seeds its own rows under the 'Shop Test' prefix and sweeps them in tearDown
final class ShopControllerTest extends WebTestCase
{
    private const CUSTOMER_EMAIL = 'shop-tester-customer@example.test';
    private const BANNED_EMAIL = 'shop-tester-banned@example.test';
    private const PASSWORD = 'shopBreeze8';

    protected function tearDown(): void
    {
        // sweep order rows, products and users out again (children first, per the FK order)
        $connection = static::getContainer()->get(Connection::class);

        $connection->executeStatement(
            'DELETE oi FROM order_items oi
             JOIN orders o ON oi.order_id = o.order_id
             JOIN users u ON o.user_id = u.user_id
             WHERE u.user_email IN (?, ?)',
            [self::CUSTOMER_EMAIL, self::BANNED_EMAIL],
            [ParameterType::STRING, ParameterType::STRING]
        );
        $connection->executeStatement(
            'DELETE o FROM orders o
             JOIN users u ON o.user_id = u.user_id
             WHERE u.user_email IN (?, ?)',
            [self::CUSTOMER_EMAIL, self::BANNED_EMAIL],
            [ParameterType::STRING, ParameterType::STRING]
        );
        $connection->executeStatement("DELETE FROM products WHERE product_name LIKE 'Shop Test %'");
        $connection->executeStatement(
            'DELETE FROM users WHERE user_email IN (?, ?)',
            [self::CUSTOMER_EMAIL, self::BANNED_EMAIL],
            [ParameterType::STRING, ParameterType::STRING]
        );

        parent::tearDown();
    }

    public function testShopIsLockedForGuests(): void
    {
        $client = static::createClient();
        $client->request('GET', '/shop');

        // guests must log in first to access shop
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorExists('.auth-card');
    }

    public function testShopShowsProductGridWithStockPills(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $plenty = $this->createProduct('Shop Test Plenty', '499.00', 12, null);
        $this->createProduct('Shop Test Almost Gone', '299.00', 3, null);
        $empty = $this->createProduct('Shop Test All Gone', '199.00', 0, null);
        // a bundled xampp image and a bogus filename exercise the image fallbacks
        $this->createProduct('Shop Test Pictured', '349.00', 8, '1.png', 'Shop Test Pictured');

        $crawler = $client->request('GET', '/shop');
        self::assertResponseIsSuccessful();

        // page header + checkout disabled (checkout page arrives in the next phase)
        self::assertSelectorTextContains('.shop-page-title h1', 'Botanical Shop');
        self::assertSelectorExists('.shop-checkout-btn[disabled]');
        self::assertSelectorNotExists('.account-alert.err');

        // three purchasable cards get the modal hook, the empty one does not
        self::assertCount(4, $crawler->filter('.shop-product-card'));
        self::assertCount(3, $crawler->filter('[data-product-card="true"]'));

        $stockLabels = $crawler->filter('.product-stock-pill')->each(
            static fn ($n) => $n->text()
        );
        self::assertStringContainsString('In Stock', implode(' | ', $stockLabels));
        self::assertStringContainsString('Only 3 left', implode(' | ', $stockLabels));
        self::assertStringContainsString('Out of Stock', implode(' | ', $stockLabels));

        $goneCard = $crawler->filter(sprintf('.shop-product-card[data-id="%d"]', $empty))->first();
        self::assertNotNull($goneCard->filter('.btn-product-action.disabled')->getNode(0));

        // bundled image resolves through the asset mapper (hashed url), unknown names fall back to the placeholder
        $pictured = $crawler->filter('div')->reduce(static fn ($n) => 'Shop Test Pictured' === $n->attr('data-name'));
        self::assertMatchesRegularExpression('#/assets/images/1-[a-zA-Z0-9]+\.png$#', (string) $pictured->attr('data-image'));
        $plentyCard = $crawler->filter('div')->reduce(static fn ($n) => 'Shop Test Plenty' === $n->attr('data-name'));
        self::assertMatchesRegularExpression('#/assets/images/3-[a-zA-Z0-9_-]+\.png$#', (string) $plentyCard->attr('data-image'));

        // cart badge stays hidden while the cart is empty
        self::assertSelectorExists('#addToCartModal form input[name="_csrf_token"]');
        self::assertSelectorNotExists('.shop-checkout-btn span');
    }

    public function testBestSellersShowsTopHalfOrdered(): void
    {
        $client = static::createClient();
        $customer = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($customer);

        $alpha = $this->createProduct('Shop Test Alpha', '499.00', 50, null);
        $beta = $this->createProduct('Shop Test Beta', '399.00', 50, null);
        $this->createProduct('Shop Test Gamma', '299.00', 50, null);
        $this->createProduct('Shop Test Delta', '199.00', 50, null);
        $this->sell($customer->getId(), $alpha, 6);
        $this->sell($customer->getId(), $beta, 3);

        $crawler = $client->request('GET', '/shop', ['filter' => 'best_sellers']);
        self::assertResponseIsSuccessful();

        // 4 products → top 50% rounded up = 2 cards, ranked by sold then alphabetical
        self::assertSelectorTextContains('.shop-page-title h1', 'Best Sellers');
        $names = $crawler->filter('.product-card-name')->each(static fn ($n) => $n->text());
        self::assertSame(['Shop Test Alpha', 'Shop Test Beta'], $names);
    }

    public function testBestSellersIsEmptyWithoutProducts(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $crawler = $client->request('GET', '/shop', ['filter' => 'best_sellers']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'No products found');
        self::assertSelectorTextContains('.shop-filter-tabs', 'Best Sellers');
    }

    public function testAddToCartStoresSessionAndFlashesBatchTotal(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $product_id = $this->createProduct('Shop Test Serum', '499.00', 10, null);

        $crawler = $client->request('GET', '/shop');
        $token = $crawler->filter('#addToCartForm input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/shop', [
            'action' => 'add_to_cart',
            '_csrf_token' => $token,
            'product_id' => $product_id,
            'quantity' => '2',
        ]);

        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertSelectorExists('#shopAlertModal');
        self::assertSelectorTextContains('#shopAlertModal .confirm-modal-message', "Added 2x 'Shop Test Serum' to your cart! (Batch Total: ₱998.00)");

        // the session cart holds the qty and the badge shows it after the redirect
        $cart = $client->getRequest()->getSession()->get('cart');
        self::assertSame([$product_id => 2], $cart);
        self::assertSelectorTextContains('.shop-checkout-btn', '2');
    }

    public function testAddToCartClampsToAvailableStock(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $product_id = $this->createProduct('Shop Test Scarce', '100.00', 3, null);

        $crawler = $client->request('GET', '/shop');
        $token = $crawler->filter('#addToCartForm input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/shop', [
            'action' => 'add_to_cart',
            '_csrf_token' => $token,
            'product_id' => $product_id,
            'quantity' => '99',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorExists('#shopAlertModal');
        self::assertSelectorTextContains('#shopAlertModal .confirm-modal-message', "Added 3x 'Shop Test Scarce' to your cart!");

        $cart = $client->getRequest()->getSession()->get('cart');
        self::assertSame([$product_id => 3], $cart);
    }

    public function testAddToCartRejectsOutOfStock(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $product_id = $this->createProduct('Shop Test Vapor', '100.00', 0, null);

        $crawler = $client->request('GET', '/shop');
        $token = $crawler->filter('#addToCartForm input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/shop', [
            'action' => 'add_to_cart',
            '_csrf_token' => $token,
            'product_id' => $product_id,
            'quantity' => '1',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('#shopAlertModal .confirm-modal-message', 'Sorry, this item is currently out of stock.');
        self::assertSame([], $client->getRequest()->getSession()->get('cart', []));
    }

    public function testBannedUserCanBrowseButCannotAddToCart(): void
    {
        $client = static::createClient();
        $banned = $this->makeUser('banned', self::BANNED_EMAIL);
        $client->loginUser($banned);

        $product_id = $this->createProduct('Shop Test Locked', '499.00', 12, null);

        // browsing works but every card is restricted and the strip warns
        $crawler = $client->request('GET', '/shop');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-alert.err', 'Your account is currently restricted. Purchasing and cart additions are disabled.');
        self::assertSelectorTextContains('.btn-product-action.banned', 'Restricted');
        self::assertCount(0, $crawler->filter('[data-product-card="true"]'));

        $token = $crawler->filter('#addToCartForm input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/shop', [
            'action' => 'add_to_cart',
            '_csrf_token' => $token,
            'product_id' => $product_id,
            'quantity' => '1',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        // the banned-post rejection lands in the shop alert modal, the strip carries the browse warning
        self::assertSelectorTextContains('#shopAlertModal .confirm-modal-message', "Your account has been restricted. You cannot add products to cart.");
        self::assertFalse($client->getRequest()->getSession()->has('cart'));
    }

    public function testAddToCartFailsWithoutCsrfToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('customer', self::CUSTOMER_EMAIL));

        $product_id = $this->createProduct('Shop Test Untokened', '499.00', 10, null);
        $client->request('GET', '/shop');

        $client->request('POST', '/shop', [
            'action' => 'add_to_cart',
            'product_id' => $product_id,
            'quantity' => '1',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        // csrf rejection lands in the shop alert modal after the redirect
        self::assertSelectorTextContains('#shopAlertModal .confirm-modal-message', 'Security token invalid or expired. Please try again.');
        self::assertFalse($client->getRequest()->getSession()->has('cart'));
    }

    // ---------- seeding helpers ----------

    private function makeUser(string $role, string $email): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setUsername('shop_'.str_replace('-', '_', explode('@', $email)[0]));
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

    // fabricate a past sale so best sellers has something to rank by
    private function sell(int $user_id, int $product_id, int $quantity): void
    {
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'INSERT INTO orders (user_id, total_amount, order_date, status) VALUES (?, ?, CURRENT_TIMESTAMP, ?)',
            [$user_id, '0.00', 'pending']
        );
        $order_id = (int) $connection->lastInsertId();
        $connection->executeStatement(
            'INSERT INTO order_items (order_id, product_id, price_at_purchase, quantity) VALUES (?, ?, ?, ?)',
            [$order_id, $product_id, '499.00', $quantity]
        );
    }
}
