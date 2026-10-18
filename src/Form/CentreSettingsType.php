<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\AppSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The centre's details: its name, the copy room and management mailboxes, the RAICES link, and which
 * optional modules are in use.
 *
 * A mailbox field is one line of text that accepts several addresses however they are typed (commas,
 * semicolons, spaces), turned into a list here so the rest of the application never parses strings.
 *
 * @extends AbstractType<CentreSettingsFormData>
 */
final class CentreSettingsType extends AbstractType
{
    /**
     * Adds the fields and turns each mailbox field into a list of addresses.
     *
     * @param FormBuilderInterface<CentreSettingsFormData|null> $builder the form builder
     * @param array<string, mixed>                              $options the form options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('organizationName', TextType::class, [
                'label' => 'Nombre del centro',
                'help' => 'Sale en la cabecera, en el título de cada página, en las actas y en los correos.',
                'empty_data' => '',
            ])
            ->add('copyShopEmails', TextType::class, [
                'label' => 'Correo de conserjería',
                'help' => 'Adonde llegan los encargos de fotocopias. Si son varios, sepáralos con comas.',
                'attr' => ['inputmode' => 'email', 'autocomplete' => 'off'],
            ])
            ->add('directionEmails', TextType::class, [
                'label' => 'Correo del equipo directivo',
                'required' => false,
                'help' => 'Los encargos de fotocopias salen a su nombre y las respuestas de conserjería llegan aquí. Si lo dejas vacío, las respuestas van a quien pidió las copias. Si son varios, sepáralos con comas.',
                'attr' => ['inputmode' => 'email', 'autocomplete' => 'off'],
            ])
            ->add('raicesUrl', UrlType::class, [
                'label' => 'Enlace a RAICES',
                'required' => false,
                'help' => 'Se abre desde el aviso «apunta las ausencias del alumnado» de cada guardia. Vacío: el aviso sale igual, sin enlace.',
                'default_protocol' => null,
                'empty_data' => '',
            ])
            ->add('familyActivitiesEnabled', CheckboxType::class, [
                'label' => 'Actividades con familias',
                'required' => false,
                'help' => 'El profesorado publica actividades abiertas a familias. Apagado, desaparece del menú y sus páginas no se abren; lo publicado se conserva.',
            ]);

        foreach (['copyShopEmails', 'directionEmails'] as $field) {
            $builder->get($field)->addModelTransformer(new CallbackTransformer(
                static fn (?array $addresses): string => AppSettings::joinAddresses($addresses ?? []),
                static fn (?string $typed): array => AppSettings::splitAddresses($typed ?? ''),
            ));
        }
    }

    /**
     * Binds the form to {@see CentreSettingsFormData}.
     *
     * @param OptionsResolver $resolver the options resolver
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CentreSettingsFormData::class]);
    }
}
