<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Role;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use Doctrine\Persistence\ObjectManager;

/**
 * The role catalog of the centre (configurable, not an enum). Part of the GOLDEN backbone: these
 * roles are the access skeleton the production database would start from, and the roster import
 * upserts them by code (so `--group=golden` + `app:import-roster` never duplicates a role).
 *
 * Codes are the canonical ones the app reasons about (e.g. {@see \App\Controller\TaskController}
 * checks "direction"/"head_of_studies" for the task-role privilege); the roster import maps each
 * "cargo" to one of these same codes.
 */
final class RoleFixtures extends AbstractGoldenFixture
{
    /**
     * Reference name for the role with the given code, so other fixtures can wire to it.
     *
     * @param string $code the role code
     *
     * @return string the fixture reference name
     */
    public static function ref(string $code): string
    {
        return 'role-'.$code;
    }

    public function load(ObjectManager $manager): void
    {
        // Direction manages via the permission matrix (write on Administration) WITHOUT the superuser
        // flag: it reaches /admin but is not ROLE_ADMIN. TIC is the actual superuser. The rest are
        // responsibility markers used for assignment, hierarchy and the leadership privilege.
        //
        // canConvene = convoca reuniones. Regla del centro: TODOS los cargos convocan; el docente sin cargo
        // es a quien se convoca. Bandera del rol y no una lista de códigos en PHP, para que el centro la
        // mueva cuando aparezca un cargo nuevo.
        //
        // hierarchyLevel = rank in the chain of command (higher = more senior); null = no hierarchy
        // (functional/permission role only). The leadership team is centre-wide; jefatura de departamento
        // is per-department (commands only its own department). TIC, secretaría, tutor and docente carry
        // no rank — they grant feature access but command nobody.
        $direction = (new Role())->setCode('direction')->setName('Dirección')
            ->setLevel(Area::ADMINISTRATION, PermissionLevel::WRITE)
            // Espacios: the equipo directivo is who decides where a group goes and who completes the
            // room catalogue (size, type), so it is the one role that writes here by default.
            ->setLevel(Area::ESPACIOS, PermissionLevel::WRITE)
            // Incidencias TIC: bus factor. El rol TIC ya llega por ser superusuario, así que esto es para
            // que cerrar una avería no dependa de que esté esa única persona. Tiene que estar aquí Y en
            // `Version20260803180000`: la migración arregla las bases ya poblada (producción), y esta
            // fixture es la que ve un entorno nuevo — si solo estuviera en una, dev y prod darían
            // permisos distintos y el 403 aparecería en el sitio donde nadie lo busca.
            ->setLevel(Area::TIC, PermissionLevel::WRITE)
            ->setHierarchyLevel(40)
            ->setCanConvene(true);
        $catalog = [
            $direction,
            (new Role())->setCode('tic')->setName('TIC')->setAdmin(true)->setCanConvene(true),
            (new Role())->setCode('head_of_studies')->setName('Jefatura de estudios')->setHierarchyLevel(30)->setCanConvene(true),
            (new Role())->setCode('head_of_studies_deputy')->setName('Jefatura de estudios adjunta')->setHierarchyLevel(20)->setCanConvene(true),
            (new Role())->setCode('secretary')->setName('Secretaría')->setCanConvene(true),
            // Guardia coordinator: manages the daily parte (register absences, assign covers, mark
            // incidents, history and stats). A functional permission role, not a rank — it commands
            // nobody. Any role can be granted this same access from the roles matrix (Guardias = escritura).
            (new Role())->setCode('guardias')->setName('Coordinación de guardias')
                ->setLevel(Area::GUARDIAS, PermissionLevel::WRITE)
                // Read on Espacios so the coordinator can find a big free room to merge groups into when
                // there are more absences than teachers on call — that consultation is why it exists.
                ->setLevel(Area::ESPACIOS, PermissionLevel::READ)
                // Lectura en Fotocopias: la coordinación ve la cola entera porque es quien deja dichas las
                // copias de la tarea de cada guardia y tiene que comprobar que salieron. LECTURA y no
                // escritura: marcar un encargo hecho es del que está en la fotocopiadora.
                ->setLevel(Area::FOTOCOPIAS, PermissionLevel::READ)
                ->setCanConvene(true),
            // Project coordination: convenes the meetings of the projects it coordinates and uploads their
            // minutes. NO rank (commands nobody) and NO area in the matrix, on purpose: the power is scoped
            // to a project, and that scope lives in Project.coordinator — not in this role, which would
            // otherwise reach every project at once. This entry is the catalogue label of the job.
            (new Role())->setCode('project_coordinator')->setName('Coordinación de proyectos')->setCanConvene(true),
            // Per-department roles: a holder is "X of a given department", so a task's responsibility on
            // one of these also needs the department (resolved live to whoever holds it there).
            (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10)->setCanConvene(true),
            // La tutoría convoca (a su equipo docente) aunque no manda en nadie: convocar es de los CARGOS,
            // no del rango.
            (new Role())->setCode('tutor')->setName('Tutor/a')->setPerDepartment(true)->setCanConvene(true),
            (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true),
            // PERSONAL NO DOCENTE. Tres cargos con funciones concretas, no un cajón "PAS": el centro pidió
            // que entraran por lo que HACEN en la aplicación, y cada uno recibe exactamente eso.
            //
            // Ninguno convoca reuniones: la regla del centro es que convocan los cargos con equipo al que
            // convocar. Se les convoca (a un claustro, a una comisión), y para eso no hace falta la bandera.
            //
            // Auxiliares de control (conserjería). UN solo permiso: ESCRITURA en Fotocopias, porque son
            // quienes imprimen — ven la cola completa y marcan cada encargo hecho, que es lo que convierte
            // la lista en una cola de trabajo.
            //
            // Su otra función, "ver dónde están los profesores que han cambiado de aula", NO necesita
            // permiso: «Aulas libres» se abrió a todo el claustro quitándole la puerta (PR #139) y responde
            // sobre el horario EFECTIVO, planes de cambio aprobados incluidos ({@see \App\Space\RoomOccupancy}).
            // Y `espacios: read` sería peor que inútil: no enseña nada (el bloque Espacios del menú exige
            // ESCRITURA) y abriría el área entera a cualquier lectura que el módulo añada mañana.
            (new Role())->setCode('auxiliar_control')->setName('Auxiliar de control')
                ->setLevel(Area::FOTOCOPIAS, PermissionLevel::WRITE),
            // Personal administrativo. LECTURA en Guardias: el parte del día, el histórico y las
            // estadísticas, que es "ver los partes de ausencias". Sin escritura no apuntan ausencias ni
            // reparten guardias — eso sigue siendo del equipo directivo.
            //
            // Lo que el centro pidió y NO está aquí: completar los horarios. Las dos pantallas que lo hacen
            // están detrás de permisos que dan mucho más (`/admin/horario` exige escritura en
            // ADMINISTRACIÓN, o sea todo el back-office incluido /admin/roles; `/guardias/horario` exige
            // escritura en GUARDIAS, o sea el parte entero). Darles cualquiera de los dos para que puedan
            // teclear un horario es una escalada de privilegios, no un matiz. Queda pendiente partir la
            // puerta de {@see \App\Controller\AdminTimetableController}.
            (new Role())->setCode('personal_administrativo')->setName('Personal administrativo')
                ->setLevel(Area::GUARDIAS, PermissionLevel::READ),
            // Integración social. Ni un permiso: lo que pidió el centro es que EXISTAN en el organigrama,
            // dentro del departamento de Orientación — de ahí perDepartment, igual que el resto de roles
            // que se ostentan "de un departamento".
            //
            // Rol propio y no `teacher` con el departamento puesto, aunque técnicamente valdría (nada en el
            // código comprueba el código 'teacher', y el reparto de guardias sale del horario importado, no
            // del rol). El motivo es que la responsabilidad de una tarea se resuelve en vivo por
            // rol+departamento: "Docente / Orientación" les caería encima a quienes no dan clase, y en la
            // ficha del departamento saldrían etiquetadas como docentes. El nombre es la función y no la
            // persona, como Dirección o Jefatura de estudios, y así además no tiene género.
            (new Role())->setCode('integracion_social')->setName('Integración social')->setPerDepartment(true),
        ];

        foreach ($catalog as $role) {
            $manager->persist($role);
            $this->addReference(self::ref($role->getCode()), $role);
        }

        $manager->flush();
    }
}
