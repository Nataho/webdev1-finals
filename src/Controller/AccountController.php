<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractController
{
    #[Route('/account', name: 'app_account')]
    public function index(Request $request, Connection $connection): Response
    {
        $user = $this->getUser();
        $isAdmin = $this->isGranted("ROLE_ADMIN");
        $allowed_tabs = ['general', 'logs'];
        if ($isAdmin) {
            $allowed_tabs = array_merge(['dashboard'], $allowed_tabs, ['users', 'stock', 'add_product']);
        }
        $default_tab = $isAdmin ? 'dashboard' : 'general';
        $active_tab = $request->query->get('tab', $default_tab);

        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = $default_tab;
        }

        // Handle POST actions (like Ban / Unban)
        if ($request->isMethod('POST') && $isAdmin) {
            $action = $request->getPayload()->getString('action');
            $csrfToken = $request->getPayload()->getString('_csrf_token');

            if ($action === 'toggle_user_ban' && $this->isCsrfTokenValid('toggle_user_ban', $csrfToken)) {
                $targetUserId = $request->getPayload()->getInt('target_user_id');
                $targetAction = $request->getPayload()->getString('target_action');

                // Prevent admin from banning themselves
                if ($user && $targetUserId !== (int) $user->getId()) {
                    $newRole = ($targetAction === 'unban') ? 'customer' : 'banned';
                    $connection->executeStatement(
                        'UPDATE users SET user_role = ? WHERE user_id = ?',
                        [$newRole, $targetUserId]
                    );
                }

                return $this->redirectToRoute('app_account', ['tab' => 'users']);
            }
        }

        $orders_list = [];
        if ($active_tab === 'logs') {
            if ($isAdmin) {
                $orders_list = $connection->fetchAllAssociative(
                    'SELECT o.*, u.user_name, u.user_email FROM orders o LEFT JOIN users u ON o.user_id = u.user_id ORDER BY o.order_date DESC'
                );
            } elseif ($user) {
                $orders_list = $connection->fetchAllAssociative(
                    'SELECT * FROM orders WHERE user_id = ? ORDER BY order_date DESC',
                    [$user->getId()]
                );
            }
        }

        $avatar_url = null;

        // [TEST]
        $users_count    = (int) $connection->fetchOne('SELECT COUNT(*) FROM users');
        $products_count = (int) $connection->fetchOne('SELECT COUNT(*) FROM products');
        $orders_count   = (int) $connection->fetchOne('SELECT COUNT(*) FROM orders');
        $recent_orders  = $connection->fetchAllAssociative(
            'SELECT o.*, u.user_name, u.user_email
            FROM orders o LEFT JOIN users u ON o.user_id = u.user_id
            ORDER BY o.order_date DESC LIMIT 5'
        );

        $user_q = trim($request->query->getString('user_q'));
        
        $admin_users_list = [];
        if ($active_tab === 'users' && $isAdmin) {
            if ($user_q !== '') {
                $searchTerm = '%' . $user_q . '%';
                $admin_users_list = $connection->fetchAllAssociative(
                    'SELECT user_id, user_name, user_email, user_role, user_profile_pic
                    FROM users
                    WHERE user_name LIKE ? OR user_email LIKE ?
                    ORDER BY user_id DESC',
                    [$searchTerm, $searchTerm]
                );
            } else {
                $admin_users_list = $connection->fetchAllAssociative(
                    'SELECT user_id, user_name, user_email, user_role, user_profile_pic
                    FROM users
                    ORDER BY user_id DESC'
                );
            }
        }
        // [TEST]

        return $this->render('account/index.html.twig', [
            'controller_name' => 'AccountController',
            'allowed_tabs' => $allowed_tabs,
            'active_tab' => $active_tab,
            'orders_list' => $orders_list,

            "user_q" => $user_q,
            "admin_users_list" => $admin_users_list,

            "user" => $user,
            "avatar_url" => $avatar_url,

            'users_count' => $users_count,
            'products_count' => $products_count,
            'orders_count' => $orders_count,
            'recent_orders' => $recent_orders,
        ]);
    }
}
