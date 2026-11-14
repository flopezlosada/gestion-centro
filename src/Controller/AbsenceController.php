<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Area;
use App\Guardia\AbsenceListing;
use App\Security\Voter\AreaVoter;
use App\Service\AppSettings;
use App\Service\PdfRenderer;
use App\Util\CalendarDate;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Who is away each day, for the administrative staff: they ask each teacher for the justificante and
 * record the absence in RAICES, and this is where they learn there is one to ask for.
 *
 * Read only, and only the absence: teacher, day and periods. Not the group left without them, nor who
 * covered it — that is the parte, and it is behind {@see Area::GUARDIAS}. The application never holds nor
 * chases the justificante either; that stays in the Consejería's own tools.
 *
 * It lists what the centre still keeps and nothing older, even before the daily purge has run, so the
 * screen never shows past the plazo the privacy notice promises.
 */
#[Route('/ausencias')]
final class AbsenceController extends AbstractController
{
    /**
     * @param AbsenceListing $listing  the absences as the administrative staff read them
     * @param AppSettings    $settings where the centre's plazo is kept
     * @param ClockInterface $clock    today, for the plazo and the default week
     */
    public function __construct(
        private readonly AbsenceListing $listing,
        private readonly AppSettings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The absences kept, the most recent day first.
     */
    #[Route('', name: 'absence_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::AUSENCIAS);

        $today = $this->clock->now();

        return $this->render('absence/index.html.twig', [
            'rows' => $this->listing->kept($today),
            'retention' => $this->settings->getAbsenceRetention(),
            'keptFrom' => $this->listing->keptFrom($today),
            'thisWeek' => CalendarDate::mondayOf($today),
        ]);
    }

    /**
     * One week of absences as a PDF, day by day, to record them in RAICES with the list at hand. Any day of
     * the week can be given (`?semana=Y-m-d`); without one, the current week. Only what the centre still
     * keeps ({@see AbsenceListing::week()}).
     */
    #[Route('/semana.pdf', name: 'absence_week_pdf', methods: ['GET'])]
    public function weekPdf(Request $request, PdfRenderer $pdf): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::AUSENCIAS);

        $today = $this->clock->now();
        $asked = $request->query->getString('semana');
        $day = '' === $asked ? $today : CalendarDate::parse($asked, $today->getTimezone());
        if (null === $day) {
            throw $this->createNotFoundException('Esa fecha no existe.');
        }

        $week = $this->listing->week($day, $today);
        $response = new Response($pdf->render('absence/week_pdf.html.twig', $week + [
            'retention' => $this->settings->getAbsenceRetention(),
            'generatedAt' => $today,
        ]));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            \sprintf('ausencias-semana-%s.pdf', $week['monday']->format('Y-m-d')),
        ));
        // Names and days of absence: not to be kept by any cache on the way, nor by the browser's.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
