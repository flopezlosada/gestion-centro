<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\LessonPlan;
use App\Entity\NonLectiveDay;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\LessonActivity;
use App\Enum\LessonOutcome;
use App\Enum\Weekday;
use App\Tests\Support\BuildsTheCentresTimetable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Programar una clase propia con toques: tema de la lista compartida (o uno nuevo), actividad, cómo fue y
 * una línea opcional. Solo las clases que uno da de verdad, solo las ve quien las programa, y la siguiente
 * clase con el mismo grupo sabe dónde se quedó.
 *
 * Fechas FIJAS: dos lunes del curso 2025-2026, con la misma clase (B1A y B1B, Literatura Universal, 6ª).
 */
final class LessonPlanTest extends WebTestCase
{
    use BuildsTheCentresTimetable;

    private const FIRST = '2026-01-12';
    private const NEXT = '2026-01-19';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $teacher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $year = $this->courseWithTheCentresFrame($this->em);
        $this->teacher = (new User())->setFullName('Carolina Programa')->setEmail('carolina.programa@educa.madrid.org');
        $this->em->persist($this->teacher);
        $this->classCell($this->em, $year, $this->teacher, Weekday::MONDAY, 7, 'B1A', 'S ACTOS');
        $this->classCell($this->em, $year, $this->teacher, Weekday::MONDAY, 7, 'B1B', 'S ACTOS');
        $this->em->flush();
        $this->client->loginUser($this->teacher);
    }

    public function testPlanningAClassSavesItAndCreatesTheTopicInTheSharedList(): void
    {
        $this->save(self::FIRST, ['tema' => 'La novela del siglo XX', 'actividad' => 'ejercicios', 'resultado' => 'a_medias', 'nota' => 'pág. 52, ej. 1-8']);

        self::assertResponseRedirects('/calendario?vista=dia&fecha='.self::FIRST);
        $plan = $this->planOn(self::FIRST);
        self::assertNotNull($plan);
        $topic = $plan->getTopic();
        self::assertNotNull($topic);
        self::assertSame('La novela del siglo XX', $topic->getName());
        self::assertSame('Literatura Universal', $topic->getSubject());
        self::assertSame(LessonActivity::EXERCISES, $plan->getActivity());
        self::assertSame(LessonOutcome::PARTIAL, $plan->getOutcome());
        self::assertSame('pág. 52, ej. 1-8', $plan->getNote());
        self::assertSame('B1A, B1B', $plan->getGroupNames());
    }

    /** La clase siguiente con el mismo grupo enseña dónde se quedó y lo propone. */
    public function testTheNextClassWithTheGroupShowsWhereItWasLeft(): void
    {
        $this->save(self::FIRST, ['tema' => 'La novela del siglo XX', 'actividad' => 'ejercicios', 'resultado' => 'a_medias', 'nota' => 'pág. 52']);

        $crawler = $this->client->request('GET', '/mis-clases/'.self::NEXT.'/7');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'La última clase con este grupo');
        self::assertSame('La novela del siglo XX', $crawler->filter('#tema')->attr('value'));
        self::assertSame('pág. 52', $crawler->filter('#nota')->attr('value'), 'lo que quedó a medias se propone');
    }

    /** «Seguimos igual»: un toque copia tema y actividad, y la línea si quedó algo pendiente. */
    public function testSeguimosIgualCopiesThePreviousClass(): void
    {
        $this->save(self::FIRST, ['tema' => 'La novela del siglo XX', 'actividad' => 'ejercicios', 'resultado' => 'a_medias', 'nota' => 'pág. 52']);
        $crawler = $this->client->request('GET', '/mis-clases/'.self::NEXT.'/7');
        $this->client->submit($crawler->selectButton('Seguimos igual')->form());

        $plan = $this->planOn(self::NEXT);
        self::assertNotNull($plan);
        self::assertSame('La novela del siglo XX', $plan->getTopic()?->getName());
        self::assertSame(LessonActivity::EXERCISES, $plan->getActivity());
        self::assertNull($plan->getOutcome(), 'cómo fue se marca después');
        self::assertSame('pág. 52', $plan->getNote());
        self::assertCount(1, $this->em->getRepository(Topic::class)->findAll(), 'el mismo tema, no uno nuevo');
    }

    /** Escribir el tema en otras mayúsculas es el mismo tema de la lista. */
    public function testTypingAnExistingTopicDifferentlyReusesIt(): void
    {
        $this->save(self::FIRST, ['tema' => 'La novela del siglo XX']);
        $this->save(self::NEXT, ['tema' => 'LA NOVELA DEL SIGLO XX']);

        self::assertCount(1, $this->em->getRepository(Topic::class)->findAll());
    }

    /** Vaciarlo todo borra la programación: una vacía no vale una fila. */
    public function testClearingEverythingRemovesThePlan(): void
    {
        $this->save(self::FIRST, ['tema' => 'La novela del siglo XX']);
        $this->save(self::FIRST, ['tema' => '']);

        self::assertNull($this->planOn(self::FIRST));
    }

    /** Solo se programa una clase que uno da de verdad: otra hora, otro día o un festivo no existen. */
    public function testOnlyARealClassOfYoursCanBePlanned(): void
    {
        $this->client->request('GET', '/mis-clases/'.self::FIRST.'/2');
        self::assertResponseStatusCodeSame(404, 'a esa hora no da clase');

        $this->em->persist((new NonLectiveDay())->setDate(new \DateTimeImmutable(self::NEXT))->setDescription('Fiesta'));
        $this->em->flush();
        $this->client->request('GET', '/mis-clases/'.self::NEXT.'/7');
        self::assertResponseStatusCodeSame(404, 'un festivo no tiene clases');
    }

    /** Lo programado es privado: otra persona no llega a la clase de nadie, ni la ve en su calendario. */
    public function testSomebodyElseCannotReachIt(): void
    {
        $this->save(self::FIRST, ['tema' => 'La novela del siglo XX']);
        $other = (new User())->setFullName('Otra Persona')->setEmail('otra.programa@educa.madrid.org');
        $this->em->persist($other);
        $this->em->flush();

        $this->client->loginUser($other);
        $this->client->request('GET', '/mis-clases/'.self::FIRST.'/7');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/calendario?vista=dia&fecha='.self::FIRST);
        self::assertSelectorTextNotContains('body', 'La novela del siglo XX');
    }

    /** El calendario del día dice qué toca en cada clase programada. */
    public function testTheDayViewShowsWhatIsPlanned(): void
    {
        $this->save(self::FIRST, ['tema' => 'La novela del siglo XX', 'actividad' => 'examen']);

        $this->client->request('GET', '/calendario?vista=dia&fecha='.self::FIRST);

        self::assertSelectorTextContains('.cal-block--class .cal-block__subtitle', 'La novela del siglo XX · Examen');
    }

    /** Una clase puede cerrar un tema y abrir otro: cuenta media clase para cada uno, no la clase entera
     *  para el segundo. */
    public function testAClassCanCloseOneTopicAndOpenAnother(): void
    {
        $this->save(self::FIRST, [
            'tema' => 'La novela del siglo XX', 'actividad' => 'ejercicios', 'resultado' => 'hecho',
            'tema2' => 'Romanticismo', 'actividad2' => 'explicacion',
        ]);

        $plan = $this->planOn(self::FIRST);
        self::assertNotNull($plan);
        $topics = $plan->getTopics();
        self::assertCount(2, $topics);
        self::assertSame('La novela del siglo XX', $topics[0]->getTopic()?->getName());
        self::assertSame(LessonOutcome::DONE, $topics[0]->getOutcome());
        self::assertSame('Romanticismo', $topics[1]->getTopic()?->getName());
        self::assertNull($topics[1]->getOutcome(), 'el que se abre no tiene resultado todavía');
        // El resumen de la clase (el que da "seguimos igual" a la clase siguiente) es el que se abrió.
        self::assertSame('Romanticismo', $plan->getTopic()?->getName());
        self::assertCount(2, $this->em->getRepository(Topic::class)->findAll());
    }

    /** Editar una clase que ya tenía dos temas y quitar el segundo lo borra, no lo deja huérfano. */
    public function testRemovingTheSecondTopicOnEditDropsIt(): void
    {
        $this->save(self::FIRST, ['tema' => 'La novela del siglo XX', 'tema2' => 'Romanticismo']);
        $this->save(self::FIRST, ['tema' => 'La novela del siglo XX', 'tema2' => '']);

        $plan = $this->planOn(self::FIRST);
        self::assertNotNull($plan);
        self::assertCount(1, $plan->getTopics());
    }

    /**
     * Sends the plan form of the Monday class.
     *
     * @param string                $day    the Monday, "YYYY-MM-DD"
     * @param array<string, string> $fields the fields to send
     */
    private function save(string $day, array $fields): void
    {
        $crawler = $this->client->request('GET', '/mis-clases/'.$day.'/7');
        $this->client->submit($crawler->selectButton('Guardar')->form(), $fields + ['actividad' => '', 'resultado' => '', 'nota' => '']);
    }

    private function planOn(string $day): ?LessonPlan
    {
        $this->em->clear();

        return $this->em->getRepository(LessonPlan::class)->findOneBy(['date' => new \DateTimeImmutable($day), 'slotIndex' => 7]);
    }
}
