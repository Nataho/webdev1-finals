<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Photo upload flows on the account page: profile avatars and admin product images.
 * Files really land in public/uploads/<subdir>/ — every test sweeps its own rows and files.
 */
final class AccountPhotoUploadTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'admin-uploader@example.test';
    private const CUSTOMER_EMAIL = 'avatar-customer@example.test';
    private const PASSWORD = 'uploadTester9';
    private const PRODUCT_NAME = 'Test Rose Serum';

    /**
     * files created by the tests: ['subdir' => ..., 'filename' => ...]
     *
     * @var list<array{subdir: string, filename: string}>
     */
    private array $uploadedFiles = [];

    protected function tearDown(): void
    {
        $connection = static::getContainer()->get(Connection::class);
        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');

        // sweep the test rows out
        $connection->executeStatement('DELETE FROM products WHERE product_name = ?', [self::PRODUCT_NAME]);
        $connection->executeStatement(
            'DELETE FROM users WHERE user_email IN (?, ?)',
            [self::ADMIN_EMAIL, self::CUSTOMER_EMAIL]
        );

        // sweep the files the uploads wrote
        foreach ($this->uploadedFiles as $file) {
            $path = $projectDir.'/public/uploads/'.$file['subdir'].'/'.$file['filename'];
            if (is_file($path)) {
                @unlink($path);
            }
        }

        foreach (glob(sys_get_temp_dir().'/eb_png_*') ?: [] as $tmp) {
            @unlink((string) $tmp);
        }

        parent::tearDown();
    }

    public function testAddProductFormIncludesCsrfTokenField(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('admin', self::ADMIN_EMAIL));

        $crawler = $client->request('GET', '/account', ['tab' => 'add_product']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="action"][value="add_product"]');
        // regression: this field was missing, so every add_product submission failed with "Security token invalid"
        self::assertSelectorExists('form input[name="_csrf_token"]');
    }

    public function testAdminCanAddProductWithPhotoUpload(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('admin', self::ADMIN_EMAIL));

        $crawler = $client->request('GET', '/account', ['tab' => 'add_product']);
        $token = $crawler->filterXPath('//form[input[@name="action" and @value="add_product"]]/input[@name="_csrf_token"]')->attr('value');

        $png = $this->makePng();
        $client->request('POST', '/account', [
            'action' => 'add_product',
            '_csrf_token' => $token,
            'product_name' => self::PRODUCT_NAME,
            'product_price' => '149.99',
            'product_stock_quantity' => '7',
            'product_description' => 'A test blossom.',
        ], [
            'product_image' => [
                'tmp_name' => $png,
                'name' => 'rose.png',
                'type' => 'image/png',
                'error' => 0,
                'size' => filesize($png),
            ],
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.ok', 'added to store inventory!');

        // the row carries the uploaded filename and the real numbers
        $connection = static::getContainer()->get(Connection::class);
        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        $row = $connection->fetchAssociative(
            'SELECT product_price, product_stock_quantity, product_img FROM products WHERE product_name = ?',
            [self::PRODUCT_NAME]
        );
        self::assertNotFalse($row);
        self::assertSame('149.99', (string) $row['product_price']);
        self::assertSame(7, (int) $row['product_stock_quantity']);
        self::assertIsString($row['product_img']);
        self::assertNotSame('', $row['product_img']);

        // and the photo itself is on disk under uploads/products/
        $path = $projectDir.'/public/uploads/products/'.$row['product_img'];
        self::assertFileExists($path);

        $this->uploadedFiles[] = ['subdir' => 'products', 'filename' => (string) $row['product_img']];
    }

    public function testAddProductFailsWithoutCsrfToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('admin', self::ADMIN_EMAIL));

        $png = $this->makePng();
        $client->request('POST', '/account', [
            'action' => 'add_product',
            'product_name' => self::PRODUCT_NAME,
            'product_price' => '149.99',
            'product_stock_quantity' => '7',
        ], [
            'product_image' => [
                'tmp_name' => $png,
                'name' => 'rose.png',
                'type' => 'image/png',
                'error' => 0,
                'size' => filesize($png),
            ],
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.err', 'Security token invalid or expired.');

        // nothing reached the database
        $connection = static::getContainer()->get(Connection::class);
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM products WHERE product_name = ?',
            [self::PRODUCT_NAME]
        );
        self::assertSame(0, (int) $count);
    }

    public function testCustomerCanUploadAvatar(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('customer', self::CUSTOMER_EMAIL);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/account', ['tab' => 'general']);
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('form.avatar-upload-form input[name="_csrf_token"]')->attr('value');

        $png = $this->makePng();
        $client->request('POST', '/account', [
            'action' => 'update_avatar',
            '_csrf_token' => $token,
        ], [
            'avatar' => [
                'tmp_name' => $png,
                'name' => 'me.png',
                'type' => 'image/png',
                'error' => 0,
                'size' => filesize($png),
            ],
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.account-alert.ok', 'Profile picture updated successfully!');

        // the user row carries the filename and the file is on disk
        $repository = static::getContainer()->get(UserRepository::class);
        $fresh = $repository->findOneBy(['email' => self::CUSTOMER_EMAIL]);
        self::assertNotNull($fresh);
        $pic = $fresh->getProfilePic();
        self::assertIsString($pic);
        self::assertNotSame('', $pic);

        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        self::assertFileExists($projectDir.'/public/uploads/avatars/'.$pic);

        $this->uploadedFiles[] = ['subdir' => 'avatars', 'filename' => $pic];
    }

    private function makeUser(string $role, string $email): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setUsername('upload_'.str_replace('-', '_', explode('@', $email)[0]));
        $user->setEmail($email);
        $user->setRole($role);
        $user->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    /**
     * Writes a real 1×1 PNG to the temp dir so mime sniffing treats the upload as genuine.
     */
    private function makePng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'eb_png_');
        file_put_contents((string) $path, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        return (string) $path;
    }
}
