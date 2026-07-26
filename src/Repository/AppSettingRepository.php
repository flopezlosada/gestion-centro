<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppSetting>
 */
class AppSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSetting::class);
    }

    /**
     * Every stored setting, keyed by name. There is a handful of them at most, so they are read in
     * one query and cached for the request instead of hitting the database per lookup.
     *
     * @return array<string, AppSetting> the settings, keyed by their name
     */
    public function findAllIndexed(): array
    {
        $indexed = [];
        foreach ($this->findAll() as $setting) {
            $indexed[$setting->getName()] = $setting;
        }

        return $indexed;
    }
}
