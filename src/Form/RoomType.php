<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Room;
use App\Enum\RoomKind;
use App\Enum\RoomSize;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form to create or complete a {@see Room}. Most cards arrive auto-created from the timetable with
 * only a code, so the labels are written for someone COMPLETING a card rather than inventing one.
 *
 * @extends AbstractType<Room>
 */
final class RoomType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'El que usa el horario de Peñalara, p. ej. "2IN5" o "S ACTOS". Si no coincide, el espacio no se cruzará con las clases.',
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'help' => 'Cómo lo llamáis en el centro, p. ej. "Aula de Inglés 5".',
            ])
            ->add('kind', EnumType::class, [
                'label' => 'Tipo',
                'class' => RoomKind::class,
                'choices' => RoomKind::inDisplayOrder(),
                'choice_label' => static fn (RoomKind $kind): string => $kind->label(),
            ])
            ->add('size', EnumType::class, self::sizeOptions())
            ->add('capacity', IntegerType::class, [
                'label' => 'Plazas (opcional)',
                'required' => false,
                'help' => 'Solo si necesitáis el número exacto de personas (aulas pequeñas específicas, encargos de fotocopias).',
            ])
            ->add('building', TextType::class, [
                'label' => 'Edificio o ala',
                'required' => false,
                'help' => 'Opcional. Sirve para no mandar a un grupo al otro extremo del centro.',
            ])
            ->add('floor', IntegerType::class, [
                'label' => 'Planta',
                'required' => false,
                'help' => 'Opcional. 0 = baja.',
            ])
            ->add('assignable', CheckboxType::class, [
                'label' => 'Se le pueden enviar grupos',
                'required' => false,
                'help' => 'Desmárcalo para espacios donde no se puede recolocar un grupo: pistas y gimnasio (que Peñalara sí llama aulas), almacenes, o los que el centro reserve.',
            ])
            ->add('reservable', CheckboxType::class, [
                'label' => 'Se puede reservar',
                'required' => false,
                'help' => 'Si está marcado, cualquiera del claustro puede cogerlo una hora desde «Reservas». Desmárcalo para lo que no se reserva: laboratorios, talleres, gimnasio y pistas, que los organiza su departamento.',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'En uso',
                'required' => false,
                'help' => 'Desmárcalo cuando el espacio deje de usarse. No se borra: el horario de cursos anteriores lo sigue nombrando.',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas',
                'required' => false,
                'help' => 'Lo que conviene saber antes de mandar a alguien: "sin proyector", "la llave está en conserjería".',
                'attr' => ['rows' => 3],
            ]);

        // The timetable's own evidence, offered as a starting point: filling the size in is then confirming
        // or correcting a figure instead of estimating one from memory. Done in PRE_SET_DATA because it
        // depends on the card being edited, and only when there IS evidence — an empty "según el horario"
        // would be worse than no hint at all.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $room = $event->getData();
            $observed = $room instanceof Room ? $room->getObservedGroups() : null;
            if (null === $observed) {
                return;
            }

            $event->getForm()->add('size', EnumType::class, self::sizeOptions(sprintf(
                'Cuántos grupos caben. El horario ha llegado a poner %d %s a la vez aquí, así que al menos esos caben; corrige si sabes más.',
                $observed,
                1 === $observed ? 'grupo' : 'grupos',
            )));
        });
    }

    /**
     * The size field's options, declared once because the field is added twice: plainly, and again with
     * the timetable's suggestion in its help text.
     *
     * @param string|null $help the help text, or null for the plain one
     *
     * @return array<string, mixed> the field options
     */
    private static function sizeOptions(?string $help = null): array
    {
        return [
            'label' => 'Tamaño',
            'class' => RoomSize::class,
            'choices' => RoomSize::inDisplayOrder(),
            'choice_label' => static fn (RoomSize $s): string => $s->label(),
            'required' => false,
            'placeholder' => 'Sin indicar',
            'help' => $help ?? 'Cuántos grupos caben. Es lo que usa el programa para recolocar: sin esto no puede avisar de que un grupo no cabe.',
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Room::class]);
    }
}
