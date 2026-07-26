<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single runtime setting, stored as a name/value pair so an administrator can change the
 * application's behaviour from the back-office without a deploy (unlike the parameters in
 * services.yaml, which are baked into the container at build time).
 *
 * Deliberately untyped at the storage level: the type safety lives in {@see \App\Service\AppSettings},
 * the only class allowed to read or write these rows, which exposes one typed accessor per setting.
 * That keeps a new setting to a constant plus two methods instead of a schema migration.
 *
 * Auditable: flipping one of these changes how the whole application behaves for everybody, so who
 * did it and when belongs in the activity trail.
 */
#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
#[ORM\Table(name: 'app_setting')]
class AppSetting implements Auditable
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $name;

    /** Column named setting_value: "value" is a keyword in several engines and Doctrine would quote it. */
    #[ORM\Column(name: 'setting_value', length: 255)]
    private string $value;

    public function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }
}
