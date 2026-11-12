<?php

namespace App\Controller;

use App\Entity\Address;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Repository\ProductRepository;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

// checkout page — review the session cart, adjust quantities and place the order
final class CheckoutController extends AbstractController
{
    private const CSRF_ACTIONS = ['update_qty', 'remove_item', 'place_order'];

    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly AddressRepository $addressRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly FileUploader $fileUploader,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/checkout', name: 'app_checkout')]
    public function index(Request $request): Response
    {
        // guests must login to checkout
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        // if the account is banned, checkout is not allowed — bounce to the shop
        if ($user->isBanned()) {
            return $this->redirectToRoute('app_shop');
        }

        // handle cart actions
        if ($request->isMethod('POST') && $response = $this->handleAction($request)) {
            return $response;
        }

        // load all cart product details
        [$cart_items, $subtotal] = $this->loadCart($request->getSession());

        // fetch user's saved addresses
        $addresses = $this->addressRepository->findBy(
            ['user' => $user],
            ['isDefault' => 'DESC', 'id' => 'DESC']
        );

        return $this->render('checkout/index.html.twig', [
            'cart_items' => $cart_items,
            'subtotal' => $subtotal,
            'addresses' => $addresses,
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

            return $this->redirectToRoute('app_checkout');
        }

        return match ($action) {
            'update_qty' => $this->updateQty($request),
            'remove_item' => $this->removeItem($request),
            'place_order' => $this->placeOrder($request, $this->getUser()),
            default => null,
        };
    }

    // change one cart item's quantity — zero removes it, overstock clamps it
    private function updateQty(Request $request): Response
    {
        $product_id = (int) $request->getPayload()->get('product_id', 0);
        $qty = (int) $request->getPayload()->get('quantity', 0);

        if ($product_id > 0) {
            $session = $request->getSession();
            $cart = $this->sessionCart($session);

            // zero or below takes the item right out of the cart
            if ($qty <= 0) {
                unset($cart[$product_id]);
                $session->set('cart', $cart);
                $this->addFlash('success', 'Item removed from cart.');
            } else {
                // verify stock
                $stock = $this->productRepository->find($product_id)?->getProductStockQuantity() ?? 0;

                if ($qty > $stock) {
                    $cart[$product_id] = $stock;
                    $session->set('cart', $cart);
                    $this->addFlash('error', "Quantity capped to available stock ({$stock} units).");
                } else {
                    $cart[$product_id] = $qty;
                    $session->set('cart', $cart);
                    $this->addFlash('success', 'Cart quantity updated.');
                }
            }
        }

        return $this->redirectToRoute('app_checkout');
    }

    // take one product out of the session cart
    private function removeItem(Request $request): Response
    {
        $product_id = (int) $request->getPayload()->get('product_id', 0);

        $session = $request->getSession();
        $cart = $this->sessionCart($session);

        if (isset($cart[$product_id])) {
            unset($cart[$product_id]);
            $session->set('cart', $cart);
            $this->addFlash('success', 'Item removed from cart.');
        }

        return $this->redirectToRoute('app_checkout');
    }

    // place the order: revalidate stock, then order + items + stock deduction in one flushed transaction
    private function placeOrder(Request $request, User $user): Response
    {
        $cart = $this->sessionCart($request->getSession());

        if (empty($cart)) {
            $this->addFlash('error', 'Your cart is empty. Add botanical items first.');

            return $this->redirectToRoute('app_checkout');
        }

        // fetch fresh product info and validate stock
        $pids = array_map('intval', array_keys($cart));
        $products_by_id = [];
        foreach ($this->productRepository->findBy(['id' => $pids]) as $product) {
            $products_by_id[$product->getId()] = $product;
        }

        $order_items_to_save = [];
        $total_amount = 0.0;
        $stock_insufficient = false;
        $insufficient_item_name = '';

        foreach ($cart as $pid => $qty) {
            $pid = (int) $pid;
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }

            if (!isset($products_by_id[$pid])) {
                $stock_insufficient = true;
                $insufficient_item_name = "Item #{$pid} is no longer available.";
                break;
            }

            $product = $products_by_id[$pid];
            $current_stock = $product->getProductStockQuantity();

            if ($qty > $current_stock) {
                $stock_insufficient = true;
                $insufficient_item_name = "Not enough stock for '{$product->getProductName()}' (Only {$current_stock} available).";
                break;
            }

            $unit_price = (float) $product->getProductPrice();
            $line_total = $unit_price * $qty;
            $total_amount += $line_total;

            $order_items_to_save[] = [
                'product' => $product,
                'quantity' => $qty,
                'price_at_purchase' => $unit_price,
            ];
        }

        if ($stock_insufficient) {
            $this->addFlash('error', $insufficient_item_name);

            return $this->redirectToRoute('app_checkout');
        }

        // validate payment method
        $payment_method = $request->getPayload()->getString('payment_method', 'cash_on_delivery');
        $valid_methods = ['cash_on_delivery', 'gcash', 'card'];
        if (!in_array($payment_method, $valid_methods, true)) {
            $payment_method = 'cash_on_delivery';
        }

        $payment_details = null;
        if ($payment_method === 'gcash') {
            $submitted_gcash = trim($request->getPayload()->getString('gcash_number'));
            $gcash_num = $submitted_gcash !== '' ? $submitted_gcash : trim($user->getGcashNumber() ?? '');
            if ($gcash_num === '') {
                $this->addFlash('error', 'Please enter your GCash mobile number to attach it to your account.');

                return $this->redirectToRoute('app_checkout');
            }
            $payment_details = $gcash_num;
            if ($user->getGcashNumber() !== $gcash_num || $request->getPayload()->getBoolean('save_payment_method')) {
                // persist through the entity
                $user->setGcashNumber($gcash_num);
                $this->entityManager->flush();
            }
        } elseif ($payment_method === 'card') {
            $submitted_card = trim($request->getPayload()->getString('card_number'));
            $card_num = $submitted_card !== '' ? $submitted_card : trim($user->getCardNumber() ?? '');
            if ($card_num === '') {
                $this->addFlash('error', 'Please enter your card number to attach it to your account.');

                return $this->redirectToRoute('app_checkout');
            }
            $clean_card = preg_replace('/\D/', '', $card_num);
            $payment_details = '•••• •••• •••• ' . substr($clean_card, -4);
            if ($user->getCardNumber() !== $clean_card || $request->getPayload()->getBoolean('save_payment_method')) {
                $user->setCardNumber($clean_card);
                $this->entityManager->flush();
            }
        } else {
            $payment_details = 'Cash on Delivery';
        }

        // resolve delivery address
        $raw_address_id = $request->getPayload()->getString('selected_address_id');
        $address_id = is_numeric($raw_address_id) ? (int) $raw_address_id : 0;
        $delivery_address = null;
        $recipient_name = trim($request->getPayload()->getString('recipient_name'));
        $recipient_phone = trim($request->getPayload()->getString('recipient_phone'));

        if ($address_id > 0) {
            $saved_addr = $this->addressRepository->findOneBy(['id' => $address_id, 'user' => $user]);
            if ($saved_addr instanceof Address) {
                $recipient_name = $saved_addr->getRecipientName();
                $recipient_phone = $saved_addr->getPhoneNumber();
                $delivery_address = $saved_addr->getFormattedAddress();
            }
        }

        // if not picked from saved address, check individual address inputs
        if (!$delivery_address) {
            $street = trim($request->getPayload()->getString('street_address'));
            $barangay = trim($request->getPayload()->getString('barangay'));
            $city = trim($request->getPayload()->getString('city'));
            $region = trim($request->getPayload()->getString('region'));
            $province = trim($request->getPayload()->getString('province'));
            $postal_code = trim($request->getPayload()->getString('postal_code'));

            if ($street !== '' || $city !== '' || $region !== '') {
                $delivery_parts = array_filter([
                    $street,
                    $barangay ? 'Brgy. ' . $barangay : null,
                    $city,
                    $province && $province !== $city ? $province : null,
                    $region,
                    $postal_code,
                ]);
                $delivery_address = implode(', ', $delivery_parts);

                // optionally save to the user's address book
                if ($request->getPayload()->getBoolean('save_address')) {
                    $has_default = $this->addressRepository->count(['user' => $user]);
                    $this->entityManager->persist(
                        (new Address())
                            ->setUser($user)
                            ->setLabel('Home')
                            ->setRecipientName($recipient_name ?: $user->getUsername())
                            ->setPhoneNumber($recipient_phone ?: 'N/A')
                            ->setRegion($region ?: 'Metro Manila')
                            ->setProvince($province ?: null)
                            ->setCity($city ?: 'Manila')
                            ->setBarangay($barangay ?: 'Central')
                            ->setPostalCode($postal_code ?: null)
                            ->setStreetAddress($street ?: 'Address')
                            ->setIsDefault($has_default === 0)
                    );
                    $this->entityManager->flush();
                }
            } else {
                // fall back to user's default saved address if available
                $default_addr = $this->addressRepository->findOneBy(
                    ['user' => $user],
                    ['isDefault' => 'DESC', 'id' => 'DESC']
                );
                if ($default_addr instanceof Address) {
                    $recipient_name = $default_addr->getRecipientName();
                    $recipient_phone = $default_addr->getPhoneNumber();
                    $delivery_address = $default_addr->getFormattedAddress();
                }
            }
        }

        if (!$recipient_name) {
            $recipient_name = $user->getUsername();
        }

        // order + items + stock deduction — one flush is one transaction
        try {
            $order = (new Order())
                ->setUser($user)
                ->setTotalAmount(number_format($total_amount, 2, '.', ''))
                ->setOrderDate(new \DateTime())
                ->setStatus('pending')
                ->setPaymentMethod($payment_method)
                ->setPaymentDetails($payment_details)
                ->setDeliveryAddress($delivery_address)
                ->setRecipientName($recipient_name)
                ->setRecipientPhone($recipient_phone);

            foreach ($order_items_to_save as $item) {
                $order_item = (new OrderItem())
                    ->setProduct($item['product'])
                    ->setQuantity($item['quantity'])
                    ->setPriceAtPurchase(number_format($item['price_at_purchase'], 2, '.', ''));
                $order->addOrderItem($order_item);
                $this->entityManager->persist($order_item);

                // deduct the stock through the entity
                $product = $item['product'];
                $product->setProductStockQuantity($product->getProductStockQuantity() - $item['quantity']);
            }

            $this->entityManager->persist($order);
            $this->entityManager->flush();

            // clear the session cart
            $request->getSession()->set('cart', []);

            // redirect to the order summary
            return $this->redirectToRoute('app_checkout_summary', ['order_id' => $order->getId(), 'placed' => 1]);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Failed to process order: ' . $e->getMessage());

            return $this->redirectToRoute('app_checkout');
        }
    }

    // load every product in the cart with its line total — [items, subtotal]
    private function loadCart(SessionInterface $session): array
    {
        $cart_items = [];
        $subtotal = 0.0;

        $cart = $this->sessionCart($session);
        if (empty($cart)) {
            return [$cart_items, $subtotal];
        }

        $pids = array_map('intval', array_keys($cart));

        foreach ($this->productRepository->findBy(['id' => $pids]) as $product) {
            $product_id = $product->getId();
            $qty = (int) ($cart[$product_id] ?? 1);
            $unit_price = (float) $product->getProductPrice();
            $line_total = $unit_price * $qty;
            $subtotal += $line_total;

            $cart_items[] = [
                'id' => $product_id,
                'name' => $product->getProductName(),
                'price' => $unit_price,
                'stock' => $product->getProductStockQuantity(),
                'qty' => $qty,
                'line_total' => $line_total,
                'image_url' => $this->resolveImageUrl($product->getProductImg() ?: null),
            ];
        }

        return [$cart_items, $subtotal];
    }

    // the session cart as an array, whichever way it was stored
    private function sessionCart(SessionInterface $session): array
    {
        $cart = $session->get('cart', []);

        return is_array($cart) ? $cart : [];
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
