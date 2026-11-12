<?php

namespace App\Controller;

use App\Entity\Address;
use App\Entity\Feedback;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Repository\FeedbackRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        'edit_product',
        'delete_product',
        'update_payment_options',
        'remove_payment_option',
        'add_address',
        'edit_address',
        'delete_address',
        'set_default_address',
        'update_order_status',
        'cancel_order',
        'add_feedback',
        'delete_feedback',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly ProductRepository $productRepository,
        private readonly OrderRepository $orderRepository,
        private readonly AddressRepository $addressRepository,
        private readonly FeedbackRepository $feedbackRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly FileUploader $fileUploader,
    ) {
    }

    #[Route('/account', name: 'app_account')]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->renderAccountPage($request);
    }

    /**
     * Admin-only mirror of the account page. The dedicated /admin path is what
     * the security.yaml "^/admin → ROLE_ADMIN" rule protects, so non-admins are
     * stopped by the firewall before any controller code runs. Same page,
     * stricter door.
     */
    #[Route('/admin/account', name: 'app_admin_account')]
    #[IsGranted('ROLE_ADMIN', message: 'Only administrators may open the admin area.')]
    public function adminAccount(Request $request): Response
    {
        return $this->renderAccountPage($request);
    }

    private function renderAccountPage(Request $request): Response
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
        $isStaff = $this->isGranted('ROLE_STAFF');
        if ($isAdmin) {
            $allowed_tabs = array_merge(['dashboard'], $allowed_tabs, ['users', 'stock', 'add_product', 'feedback']);
        } elseif ($isStaff) {
            // staff are the shop's product managers: catalog tabs, no dashboard/users
            $allowed_tabs = array_merge($allowed_tabs, ['stock', 'add_product', 'feedback']);
        }
        $default_tab = $isAdmin ? 'dashboard' : 'general';
        $active_tab = $request->query->get('tab', $default_tab);

        if (!in_array($active_tab, $allowed_tabs, true)) {
            // staff/customers poking an admin tab get a visible denial, not a silent fallback
            $denied_tabs = $isAdmin ? [] : ($isStaff ? ['dashboard', 'users'] : ['dashboard', 'users', 'stock', 'add_product', 'feedback']);
            if (in_array($active_tab, $denied_tabs, true)) {
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
        $addresses = $this->addressRepository->findBy(
            ['user' => $user],
            ['isDefault' => 'DESC', 'id' => 'DESC']
        );

        // fetch data for the dashboard tab (admin only)
        $users_count = $staff_count = $products_count = $orders_count = 0;
        $recent_orders = [];
        if ($isAdmin) {
            $users_count = $this->userRepository->count([]);
            $staff_count = $this->userRepository->countByRole('staff');
            $products_count = $this->productRepository->count([]);
            $orders_count = $this->orderRepository->count([]);
            $recent_orders = $this->orderRepository->findRecent(5);
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

            if ($isAdmin || $isStaff) {
                // admins and staff both see the full store order log; only admins may change statuses
                $orders_list = $this->orderRepository->listAll($status_filter);
                $status_counts = $this->orderRepository->countByStatus();
            } else {
                $orders_list = $this->orderRepository->listForUser($user, $status_filter);
                $status_counts = $this->orderRepository->countByStatus($user);
            }
        }

        // fetch data for the users tab (admin only)
        $user_q = trim($request->query->getString('user_q'));
        $admin_users_list = [];
        if ($active_tab === 'users' && $isAdmin) {
            $admin_users_list = $this->userRepository->searchAll($user_q !== '' ? '%'.$user_q.'%' : '');
        }

        // fetch data for the stock tab (admins and staff share the catalog view)
        $stock_q = trim($request->query->getString('stock_q'));
        $admin_products_list = [];
        if ($active_tab === 'stock' && ($isAdmin || $isStaff)) {
            $admin_products_list = $this->productRepository->searchAll($stock_q !== '' ? '%'.$stock_q.'%' : '');
        }

        // fetch data for the feedback tab (admins and staff curate the testimonials)
        $feedback_list = [];
        if ($active_tab === 'feedback' && ($isAdmin || $isStaff)) {
            $feedback_list = $this->feedbackRepository->findLatest();
            // resolve each avatar url server side so the card stays dumb
            foreach ($feedback_list as $feedback) {
                $feedback->setAvatarUrl(
                    $this->fileUploader->exists('feedback', $feedback->getAvatar())
                        ? 'uploads/feedback/'.$feedback->getAvatar()
                        : null
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

            'stock_q' => $stock_q,
            'admin_products_list' => $admin_products_list,

            'feedback_list' => $feedback_list,

            'user' => $user,
            'avatar_file' => $avatar_file,

            'users_count' => $users_count,
            'staff_count' => $staff_count,
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
        // admins working through /admin/account stay on the admin surface after POST redirects
        $redirect_route = 'app_admin_account' === $request->attributes->get('_route') ? 'app_admin_account' : 'app_account';
        $redirect = fn (array $params = []): Response => $this->redirectToRoute($redirect_route, $params);

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
            'update_order_status',
        ];
        if (in_array($action, $admin_actions, true) && !$isAdmin) {
            $this->addFlash('error', 'You do not have permission to do that.');

            return $redirect('update_order_status' === $action ? ['tab' => 'logs'] : []);
        }

        // product management is staff work too; only plain customers bounce here.
        // staff still face per-row ownership checks inside the actions themselves
        $product_actions = ['update_stock', 'add_product', 'edit_product', 'delete_product'];
        if (in_array($action, $product_actions, true) && !$isAdmin && !$this->isGranted('ROLE_STAFF')) {
            $this->addFlash('error', 'You do not have permission to do that.');

            return $redirect();
        }

        // testimonial curation is staff work too; plain customers bounce here
        $feedback_actions = ['add_feedback', 'delete_feedback'];
        if (in_array($action, $feedback_actions, true) && !$isAdmin && !$this->isGranted('ROLE_STAFF')) {
            $this->addFlash('error', 'You do not have permission to do that.');

            return $redirect();
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
            'update_stock' => $this->updateStock($request, $user, $isAdmin, $redirect),
            'add_product' => $this->addProduct($request, $user, $redirect),
            'edit_product' => $this->editProduct($request, $user, $isAdmin, $redirect),
            'delete_product' => $this->deleteProduct($request, $user, $isAdmin, $redirect),
            'update_payment_options' => $this->updatePaymentOptions($request, $user, $redirect),
            'remove_payment_option' => $this->removePaymentOption($request, $user, $redirect),
            'add_address' => $this->addAddress($request, $user, $redirect),
            'edit_address' => $this->editAddress($request, $user, $redirect),
            'delete_address' => $this->deleteAddress($request, $user, $redirect),
            'set_default_address' => $this->setDefaultAddress($request, $user, $redirect),
            'update_order_status' => $this->updateOrderStatus($request, $redirect),
            'cancel_order' => $this->cancelOrder($request, $user, $redirect),
            'add_feedback' => $this->addFeedback($request, $redirect),
            'delete_feedback' => $this->deleteFeedback($request, $redirect),
            default => null,
        };
    }

    // add a storefront testimonial — staff/admin curate the home feedback section
    private function addFeedback(Request $request, \Closure $redirect): Response
    {
        $name = trim($request->getPayload()->getString('feedback_name'));
        $message = trim($request->getPayload()->getString('feedback_message'));
        $role_label = trim($request->getPayload()->getString('feedback_role_label', 'Customer'));
        if ($role_label === '') {
            $role_label = 'Customer';
        }

        if ($name === '' || $message === '') {
            $this->addFlash('error', 'Please fill in the customer name and the testimonial text.');

            return $redirect(['tab' => 'feedback']);
        }

        // optional photo (same 5MB image gate as every other upload)
        $avatar = null;
        $file = $request->files->get('feedback_avatar');
        if ($file instanceof UploadedFile) {
            try {
                $avatar = $this->fileUploader->uploadImage($file, 'feedback');
            } catch (RuntimeException $e) {
                $this->addFlash('error', 'Image upload failed: '.$e->getMessage());

                return $redirect(['tab' => 'feedback']);
            }
        }

        $feedback = (new Feedback())
            ->setName($name)
            ->setMessage($message)
            ->setRoleLabel($role_label)
            ->setAvatar($avatar);
        $this->entityManager->persist($feedback);
        $this->entityManager->flush();

        $this->addFlash('success', 'Testimonial added — it now shows in the home page feedback section.');

        return $redirect(['tab' => 'feedback']);
    }

    // take one testimonial off the home page (and its uploaded photo off disk)
    private function deleteFeedback(Request $request, \Closure $redirect): Response
    {
        $feedback_id = (int) $request->getPayload()->getString('feedback_id');

        $feedback = $this->feedbackRepository->find($feedback_id);

        if (!$feedback instanceof Feedback) {
            $this->addFlash('error', 'Testimonial not found.');

            return $redirect(['tab' => 'feedback']);
        }

        $old_avatar = $feedback->getAvatar();
        $this->entityManager->remove($feedback);
        $this->entityManager->flush();

        if ($old_avatar) {
            $this->fileUploader->delete('feedback', $old_avatar);
        }

        $this->addFlash('success', 'Testimonial deleted.');

        return $redirect(['tab' => 'feedback']);
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
        $this->entityManager->flush();

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

        if ($this->userRepository->countByUsernameExcept($new_username, $user->getId()) > 0) {
            $this->addFlash('error', 'That username is already taken. Please choose another.');

            return $redirect();
        }

        $user->setUsername($new_username);
        $this->entityManager->flush();
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
        $this->entityManager->flush();
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
        $this->entityManager->remove($user);
        $this->entityManager->flush();

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

        $target = $this->userRepository->find($target_id);

        if (!$target instanceof User) {
            $this->addFlash('error', 'User not found.');

            return $redirect(['tab' => 'users']);
        }

        if ($target->getRole() === 'admin') {
            $this->addFlash('error', 'Administrators cannot be banned.');

            return $redirect(['tab' => 'users']);
        }

        $new_role = $target->isBanned() ? 'customer' : 'banned';
        $target->setRole($new_role);
        $this->entityManager->flush();

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

        $email_taken = $this->userRepository->countByEmailExcept($email);
        if ($email_taken > 0) {
            $this->addFlash('error', 'An account with that email already exists.');

            return $redirect($tab);
        }

        $name_taken = $this->userRepository->countByUsernameExcept($name);
        if ($name_taken > 0) {
            $this->addFlash('error', 'That username is already taken. Please choose another.');

            return $redirect($tab);
        }

        // hash through the entity so the auto hasher picks the right algorithm
        $hashed = $this->passwordHasher->hashPassword(new User(), $password);

        $account = (new User())
            ->setUsername($name)
            ->setEmail($email)
            ->setRole($role)
            ->setPassword($hashed);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

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

        $target = $this->userRepository->find($target_id);

        if (!$target instanceof User) {
            $this->addFlash('error', 'User not found.');

            return $redirect($tab);
        }

        $name = trim($request->getPayload()->getString('edit_user_name'));
        $email = trim($request->getPayload()->getString('edit_user_email'));
        $role = $request->getPayload()->getString('edit_user_role', (string) $target->getRole());
        $new_password = $request->getPayload()->getString('edit_user_password');

        // a ban is a deliberate moderation action, not a role: editing a banned
        // account never silently lifts it — the Unban button is the only path out
        $is_banned_target = $target->isBanned();
        if ($is_banned_target) {
            $role = 'banned';
        }

        if (mb_strlen($name) < 3) {
            $this->addFlash('error', 'Name must be at least 3 characters long.');

            return $redirect($tab);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Please enter a valid email address.');

            return $redirect($tab);
        }

        if (!$is_banned_target && !in_array($role, ['admin', 'staff', 'customer'], true)) {
            $this->addFlash('error', 'Please choose a valid role.');

            return $redirect($tab);
        }

        if ('' !== $new_password && mb_strlen($new_password) < 8) {
            $this->addFlash('error', 'Password must be at least 8 characters long.');

            return $redirect($tab);
        }

        // an admin changing their own role could lock themselves out of the admin area
        if ($target_id === $currentUser->getId() && $role !== $target->getRole()) {
            $this->addFlash('error', 'You cannot change your own role.');

            return $redirect($tab);
        }

        $email_taken = $this->userRepository->countByEmailExcept($email, $target_id);
        if ($email_taken > 0) {
            $this->addFlash('error', 'An account with that email already exists.');

            return $redirect($tab);
        }

        $name_taken = $this->userRepository->countByUsernameExcept($name, $target_id);
        if ($name_taken > 0) {
            $this->addFlash('error', 'That username is already taken. Please choose another.');

            return $redirect($tab);
        }

        // empty password field = keep the current password
        $reset_password = '' !== $new_password;

        $target->setUsername($name);
        $target->setEmail($email);
        $target->setRole($role);
        if ($reset_password) {
            $target->setPassword($this->passwordHasher->hashPassword(new User(), $new_password));
        }
        $this->entityManager->flush();

        $this->addFlash(
            'success',
            "User '{$name}' has been updated."
            .($reset_password ? ' Their password has been reset.' : '')
            .($is_banned_target ? ' Ban status is unchanged.' : '')
        );

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

        $target = $this->userRepository->find($target_id);

        if (!$target instanceof User) {
            $this->addFlash('error', 'User not found.');

            return $redirect($tab);
        }

        // mirror the ban guard: admin accounts are untouchable by other admins
        if ($target->getRole() === 'admin') {
            $this->addFlash('error', 'Administrator accounts cannot be deleted.');

            return $redirect($tab);
        }

        $old_pic = $target->getProfilePic();
        $target_name = $target->getUsername();

        $this->entityManager->remove($target);
        $this->entityManager->flush();

        if ($old_pic) {
            $this->fileUploader->delete('avatars', $old_pic);
        }

        $this->addFlash('success', "User '{$target_name}' has been permanently deleted.");

        return $redirect($tab);
    }

    private function updateStock(Request $request, User $user, bool $isAdmin, \Closure $redirect): Response
    {
        $product_id = $request->getPayload()->getInt('product_id');
        $new_stock = $request->getPayload()->getInt('new_stock');

        if ($product_id <= 0 || $new_stock < 0) {
            $this->addFlash('error', 'Invalid stock quantity entered.');

            return $redirect(['tab' => 'stock']);
        }

        if (!$this->canManageProduct($product_id, $user, $isAdmin)) {
            $this->addFlash('error', 'You can only manage products you created.');

            return $redirect(['tab' => 'stock']);
        }

        $product = $this->productRepository->find($product_id);
        if ($product instanceof Product) {
            $product->setProductStockQuantity($new_stock);
            $this->entityManager->flush();
        }

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

        $product = (new Product())
            ->setProductName($p_name)
            ->setProductDescription($p_desc)
            ->setProductPrice(number_format($p_price, 2, '.', ''))
            ->setProductStockQuantity($p_stock)
            ->setProductImg($p_img)
            ->setCreatedBy($user);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $this->addFlash('success', "Product '{$p_name}' added to store inventory!");

        return $redirect(['tab' => 'stock']);
    }

    private function deleteProduct(Request $request, User $user, bool $isAdmin, \Closure $redirect): Response
    {
        $product_id = $request->getPayload()->getInt('product_id');

        if ($product_id <= 0) {
            $this->addFlash('error', 'Invalid product specified for deletion.');

            return $redirect(['tab' => 'stock']);
        }

        if (!$this->canManageProduct($product_id, $user, $isAdmin)) {
            $this->addFlash('error', 'You can only delete products you created.');

            return $redirect(['tab' => 'stock']);
        }

        $product = $this->productRepository->find($product_id);

        if (!$product instanceof Product) {
            $this->addFlash('error', 'Failed to delete product or product not found.');

            return $redirect(['tab' => 'stock']);
        }

        $this->entityManager->remove($product);
        $this->entityManager->flush();

        $this->addFlash('success', 'Product has been permanently deleted.');

        return $redirect(['tab' => 'stock']);
    }

    private function editProduct(Request $request, User $user, bool $isAdmin, \Closure $redirect): Response
    {
        $product_id = $request->getPayload()->getInt('product_id');
        $p_name = trim($request->getPayload()->getString('product_name'));
        $p_price = (float) $request->getPayload()->get('product_price', 0);
        $p_desc = trim($request->getPayload()->getString('product_description'));

        if ($product_id <= 0 || '' === $p_name || $p_price < 0) {
            $this->addFlash('error', 'Please fill in all required product fields with valid numbers.');

            return $redirect(['tab' => 'stock']);
        }

        // admins edit any product; staff only the ones they created
        if (!$this->canManageProduct($product_id, $user, $isAdmin)) {
            $this->addFlash('error', 'You can only edit products you created.');

            return $redirect(['tab' => 'stock']);
        }

        $product = $this->productRepository->find($product_id);

        if (!$product instanceof Product) {
            $this->addFlash('error', 'Failed to edit product or product not found.');

            return $redirect(['tab' => 'stock']);
        }

        $old_img = (string) $product->getProductImg();
        $p_img = $old_img;

        // optional replacement image
        $file = $request->files->get('product_image');
        if ($file instanceof UploadedFile) {
            try {
                $p_img = $this->fileUploader->uploadImage($file, 'products');
            } catch (RuntimeException $e) {
                $this->addFlash('error', 'Image upload failed: '.$e->getMessage());

                return $redirect(['tab' => 'stock']);
            }

            // drop the replaced file so the uploads folder stays clean
            if ('' !== $old_img && $old_img !== $p_img) {
                $this->fileUploader->delete('products', $old_img);
            }
        }

        $product->setProductName($p_name);
        $product->setProductDescription($p_desc);
        $product->setProductPrice(number_format($p_price, 2, '.', ''));
        $product->setProductImg($p_img);
        $this->entityManager->flush();

        $this->addFlash('success', "Product '{$p_name}' has been updated.");

        return $redirect(['tab' => 'stock']);
    }

    /**
     * Admins manage every catalog row; staff only the products they created.
     */
    private function canManageProduct(int $product_id, User $user, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }

        $product = $this->productRepository->find($product_id);

        return $product instanceof Product
            && $product->getCreatedBy()?->getId() === $user->getId();
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
            $this->entityManager->flush();
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
            $this->entityManager->flush();
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
            $this->entityManager->flush();
            $this->addFlash('success', 'GCash account removed.');
        } elseif ($payment_type === 'card') {
            $user->setCardNumber(null);
            $this->entityManager->flush();
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
        $is_first = $this->addressRepository->count(['user' => $user]) === 0;
        if ($is_first || $is_default) {
            $is_default = true;

            // demote every existing address first
            foreach ($this->addressRepository->findBy(['user' => $user]) as $existing) {
                $existing->setIsDefault(false);
            }
        }

        $address = (new Address())
            ->setUser($user)
            ->setLabel($label ?: 'Home')
            ->setRecipientName($recipient_name)
            ->setPhoneNumber($phone_number)
            ->setRegion($region)
            ->setProvince($province ?: null)
            ->setCity($city)
            ->setBarangay($barangay)
            ->setPostalCode($postal_code ?: null)
            ->setStreetAddress($street_address)
            ->setIsDefault($is_default);
        $this->entityManager->persist($address);
        $this->entityManager->flush();

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

        $address = $this->addressRepository->findOneBy(['id' => $address_id, 'user' => $user]);

        if (!$address instanceof Address) {
            $this->addFlash('error', 'Address not found or unauthorized.');

            return $redirect(['tab' => 'addresses']);
        }

        if ($recipient_name === '' || $phone_number === '' || $region === '' || $city === '' || $barangay === '' || $street_address === '') {
            $this->addFlash('error', 'Please fill in all required address fields.');

            return $redirect(['tab' => 'addresses']);
        }

        if ($is_default) {
            // demote every other address first
            foreach ($this->addressRepository->findBy(['user' => $user]) as $existing) {
                $existing->setIsDefault(false);
            }
        }

        $address->setLabel($label ?: 'Home')
            ->setRecipientName($recipient_name)
            ->setPhoneNumber($phone_number)
            ->setRegion($region)
            ->setProvince($province ?: null)
            ->setCity($city)
            ->setBarangay($barangay)
            ->setPostalCode($postal_code ?: null)
            ->setStreetAddress($street_address)
            ->setIsDefault($is_default);
        $this->entityManager->flush();

        $this->addFlash('success', 'Delivery address updated successfully.');

        return $redirect(['tab' => 'addresses']);
    }

    private function deleteAddress(Request $request, User $user, \Closure $redirect): Response
    {
        $address_id = (int) $request->getPayload()->getString('address_id');

        $address = $this->addressRepository->findOneBy(['id' => $address_id, 'user' => $user]);

        if (!$address instanceof Address) {
            $this->addFlash('error', 'Failed to delete address.');

            return $redirect(['tab' => 'addresses']);
        }

        $was_default = $address->isDefault();
        $this->entityManager->remove($address);
        $this->entityManager->flush();

        // if we deleted the default address, set another remaining address as default if one exists
        if ($was_default) {
            $other = $this->addressRepository->findOneBy(['user' => $user], ['id' => 'DESC']);
            if ($other instanceof Address) {
                $other->setIsDefault(true);
                $this->entityManager->flush();
            }
        }

        $this->addFlash('success', 'Address deleted successfully.');

        return $redirect(['tab' => 'addresses']);
    }

    private function setDefaultAddress(Request $request, User $user, \Closure $redirect): Response
    {
        $address_id = (int) $request->getPayload()->getString('address_id');

        $address = $this->addressRepository->findOneBy(['id' => $address_id, 'user' => $user]);

        if ($address instanceof Address) {
            // demote every address, then promote the chosen one
            foreach ($this->addressRepository->findBy(['user' => $user]) as $existing) {
                $existing->setIsDefault(false);
            }
            $address->setIsDefault(true);
            $this->entityManager->flush();

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

        $order = $this->orderRepository->find($order_id);

        if (!$order instanceof Order) {
            $this->addFlash('error', 'Order not found; status not updated.');

            return $redirect(['tab' => 'logs']);
        }

        $previous_status = (string) $order->getStatus();

        if ($previous_status === $new_status) {
            $this->addFlash('error', 'This order already has that status.');

            return $redirect(['tab' => 'logs']);
        }

        // the status flip and the stock hand-back commit together — one flush
        // is one transaction, and the shortage check below bails before it
        try {
            // cancelling returns the reserved items to the shelf, re-opening an
            // already cancelled order takes them back out — atomically either way
            $returning_to_cancelled = 'cancelled' === $new_status && 'cancelled' !== $previous_status;
            $leaving_the_cancelled_bin = 'cancelled' !== $new_status && 'cancelled' === $previous_status;

            if ($returning_to_cancelled) {
                $stock_moved = $this->adjustStockForOrder($order, 'restock');
            } elseif ($leaving_the_cancelled_bin) {
                $stock_moved = $this->adjustStockForOrder($order, 'reserve');
            } else {
                $stock_moved = true;
            }

            if (!$stock_moved) {
                // in-memory changes are discarded — nothing was flushed
                return $redirect(['tab' => 'logs']);
            }

            $order->setStatus($new_status);
            $this->entityManager->flush();
        } catch (\Throwable) {
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
        $order = $this->orderRepository->find($order_id);

        if (!$order instanceof Order || $order->getUser()?->getId() !== $user->getId()) {
            $this->addFlash('error', 'Order not found or not yours to cancel.');

            return $redirect(['tab' => 'logs']);
        }

        if (!in_array((string) $order->getStatus(), Order::CANCELLABLE_STATUSES, true)) {
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

        // the status flip and the stock hand-back commit together — one flush
        try {
            $order->setStatus('cancelled');
            $order->setCancelReason($cancel_reason);

            if (!$this->adjustStockForOrder($order, 'restock')) {
                // in-memory changes are discarded — nothing was flushed
                return $redirect(['tab' => 'logs']);
            }

            $this->entityManager->flush();
        } catch (\Throwable) {
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
     * a missing payment method is the marker for that, so this skips them.
     * The changes only become permanent when the caller flushes.
     */
    private function adjustStockForOrder(Order $order, string $direction): bool
    {
        $direction = in_array($direction, ['restock', 'reserve'], true) ? $direction : 'restock';

        // only checkout-placed orders have reservations to move around
        if ($order->getPaymentMethod() === null) {
            return true;
        }

        // re-reserving must never push the shelf below zero (the guard checkout applies too)
        if ('reserve' === $direction) {
            foreach ($order->getOrderItems() as $item) {
                if ($item->getProduct()->getProductStockQuantity() < $item->getQuantity()) {
                    $this->addFlash('error', 'Not enough stock on the shelf to move this order out of cancelled — some items have to be reserved again.');

                    return false;
                }
            }
        }

        foreach ($order->getOrderItems() as $item) {
            $product = $item->getProduct();
            $product->setProductStockQuantity(
                'restock' === $direction
                    ? $product->getProductStockQuantity() + $item->getQuantity()
                    : $product->getProductStockQuantity() - $item->getQuantity()
            );
        }

        return true;
    }
}
