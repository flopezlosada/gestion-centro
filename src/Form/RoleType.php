<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Role;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Admin form to create/edit a role and its per-area permission matrix. One (unmapped) level
 * selector is added per {@see Area}; the controller writes the chosen levels back via
 * {@see Role::setLevel()}. Rendered expanded (radios) so the template can paint a segmented control.
 *
 * @extends AbstractType<Role>
 */
final class RoleType extends AbstractType
{
    /** Prefix of the unmapped per-area level fields, e.g. "perm_task". */
    public const PERMISSION_PREFIX = 'perm_';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'Identificador corto y estable, p. ej. "direccion" o "secretaria".',
            ])
            ->add('name', TextType::class, ['label' => 'Nombre'])
            ->add('description', TextareaType::class, ['label' => 'Descripción', 'required' => false])
            ->add('admin', CheckboxType::class, [
                'label' => 'Administrador',
                'required' => false,
                'help' => 'Acceso total a todas las áreas; ignora los niveles de abajo.',
            ]);

        // One permission selector per area, pre-filled with the role's current level. Unmapped: the
        // controller reads them and calls Role::setLevel(). Added on PRE_SET_DATA so the "data"
        // (current level) reflects the role being edited.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $form = $event->getForm();
            $role = $event->getData();

            foreach (Area::cases() as $area) {
                $form->add(self::PERMISSION_PREFIX.$area->value, EnumType::class, [
                    'class' => PermissionLevel::class,
                    'label' => $area->label(),
                    'mapped' => false,
                    'expanded' => true,
                    'data' => $role instanceof Role ? $role->getLevel($area) : PermissionLevel::NONE,
                    'choice_label' => static fn (PermissionLevel $level): string => $level->label(),
                ]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Role::class]);
    }
}
