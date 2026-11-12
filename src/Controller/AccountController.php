<?php

namespace App\Controller;

use App\Entity\Order;
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
        'create_user',
        'edit_user',
        'delete_user',
        'update_stock',
        'add_product',
        'delete_product',
        'update_payment_options',
        'remove_payment_option',
        'add_address',
        'edit_address',
        'delete_address',
        'set_default_address',
        'update_order_status',
        'cancel_order',
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
        $allowed_tabs = ['general', 'payment_options', 'addresses', 'logs'];
        if ($isAdmin) {
            $allowed_tabs = array_merge(['dashboard'], $allowed_tabs, ['users', 'stock', 'add_product']);
        }
        $default_tab = $isAdmin ? 'dashboard' : 'general';
        $active_tab = $request->query->get('tab', $default_tab);

        if (!in_array($active_tab, $allowed_tabs, true)) {
            // staff/customers poking an admin tab get a visible denial, not a silent fallback
            if (!$isAdmin && in_array($active_tab, ['dashboard', 'users', 'stock', 'add_product'], true)) {
                $this->addFlash('error', 'You do not have permission to open that area.');
            }
            $active_tab = $default_tab;
        }

        // compute avatar url (only show if the stored file actually exists)
        $avatar_file = null;
        if ($this->fileUploader->exists('avatars', $user->getProfilePic())) {
            $avatar_file = $user->getProfilePic();
        }

        // fetch user delivery addresses
        $addresses = $this->connection->fetchAllAssociative(
            'SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, address_id DESC',
            [$user->getId()]
        );

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
        $status_counts = [];
        $status_filter = null;
        if ($active_tab === 'logs') {
            // ?status= narrows the list; anything outside the pipeline falls back to all orders
            $status_filter = $request->query->getString('status');
            if (!in_array($status_filter, Order::STATUSES, true)) {
                $status_filter = null;
            }

            if ($isAdmin) {
                $sql = 'SELECT o.*, u.user_name, u.user_email FROM orders o LEFT JOIN users u ON o.user_id = u.user_id';
                $params = [];
                if ($status_filter !== null) {
                    $sql .= ' WHERE o.status = ?';
                    $params[] = $status_filter;
                }
                $orders_list = $this->connection->fetchAllAssociative($sql.' ORDER BY o.order_date DESC', $params);

                $count_rows = $this->connection->fetchAllAssociative(
                    'SELECT status, COUNT(*) AS total FROM orders GROUP BY status'
                );
            } else {
                $sql = 'SELECT * FROM orders WHERE user_id = ?';
                $params = [$user->getId()];
                if ($status_filter !== null) {
                    $sql .= ' AND status = ?';
                    $params[] = $status_filter;
                }
                $orders_list = $this->connection->fetchAllAssociative($sql.' ORDER BY order_date DESC', $params);

                $count_rows = $this->connection->fetchAllAssociative(
                    'SELECT status, COUNT(*) AS total FROM orders WHERE user_id = ? GROUP BY status',
                    [$user->getId()]
                );
            }

            // turn the counts into a status => total map (statuses with zero orders are just absent)
            $status_counts = [];
            foreach ($count_rows as $row) {
                $status_counts[$row['status']] = (int) $row['total'];
            }
        }

        // fetch data for the users tab (admin only)
        $user_q = trim($request->query->getString('user_q'));
        $admin_users_list = [];
        $edit_user_row = null;
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

            // ?edit_id=N opens the edit panel for that account on the same tab
            $edit_id = $request->query->getInt('edit_id');
            if ($edit_id > 0) {
                $edit_user_row = $this->connection->fetchAssociative(
                    'SELECT user_id, user_name, user_email, user_role FROM users WHERE user_id = ?',
                    [$edit_id]
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
            'status_counts' => $status_counts,
            'status_filter' => $status_filter,
            'addresses' => $addresses,

            'user_q' => $user_q,
            'admin_users_list' => $admin_users_list,
            'edit_user_row' => $edit_user_row,

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

        // admin-only actions bounce with a visible denial for everyone else,
        // before any token validation runs
        $admin_actions = [
            'toggle_user_ban',
            'create_user',
            'edit_user',
            'delete_user',
            'update_stock',
            'add_product',
            'delete_product',
            'update_order_status',
        ];
        if (in_array($action, $admin_actions, true) && !$isAdmin) {
            $this->addFlash('error', 'You do not have permission to do that.');

            return $redirect('update_order_status' === $action ? ['tab' => 'logs'] : []);
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
            'toggle_user_ban' => $this->toggleUserBan($request, $user, $redirect),
            'create_user' => $this->createUser($request, $redirect),
            'edit_user' => $this->editUser($request, $user, $redirect),
            'delete_user' => $this->deleteUser($request, $user, $redirect),
            'update_stock' => $this->updateStock($request, $redirect),
            'add_product' => $this->addProduct($request, $user, $redirect),
            'delete_product' => $this->deleteProduct($request, $redirect),
            'update_payment_options' => $this->updatePaymentOptions($request, $user, $redirect),
            'remove_payment_option' => $this->removePaymentOption($request, $user, $redirect),
            'add_address' => $this->addAddress($request, $user, $redirect),
            'edit_address' => $this->editAddress($request, $user, $redirect),
            'delete_address' => $this->deleteAddress($request, $user, $redirect),
            'set_default_address' => $this->setDefaultAddress($request, $user, $redirect),
            'update_order_status' => $this->updateOrderStatus($request, $redirect),
            'cancel_order' => $this->cancelOrder($request, $user, $redirect),
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
            $filename = $this->fileUploader->uploadImage($file, 'avatars');
        } catch (RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $redirect();
        }

        $old_pic = $user->getProfilePic();
        $user->setProfilePic($filename);
        $this->connection->executeStatement('UPDATE users SET user_profile_pic = ? WHERE user_id = ?', [$filename, $user->getId()]);

        if ($old_pic) {
            $this->fileUploader->delete('avatars', $old_pic);
        }

        $this->addFlash('success', 'Profile picture updated successfully!');

        return $redirect();
    }

    private function changeUsername(Request $request, User $user, \Closure $redirect): Response
    {
        $new_username = trim($request->getPayload()->getString('new_username'));

        if (mb_strlen($new_username) < 3) {
            $this->addFlash('error', 'Username must be at least 3 characters long.');

            return $redirect();
        }

        if ($new_username === $user->getUsername()) {
            $this->addFlash('error', 'New username cannot be the same as your current username.');

            return $redirect();
        }

        $existing = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users WHERE user_name = ? AND user_id != ?',
            [$new_username, $user->getId()]
        );
        if ($existing > 0) {
            $this->addFlash('error', 'That username is already taken. Please choose another.');

            return $redirect();
        }

        $user->setUsername($new_username);
        $this->connection->executeStatement('UPDATE users SET user_name = ? WHERE user_id = ?', [$new_username, $user->getId()]);
        $this->addFlash('success', 'Username updated successfully!');

        return $redirect();
    }

    private function changePassword(Request $request, User $user, \Closure $redirect): Response
    {
        $curr = $request->getPayload()->getString('curr_password');
        $new = $request->getPayload()->getString('new_password');
        $confirm = $request->getPayload()->getString('confirm_password');

        if (!$this->passwordHasher->isPasswordValid($user, $curr)) {
            $this->addFlash('error', 'Incorrect current password.');

            return $redirect();
        }

        if (mb_strlen($new) < 6) {
            $this->addFlash('error', 'New password must be at least 6 characters long.');

            return $redirect();
        }

        if ($new !== $confirm) {
            $this->addFlash('error', 'New passwords do not match.');

            return $redirect();
        }

        $hashed = $this->passwordHasher->hashPassword($user, $new);
        $user->setPassword($hashed);
        $this->connection->executeStatement('UPDATE users SET user_password = ? WHERE user_id = ?', [$hashed, $user->getId()]);
        $this->addFlash('success', 'Password updated successfully!');

        return $redirect();
    }

    private function deleteAccount(Request $request, User $user, \Closure $redirect): Response
    {
        $typed = trim($request->getPayload()->getString('confirmation_input'));
        $expected = 'DELETE_'.mb_strtoupper($user->getUsername());

        if ($typed !== $expected) {
            $this->addFlash('error', 'Account deletion cancelled: phrase did not match.');

            return $redirect();
        }

        $old_pic = $user->getProfilePic();
        $this->connection->executeStatement('DELETE FROM users WHERE user_id = ?', [$user->getId()]);

        if ($old_pic) {
            $this->fileUploader->delete('avatars', $old_pic);
        }

        $request->getSession()->invalidate();

        return $this->redirectToRoute('app_logout');
    }

    private function toggleUserBan(Request $request, User $currentUser, \Closure $redirect): Response
    {
        $target_id = $request->getPayload()->getInt('target_user_id');

        if ($target_id <= 0 || $target_id === $currentUser->getId()) {
            $this->addFlash('error', 'Invalid target user specified.');

            return $redirect(['tab' => 'users']);
        }

        $row = $this->connection->fetchAssociative(
            'SELECT user_id, user_role FROM users WHERE user_id = ?',
            [$target_id]
        );

        if (!$row) {
            $this->addFlash('error', 'User not found.');

            return $redirect(['tab' => 'users']);
        }

        if ('admin' === $row['user_role']) {
            $this->addFlash('error', 'Administrators cannot be banned.');

            return $redirect(['tab' => 'users']);
        }

        $new_role = ('banned' === $row['user_role']) ? 'customer' : 'banned';
        $this->connection->executeStatement(
            'UPDATE users SET user_role = ? WHERE user_id = ?',
            [$new_role, $target_id]
        );

        $this->addFlash('success', 'banned' === $new_role ? 'User has been banned.' : 'User has been unbanned.');

        return $redirect(['tab' => 'users']);
    }

    private function createUser(Request $request, \Closure $redirect): Response
    {
        $tab = ['tab' => 'users'];

        $name = trim($request->getPayload()->getString('new_user_name'));
        $email = trim($request->getPayload()->getString('new_user_email'));
        $role = $request->getPayload()->getString('new_user_role', 'customer');
        $password = $request->getPayload()->getString('new_user_password');
        $confirm = $request->getPayload()->getString('new_user_confirm');

        if (mb_strlen($name) < 3) {
            $this->addFlash('error', 'Name must be at least 3 characters long.');

            return $redirect($tab);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Please enter a valid email address.');

            return $redirect($tab);
        }

        if (!in_array($role, ['admin', 'staff', 'customer'], true)) {
            $this->addFlash('error', 'Please choose a valid role.');

            return $redirect($tab);
        }

        // same minimum as public signup (8 chars)
        if (mb_strlen($password) < 8) {
            $this->addFlash('error', 'Password must be at least 8 characters long.');

            return $redirect($tab);
        }

        if ($password !== $confirm) {
            $this->addFlash('error', 'Passwords do not match.');

            return $redirect($tab);
        }

        $email_taken = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users WHERE user_email = ?',
            [$email]
        );
        if ($email_taken > 0) {
            $this->addFlash('error', 'An account with that email already exists.');

            return $redirect($tab);
        }

        $name_taken = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users WHERE user_name = ?',
            [$name]
        );
        if ($name_taken > 0) {
            $this->addFlash('error', 'That username is already taken. Please choose another.');

            return $redirect($tab);
        }

        // hash through the entity so the auto hasher picks the right algorithm
        $hashed = $this->passwordHasher->hashPassword(new User(), $password);

        $this->connection->executeStatement(
            'INSERT INTO users (user_name, user_email, user_password, user_role) VALUES (?, ?, ?, ?)',
            [$name, $email, $hashed, $role]
        );

        $this->addFlash('success', ucfirst($role)." account '{$name}' has been created.");

        return $redirect($tab);
    }

    private function editUser(Request $request, User $currentUser, \Closure $redirect): Response
    {
        $tab = ['tab' => 'users'];

        $target_id = $request->getPayload()->getInt('target_user_id');
        if ($target_id <= 0) {
            $this->addFlash('error', 'Invalid target user specified.');

            return $redirect($tab);
        }

        $row = $this->connection->fetchAssociative(
            'SELECT user_id, user_name, user_email, user_role FROM users WHERE user_id = ?',
            [$target_id]
        );

        if (!$row) {
            $this->addFlash('error', 'User not found.');

            return $redirect($tab);
        }

        $name = trim($request->getPayload()->getString('edit_user_name'));
        $email = trim($request->getPayload()->getString('edit_user_email'));
        $role = $request->getPayload()->getString('edit_user_role', $row['user_role']);
        $new_password = $request->getPayload()->getString('edit_user_password');

        if (mb_strlen($name) < 3) {
            $this->addFlash('error', 'Name must be at least 3 characters long.');

            return $redirect($tab);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Please enter a valid email address.');

            return $redirect($tab);
        }

        if (!in_array($role, ['admin', 'staff', 'customer'], true)) {
            $this->addFlash('error', 'Please choose a valid role.');

            return $redirect($tab);
        }

        if ('' !== $new_password && mb_strlen($new_password) < 8) {
            $this->addFlash('error', 'Password must be at least 8 characters long.');

            return $redirect($tab);
        }

        // an admin changing their own role could lock themselves out of the admin area
        if ($target_id === $currentUser->getId() && $role !== $row['user_role']) {
            $this->addFlash('error', 'You cannot change your own role.');

            return $redirect($tab);
        }

        $email_taken = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users WHERE user_email = ? AND user_id != ?',
            [$email, $target_id]
        );
        if ($email_taken > 0) {
            $this->addFlash('error', 'An account with that email already exists.');

            return $redirect($tab);
        }

        $name_taken = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users WHERE user_name = ? AND user_id != ?',
            [$name, $target_id]
        );
        if ($name_taken > 0) {
            $this->addFlash('error', 'That username is already taken. Please choose another.');

            return $redirect($tab);
        }

        // empty password field = keep the current password
        $hashed = '' !== $new_password ? $this->passwordHasher->hashPassword(new User(), $new_password) : null;

        if (null !== $hashed) {
            $this->connection->executeStatement(
                'UPDATE users SET user_name = ?, user_email = ?, user_role = ?, user_password = ? WHERE user_id = ?',
                [$name, $email, $role, $hashed, $target_id]
            );
        } else {
            $this->connection->executeStatement(
                'UPDATE users SET user_name = ?, user_email = ?, user_role = ? WHERE user_id = ?',
                [$name, $email, $role, $target_id]
            );
        }

        $this->addFlash('success', "User '{$name}' has been updated.".(null !== $hashed ? ' Their password has been reset.' : ''));

        return $redirect($tab);
    }

    private function deleteUser(Request $request, User $currentUser, \Closure $redirect): Response
    {
        $tab = ['tab' => 'users'];

        $typed = trim($request->getPayload()->getString('confirmation_input'));
        $target_id = $request->getPayload()->getInt('target_user_id');

        if ($target_id <= 0 || $target_id === $currentUser->getId()) {
            $this->addFlash('error', 'Invalid target user specified.');

            return $redirect($tab);
        }

        if ($typed !== 'DELETE_USER_ACCOUNT') {
            $this->addFlash('error', 'User deletion cancelled: phrase did not match.');

            return $redirect($tab);
        }

        $row = $this->connection->fetchAssociative(
            'SELECT user_id, user_name, user_role, user_profile_pic FROM users WHERE user_id = ?',
            [$target_id]
        );

        if (!$row) {
            $this->addFlash('error', 'User not found.');

            return $redirect($tab);
        }

        // mirror the ban guard: admin accounts are untouchable by other admins
        if ('admin' === $row['user_role']) {
            $this->addFlash('error', 'Administrator accounts cannot be deleted.');

            return $redirect($tab);
        }

        $affected = $this->connection->executeStatement(
            'DELETE FROM users WHERE user_id = ?',
            [$target_id]
        );

        if ($affected > 0) {
            if ($row['user_profile_pic']) {
                $this->fileUploader->delete('avatars', $row['user_profile_pic']);
            }

            $this->addFlash('success', "User '{$row['user_name']}' has been permanently deleted.");
        } else {
            $this->addFlash('error', 'Failed to delete user or user not found.');
        }

        return $redirect($tab);
    }

    private function updateStock(Request $request, \Closure $redirect): Response
    {
        $product_id = $request->getPayload()->getInt('product_id');
        $new_stock = $request->getPayload()->getInt('new_stock');

        if ($product_id <= 0 || $new_stock < 0) {
            $this->addFlash('error', 'Invalid stock quantity entered.');

            return $redirect(['tab' => 'stock']);
        }

        $this->connection->executeStatement(
            'UPDATE products SET product_stock_quantity = ? WHERE product_id = ?',
            [$new_stock, $product_id]
        );
        $this->addFlash('success', 'Stock level updated successfully!');

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

    private function updatePaymentOptions(Request $request, User $user, \Closure $redirect): Response
    {
        $payment_type = $request->getPayload()->getString('payment_type');

        if ($payment_type === 'gcash') {
            $number = trim($request->getPayload()->getString('gcash_number'));
            if ($number === '') {
                $this->addFlash('error', 'Please enter a valid GCash mobile number.');

                return $redirect(['tab' => 'payment_options']);
            }
            $user->setGcashNumber($number);
            $this->connection->executeStatement('UPDATE users SET gcash_number = ? WHERE user_id = ?', [$number, $user->getId()]);
            $this->addFlash('success', 'GCash account updated successfully.');
        } elseif ($payment_type === 'card') {
            $number = trim($request->getPayload()->getString('card_number'));
            // strip spaces/dashes
            $clean_number = preg_replace('/\D/', '', $number);
            if (mb_strlen($clean_number) < 12) {
                $this->addFlash('error', 'Please enter a valid card number (at least 12 digits).');

                return $redirect(['tab' => 'payment_options']);
            }
            $user->setCardNumber($clean_number);
            $this->connection->executeStatement('UPDATE users SET card_number = ? WHERE user_id = ?', [$clean_number, $user->getId()]);
            $this->addFlash('success', 'Card details updated successfully.');
        } else {
            $this->addFlash('error', 'Invalid payment option type.');
        }

        return $redirect(['tab' => 'payment_options']);
    }

    private function removePaymentOption(Request $request, User $user, \Closure $redirect): Response
    {
        $payment_type = $request->getPayload()->getString('payment_type');

        if ($payment_type === 'gcash') {
            $user->setGcashNumber(null);
            $this->connection->executeStatement('UPDATE users SET gcash_number = NULL WHERE user_id = ?', [$user->getId()]);
            $this->addFlash('success', 'GCash account removed.');
        } elseif ($payment_type === 'card') {
            $user->setCardNumber(null);
            $this->connection->executeStatement('UPDATE users SET card_number = NULL WHERE user_id = ?', [$user->getId()]);
            $this->addFlash('success', 'Card details removed.');
        }

        return $redirect(['tab' => 'payment_options']);
    }

    private function addAddress(Request $request, User $user, \Closure $redirect): Response
    {
        $label = trim($request->getPayload()->getString('label', 'Home'));
        $recipient_name = trim($request->getPayload()->getString('recipient_name'));
        $phone_number = trim($request->getPayload()->getString('phone_number'));
        $region = trim($request->getPayload()->getString('region'));
        $province = trim($request->getPayload()->getString('province'));
        $city = trim($request->getPayload()->getString('city'));
        $barangay = trim($request->getPayload()->getString('barangay'));
        $postal_code = trim($request->getPayload()->getString('postal_code'));
        $street_address = trim($request->getPayload()->getString('street_address'));
        $is_default = $request->getPayload()->getBoolean('is_default');

        if ($recipient_name === '' || $phone_number === '' || $region === '' || $city === '' || $barangay === '' || $street_address === '') {
            $this->addFlash('error', 'Please fill in all required address fields (Recipient, Phone, Region, City, Barangay, Street).');

            return $redirect(['tab' => 'addresses']);
        }

        // if user has no addresses yet, make this one default
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_addresses WHERE user_id = ?', [$user->getId()]);
        if ($count === 0 || $is_default) {
            $is_default = true;
            $this->connection->executeStatement('UPDATE user_addresses SET is_default = 0 WHERE user_id = ?', [$user->getId()]);
        }

        $this->connection->executeStatement(
            'INSERT INTO user_addresses (user_id, label, recipient_name, phone_number, region, province, city, barangay, postal_code, street_address, is_default)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $user->getId(),
                $label ?: 'Home',
                $recipient_name,
                $phone_number,
                $region,
                $province ?: null,
                $city,
                $barangay,
                $postal_code ?: null,
                $street_address,
                $is_default ? 1 : 0,
            ]
        );

        $this->addFlash('success', 'New delivery address added successfully.');

        return $redirect(['tab' => 'addresses']);
    }

    private function editAddress(Request $request, User $user, \Closure $redirect): Response
    {
        $address_id = (int) $request->getPayload()->getString('address_id');
        $label = trim($request->getPayload()->getString('label', 'Home'));
        $recipient_name = trim($request->getPayload()->getString('recipient_name'));
        $phone_number = trim($request->getPayload()->getString('phone_number'));
        $region = trim($request->getPayload()->getString('region'));
        $province = trim($request->getPayload()->getString('province'));
        $city = trim($request->getPayload()->getString('city'));
        $barangay = trim($request->getPayload()->getString('barangay'));
        $postal_code = trim($request->getPayload()->getString('postal_code'));
        $street_address = trim($request->getPayload()->getString('street_address'));
        $is_default = $request->getPayload()->getBoolean('is_default');

        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_addresses WHERE address_id = ? AND user_id = ?',
            [$address_id, $user->getId()]
        );

        if (!$exists) {
            $this->addFlash('error', 'Address not found or unauthorized.');

            return $redirect(['tab' => 'addresses']);
        }

        if ($recipient_name === '' || $phone_number === '' || $region === '' || $city === '' || $barangay === '' || $street_address === '') {
            $this->addFlash('error', 'Please fill in all required address fields.');

            return $redirect(['tab' => 'addresses']);
        }

        if ($is_default) {
            $this->connection->executeStatement('UPDATE user_addresses SET is_default = 0 WHERE user_id = ?', [$user->getId()]);
        }

        $this->connection->executeStatement(
            'UPDATE user_addresses SET
                label = ?, recipient_name = ?, phone_number = ?, region = ?,
                province = ?, city = ?, barangay = ?, postal_code = ?,
                street_address = ?, is_default = ?
            WHERE address_id = ? AND user_id = ?',
            [
                $label ?: 'Home',
                $recipient_name,
                $phone_number,
                $region,
                $province ?: null,
                $city,
                $barangay,
                $postal_code ?: null,
                $street_address,
                $is_default ? 1 : 0,
                $address_id,
                $user->getId(),
            ]
        );

        $this->addFlash('success', 'Delivery address updated successfully.');

        return $redirect(['tab' => 'addresses']);
    }

    private function deleteAddress(Request $request, User $user, \Closure $redirect): Response
    {
        $address_id = (int) $request->getPayload()->getString('address_id');

        $is_default = (int) $this->connection->fetchOne(
            'SELECT is_default FROM user_addresses WHERE address_id = ? AND user_id = ?',
            [$address_id, $user->getId()]
        );

        $deleted = $this->connection->executeStatement(
            'DELETE FROM user_addresses WHERE address_id = ? AND user_id = ?',
            [$address_id, $user->getId()]
        );

        if ($deleted > 0) {
            // if we deleted the default address, set another remaining address as default if one exists
            if ($is_default) {
                $other_id = $this->connection->fetchOne(
                    'SELECT address_id FROM user_addresses WHERE user_id = ? ORDER BY address_id DESC LIMIT 1',
                    [$user->getId()]
                );
                if ($other_id) {
                    $this->connection->executeStatement(
                        'UPDATE user_addresses SET is_default = 1 WHERE address_id = ?',
                        [$other_id]
                    );
                }
            }
            $this->addFlash('success', 'Address deleted successfully.');
        } else {
            $this->addFlash('error', 'Failed to delete address.');
        }

        return $redirect(['tab' => 'addresses']);
    }

    private function setDefaultAddress(Request $request, User $user, \Closure $redirect): Response
    {
        $address_id = (int) $request->getPayload()->getString('address_id');

        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_addresses WHERE address_id = ? AND user_id = ?',
            [$address_id, $user->getId()]
        );

        if ($exists) {
            $this->connection->executeStatement('UPDATE user_addresses SET is_default = 0 WHERE user_id = ?', [$user->getId()]);
            $this->connection->executeStatement('UPDATE user_addresses SET is_default = 1 WHERE address_id = ? AND user_id = ?', [$address_id, $user->getId()]);
            $this->addFlash('success', 'Default delivery address updated.');
        } else {
            $this->addFlash('error', 'Address not found.');
        }

        return $redirect(['tab' => 'addresses']);
    }

    private function updateOrderStatus(Request $request, \Closure $redirect): Response
    {
        $order_id = $request->getPayload()->getInt('order_id');
        $new_status = $request->getPayload()->getString('new_status');

        if (!in_array($new_status, Order::STATUSES, true)) {
            $this->addFlash('error', 'Unknown order status.');

            return $redirect(['tab' => 'logs']);
        }

        if ($order_id <= 0) {
            $this->addFlash('error', 'Invalid order specified.');

            return $redirect(['tab' => 'logs']);
        }

        $order = $this->connection->fetchAssociative(
            'SELECT status FROM orders WHERE order_id = ? LIMIT 1',
            [$order_id]
        );

        if (!$order) {
            $this->addFlash('error', 'Order not found; status not updated.');

            return $redirect(['tab' => 'logs']);
        }

        $previous_status = (string) $order['status'];

        if ($previous_status === $new_status) {
            $this->addFlash('error', 'This order already has that status.');

            return $redirect(['tab' => 'logs']);
        }

        // status flip and the stock hand-back commit (or roll back) together
        try {
            $this->connection->beginTransaction();

            $this->connection->executeStatement(
                'UPDATE orders SET status = ? WHERE order_id = ?',
                [$new_status, $order_id]
            );

            // cancelling returns the reserved items to the shelf, re-opening an
            // already cancelled order takes them back out — atomically either way
            $returning_to_cancelled = 'cancelled' === $new_status && 'cancelled' !== $previous_status;
            $leaving_the_cancelled_bin = 'cancelled' !== $new_status && 'cancelled' === $previous_status;

            if ($returning_to_cancelled) {
                $stock_moved = $this->adjustStockForOrder($order_id, 'restock');
            } elseif ($leaving_the_cancelled_bin) {
                $stock_moved = $this->adjustStockForOrder($order_id, 'reserve');
            } else {
                $stock_moved = true;
            }

            if (!$stock_moved) {
                $this->connection->rollBack();

                return $redirect(['tab' => 'logs']);
            }

            $this->connection->commit();
        } catch (\Throwable) {
            $this->rollBackQuietly();
            $this->addFlash('error', 'Something went wrong while updating this order. Please try again.');

            return $redirect(['tab' => 'logs']);
        }

        $this->addFlash('success', "Order #{$order_id} status updated to '{$new_status}'.");

        return $redirect(['tab' => 'logs']);
    }

    private function cancelOrder(Request $request, User $user, \Closure $redirect): Response
    {
        $order_id = $request->getPayload()->getInt('order_id');

        if ($order_id <= 0) {
            $this->addFlash('error', 'Invalid order specified.');

            return $redirect(['tab' => 'logs']);
        }

        // only the order's owner may cancel it
        $order = $this->connection->fetchAssociative(
            'SELECT status, user_id FROM orders WHERE order_id = ? LIMIT 1',
            [$order_id]
        );

        if (!$order || (int) $order['user_id'] !== $user->getId()) {
            $this->addFlash('error', 'Order not found or not yours to cancel.');

            return $redirect(['tab' => 'logs']);
        }

        if (!in_array((string) $order['status'], Order::CANCELLABLE_STATUSES, true)) {
            $message = in_array('pending', Order::CANCELLABLE_STATUSES, true)
                ? 'This order is already being processed and can no longer be cancelled. Please contact us if you still want it withdrawn.'
                : 'This order can no longer be cancelled.';

            $this->addFlash('error', $message);

            return $redirect(['tab' => 'logs']);
        }

        // the typed reason is mandatory — anything leaner than 5 characters bounces back
        $cancel_reason = trim($request->getPayload()->getString('cancel_reason'));
        if (mb_strlen($cancel_reason) < 5) {
            $this->addFlash('error', 'Please type a cancellation reason (at least 5 characters) — it goes to the shop.');

            return $redirect(['tab' => 'logs']);
        }

        // the status flip and the stock hand-back commit (or roll back) together
        try {
            $this->connection->beginTransaction();

            $this->connection->executeStatement(
                "UPDATE orders SET status = 'cancelled', cancel_reason = ? WHERE order_id = ?",
                [$cancel_reason, $order_id]
            );

            if (!$this->adjustStockForOrder($order_id, 'restock')) {
                $this->connection->rollBack();

                return $redirect(['tab' => 'logs']);
            }

            $this->connection->commit();
        } catch (\Throwable) {
            $this->rollBackQuietly();
            $this->addFlash('error', 'Something went wrong while cancelling this order. Please try again.');

            return $redirect(['tab' => 'logs']);
        }

        $this->addFlash('success', "Order #{$order_id} has been cancelled and its items are back on the shelf.");

        return $redirect(['tab' => 'logs']);
    }

    /**
     * Moves an order's reserved stock between the shelf and the reservation:
     * 'restock' puts the items back (cancellation),
     * 'reserve' takes them out again (admin re-opens a cancelled order).
     * Legacy orders that predate the checkout transaction never reserved stock;
     * a set payment method is the marker for that, so this skips them.
     */
    private function adjustStockForOrder(int $order_id, string $direction): bool
    {
        $direction = in_array($direction, ['restock', 'reserve'], true) ? $direction : 'restock';

        // only checkout-placed orders have reservations to move around
        $reserved_at_checkout = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM orders WHERE order_id = ? AND payment_method IS NOT NULL',
            [$order_id]
        );

        if (0 === $reserved_at_checkout) {
            return true;
        }

        // re-reserving must never push the shelf below zero (the guard checkout applies too)
        if ('reserve' === $direction) {
            $shortage = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM order_items oi
                 JOIN products p ON p.product_id = oi.product_id
                 WHERE oi.order_id = ? AND p.product_stock_quantity < oi.quantity',
                [$order_id]
            );

            if ($shortage > 0) {
                $this->addFlash('error', 'Not enough stock on the shelf to move this order out of cancelled — some items have to be reserved again.');

                return false;
            }
        }

        $symbol = 'restock' === $direction ? '+' : '-';
        $this->connection->executeStatement(
            "UPDATE products p
             JOIN order_items oi ON oi.product_id = p.product_id
             SET p.product_stock_quantity = p.product_stock_quantity {$symbol} oi.quantity
             WHERE oi.order_id = ?",
            [$order_id]
        );

        return true;
    }

    private function rollBackQuietly(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }
}
