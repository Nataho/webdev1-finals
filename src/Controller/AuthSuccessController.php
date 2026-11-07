<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthSuccessController extends AbstractController
{
    #[Route('/auth-success', name: 'app_auth_success')]
    public function index(Request $request): Response
    {
        // ported from prev/functions/auth.php::require_auth() —
        // guests get sent to the login page
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $type = $request->query->getString('type', 'login');
        $type = in_array($type, ['signup', 'login'], true) ? $type : 'login';

        return $this->render('auth_success/index.html.twig', [
            'type' => $type,
        ]);
    }
}
