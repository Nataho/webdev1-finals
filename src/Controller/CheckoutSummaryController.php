<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\FileUploader;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// order summary / invoice view — the customer who placed it and any admin
final class CheckoutSummaryController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FileUploader $fileUploader,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/checkout/summary', name: 'app_checkout_summary')]
    public function index(Request $request): Response
    {
        // guests must login to view orders
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();
        $is_admin = $this->isGranted('ROLE_ADMIN');

        $order_id = (int) $request->query->get('order_id', 0);
        // presence of ?placed means the order was just placed — the banner opens
        $just_placed = $request->query->has('placed');

        if ($order_id <= 0) {
            // no order id, nothing to summarize — back to the shop
            return $this->redirectToRoute('app_shop');
        }

        // fetch order details with the customer
        $order = $this->connection->fetchAssociative('
            SELECT o.*, u.user_name, u.user_email, u.user_role
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.user_id
            WHERE o.order_id = ?
            LIMIT 1
        ', [$order_id]);

        if (!$order) {
            throw $this->createNotFoundException('Order not found');
        }

        // access control: user can only view their own order, unless they are an admin
        if (!$is_admin && (int) $order['user_id'] !== $user->getId()) {
            return $this->redirectToRoute('app_account');
        }

        // fetch order items with their product names
        $items = $this->connection->fetchAllAssociative('
            SELECT oi.*, p.product_name, p.product_img
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.product_id
            WHERE oi.order_id = ?
            ORDER BY oi.item_id ASC
        ', [$order_id]);

        // resolve each item image url server side so the table row stays dumb
        foreach ($items as $i => $item) {
            $items[$i]['image_url'] = $this->resolveImageUrl($item['product_img'] ?: null);
        }

        return $this->render('checkout_summary/index.html.twig', [
            'order' => $order,
            'items' => $items,
            'just_placed' => $just_placed,
            'is_admin' => $is_admin,
        ]);
    }

    // resolve a product image: uploaded file first, then the assets folder, else null (3.png fallback in twig)
    private function resolveImageUrl(?string $productImg): ?string
    {
        if (!$productImg) {
            return null;
        }

        // see if it exists in the uploads folder first (new admin-added products)
        if ($this->fileUploader->exists('products', $productImg)) {
            return 'uploads/products/'.$productImg;
        }

        // then the bundled assets folder (the original xampp images)
        if (is_file($this->projectDir.'/assets/images/'.$productImg)) {
            return 'images/'.$productImg;
        }

        return null;
    }
}
