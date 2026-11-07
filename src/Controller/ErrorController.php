<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ErrorController extends AbstractController
{
    #[Route('/error', name: 'app_error')]
    #[Route('/error.php')]
    public function index(Request $request): Response
    {
        $code = (int) $request->query->get('code', 404);

        if ($code === 403) {
            return $this->render('@Twig/Exception/error403.html.twig', [], new Response(status: 403));
        }

        if ($code === 404) {
            return $this->render('@Twig/Exception/error404.html.twig', [], new Response(status: 404));
        }

        return $this->render('@Twig/Exception/error.html.twig', [
            'status_code' => $code > 0 ? $code : 500,
            'status_text' => 'An error occurred',
        ], new Response(status: $code > 0 ? $code : 500));
    }
}
