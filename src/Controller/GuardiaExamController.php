<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Enum\Area;
use App\Guardia\ExamPeriodProposal;
use App\Guardia\ExamPeriodRelief;
use App\Repository\AcademicYearRepository;
use App\Security\Voter\AreaVoter;
use App\Support\GuardiaDate;
use App\Util\SchoolYear;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La semana de exámenes de 2º de Bachillerato, desde las guardias: qué guardias hay que pasar de manos
 * porque quien las tenía está acompañando un examen, y quién queda libre para cogerlas. El programa lo
 * propone, el equipo directivo lo valida o lo retoca y con un botón se aplica.
 *
 * Pantalla aparte y no más rutas en {@see GuardiaController} —que ya es el controlador más largo de la
 * aplicación— y con las mismas puertas que el resto de superficies de coordinación: {@see AreaVoter::READ}
 * para mirar, {@see AreaVoter::WRITE} para aplicar.
 *
 * **Es "activable" sin interruptor propio.** Mientras no haya un plan de exámenes APROBADO tocando el día,
 * la pantalla lo dice y no propone nada; en cuanto lo hay, tiene contenido. El interruptor es aprobar el
 * plan, que es un gesto que el equipo directivo ya hace en el módulo de espacios y que ya cambia la rejilla
 * efectiva: un segundo interruptor aquí solo serviría para quedarse desparejado del primero y para que
 * alguien se preguntara por qué la pantalla está vacía con los exámenes ya en marcha.
 */
#[Route('/guardias/examenes')]
final class GuardiaExamController extends AbstractController
{
    use GuardiaParteTrait;

    /**
     * La propuesta del día: quién acompaña cada examen y qué guardias tiene puestas, y a quién dejan libre
     * los exámenes. Las casillas vienen marcadas — el programa propone, y lo que se espera de quien valida
     * es desmarcar lo que no cuadre, no reconstruir la lista.
     */
    #[Route('', name: 'guardia_exam_index', methods: ['GET'])]
    public function index(Request $request, AcademicYearRepository $years, ExamPeriodRelief $relief): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::GUARDIAS);

        $date = GuardiaDate::fromRequest($request);
        $schoolYear = SchoolYear::current($date);
        $year = $years->findBySchoolYear($schoolYear);

        return $this->render('guardia/exams.html.twig', [
            'date' => $date,
            'schoolYear' => $schoolYear,
            'hasTimetable' => $year instanceof AcademicYear,
            'proposal' => $year instanceof AcademicYear ? $relief->proposeFor($year, $date) : new ExamPeriodProposal([], []),
            'canApply' => $this->isGranted(AreaVoter::WRITE, Area::GUARDIAS),
        ]);
    }

    /**
     * Aplica lo validado: da de alta el apoyo marcado, retira las guardias de quien acompaña un examen y
     * vuelve a repartir. El detalle de qué se hace y en qué orden está en {@see ExamPeriodRelief::apply()}.
     */
    #[Route('/aplicar', name: 'guardia_exam_apply', methods: ['POST'])]
    public function apply(Request $request, AcademicYearRepository $years, ExamPeriodRelief $relief): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_exam_apply');

        $date = GuardiaDate::fromRequest($request);
        $year = $years->findBySchoolYear(SchoolYear::current($date));
        if (!$year instanceof AcademicYear) {
            $this->addFlash('error', sprintf('No hay horario importado para el curso %s.', SchoolYear::current($date)));

            return $this->backToExams($date);
        }

        // support[<tramo>][] = <id>. Las claves llegan como cadenas; el servicio trabaja con tramos enteros.
        /** @var array<int|string, mixed> $posted */
        $posted = $request->request->all('support');
        $supportBySlot = [];
        foreach ($posted as $slotIndex => $teacherIds) {
            $supportBySlot[(int) $slotIndex] = array_map(intval(...), (array) $teacherIds);
        }

        // Dos personas aplicando la misma propuesta en el mismo instante chocan contra el UNIQUE del apoyo.
        // Como en el alta de ausencias, Doctrine cierra el gestor y no se puede reintentar aquí: no se ha
        // escrito nada, así que basta con decirlo y que se vuelva a enviar.
        try {
            $result = $relief->apply($year, $date, $supportBySlot);
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('warning', 'Alguien acaba de aplicar esta misma propuesta a la vez que tú, así que no se ha guardado nada de este intento. Vuelve a enviarlo: verás lo que quede por hacer.');

            return $this->backToExams($date);
        }

        $this->addFlash(
            0 === $result['support'] + $result['handedOver'] ? 'warning' : 'success',
            0 === $result['support'] + $result['handedOver']
                ? 'No había nada que aplicar: nadie acompaña un examen con una guardia puesta y no marcaste a nadie como apoyo.'
                : sprintf(
                    '%d persona(s) de alta como apoyo y %d guardia(s) retirada(s) a quien acompaña un examen. Se han repartido %d.',
                    $result['support'],
                    $result['handedOver'],
                    $result['assigned'],
                ),
        );
        // Lo rechazado se dice, nunca se traga: quien valida tiene que saber que a alguien que marcó no se le
        // dio de alta, o creerá que ese hueco está resuelto.
        foreach ($result['refused'] as $reason) {
            $this->addFlash('warning', $reason);
        }

        return $this->backToExams($date);
    }

    /**
     * Vuelve a la pantalla de exámenes del día en el que se estaba trabajando.
     *
     * @param \DateTimeImmutable $date the day
     *
     * @return Response the redirect
     */
    private function backToExams(\DateTimeImmutable $date): Response
    {
        return $this->redirectToRoute('guardia_exam_index', ['date' => $date->format('Y-m-d')]);
    }
}
