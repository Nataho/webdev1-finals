<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\FileUploader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// shop page — product catalog, best sellers and cart additions
final class ShopController extends AbstractController
{
    private const CSRF_ACTIONS = ['add_to_cart'];

    public function __construct(
        private readonly Connection $connection,
        private readonly FileUploader $fileUploader,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/shop', name: 'app_shop')]
    public function index(Request $request): Response
    {
        // guests must log in first to access shop
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        // handle cart actions
        if ($request->isMethod('POST') && $response = $this->handleAction($request)) {
            return $response;
        }

        // determine current view filter: 'all' or 'best_sellers'
        $filter = $request->query->getString('filter', 'all');
        if (!in_array($filter, ['all', 'best_sellers'])) {
            $filter = 'all';
        }

        $products = $this->fetchProducts($filter);

        // resolve each product image url server-side so the card stays dumb
        foreach ($products as $i => $prod) {
            $products[$i]['image_url'] = $this->resolveImageUrl($prod['product_img']);
        }

        // compute total cart count
        $cart = $request->getSession()->get('cart', []);
        if (!is_array($cart)) {
            $cart = [];
        }
        $cart_count = array_sum($cart);

        return $this->render('shop/index.html.twig', [
            'filter' => $filter,
            'products' => $products,
            'cart_count' => $cart_count,
            'is_banned' => $user->isBanned(),
        ]);
    }

    /**
     * Dispatches a POST form action and returns the redirect response, or null
     * when the request is not a supported action.
     */
    private function handleAction(Request $request): ?Response
    {
        $action = $request->getPayload()->getString('action');

        if (!in_array($action, self::CSRF_ACTIONS, true)) {
            return null;
        }

        // every action requires a valid CSRF token
        if (!$this->isCsrfTokenValid($action, $request->getPayload()->getString('_csrf_token'))) {
            $this->addFlash('error', 'Security token invalid or expired. Please try again.');

            return $this->redirectToRoute('app_shop');
        }

        return match ($action) {
            'add_to_cart' => $this->addToCart($request, $this->getUser()),
            default => null,
        };
    }

    private function addToCart(Request $request, User $user): Response
    {
        $product_id = (int) $request->getPayload()->get('product_id', 0);
        $qty = max(1, (int) $request->getPayload()->get('quantity', 1));

        if ($user->isBanned()) {
            $this->addFlash('error', "Your account has been restricted. You cannot add products to cart.");

            return $this->redirectToRoute('app_shop');
        }

        // check product stock
        $prod = $this->connection->fetchAssociative(
            'SELECT product_name, product_price, product_stock_quantity FROM products WHERE product_id = ? LIMIT 1',
            [$product_id]
        );

        if ($prod && (int) $prod['product_stock_quantity'] > 0) {
            $available_stock = (int) $prod['product_stock_quantity'];

            // prevent adding more than available stock
            if ($qty > $available_stock) {
                $qty = $available_stock;
            }

            // simple session-based cart
            $session = $request->getSession();
            $cart = $session->get('cart', []);
            if (!is_array($cart)) {
                $cart = [];
            }

            $current_in_cart = (int) ($cart[$product_id] ?? 0);
            $new_total_qty = min($available_stock, $current_in_cart + $qty);
            $cart[$product_id] = $new_total_qty;
            $session->set('cart', $cart);

            $batch_total = (float) $prod['product_price'] * $qty;
            $this->addFlash('success', "Added {$qty}x '{$prod['product_name']}' to your cart! (Batch Total: ₱".number_format($batch_total, 2).')');
        } else {
            $this->addFlash('error', 'Sorry, this item is currently out of stock.');
        }

        return $this->redirectToRoute('app_shop');
    }

    // fetch products according to filter
    private function fetchProducts(string $filter): array
    {
        if ($filter === 'best_sellers') {
            // count total products to find 50% rounded up
            $total_products_count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM products');
            $best_seller_limit = (int) ceil($total_products_count * 0.5);

            // no products at all — nothing to rank
            if ($best_seller_limit === 0) {
                return [];
            }

            // fetch top 50% products ranked by total sold quantity, then alphabetical by name
            return $this->connection->fetchAllAssociative('
                SELECT p.*, COALESCE(SUM(oi.quantity), 0) AS total_sold
                FROM products p
                LEFT JOIN order_items oi ON p.product_id = oi.product_id
                GROUP BY p.product_id
                ORDER BY total_sold DESC, p.product_name ASC
                LIMIT ?', [$best_seller_limit], [ParameterType::INTEGER]);
        }

        // fetch all products: in-stock first, out-of-stock at the very end
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM products ORDER BY (product_stock_quantity > 0) DESC, product_id DESC'
        );
    }

    // resolve a product image: uploaded file first, then the assets folder, else the 3.png placeholder
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
