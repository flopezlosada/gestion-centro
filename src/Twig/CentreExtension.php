<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\AppSettings;
use Doctrine\DBAL\Exception as DatabaseException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * The centre's details every template may need, read from {@see AppSettings} so a change made in the
 * back-office shows on the next page instead of waiting for a deploy (a global in twig.yaml would be
 * frozen into the container).
 *
 * The names are the ones the templates already used when these were parameters, so none of them had
 * to change. Twig resolves globals once per request and {@see AppSettings} reads its handful of rows
 * in a single query, so a rendered page costs one small query more, not one per template.
 *
 * The error page extends the same layout, so it reads these too: with the database down it must still
 * render, which is why a database failure falls back to the environment instead of throwing.
 */
final class CentreExtension extends AbstractExtension implements GlobalsInterface
{
    /**
     * @param AppSettings $settings                the runtime settings
     * @param string      $defaultOrganizationName APP_ORG_NAME, for when the database cannot be read
     */
    public function __construct(
        private readonly AppSettings $settings,
        #[Autowire('%app.organization_name%')]
        private readonly string $defaultOrganizationName,
    ) {
    }

    /**
     * @return array{app_organization_name: string, app_raices_url: string} the centre's name and the
     *                                                                       address of RAICES (empty = no link)
     */
    public function getGlobals(): array
    {
        try {
            return [
                'app_organization_name' => $this->settings->getOrganizationName(),
                'app_raices_url' => $this->settings->getRaicesUrl(),
            ];
        } catch (DatabaseException) {
            return ['app_organization_name' => $this->defaultOrganizationName, 'app_raices_url' => ''];
        }
    }
}
