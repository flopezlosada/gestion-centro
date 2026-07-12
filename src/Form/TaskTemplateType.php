<?php

declare(strict_types=1);

namespace App\Form;

use App\DueDate\DueDateRule;
use App\DueDate\FixedDate;
use App\DueDate\MonthlyOnDay;
use App\DueDate\NthWeekdayOfMonth;
use App\DueDate\PerTerm;
use App\DueDate\RelativeToAnchor;
use App\Entity\Role;
use App\Entity\TaskTemplate;
use App\Enum\CalendarAnchor;
use App\Enum\DueDateRuleKind;
use App\Enum\TaskType;
use App\Enum\TermBoundary;
use App\Enum\Weekday;
use App\Enum\WeekOrdinal;
use App\Repository\RoleRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Admin form to create/edit a {@see TaskTemplate} of the recurring-task catalogue: what the task is,
 * how it behaves, who is responsible, and — optionally — the rule that computes its due date each
 * course.
 *
 * The deadline rule is polymorphic, so it is handled with unmapped fields and form events: on load
 * the stored {@see \App\DueDate\DueDateRule} is decomposed into the parameter fields; on submit the
 * fields for the chosen kind are recombined into a value object (an incomplete or invalid choice
 * becomes a form error, never a 500). All parameter fields are shown at once (there is no client
 * build step); only those relevant to the chosen kind are read.
 *
 * @extends AbstractType<TaskTemplate>
 */
final class TaskTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Título'])
            ->add('description', TextareaType::class, ['label' => 'Descripción', 'required' => false])
            ->add('type', EnumType::class, [
                'class' => TaskType::class,
                'label' => 'Tipo',
                'choice_label' => static fn (TaskType $t): string => $t->label(),
                'help' => 'Simple, o con entregable (añade un paso intermedio de entrega).',
            ])
            ->add('mandatory', CheckboxType::class, [
                'label' => 'Obligatoria',
                'required' => false,
                'help' => 'Las obligatorias se asignan de arriba abajo; las voluntarias puede cogerlas cada persona.',
            ])
            ->add('responsibleRole', EntityType::class, [
                'class' => Role::class,
                'choice_label' => 'name',
                'label' => 'Rol responsable',
                'required' => false,
                'placeholder' => '— Se decide en cada instancia —',
                'query_builder' => static fn (RoleRepository $repo) => $repo->createQueryBuilder('r')->orderBy('r.name', 'ASC'),
            ])
            ->add('requiresDocument', CheckboxType::class, ['label' => 'Requiere documento entregable', 'required' => false])
            ->add('requiresCheckbox', CheckboxType::class, ['label' => 'Requiere casilla de "hecho"', 'required' => false])
            ->add('active', CheckboxType::class, [
                'label' => 'Activa (se instancia en nuevos cursos)',
                'required' => false,
            ]);

        $this->addRuleFields($builder);

        // Load: break the stored rule apart into the parameter fields.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $template = $event->getData();
            $rule = $template instanceof TaskTemplate ? $template->getDueDateRule() : null;
            if (null === $rule) {
                return;
            }

            $form = $event->getForm();
            $data = $rule->toArray();
            $form->get('ruleKind')->setData($rule->kind());
            match ($rule->kind()) {
                DueDateRuleKind::FIXED => self::fill($form, ['ruleMonth' => $data['month'], 'ruleDay' => $data['day']]),
                DueDateRuleKind::NTH_WEEKDAY => self::fill($form, [
                    'ruleOrdinal' => WeekOrdinal::from((string) $data['ordinal']),
                    'ruleWeekday' => Weekday::from((int) $data['weekday']),
                    'ruleMonth' => $data['month'],
                ]),
                DueDateRuleKind::RELATIVE_TO_ANCHOR => self::fill($form, [
                    'ruleAnchor' => CalendarAnchor::from((string) $data['anchor']),
                    'ruleOffsetDays' => $data['offsetDays'],
                ]),
                DueDateRuleKind::MONTHLY => self::fill($form, ['ruleDay' => $data['day']]),
                DueDateRuleKind::PER_TERM => self::fill($form, ['ruleBoundary' => TermBoundary::from((string) $data['boundary'])]),
            };
        });

        // Submit: recombine the fields for the chosen kind into a value object.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $template = $event->getData();
            if (!$template instanceof TaskTemplate) {
                return;
            }

            $form = $event->getForm();
            $kind = $form->get('ruleKind')->getData();

            try {
                $template->setDueDateRule($this->buildRule($form, $kind));
            } catch (\InvalidArgumentException $e) {
                $form->get('ruleKind')->addError(new FormError($e->getMessage()));
            }
        });
    }

    /**
     * Adds the unmapped parameter fields shared by the different rule kinds.
     *
     * @param FormBuilderInterface<mixed> $builder the template form builder
     */
    private function addRuleFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('ruleKind', EnumType::class, [
                'class' => DueDateRuleKind::class,
                'label' => 'Regla de fecha límite',
                'mapped' => false,
                'required' => false,
                'placeholder' => '— Sin regla (fecha a mano cada curso) —',
                'choice_label' => static fn (DueDateRuleKind $k): string => $k->label(),
                'help' => 'Cómo se calcula el plazo al generar el curso. Rellena solo los campos del tipo elegido.',
            ])
            ->add('ruleMonth', IntegerType::class, ['label' => 'Mes (1-12)', 'mapped' => false, 'required' => false])
            ->add('ruleDay', IntegerType::class, ['label' => 'Día del mes (1-31)', 'mapped' => false, 'required' => false])
            ->add('ruleWeekday', EnumType::class, [
                'class' => Weekday::class,
                'label' => 'Día de la semana',
                'mapped' => false,
                'required' => false,
                'placeholder' => '—',
                'choice_label' => static fn (Weekday $d): string => $d->label(),
            ])
            ->add('ruleOrdinal', EnumType::class, [
                'class' => WeekOrdinal::class,
                'label' => 'Ocurrencia',
                'mapped' => false,
                'required' => false,
                'placeholder' => '—',
                'choice_label' => static fn (WeekOrdinal $o): string => $o->label(),
            ])
            ->add('ruleAnchor', EnumType::class, [
                'class' => CalendarAnchor::class,
                'label' => 'Hito del curso',
                'mapped' => false,
                'required' => false,
                'placeholder' => '—',
                'choice_label' => static fn (CalendarAnchor $a): string => $a->label(),
            ])
            ->add('ruleOffsetDays', IntegerType::class, [
                'label' => 'Desplazamiento (días; negativo = antes)',
                'mapped' => false,
                'required' => false,
            ])
            ->add('ruleBoundary', EnumType::class, [
                'class' => TermBoundary::class,
                'label' => 'Inicio o fin de trimestre',
                'mapped' => false,
                'required' => false,
                'placeholder' => '—',
                'choice_label' => static fn (TermBoundary $b): string => $b->label(),
            ]);
    }

    /**
     * Builds the deadline rule for the chosen kind from the submitted fields, or null when no kind is
     * chosen. Missing fields for the kind raise an {@see \InvalidArgumentException}, surfaced as a
     * form error by the caller.
     *
     * @param FormInterface<mixed>  $form the submitted template form
     * @param DueDateRuleKind|null  $kind the chosen rule kind, or null for "no rule"
     *
     * @return DueDateRule|null the rule, or null
     */
    private function buildRule(FormInterface $form, ?DueDateRuleKind $kind): ?DueDateRule
    {
        return match ($kind) {
            null => null,
            DueDateRuleKind::FIXED => new FixedDate($this->int($form, 'ruleMonth'), $this->int($form, 'ruleDay')),
            DueDateRuleKind::NTH_WEEKDAY => new NthWeekdayOfMonth(
                $this->ordinal($form),
                $this->weekday($form),
                $this->int($form, 'ruleMonth'),
            ),
            DueDateRuleKind::RELATIVE_TO_ANCHOR => new RelativeToAnchor($this->anchor($form), $this->int($form, 'ruleOffsetDays')),
            DueDateRuleKind::MONTHLY => new MonthlyOnDay($this->int($form, 'ruleDay')),
            DueDateRuleKind::PER_TERM => new PerTerm($this->boundary($form)),
        };
    }

    /**
     * @param FormInterface<mixed> $form  the submitted form
     * @param string               $field the field name to read
     *
     * @throws \InvalidArgumentException if the field is empty or not numeric
     */
    private function int(FormInterface $form, string $field): int
    {
        $value = $form->get($field)->getData();
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('Faltan datos de la regla de fecha para el tipo elegido.');
        }

        return (int) $value;
    }

    /**
     * @param FormInterface<mixed> $form the submitted form
     *
     * @throws \InvalidArgumentException if the field is empty
     */
    private function ordinal(FormInterface $form): WeekOrdinal
    {
        $value = $form->get('ruleOrdinal')->getData();
        if (!$value instanceof WeekOrdinal) {
            throw new \InvalidArgumentException('Indica la ocurrencia (primer, último…).');
        }

        return $value;
    }

    /**
     * @param FormInterface<mixed> $form the submitted form
     *
     * @throws \InvalidArgumentException if the field is empty
     */
    private function weekday(FormInterface $form): Weekday
    {
        $value = $form->get('ruleWeekday')->getData();
        if (!$value instanceof Weekday) {
            throw new \InvalidArgumentException('Indica el día de la semana.');
        }

        return $value;
    }

    /**
     * @param FormInterface<mixed> $form the submitted form
     *
     * @throws \InvalidArgumentException if the field is empty
     */
    private function anchor(FormInterface $form): CalendarAnchor
    {
        $value = $form->get('ruleAnchor')->getData();
        if (!$value instanceof CalendarAnchor) {
            throw new \InvalidArgumentException('Indica el hito del curso.');
        }

        return $value;
    }

    /**
     * @param FormInterface<mixed> $form the submitted form
     *
     * @throws \InvalidArgumentException if the field is empty
     */
    private function boundary(FormInterface $form): TermBoundary
    {
        $value = $form->get('ruleBoundary')->getData();
        if (!$value instanceof TermBoundary) {
            throw new \InvalidArgumentException('Indica si es inicio o fin de trimestre.');
        }

        return $value;
    }

    /**
     * Sets the data of several form fields at once.
     *
     * @param FormInterface<mixed>  $form   the parent form
     * @param array<string, mixed>  $values field name → value
     */
    private static function fill(FormInterface $form, array $values): void
    {
        foreach ($values as $field => $value) {
            $form->get($field)->setData($value);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TaskTemplate::class,
        ]);
    }
}
