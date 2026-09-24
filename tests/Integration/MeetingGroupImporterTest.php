<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\MeetingGroup;
use App\Entity\MeetingType;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\Weekday;
use App\Repository\MeetingGroupRepository;
use App\Service\MeetingGroupImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El import de las reuniones semanales de Peñalara a los grupos de convocatoria: se puede repetir sin
 * duplicar, solo toca día, hora y personas, y pregunta antes de pisar lo que se cambió en la aplicación.
 */
final class MeetingGroupImporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MeetingGroupImporter $importer;
    private User $ana;
    private User $luis;
    private User $rober;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->importer = self::getContainer()->get(MeetingGroupImporter::class);

        $this->ana = $this->person('Ana Ruiz Gil', 'ana@educa.madrid.org', '111');
        $this->luis = $this->person('Luis Sanz Mora', 'luis@educa.madrid.org', '222');
        // Rober está en la aplicación pero no en Peñalara: sin código, como quien se da de alta a mano.
        $this->rober = $this->person('Rober Pérez', 'rober@educa.madrid.org', null);
        $this->em->flush();
    }

    public function testCreatesOneGroupPerMeetingWithDayPeriodAndMembers(): void
    {
        $result = $this->importer->import($this->xml(['111', '222']), false, $this->noQuestionExpected(...));

        self::assertSame(['REUNIÓN TIC'], $result->created);
        $group = $this->group();
        self::assertSame('REUNIÓN TIC', $group->getName());
        self::assertSame('REUNIÓN TIC', $group->getPenalaraKey());
        self::assertSame(Weekday::WEDNESDAY, $group->getWeekday());
        self::assertSame(2, $group->getSlotIndex());
        self::assertEqualsCanonicalizing([$this->ana, $this->luis], $group->getMembers()->toArray());
        self::assertFalse($group->isEditedSinceImport());
        self::assertSame(['REUNIÓN TIC'], $result->noConvener, 'sin convocante no genera nada, y se dice');
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $this->importer->import($this->xml(['111', '222']), false, $this->noQuestionExpected(...));
        $result = $this->importer->import($this->xml(['111', '222']), false, $this->noQuestionExpected(...));

        self::assertSame([], $result->created);
        self::assertSame(['REUNIÓN TIC'], $result->unchanged);
        self::assertCount(1, self::getContainer()->get(MeetingGroupRepository::class)->findAll());
    }

    /** Un cambio hecho en Peñalara se recoge sin preguntar si en la aplicación nadie lo tocó. */
    public function testPicksUpAChangeMadeInPenalara(): void
    {
        $this->importer->import($this->xml(['111', '222']), false, $this->noQuestionExpected(...));
        $result = $this->importer->import($this->xml(['111'], dia: 4, indice: 5), false, $this->noQuestionExpected(...));

        self::assertSame(['REUNIÓN TIC'], $result->updated);
        $group = $this->group();
        self::assertSame(Weekday::FRIDAY, $group->getWeekday());
        self::assertSame(5, $group->getSlotIndex());
        self::assertSame([$this->ana], array_values($group->getMembers()->toArray()));
    }

    /**
     * El caso de la reunión TIC: se añadió a Rober en la aplicación y Peñalara no lo tiene. Si quien
     * importa dice que no, Rober se queda.
     */
    public function testAGroupEditedHereIsKeptWhenTheImportIsToldNo(): void
    {
        $this->importer->import($this->xml(['111', '222']), false, $this->noQuestionExpected(...));
        $this->group()->addMember($this->rober)->markEditedSinceImport();
        $this->em->flush();

        $asked = [];
        $result = $this->importer->import($this->xml(['111', '222']), false, function (MeetingGroup $g) use (&$asked): bool {
            $asked[] = $g->getName();

            return false;
        });

        self::assertSame(['REUNIÓN TIC'], $asked, 'se pregunta por el grupo editado');
        self::assertSame(['REUNIÓN TIC'], $result->keptEdited);
        self::assertContains($this->rober, $this->group()->getMembers()->toArray());
        self::assertTrue($this->group()->isEditedSinceImport(), 'sigue marcado: la próxima vez se volverá a preguntar');
    }

    public function testAGroupEditedHereIsOverwrittenWhenTheImportIsToldYes(): void
    {
        $this->importer->import($this->xml(['111', '222']), false, $this->noQuestionExpected(...));
        $this->group()->addMember($this->rober)->markEditedSinceImport();
        $this->em->flush();

        $result = $this->importer->import($this->xml(['111', '222']), false, static fn (): bool => true);

        self::assertSame(['REUNIÓN TIC'], $result->updated);
        self::assertNotContains($this->rober, $this->group()->getMembers()->toArray());
        self::assertFalse($this->group()->isEditedSinceImport());
    }

    /**
     * Un grupo hecho a mano con el mismo nombre se adopta, sin duplicarlo y preguntando antes de cambiarlo.
     * Escrito sin tilde y en minúsculas, como lo teclea la gente: el índice único de la base de datos los
     * considera el mismo nombre, así que crear otro reventaría.
     */
    public function testAdoptsAHandMadeGroupWithTheSameNameAskingFirst(): void
    {
        $handMade = (new MeetingGroup())->setName('Reunion TIC')->addMember($this->rober);
        $this->em->persist($handMade);
        $this->em->flush();

        $result = $this->importer->import($this->xml(['111', '222']), false, static fn (): bool => false);

        self::assertSame([], $result->created);
        self::assertSame(['REUNIÓN TIC'], $result->keptEdited);
        self::assertCount(1, self::getContainer()->get(MeetingGroupRepository::class)->findAll());
        self::assertSame([$this->rober], array_values($handMade->getMembers()->toArray()));
    }

    /**
     * El mismo nombre con otras mayúsculas en un export posterior es la MISMA reunión: el índice único de la
     * base de datos no los distingue, y tratarlo como nueva reventaría el import entero.
     */
    public function testANameThatOnlyChangesCaseIsTheSameMeeting(): void
    {
        $this->importer->import($this->xml(['111', '222']), false, $this->noQuestionExpected(...));
        $result = $this->importer->import($this->xml(['111', '222'], name: 'Reunión tic'), false, $this->noQuestionExpected(...));

        self::assertSame([], $result->created);
        self::assertSame(['Reunión tic'], $result->unchanged);
        self::assertCount(1, self::getContainer()->get(MeetingGroupRepository::class)->findAll());
    }

    /** La misma reunión dos veces en un fichero se importa una sola vez. */
    public function testTheSameMeetingTwiceInOneFileIsImportedOnce(): void
    {
        $once = $this->xml(['111']);
        $twice = str_replace('</reuniones>', '<reunion><nombre>REUNIÓN TIC</nombre><plantilla><tramo dia="0" indice="1" marco="A">fijado</tramo></plantilla><integrantes><integrante>222</integrante></integrantes></reunion></reuniones>', $once);

        $result = $this->importer->import($twice, false, $this->noQuestionExpected(...));

        self::assertSame(['REUNIÓN TIC'], $result->created);
        self::assertCount(1, self::getContainer()->get(MeetingGroupRepository::class)->findAll());
        self::assertSame(Weekday::WEDNESDAY, $this->group()->getWeekday(), 'manda la primera');
    }

    /** Quién convoca y el tipo no vienen de Peñalara: ningún import los toca. */
    public function testNeverTouchesConvenerOrKind(): void
    {
        $this->importer->import($this->xml(['111', '222']), false, $this->noQuestionExpected(...));
        $kind = (new MeetingType())->setName('Reunión TIC');
        $this->em->persist($kind);
        $this->group()->setConvener($this->ana)->setType($kind);
        $this->em->flush();

        $this->importer->import($this->xml(['222'], dia: 1), false, $this->noQuestionExpected(...));

        self::assertSame($this->ana, $this->group()->getConvener());
        self::assertSame($kind, $this->group()->getType());
    }

    /**
     * Sin convocante no se genera ninguna reunión, y Peñalara no lo trae: el import pone uno de partida. En
     * un departamento, su jefe si está en el grupo; en lo demás, dirección.
     */
    public function testADepartmentMeetingDefaultsToItsHeadAndAnyOtherToDirection(): void
    {
        $this->luis->addAssignedRole($this->role('head_dept'));
        $this->rober->addAssignedRole($this->role('direction'));
        $this->em->flush();

        $dryRun = $this->importer->import($this->xml(['111', '222'], name: 'DPTO LENGUA'), true, $this->noQuestionExpected(...));
        self::assertSame(['DPTO LENGUA' => 'Luis Sanz Mora'], $dryRun->defaulted, 'el ensayo dice a quién pondría');

        $this->importer->import($this->xml(['111', '222'], name: 'DPTO LENGUA'), false, $this->noQuestionExpected(...));
        $result = $this->importer->import($this->xml(['111', '222']), false, $this->noQuestionExpected(...));

        $groups = self::getContainer()->get(MeetingGroupRepository::class);
        self::assertSame($this->luis, $groups->findOneBy(['penalaraKey' => 'DPTO LENGUA'])?->getConvener());
        self::assertSame($this->rober, $this->group()->getConvener());
        self::assertSame(['REUNIÓN TIC' => 'Rober Pérez'], $result->defaulted);
        self::assertSame([], $result->noConvener);
    }

    /**
     * El jefe que no está en el grupo no convoca por defecto: el formulario del grupo solo acepta a alguien
     * del grupo o del equipo directivo, y el grupo quedaría sin poder guardarse. Entonces, dirección.
     */
    public function testADepartmentWhoseHeadIsNotInTheGroupDefaultsToDirection(): void
    {
        $this->luis->addAssignedRole($this->role('head_dept'));
        $this->rober->addAssignedRole($this->role('direction'));
        $this->em->flush();

        $this->importer->import($this->xml(['111'], name: 'DPTO LENGUA'), false, $this->noQuestionExpected(...));

        self::assertSame($this->rober, self::getContainer()->get(MeetingGroupRepository::class)->findOneBy(['penalaraKey' => 'DPTO LENGUA'])?->getConvener());
    }

    /** Con dos personas en dirección no se elige a una al azar: el grupo se queda sin convocante y se dice. */
    public function testNoDefaultWhenTheRoleHasMoreThanOneHolder(): void
    {
        $direction = $this->role('direction');
        $this->ana->addAssignedRole($direction);
        $this->rober->addAssignedRole($direction);
        $this->em->flush();

        $result = $this->importer->import($this->xml(['111', '222']), false, $this->noQuestionExpected(...));

        self::assertNull($this->group()->getConvener());
        self::assertSame(['REUNIÓN TIC'], $result->noConvener);
    }

    /** Un código de Peñalara sin usuario se dice con su nombre, y no se inventa a nadie. */
    public function testReportsMembersNobodyMatchesByName(): void
    {
        $result = $this->importer->import($this->xml(['111', '999']), false, $this->noQuestionExpected(...));

        self::assertSame(['Desconocida, Eva (999)' => ['REUNIÓN TIC']], $result->unmatched);
        self::assertSame([$this->ana], array_values($this->group()->getMembers()->toArray()));
    }

    public function testADryRunWritesNothing(): void
    {
        $result = $this->importer->import($this->xml(['111', '222']), true, $this->noQuestionExpected(...));

        self::assertSame(['REUNIÓN TIC'], $result->created);
        self::assertTrue($result->dryRun);
        self::assertCount(0, self::getContainer()->get(MeetingGroupRepository::class)->findAll());
    }

    /** A group already imported that the new file no longer has is reported, not deleted. */
    public function testReportsGroupsMissingFromTheFile(): void
    {
        $this->importer->import($this->xml(['111']), false, $this->noQuestionExpected(...));
        $result = $this->importer->import($this->xml(['111'], name: 'CCP'), false, $this->noQuestionExpected(...));

        self::assertSame(['REUNIÓN TIC'], $result->missing);
        self::assertCount(2, self::getContainer()->get(MeetingGroupRepository::class)->findAll());
    }

    /**
     * A planificador with one weekly meeting.
     *
     * @param list<string> $members the members' Peñalara codes
     * @param int          $dia     0-based weekday
     * @param int          $indice  period ordinal
     * @param string       $name    the meeting's name
     */
    private function xml(array $members, int $dia = 2, int $indice = 2, string $name = 'REUNIÓN TIC'): string
    {
        $integrantes = implode('', array_map(static fn (string $c): string => "<integrante>{$c}</integrante>", $members));

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <datosGHC>
                <profesores>
                    <profesor><nombreCompleto>Ruiz Gil, Ana</nombreCompleto><claveDeExportacion>111</claveDeExportacion></profesor>
                    <profesor><nombreCompleto>Sanz Mora, Luis</nombreCompleto><claveDeExportacion>222</claveDeExportacion></profesor>
                    <profesor><nombreCompleto>Desconocida, Eva</nombreCompleto><claveDeExportacion>999</claveDeExportacion></profesor>
                </profesores>
                <reuniones>
                    <reunion subMarco="A">
                        <nombre>{$name}</nombre>
                        <plantilla><tramo dia="{$dia}" indice="{$indice}" marco="A">fijado</tramo></plantilla>
                        <integrantes>{$integrantes}</integrantes>
                    </reunion>
                </reuniones>
            </datosGHC>
            XML;
    }

    /** The overwrite question for runs where no group was edited here: being asked is a failure. */
    private function noQuestionExpected(): bool
    {
        self::fail('No debería preguntar: ningún grupo se había cambiado en la aplicación.');
    }

    private function group(): MeetingGroup
    {
        $group = self::getContainer()->get(MeetingGroupRepository::class)->findOneBy(['penalaraKey' => 'REUNIÓN TIC'])
            ?? self::getContainer()->get(MeetingGroupRepository::class)->findOneBy(['name' => 'Reunion TIC']);
        self::assertInstanceOf(MeetingGroup::class, $group);

        return $group;
    }

    /**
     * A role, persisted. The test database has none: roles come from fixtures, not from migrations.
     *
     * @param string $code the role code
     *
     * @return Role the persisted role
     */
    private function role(string $code): Role
    {
        $role = (new Role())->setCode($code)->setName($code);
        $this->em->persist($role);

        return $role;
    }

    private function person(string $name, string $email, ?string $code): User
    {
        $user = (new User())->setFullName($name)->setEmail($email)->setPenalaraCode($code);
        $this->em->persist($user);

        return $user;
    }
}
