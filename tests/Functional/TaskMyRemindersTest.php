<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Department;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskReminderSchedule;
use App\Entity\User;
use App\Enum\TaskReminderRepeat;
use App\Enum\TaskReminderStart;
use App\Enum\TaskType;
use App\Repository\TaskReminderScheduleRepository;
use App\Support\TaskStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * «Mis avisos» on a task: whoever has to do it picks when they are reminded, nobody else can, and going
 * back to "lo de siempre" leaves no row behind.
 */
final class TaskMyRemindersTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email, Department $unit): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email)->setUnit($unit);
        $this->em->persist($user);

        return $user;
    }

    /**
     * @return array{Task, User, User} the task, whoever has to do it and an unrelated colleague
     */
    private function taskForATeacher(): array
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $teacher = $this->user('profe@centro.test', $unit);
        $colleague = $this->user('otra@centro.test', $unit);
        $task = (new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE))
            ->setUnit($unit)
            ->setAssignedUser($teacher);
        $this->em->persist($task);
        $this->em->flush();

        return [$task, $teacher, $colleague];
    }

    private function scheduleOf(int $taskId, int $userId): ?TaskReminderSchedule
    {
        $this->em->clear();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em->getRepository(TaskReminderSchedule::class)->findOneBy(['task' => $taskId, 'user' => $userId]);
    }

    public function testThePersonChoosesTheirOwnReminders(): void
    {
        [$task, $teacher] = $this->taskForATeacher();
        $taskId = (int) $task->getId();
        $teacherId = (int) $teacher->getId();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        self::assertResponseIsSuccessful();
        // Nothing chosen yet: no antelación is selected, so the menu shows its first entry, "lo de siempre".
        self::assertSelectorNotExists('select[name="start"] option[selected]');
        $form = $crawler->filter('form[action$="/mis-avisos"]')->form();
        $form['start'] = (string) TaskReminderStart::FIVE_DAYS->value;
        $form['repeat'] = (string) TaskReminderRepeat::EVERY_TWO_DAYS->value;
        $this->client->submit($form);

        self::assertResponseRedirects('/tareas/'.$taskId);
        $schedule = $this->scheduleOf($taskId, $teacherId);
        self::assertNotNull($schedule);
        self::assertSame([5, 3, 1], $schedule->daysBefore());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Te avisaremos desde 5 días antes, cada 2 días.');
        self::assertSelectorExists('select[name="start"] option[value="5"][selected]');
        self::assertSelectorExists('select[name="repeat"] option[value="2"][selected]');
    }

    public function testGoingBackToTheUsualRemindersDeletesTheRow(): void
    {
        [$task, $teacher] = $this->taskForATeacher();
        $this->em->persist(new TaskReminderSchedule($task, $teacher, TaskReminderStart::ONE_DAY, TaskReminderRepeat::ONCE));
        $this->em->flush();
        $taskId = (int) $task->getId();
        $teacherId = (int) $teacher->getId();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        $form = $crawler->filter('form[action$="/mis-avisos"]')->form();
        $form['start'] = '';
        $this->client->submit($form);

        self::assertResponseRedirects('/tareas/'.$taskId);
        self::assertNull($this->scheduleOf($taskId, $teacherId));
    }

    public function testSomebodyWhoSeesTheTaskButDoesNotDoItIsNotOffered(): void
    {
        // An administrator sees every task, but the reminders are not theirs to tune.
        [$task] = $this->taskForATeacher();
        $role = (new Role())->setCode('direction')->setName('Dirección')->setAdmin(true);
        $this->em->persist($role);
        $admin = (new User())->setFullName('Directora Test')->setEmail('director@centro.test')->addAssignedRole($role);
        $this->em->persist($admin);
        $this->em->flush();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/tareas/'.$task->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action$="/mis-avisos"]');
    }

    public function testADeliveredTaskNoLongerOffersNorAcceptsReminders(): void
    {
        // Delivered, it waits for the superior: no reminders go out, so there is nothing to tune — and a
        // form left open from before the delivery is refused on the server, not just hidden.
        [$task, $teacher] = $this->taskForATeacher();
        $taskId = (int) $task->getId();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        $token = (string) $crawler->filter('form[action$="/mis-avisos"] input[name="_token"]')->attr('value');

        $delivered = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Task::class)->find($taskId);
        self::assertNotNull($delivered);
        $delivered->setStatus(TaskStatus::SUBMITTED);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->request('GET', '/tareas/'.$taskId);
        self::assertSelectorNotExists('form[action$="/mis-avisos"]');

        $this->client->request('POST', '/tareas/'.$taskId.'/mis-avisos', ['_token' => $token, 'start' => '5', 'repeat' => '0']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], self::getContainer()->get(TaskReminderScheduleRepository::class)->findAll());
    }

    public function testAnAntelacionOutsideTheMenuIsRefused(): void
    {
        [$task, $teacher] = $this->taskForATeacher();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/tareas/'.$task->getId());
        $token = $crawler->filter('form[action$="/mis-avisos"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/tareas/'.$task->getId().'/mis-avisos', ['_token' => $token, 'start' => '4', 'repeat' => '0']);
        self::assertResponseStatusCodeSame(400);

        // A missing frequency must not quietly become "Solo una vez" (whose value is 0).
        $this->client->request('POST', '/tareas/'.$task->getId().'/mis-avisos', ['_token' => $token, 'start' => '5']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], self::getContainer()->get(TaskReminderScheduleRepository::class)->findAll());
    }
}
