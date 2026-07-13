<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The site root. The landing page IS the personal agenda — "what do I have to do" — so the root
 * just forwards there ({@see PersonalEventController::index}), keeping a single canonical URL and a
 * single template for the agenda instead of two identical screens.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_homepage', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('personal_event_index');
    }
}
