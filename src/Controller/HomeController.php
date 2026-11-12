<?php

namespace App\Controller;

use App\Repository\FeedbackRepository;
use App\Service\FileUploader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly FeedbackRepository $feedbackRepository,
        private readonly FileUploader $fileUploader,
    ) {
    }

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // newest testimonials; the feedback section only renders while rows exist
        $feedback_items = $this->feedbackRepository->findLatest();
        foreach ($feedback_items as $feedback) {
            $feedback->setAvatarUrl(
                $this->fileUploader->exists('feedback', $feedback->getAvatar())
                    ? 'uploads/feedback/'.$feedback->getAvatar()
                    : null
            );
        }

        return $this->render('home/index.html.twig', [
            'feedback_items' => $feedback_items,
        ]);
    }
}
