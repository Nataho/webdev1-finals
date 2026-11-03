<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\FileUploader;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractController
{
    private const CSRF_ACTIONS = [
        'update_avatar',
        'change_username',
        'change_password',
        'delete_account',
        'toggle_user_ban',
        'update_stock',
        'add_product',
        'delete_product',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly FileUploader $fileUploader,
    ) {
    }

    #[Route('/account', name: 'app_account')]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        if ($request->isMethod('POST') && $response = $this->handleAction($request, $user, $isAdmin)) {
            return $response;
        }

        // determine active tab
        $allowed_tabs = ['general', 'logs'];
        if ($isAdmin) {
            $allowed_tabs = array_merge(['dashboard'], $allowed_tabs, ['users', 'stock', 'add_product']);
        }
        $default_tab = $isAdmin ? 'dashboard' : 'general';
        $active_tab = $request->query->get('tab', $default_tab);

        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = $default_tab;
        }

        // compute avatar url (only show if the stored file actually exists)
        $avatar_file = null;
        if ($this->fileUploader->exists('avatars', $user->getProfilePic())) {
            $avatar_file = $user->getProfilePic();
        }

        // fetch data for the dashboard tab (admin only)
        $users_count = $products_count = $orders_count = 0;
        $recent_orders = [];
        if ($isAdmin) {
            $users_count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users');
            $products_count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM products');
            $orders_count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM orders');
            $recent_orders = $this->connection->fetchAllAssociative(
                'SELECT o.*, u.user_name, u.user_email
                FROM orders o LEFT JOIN users u ON o.user_id = u.user_id
                ORDER BY o.order_date DESC LIMIT 5'
            );
        }

        // fetch data for the logs tab
        $orders_list = [];
        if ($active_tab === 'logs') {
            if ($isAdmin) {
                $orders_list = $this->connection->fetchAllAssociative(
                    'SELECT o.*, u.user_name, u.user_email FROM orders o LEFT JOIN users u ON o.user_id = u.user_id ORDER BY o.order_date DESC'
                );
            } else {
                $orders_list = $this->connection->fetchAllAssociative(
                    'SELECT * FROM orders WHERE user_id = ? ORDER BY order_date DESC',
                    [$user->getId()]
                );
            }
        }

        // fetch data for the users tab (admin only)
        $user_q = trim($request->query->getString('user_q'));
        $admin_users_list = [];
        if ($active_tab === 'users' && $isAdmin) {
            if ($user_q !== '') {
                $searchTerm = '%'.$user_q.'%';
                $admin_users_list = $this->connection->fetchAllAssociative(
                    'SELECT user_id, user_name, user_email, user_role, user_profile_pic
                    FROM users
                    WHERE user_name LIKE ? OR user_email LIKE ?
                    ORDER BY user_id DESC',
                    [$searchTerm, $searchTerm]
                );
            } else {
                $admin_users_list = $this->connection->fetchAllAssociative(
                    'SELECT user_id, user_name, user_email, user_role, user_profile_pic
                    FROM users
                    ORDER BY user_id DESC'
                );
            }
        }

        // fetch data for the stock tab (admin only)
        $stock_q = trim($request->query->getString('stock_q'));
        $admin_products_list = [];
        if ($active_tab === 'stock' && $isAdmin) {
            if ($stock_q !== '') {
                $admin_products_list = $this->connection->fetchAllAssociative(
                    'SELECT * FROM products WHERE product_name LIKE ? ORDER BY product_id DESC',
                    ['%'.$stock_q.'%']
                );
            } else {
                $admin_products_list = $this->connection->fetchAllAssociative(
                    'SELECT * FROM products ORDER BY product_id DESC'
                );
            }
        }

        return $this->render('account/index.html.twig', [
            'allowed_tabs' => $allowed_tabs,
            'active_tab' => $active_tab,
            'orders_list' => $orders_list,

            'user_q' => $user_q,
            'admin_users_list' => $admin_users_list,

            'stock_q' => $stock_q,
            'admin_products_list' => $admin_products_list,

            'user' => $user,
            'avatar_file' => $avatar_file,

            'users_count' => $users_count,
            'products_count' => $products_count,
            'orders_count' => $orders_count,
            'recent_orders' => $recent_orders,

            'delete_account_word' => 'DELETE_'.mb_strtoupper($user->getUsername()),
        ]);
    }

    /**
     * Dispatches a POST form action and returns the redirect response, or null
     * when the request is not a supported action.
     */
    private function handleAction(Request $request, User $user, bool $isAdmin): ?Response
    {
        $action = $request->getPayload()->getString('action');
        $redirect = fn (array $params = []): Response => $this->redirectToRoute('app_account', $params);

        if (!in_array($action, self::CSRF_ACTIONS, true)) {
            return null;
        }

        // every action requires a valid CSRF token
        if (!$this->isCsrfTokenValid($action, $request->getPayload()->getString('_csrf_token'))) {
            $this->addFlash('error', 'Security token invalid or expired. Please try again.');

            return $redirect();
        }

        return match ($action) {
            'update_avatar' => $this->updateAvatar($request, $user, $redirect),
            'change_username' => $this->changeUsername($request, $user, $redirect),
            'change_password' => $this->changePassword($request, $user, $redirect),
            'delete_account' => $this->deleteAccount($request, $user, $redirect),
            'toggle_user_ban' => $isAdmin ? $this->toggleUserBan($request, $user, $redirect) : $redirect(),
            'update_stock' => $isAdmin ? $this->updateStock($request, $redirect) : $redirect(),
            'add_product' => $isAdmin ? $this->addProduct($request, $user, $redirect) : $redirect(),
            'delete_product' => $isAdmin ? $this->deleteProduct($request, $redirect) : $redirect(),
            default => null,
        };
    }

    private function updateAvatar(Request $request, User $user, \Closure $redirect): Response
    {
        $file = $request->files->get('avatar');

        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Please choose an image to upload.');

            return $redirect();
        }

        try {
            $filename = $this->fileUploader->uploadImage($file);
        } catch (RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $redirect();
        }

        $user->setProfilePic($filename);
        $this->entityManager->flush();
        $this->addFlash('success', 'Profile picture updated successfully!');

        return $redirect();
    }

    private function changeUsername(Request $request, User $user, \Closure $redirect): Response
    {
        $username = trim($request->getPayload()->getString('username'));

        if (mb_strlen($username) < 3) {
            $this->addFlash('error', 'Username must be at least 3 characters long.');
        } else {
            $user->setUsername($username);
            $this->entityManager->flush();
            $this->addFlash('success', "Username updated to '".$username."'!");
        }

        return $redirect();
    }

    private function changePassword(Request $request, User $user, \Closure $redirect): Response
    {
        $current_password = $request->getPayload()->getString('current_password');
        $new_password = $request->getPayload()->getString('new_password');
        $confirm_password = $request->getPayload()->getString('confirm_password');

        if ($new_password !== $confirm_password) {
            $this->addFlash('error', 'New passwords do not match.');
        } elseif (!$this->passwordHasher->isPasswordValid($user, $current_password)) {
            $this->addFlash('error', 'Current password is incorrect.');
        } else {
            $user->setPassword($this->passwordHasher->hashPassword($user, $new_password));
            $this->entityManager->flush();
            $this->addFlash('success', 'Password updated successfully!');
        }

        return $redirect();
    }

    private function deleteAccount(Request $request, User $user, \Closure $redirect): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            $this->addFlash('error', 'Admin accounts cannot be deleted.');

            return $redirect();
        }

        try {
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        } catch (\Throwable) {
            $this->addFlash('error', 'Failed to delete account. Please try again.');

            return $redirect();
        }

        // log the (now deleted) user out
        $this->container->get('security.token_storage')->setToken(null);
        $this->addFlash('success', 'Your account has been permanently deleted.');

        return $this->redirectToRoute('app_signup');
    }

    private function toggleUserBan(Request $request, User $user, \Closure $redirect): Response
    {
        $targetUserId = $request->getPayload()->getInt('target_user_id');
        $targetAction = $request->getPayload()->getString('target_action');

        if ($targetUserId === (int) $user->getId()) {
            $this->addFlash('error', 'You cannot ban or unban your own account.');

            return $redirect(['tab' => 'users']);
        }

        $newRole = ('unban' === $targetAction) ? 'customer' : 'banned';
        $this->connection->executeStatement(
            'UPDATE users SET user_role = ? WHERE user_id = ?',
            [$newRole, $targetUserId]
        );

        $status_word = ('unban' === $targetAction) ? 'unbanned' : 'banned';
        $this->addFlash('success', "User has been successfully {$status_word}.");

        return $redirect(['tab' => 'users']);
    }

    private function updateStock(Request $request, \Closure $redirect): Response
    {
        $product_id = $request->getPayload()->getInt('product_id');
        $new_qty = $request->getPayload()->getInt('stock_quantity');

        if ($product_id <= 0) {
            $this->addFlash('error', 'Invalid product or quantity specified.');

            return $redirect(['tab' => 'stock']);
        }

        $this->connection->executeStatement(
            'UPDATE products SET product_stock_quantity = ? WHERE product_id = ?',
            [$new_qty, $product_id]
        );
        $this->addFlash('success', 'Stock quantity updated successfully!');

        return $redirect(['tab' => 'stock']);
    }

    private function addProduct(Request $request, User $user, \Closure $redirect): Response
    {
        $p_name = trim($request->getPayload()->getString('product_name'));
        $p_price = (float) $request->getPayload()->get('product_price', 0);
        $p_stock = $request->getPayload()->getInt('product_stock_quantity');
        $p_desc = trim($request->getPayload()->getString('product_description'));
        $p_img = '';

        if ('' === $p_name || $p_price < 0 || $p_stock < 0) {
            $this->addFlash('error', 'Please fill in all required product fields with valid numbers.');

            return $redirect(['tab' => 'add_product']);
        }

        // handle optional product image upload
        $file = $request->files->get('product_image');
        if ($file instanceof UploadedFile) {
            try {
                $p_img = $this->fileUploader->uploadImage($file, 'products');
            } catch (RuntimeException $e) {
                $this->addFlash('error', 'Image upload failed: '.$e->getMessage());

                return $redirect(['tab' => 'add_product']);
            }
        }

        $this->connection->executeStatement(
            'INSERT INTO products (product_name, product_description, product_price, product_stock_quantity, product_img) VALUES (?, ?, ?, ?, ?)',
            [$p_name, $p_desc, $p_price, $p_stock, $p_img]
        );
        $this->addFlash('success', "Product '{$p_name}' added to store inventory!");

        return $redirect(['tab' => 'stock']);
    }

    private function deleteProduct(Request $request, \Closure $redirect): Response
    {
        $product_id = $request->getPayload()->getInt('product_id');

        if ($product_id <= 0) {
            $this->addFlash('error', 'Invalid product specified for deletion.');

            return $redirect(['tab' => 'stock']);
        }

        $affected = $this->connection->executeStatement(
            'DELETE FROM products WHERE product_id = ?',
            [$product_id]
        );

        if ($affected > 0) {
            $this->addFlash('success', 'Product has been permanently deleted.');
        } else {
            $this->addFlash('error', 'Failed to delete product or product not found.');
        }

        return $redirect(['tab' => 'stock']);
    }
}
