<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\CentreSettingsFormData;
use App\Form\CentreSettingsType;
use App\Service\AppSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The centre's details that used to need SSH to change (they were environment variables): its name,
 * the copy room and management mailboxes, and the RAICES link.
 *
 * Reserved to superusers, like the other system screens, and on the class so an action added later
 * cannot be born unprotected: the copy room mailbox decides where exams and other documents are sent,
 * so whoever can change it can redirect them.
 */
#[Route('/admin/centro')]
#[IsGranted('ROLE_ADMIN')]
final class AdminCentreController extends AbstractController
{
    /**
     * Shows the centre's details as the application uses them now and saves them.
     *
     * @param Request     $request  the request, carrying the submitted form on POST
     * @param AppSettings $settings the runtime settings the details are read from and saved to
     *
     * @return Response the form, or a redirect back to it once saved
     */
    #[Route('', name: 'admin_centre_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, AppSettings $settings): Response
    {
        $data = CentreSettingsFormData::fromSettings($settings);
        $form = $this->createForm(CentreSettingsType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $settings->saveCentreDetails($data->organizationName, $data->copyShopEmails, $data->directionEmails, $data->raicesUrl, $data->familyActivitiesEnabled, $data->familySignupRetentionDays);
            $this->addFlash('success', 'Datos del centro guardados.');

            return $this->redirectToRoute('admin_centre_edit');
        }

        return $this->render('admin/centre/edit.html.twig', ['form' => $form]);
    }
}
