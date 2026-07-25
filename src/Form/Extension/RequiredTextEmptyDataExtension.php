<?php

declare(strict_types=1);

namespace App\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Makes every REQUIRED text field yield an empty string instead of null when it is submitted empty,
 * so an empty submit is reported as a validation error instead of blowing up with an HTTP 500.
 *
 * Why: Symfony's Form::viewToNorm() turns an empty submitted string into null for any field without a
 * view transformer (vendor/symfony/form/Form.php:998-1002), and TextType only adds the transformer
 * that maps that null back to '' when `empty_data` is explicitly '' (vendor TextType::buildForm).
 * Mapping that null into a non-nullable `string` property — Task/PersonalEvent title, Department name,
 * User fullName… — raises a TypeError inside the property accessor BEFORE the validator can report the
 * blank field: guardar el formulario de tarea vacío devolvía un 500, no el aviso de campo obligatorio.
 * Every one of those fields already carries a NotBlank constraint, so '' is rejected with a readable
 * message instead of being stored.
 *
 * Optional fields (`required: false`) keep the framework default, so "no value" (null) stays
 * distinguishable from "empty text" for nullable columns.
 *
 * Applies to TextType and every type built on it (TextareaType, EmailType, UrlType…).
 */
final class RequiredTextEmptyDataExtension extends AbstractTypeExtension
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault(
            'empty_data',
            static fn (Options $options, mixed $previousValue): mixed => true === $options['required'] ? '' : $previousValue,
        );
    }

    public static function getExtendedTypes(): iterable
    {
        return [TextType::class];
    }
}
