<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Alta de una sustitución de baja larga: a quién se sustituye, quién lo hace y desde cuándo.
 *
 * **No está mapeado a {@see \App\Entity\Substitution}**, y no por comodidad. Quien sustituye llega casi
 * siempre sin cuenta, así que el formulario pide un nombre y un correo, no una persona ya existente;
 * pero puede ser también alguien que ya estuvo aquí, y en ese caso hay que reutilizar su cuenta y no
 * crear una segunda con el mismo correo. Esa decisión —persona nueva o persona que vuelve— la toma el
 * controlador mirando el correo, y un formulario mapeado le obligaría a construir el {@see User} antes
 * de saber cuál de los dos casos es.
 *
 * A quién se sustituye sale de una lista cerrada, y son las personas CON HORARIO en el curso: sin
 * horario no hay nada que traspasar, y ofrecer a todo el claustro sería ofrecer una operación que no
 * hace nada.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class SubstitutionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('substitutedTeacher', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'fullName',
                'label' => 'Persona a la que se sustituye',
                'placeholder' => '— Elige a la persona de baja —',
                'choices' => $options['teachers'],
                'help' => 'Solo aparece el profesorado con horario en este curso: es lo que se traspasa.',
                'constraints' => [new Assert\NotNull(message: 'Elige a la persona a la que se sustituye.')],
            ])
            ->add('substituteName', TextType::class, [
                'label' => 'Nombre y apellidos de quien sustituye',
                'constraints' => [
                    new Assert\NotBlank(message: 'Escribe el nombre de quien sustituye.'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('substituteEmail', EmailType::class, [
                'label' => 'Correo de quien sustituye',
                'help' => 'Con el que accederá al sistema. Si ya tiene cuenta en el centro, se reutiliza y no se crea otra.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Escribe el correo de quien sustituye.'),
                    new Assert\Email(message: 'Ese correo no tiene una forma válida.'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('startedOn', DateType::class, [
                'label' => 'Desde',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'El día en que empezó la baja. Ojo: el horario se traspasa a partir de HOY — los partes de guardia ya registrados no cambian.',
                'constraints' => [new Assert\NotNull(message: 'Indica desde cuándo.')],
            ])
            ->add('note', TextType::class, [
                'label' => 'Nota',
                'required' => false,
                'help' => 'Opcional: el motivo o la referencia del expediente.',
                'constraints' => [new Assert\Length(max: 255)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => null])
            // Las personas sustituibles las decide el controlador (las que tienen horario en el curso
            // elegido), no el formulario: aquí no hay forma de saber de qué curso se habla.
            ->setRequired('teachers')
            ->setAllowedTypes('teachers', 'array');
    }
}
