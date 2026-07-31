<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Room;
use App\Entity\TicIncident;
use App\Enum\IncidentPriority;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Reporting a TIC incident. The group is asked for only when the equipment belongs to a class: ticking
 * "es de uso individual" clears it here, on the server, and not only by hiding the field with JS — the
 * entity refuses to hold both anyway ({@see TicIncident::markIndividualUse()}), and this is what keeps a
 * submission with JS disabled from writing a group into an incident that says it has none.
 *
 * @extends AbstractType<TicIncident>
 */
final class TicIncidentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('equipment', TextType::class, [
                'label' => '¿Qué equipo es?',
                'help' => 'Como lo llamáis vosotros: «proyector», «ordenador 12», «carro de portátiles B».',
            ])
            ->add('room', EntityType::class, [
                'label' => 'Aula o espacio',
                'class' => Room::class,
                'choices' => $options['rooms'],
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— Sin aula concreta —',
            ])
            ->add('occurredAt', DateTimeType::class, [
                'label' => '¿Cuándo pasó?',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'El día y la hora, para saber qué clase había.',
            ])
            ->add('priority', EnumType::class, [
                'class' => IncidentPriority::class,
                'label' => 'Prioridad',
                'expanded' => true,
                'choice_label' => static fn (IncidentPriority $p): string => sprintf('%s — %s', $p->label(), $p->hint()),
            ])
            ->add('individualUse', CheckboxType::class, [
                'label' => 'Es un equipo de uso individual, no de un grupo',
                'required' => false,
                'mapped' => false,
                'help' => 'Márcalo si no lo estaba usando una clase: entonces no hace falta decir el grupo.',
            ])
            ->add('groupName', TextType::class, [
                'label' => 'Grupo que estaba en el aula',
                'required' => false,
                'mapped' => false,
                'help' => 'Por ejemplo «2ºB». Déjalo vacío si no lo sabes.',
                'row_attr' => ['data-group-step' => '1'],
            ])
            ->add('description', TextareaType::class, [
                'label' => '¿Qué pasa?',
                'help' => 'Lo que se ve: qué has intentado y qué hace el equipo.',
            ]);

        // Las dos casillas no mapeadas se vuelcan a la entidad por sus DOS puertas, que se excluyen entre
        // sí: así "de uso individual y del grupo 2ºB" no llega nunca a persistirse.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $incident = $event->getData();
            $form = $event->getForm();
            if (!$incident instanceof TicIncident) {
                return;
            }

            if (true === $form->get('individualUse')->getData()) {
                $incident->markIndividualUse();

                return;
            }

            $incident->markGroupUse($form->get('groupName')->getData());
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TicIncident::class,
            'rooms' => [],
        ]);
        $resolver->setAllowedTypes('rooms', 'array');
    }
}
