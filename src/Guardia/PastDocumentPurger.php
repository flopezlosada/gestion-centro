<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\CopyRequest;
use App\Entity\GuardiaCover;
use App\Repository\CopyRequestRepository;
use App\Repository\GuardiaCoverRepository;
use App\Service\FileUploader;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/**
 * Deletes the documents of copy orders whose day is over, and the task documents of guardias from a past
 * school year.
 *
 * A copy order's file exists for that day only: the copy room gets it by e-mail and prints from there.
 * A guardia's task document is kept for the whole course, so the centre can look back at the work left
 * for a group; once the course is over it is just pupils' data waiting around (an adapted exam carries a
 * name). The bank of tasks is not touched: its documents are the departments' material, meant to be reused.
 *
 * The records stay, marked as purged, so a past guardia still says it had a document. The files go only
 * after the change is committed: a failed flush must not leave a row pointing at a file already gone. Each
 * file is removed on its own, so one that resists does not leave the rest on disk — the row is already
 * marked and no later run would find them.
 */
final class PastDocumentPurger
{
    public function __construct(
        private readonly GuardiaCoverRepository $covers,
        private readonly CopyRequestRepository $orders,
        private readonly EntityManagerInterface $em,
        private readonly FileUploader $uploader,
    ) {
    }

    /**
     * Purges the copy orders' documents from before today and the guardias' from before this course.
     *
     * @param \DateTimeImmutable $now the moment of the run
     *
     * @return array{covers: int, orders: int, unremoved: list<string>} how many documents of each kind were
     *                                                                   purged, and the files that could not
     *                                                                   be deleted from disk
     */
    public function purge(\DateTimeImmutable $now): array
    {
        $coverFiles = array_values(array_filter(array_map(
            static fn (GuardiaCover $cover): ?string => $cover->purgeTaskDocument($now),
            $this->covers->findWithTaskDocumentBefore(SchoolYear::bounds(SchoolYear::current($now))[0]),
        )));
        $orderFiles = array_merge(...array_map(
            static fn (CopyRequest $order): array => $order->purgeDocuments($now),
            $this->orders->findWithDocumentBefore($now),
        ));

        $this->em->flush();
        $unremoved = array_values(array_filter(
            [...$coverFiles, ...$orderFiles],
            fn (string $path): bool => !$this->removed($path),
        ));

        return ['covers' => \count($coverFiles), 'orders' => \count($orderFiles), 'unremoved' => $unremoved];
    }

    /**
     * Deletes one file, telling whether it worked instead of stopping the rest.
     */
    private function removed(string $path): bool
    {
        try {
            $this->uploader->remove($path);

            return true;
        } catch (IOExceptionInterface) {
            return false;
        }
    }
}
