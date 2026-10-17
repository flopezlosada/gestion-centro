<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\AppSettings;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form-backing object for the centre's details kept in {@see AppSettings}. The mailboxes arrive as
 * lists, already split by {@see CentreSettingsType}, so each address is validated on its own and the
 * error can say which one is wrong.
 */
final class CentreSettingsFormData
{
    /**
     * What a stored list of mailboxes may take: the copy room's is snapshotted onto every order
     * ({@see \App\Entity\CopyRequest::$recipient}, 180 characters), the rest fit a setting (255).
     */
    private const MAX_COPY_SHOP_LENGTH = 180;
    private const MAX_SETTING_LENGTH = 255;

    #[Assert\NotBlank(message: 'Escribe el nombre del centro.')]
    #[Assert\Length(max: self::MAX_SETTING_LENGTH)]
    public string $organizationName = '';

    /** @var list<string> */
    #[Assert\Count(min: 1, minMessage: 'Escribe al menos un buzón de conserjería: sin él, los encargos de fotocopias no llegan a nadie.')]
    #[Assert\All([new Assert\Email(message: '{{ value }} no es una dirección de correo.')])]
    public array $copyShopEmails = [];

    /** @var list<string> */
    #[Assert\All([new Assert\Email(message: '{{ value }} no es una dirección de correo.')])]
    public array $directionEmails = [];

    /** Only http(s): it is opened from a notice in the middle of a guardia. */
    #[Assert\Url(message: 'Pega el enlace completo (empieza por https://).', protocols: ['https', 'http'], requireTld: true)]
    #[Assert\Length(max: self::MAX_SETTING_LENGTH)]
    public string $raicesUrl = '';

    /**
     * Prefills the form with what the application is using now, whether saved here or still coming
     * from the environment.
     *
     * @param AppSettings $settings the runtime settings
     *
     * @return self the prefilled form data
     */
    public static function fromSettings(AppSettings $settings): self
    {
        $data = new self();
        $data->organizationName = $settings->getOrganizationName();
        $data->copyShopEmails = $settings->getCopyShopEmails();
        $data->directionEmails = $settings->getDirectionEmails();
        $data->raicesUrl = $settings->getRaicesUrl();

        return $data;
    }

    /**
     * Rejects a list of mailboxes too long to store as a whole, which no per-address rule can catch.
     *
     * @param ExecutionContextInterface $context the validation context to attach violations to
     */
    #[Assert\Callback]
    public function validateListLengths(ExecutionContextInterface $context): void
    {
        foreach (['copyShopEmails' => self::MAX_COPY_SHOP_LENGTH, 'directionEmails' => self::MAX_SETTING_LENGTH] as $field => $max) {
            if (mb_strlen(AppSettings::joinAddresses($this->{$field})) > $max) {
                $context->buildViolation(sprintf('Son demasiados buzones: caben %d caracteres en total.', $max))
                    ->atPath($field)
                    ->addViolation();
            }
        }
    }
}
