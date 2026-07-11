<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Unit;
use App\Entity\User;
use App\Repository\UnitRepository;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Admin form to create/edit an org-chart {@see Unit}: its code/name, its place in the chain of
 * command (parent) and its manager. The unit being edited — and its whole subtree — is dropped
 * from the parent choices so a cycle in the chain of command cannot be created.
 *
 * @extends AbstractType<Unit>
 */
final class UnitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $current = $options['current_unit'];
        $excludedIds = $current instanceof Unit ? self::subtreeIds($current) : [];

        $builder
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'Identificador corto y estable, p. ej. "matematicas" o "jefatura_estudios".',
            ])
            ->add('name', TextType::class, ['label' => 'Nombre'])
            ->add('description', TextareaType::class, ['label' => 'Descripción', 'required' => false])
            ->add('active', CheckboxType::class, [
                'label' => 'Activa (se usa para nuevas asignaciones)',
                'required' => false,
                'help' => 'Al desactivarla se conserva el histórico, pero deja de usarse para asignar tareas nuevas.',
            ])
            ->add('parent', EntityType::class, [
                'class' => Unit::class,
                'choice_label' => 'name',
                'label' => 'Unidad superior',
                'required' => false,
                'placeholder' => '— Sin superior (unidad raíz) —',
                'help' => 'La unidad inmediatamente por encima en la cadena de mando.',
                'query_builder' => static function (UnitRepository $repo) use ($excludedIds) {
                    $qb = $repo->createQueryBuilder('u')->orderBy('u.name', 'ASC');
                    if ([] !== $excludedIds) {
                        $qb->where('u.id NOT IN (:excluded)')->setParameter('excluded', $excludedIds);
                    }

                    return $qb;
                },
            ])
            ->add('manager', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'fullName',
                'label' => 'Responsable',
                'required' => false,
                'placeholder' => '— Sin responsable —',
                'help' => 'Quien valida las tareas de esta unidad y recibe los escalados.',
                'query_builder' => static fn (UserRepository $repo) => $repo->createQueryBuilder('u')->orderBy('u.fullName', 'ASC'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Unit::class,
            // The unit being edited, so its subtree can be excluded from the parent choices. Null on
            // creation (an unpersisted unit has no descendants yet).
            'current_unit' => null,
        ]);
        $resolver->setAllowedTypes('current_unit', ['null', Unit::class]);
    }

    /**
     * Collects the id of a unit and of every unit below it in the chain of command, walking the
     * children graph. Used to keep a unit from becoming its own (in)direct parent, which would form
     * a cycle. An unpersisted unit contributes no id.
     *
     * @param Unit $unit the root of the subtree to collect
     *
     * @return list<int> the persisted ids of the unit and all its descendants
     */
    private static function subtreeIds(Unit $unit): array
    {
        $id = $unit->getId();
        $ids = null !== $id ? [$id] : [];

        foreach ($unit->getChildren() as $child) {
            $ids = array_merge($ids, self::subtreeIds($child));
        }

        return $ids;
    }
}
