<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Editability;

/**
 * Permissive default implementation of FieldEditabilityResolverInterface.
 *
 * Includes every field in the generated form unconditionally — no attribute
 * checks, no per-row expressions, no entity-level defaults. This matches
 * DynamicEntityFormType's zero-configuration behaviour: with no policy
 * installed, every non-excluded Doctrine field/association is editable.
 *
 * Replace this binding in your own services.yaml to enforce a real policy:
 *
 * ```yaml
 * Kachnitel\DynamicFormBundle\Editability\FieldEditabilityResolverInterface:
 *     alias: App\Form\MyFieldEditabilityResolver
 * ```
 *
 * kachnitel/admin-bundle registers AdminColumnEditabilityResolver, which adds
 * #[AdminColumn(editable: ...)] and #[Admin(enableInlineEdit: ...)] support.
 */
final class AlwaysEditableFieldResolver implements FieldEditabilityResolverInterface
{
    public function canEdit(string $entityClass, string $property, ?object $entity = null): bool
    {
        return true;
    }

    public function isExplicitOverride(string $entityClass, string $property, ?object $entity = null): bool
    {
        return true;
    }
}
