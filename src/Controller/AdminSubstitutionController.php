<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\Substitution;
use App\Entity\User;
use App\Enum\Area;
use App\Form\SubstitutionType;
use App\Guardia\SubstitutionApplier;
use App\Guardia\SubstitutionRefused;
use App\Guardia\SubstitutionResult;
use App\Repository\AcademicYearRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\SubstitutionRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Service\AuditLogger;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Alta y cierre de las sustituciones de profesorado de baja larga.
 *
 * Vive en /admin y no en el módulo de guardias porque lo que se hace aquí es, sobre todo, dar de alta a
 * una persona: quien mete la baja en RAICES es quien lleva esta parte, y es la misma pantalla donde ya
 * se registra a cualquiera que entre en el centro. Cerrada con permiso de escritura sobre
 * {@see Area::ADMINISTRATION}, como {@see AdminUserController}.
 *
 * Lo que la pantalla tiene que decir en voz alta, porque no se ve: **el traspaso empieza hoy**. La
 * rejilla del horario es semanal, sin fechas ({@see \App\Entity\ScheduleEntry}), así que dar de alta una
 * baja que empezó hace dos semanas no reconstruye esas dos semanas de partes — y eso está bien, porque
 * el parte ya materializó grupo, aula y materia el día que se registró cada ausencia.
 */
#[Route('/admin/sustituciones')]
final class AdminSubstitutionController extends AbstractController
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly SubstitutionApplier $applier,
    ) {
    }

    /**
     * Las sustituciones del curso: las que están en vigor arriba, el historial debajo, y el formulario
     * de alta.
     */
    #[Route('', name: 'admin_substitution_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        AcademicYearRepository $years,
        SubstitutionRepository $substitutions,
        ScheduleEntryRepository $schedule,
        UserRepository $users,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        $curso = (string) ($request->query->get('curso') ?: SchoolYear::current(new \DateTimeImmutable('today')));
        $year = $years->findBySchoolYear($curso);

        $form = $this->createForm(SubstitutionType::class, null, [
            'teachers' => $year instanceof AcademicYear ? $schedule->teachersWithEntries($year) : [],
        ]);
        $form->handleRequest($request);

        if ($year instanceof AcademicYear && $form->isSubmitted() && $form->isValid()) {
            /** @var array{substitutedTeacher: User, substituteName: string, substituteEmail: string, startedOn: \DateTimeImmutable, note: string|null} $data */
            $data = $form->getData();

            $redirect = $this->openSubstitution($year, $data, $substitutions, $users, $em);
            if (null !== $redirect) {
                return $redirect;
            }
        }

        return $this->render('admin/substitution/index.html.twig', [
            'courses' => $years->findAllOrdered(),
            'curso' => $curso,
            'year' => $year,
            'form' => $form,
            'open' => $year instanceof AcademicYear ? $substitutions->findOpenFor($year) : [],
            'closed' => $year instanceof AcademicYear ? $substitutions->findClosedFor($year) : [],
        ]);
    }

    /**
     * Cierra una sustitución: devuelve el horario y el cuadrante de recreo a la persona que vuelve.
     */
    #[Route('/{id}/cerrar', name: 'admin_substitution_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function close(Request $request, Substitution $substitution): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        if (!$this->isCsrfTokenValid('substitution_close_'.$substitution->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $curso = $substitution->getAcademicYear()->getSchoolYear();
        if (!$substitution->isOpen()) {
            // Doble envío o dos pestañas: ya está cerrada y el horario ya volvió. Repetirlo lo movería
            // en la dirección contraria y le quitaría el horario a quien acaba de recuperarlo.
            $this->addFlash('error', 'Esa sustitución ya estaba cerrada.');

            return $this->redirectToRoute('admin_substitution_index', ['curso' => $curso]);
        }

        $returned = $this->applier->close($substitution, new \DateTimeImmutable('today'));

        // Encima del rastro automático que da {@see \App\Contract\Auditable}, no en su lugar: ese apunta
        // qué campo cambió y a qué valor, y aquí los valores son ids de persona. Quien lee la auditoría
        // en junio necesita los nombres.
        $this->auditLogger->log(
            'substitution.closed',
            'Substitution',
            (string) $substitution->getId(),
            sprintf(
                '%s deja de sustituir a %s',
                $substitution->getSubstitute()->getFullName(),
                $substitution->getSubstitutedTeacher()->getFullName(),
            ),
        );
        $this->addFlash('success', sprintf(
            'Sustitución cerrada. %s recupera %s.',
            $substitution->getSubstitutedTeacher()->getFullName(),
            self::describe($returned),
        ));

        return $this->redirectToRoute('admin_substitution_index', ['curso' => $curso]);
    }

    /**
     * Da de alta la sustitución con los datos del formulario: resuelve o crea a quien sustituye y
     * traspasa.
     *
     * @param AcademicYear                                                                                                     $year          el curso
     * @param array{substitutedTeacher: User, substituteName: string, substituteEmail: string, startedOn: \DateTimeImmutable, note: string|null} $data lo enviado
     * @param SubstitutionRepository                                                                                           $substitutions las sustituciones ya registradas
     * @param UserRepository                                                                                                   $users         el padrón
     * @param EntityManagerInterface                                                                                           $em            el gestor
     *
     * @return Response|null la redirección al índice, o null para volver a pintar el formulario con el error
     */
    private function openSubstitution(AcademicYear $year, array $data, SubstitutionRepository $substitutions, UserRepository $users, EntityManagerInterface $em): ?Response
    {
        // Comprobado ANTES de resolver a quien sustituye, y no solo dentro del applier: con una persona
        // nueva, esta es la única negativa posible, y dejarla para después habría creado su cuenta un
        // instante antes de rechazar el alta — un usuario huérfano por cada intento fallido.
        $alreadyOpen = $substitutions->findOpenInvolving($data['substitutedTeacher']);
        if (null !== $alreadyOpen) {
            $this->addFlash('error', SubstitutionRefused::alreadyInvolved($data['substitutedTeacher'], $alreadyOpen)->getMessage());

            return null;
        }

        $substitute = $this->resolveSubstitute($data['substituteEmail'], $data['substituteName'], $users, $em);

        $substitution = (new Substitution())
            ->setAcademicYear($year)
            ->setSubstitutedTeacher($data['substitutedTeacher'])
            ->setSubstitute($substitute)
            ->setStartedOn($data['startedOn'])
            ->setNote($data['note']);

        try {
            $moved = $this->applier->open($substitution, new \DateTimeImmutable('today'));
        } catch (SubstitutionRefused $refused) {
            $this->addFlash('error', $refused->getMessage());

            return null;
        }

        // Reactivada solo con la sustitución ya concedida: una sustitución rechazada no puede devolverle
        // el acceso a alguien a quien se le retiró, que es lo que pasaría reactivando al resolverla.
        if (!$substitute->isActive()) {
            $substitute->setActive(true);
            $em->flush();
        }

        $this->auditLogger->log(
            'substitution.opened',
            'Substitution',
            (string) $substitution->getId(),
            sprintf('%s sustituye a %s', $substitute->getFullName(), $data['substitutedTeacher']->getFullName()),
        );

        if ($moved->isEmpty()) {
            // Sale bien y no mueve nada cuando el horario del curso todavía no está importado. Decirlo es
            // la diferencia entre volver a intentarlo tras el import y creer que ya está resuelto.
            $this->addFlash('warning', sprintf(
                'Sustitución dada de alta, pero no había nada que traspasar: %s no tiene horario en %s. Impórtalo y vuelve a abrir la sustitución.',
                $data['substitutedTeacher']->getFullName(),
                $year->getSchoolYear(),
            ));
        } else {
            $this->addFlash('success', sprintf(
                '%s asume %s. Los partes de guardia anteriores a hoy siguen a nombre de %s.',
                $substitute->getFullName(),
                self::describe($moved),
                $data['substitutedTeacher']->getFullName(),
            ));
        }

        return $this->redirectToRoute('admin_substitution_index', ['curso' => $year->getSchoolYear()]);
    }

    /**
     * La cuenta de quien sustituye: la que ya tenga ese correo, o una nueva.
     *
     * Se busca por correo ANTES de crear nada, y no se deja que lo resuelva el UNIQUE de la tabla: aquí
     * una colisión no es un caso raro sino el caso normal —quien sustituye suele ser alguien que ya
     * estuvo en el centro—, y además un flush que falla CIERRA el EntityManager, así que no habría forma
     * de reintentar en el sitio ni de guardar nada más en esta petición.
     *
     * Aquí NO se reactiva a nadie: si la cuenta estaba desactivada, quien la reactiva es
     * {@see openSubstitution()} y solo cuando la sustitución ya está concedida. Hacerlo al resolverla
     * devolvería el acceso a alguien a quien se le retiró aunque el alta acabe rechazada, que es una
     * puerta abierta como efecto colateral de un intento fallido.
     *
     * @param string                 $email el correo tecleado
     * @param string                 $name  el nombre tecleado
     * @param UserRepository         $users el padrón
     * @param EntityManagerInterface $em    el gestor
     *
     * @return User quien sustituye, ya persistida
     */
    private function resolveSubstitute(string $email, string $name, UserRepository $users, EntityManagerInterface $em): User
    {
        $existing = $users->findOneBy(['email' => strtolower(trim($email))]);
        if ($existing instanceof User) {
            return $existing;
        }

        // El nombre NO se le pisa a quien ya existe: el padrón es de quien lo mantiene, y una errata al
        // teclear el alta no puede renombrar a nadie.
        $substitute = (new User())->setFullName($name)->setEmail($email)->setActive(true);
        $em->persist($substitute);
        $em->flush();

        return $substitute;
    }

    /**
     * Lo que se movió, en una frase con solo las partes que no son cero — "22 celdas de horario y 2
     * plazas de recreo" en vez de una plantilla con tres ceros dentro.
     *
     * @param SubstitutionResult $moved el resultado del traspaso
     *
     * @return string la enumeración, ya en castellano
     */
    private static function describe(SubstitutionResult $moved): string
    {
        $parts = [];
        if ($moved->timetableCells > 0) {
            $parts[] = sprintf('%d celda(s) de horario', $moved->timetableCells);
        }
        if ($moved->breakDutyPlaces > 0) {
            $parts[] = sprintf('%d plaza(s) de recreo', $moved->breakDutyPlaces);
        }
        if ($moved->guardiaCovers > 0) {
            $parts[] = sprintf('%d guardia(s) ya asignada(s)', $moved->guardiaCovers);
        }
        if ([] === $parts) {
            return 'nada: no había horario que mover';
        }

        $last = array_pop($parts);

        return [] === $parts ? $last : implode(', ', $parts).' y '.$last;
    }
}
