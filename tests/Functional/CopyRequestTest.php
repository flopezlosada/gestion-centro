<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\CopyRequest;
use App\Entity\Department;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaTaskBankItem;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\EducationLevel;
use App\Enum\PermissionLevel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Ordering copies from conserjería. The order is recorded AND sent: the number of copies is required
 * (an order without it cannot be worked), what it is for is snapshotted so it survives the parte line,
 * and only the people a guardia concerns may order its copies.
 */
final class CopyRequestTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function login(string $email = 'profe@centro.test', bool $coordinator = false): User
    {
        $user = (new User())->setFullName('Docente '.$email)->setEmail($email);
        if ($coordinator) {
            $role = (new Role())->setCode('guardias-'.uniqid())->setName('Coordinación de guardias')->setLevel(Area::GUARDIAS, PermissionLevel::WRITE);
            $this->em->persist($role);
            $user->addAssignedRole($role);
        }
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    /**
     * A parte line with a task taken from the bank, so there is something to print.
     *
     * @param int|null $suggestedCopies what the department suggests for that bank task
     * @param int|null $copiesNeeded    what the absent teacher (or the coordination) said this class needs
     */
    private function coverWithBankTask(User $assigned, ?int $suggestedCopies = null, ?int $copiesNeeded = null): GuardiaCover
    {
        $department = (new Department())->setCode('maths')->setName('Matemáticas');
        $absent = (new User())->setFullName('Ausente')->setEmail('ausente-'.uniqid().'@centro.test');
        $year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-22'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-22'));
        $this->em->persist($department);
        $this->em->persist($absent);
        $this->em->persist($year);

        $item = (new GuardiaTaskBankItem())
            ->setAcademicYear($year)
            ->setDepartment($department)
            ->setLevel(EducationLevel::ESO_4)
            ->setSubject('Matemáticas')
            ->setTitle('Ficha de repaso')
            ->setSuggestedCopies($suggestedCopies);
        $this->em->persist($item);

        $date = new \DateTimeImmutable('2026-01-15');
        $absence = (new Absence())->setAbsentTeacher($absent)->setDate($date);
        $this->em->persist($absence);

        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate($date)
            ->setSlotIndex(2)
            ->setAbsentTeacher($absent)
            ->setAssignedGuardia($assigned)
            ->setGroupName('E4D')
            ->setRoomName('A12')
            ->setSubjectName('Matemáticas')
            ->setCopiesNeeded($copiesNeeded)
            ->setBankItem($item);
        $this->em->persist($cover);
        $this->em->flush();

        return $cover;
    }

    /**
     * The single order in the database, failing the test when there is not exactly one.
     */
    private function onlyOrder(): CopyRequest
    {
        $this->em->clear();
        $orders = $this->em->getRepository(CopyRequest::class)->findAll();
        self::assertCount(1, $orders);

        return $orders[0];
    }

    public function testOrderingCopiesForAGuardiaRecordsAndSendsIt(): void
    {
        $user = $this->login('guardia@centro.test');
        $cover = $this->coverWithBankTask($user, suggestedCopies: 28);
        $coverId = (int) $cover->getId();

        $crawler = $this->client->request('GET', '/fotocopias/guardia/'.$coverId);
        self::assertResponseIsSuccessful();
        // The bank task's usual number of copies is prefilled, but stays editable.
        self::assertSame('28', $crawler->filter('input[name="copy_request[copies]"]')->attr('value'));

        $token = (string) $crawler->filter('input[name="copy_request[_token]"]')->attr('value');
        $this->client->request('POST', '/fotocopias/guardia/'.$coverId, ['copy_request' => [
            'copies' => '30',
            'notes' => 'A doble cara',
            '_token' => $token,
        ]]);

        self::assertResponseRedirects('/fotocopias');
        self::assertEmailCount(1);

        $order = $this->onlyOrder();
        self::assertSame(30, $order->getCopies());
        self::assertTrue($order->isSent());
        // What the copies are for is snapshotted, subject and group included, so the mailbox reads alone.
        self::assertStringContainsString('E4D', $order->getContext());
        self::assertStringContainsString('4º de ESO', $order->getContext());
        self::assertStringContainsString('Matemáticas', $order->getContext());
        self::assertSame($coverId, $order->getCover()?->getId());
    }

    public function testWhatTheAbsentTeacherSaidWinsOverTheBankSuggestion(): void
    {
        // El profesor que falta conoce su grupo: si dejó dicho el número, es el que se propone.
        $user = $this->login('guardia@centro.test');
        $cover = $this->coverWithBankTask($user, suggestedCopies: 28, copiesNeeded: 31);

        $crawler = $this->client->request('GET', '/fotocopias/guardia/'.$cover->getId());

        self::assertResponseIsSuccessful();
        self::assertSame('31', $crawler->filter('input[name="copy_request[copies]"]')->attr('value'));
    }

    public function testTheNumberOfCopiesIsRequired(): void
    {
        $user = $this->login('guardia@centro.test');
        $cover = $this->coverWithBankTask($user);
        $coverId = (int) $cover->getId();

        $crawler = $this->client->request('GET', '/fotocopias/guardia/'.$coverId);
        $token = (string) $crawler->filter('input[name="copy_request[_token]"]')->attr('value');
        $this->client->request('POST', '/fotocopias/guardia/'.$coverId, ['copy_request' => [
            'copies' => '',
            '_token' => $token,
        ]]);

        // The form comes back (422 under the project's invalid-submit convention) and nothing is sent.
        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
        self::assertCount(0, $this->em->getRepository(CopyRequest::class)->findAll());
    }

    public function testAStandaloneOrderCarriesTheUploadedDocument(): void
    {
        $this->login();

        $crawler = $this->client->request('GET', '/fotocopias/nuevo');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="copy_request[_token]"]')->attr('value');

        $path = sys_get_temp_dir().'/encargo-'.uniqid().'.txt';
        file_put_contents($path, 'contenido');

        $this->client->request(
            'POST',
            '/fotocopias/nuevo',
            ['copy_request' => ['context' => 'Examen de 2º ESO B', 'copies' => '25', '_token' => $token]],
            ['copy_request' => ['document' => new UploadedFile($path, 'examen.txt', 'text/plain', null, true)]],
        );

        self::assertResponseRedirects('/fotocopias');
        self::assertEmailCount(1);

        $order = $this->onlyOrder();
        self::assertSame('Examen de 2º ESO B', $order->getContext());
        self::assertSame(25, $order->getCopies());
        self::assertSame('examen.txt', $order->getDocumentName());
        self::assertTrue($order->isSent());
    }

    public function testAStandaloneOrderNeedsADocument(): void
    {
        $this->login();

        $crawler = $this->client->request('GET', '/fotocopias/nuevo');
        $token = (string) $crawler->filter('input[name="copy_request[_token]"]')->attr('value');
        $this->client->request('POST', '/fotocopias/nuevo', ['copy_request' => [
            'context' => 'Algo',
            'copies' => '10',
            '_token' => $token,
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
    }

    public function testAnotherTeacherCannotOrderCopiesForSomeoneElsesGuardia(): void
    {
        $covering = (new User())->setFullName('Quien cubre')->setEmail('cubre@centro.test');
        $this->em->persist($covering);
        $cover = $this->coverWithBankTask($covering);

        $this->login('curioso@centro.test');
        $this->client->request('GET', '/fotocopias/guardia/'.$cover->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testEveryoneSeesTheirOwnOrdersAndTheCoordinationSeesThemAll(): void
    {
        $mine = $this->login('mia@centro.test');
        $other = (new User())->setFullName('Otra')->setEmail('otra@centro.test');
        $this->em->persist($other);
        $this->em->persist((new CopyRequest())->setRequestedBy($mine)->setCopies(10)->setContext('Lo mío')->setRecipient('fotocopias@centro.test'));
        $this->em->persist((new CopyRequest())->setRequestedBy($other)->setCopies(5)->setContext('Lo de otra')->setRecipient('fotocopias@centro.test'));
        $this->em->flush();

        $text = $this->client->request('GET', '/fotocopias')->filter('table')->text();
        self::assertStringContainsString('Lo mío', $text);
        self::assertStringNotContainsString('Lo de otra', $text);

        $this->login('coordina@centro.test', coordinator: true);
        $text = $this->client->request('GET', '/fotocopias')->filter('table')->text();
        self::assertStringContainsString('Lo mío', $text);
        self::assertStringContainsString('Lo de otra', $text);
    }
}
