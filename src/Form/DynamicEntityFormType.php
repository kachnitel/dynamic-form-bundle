<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Form;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Kachnitel\DynamicFormBundle\Editability\FieldEditabilityResolverInterface;
use Kachnitel\DynamicFormBundle\Form\DataTransformer\RequiredValueTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Auto-generates a Symfony form for any Doctrine entity without requiring a hand-written FormType.
 *
 * Field inclusion rules:
 *   - The identifier field (single PK) is always excluded
 *   - Fields/associations the injected FieldEditabilityResolverInterface rejects via
 *     canEdit() are excluded
 *   - Inverse-side associations (mappedBy set) are skipped by default — they are managed
 *     by the owning side. Opt back in via FieldEditabilityResolverInterface::isExplicitOverride().
 *   - Fields whose Doctrine type has no Symfony form equivalent (e.g. json) are silently skipped
 *   - All scalar fields and owning-side associations are always included
 *   - Collection-valued associations (ManyToMany, OneToMany) are included by default
 *     when is_root is true; skipped when is_root is false to prevent infinite recursion
 *
 * Collection mapping:
 *   - ManyToMany → EntityType with multiple: true (multi-select)
 *   - OneToMany  → LiveCollectionType with recursive DynamicEntityFormType as entry_type
 *
 * The is_root option (default: true) controls whether this is a top-level form or a
 * child entry inside a LiveCollectionType. Child forms skip collection associations
 * to prevent infinite recursion in bidirectional relationships.
 *
 * `data_class` defaults to `entity_class` via a lazy OptionsResolver default — the
 * overwhelmingly common case is binding the form straight to the entity being
 * introspected, so callers no longer type the same class-string twice. Pass
 * `data_class` explicitly to override, including an explicit `null` for an unmapped
 * form bound to a plain array or DTO instead of the entity directly — an explicit
 * value (even null) always wins over the lazy default; see configureOptions().
 *
 * Required option:
 *   entity_class (string) — fully-qualified class name of the entity to build for
 *
 * Optional option:
 *   data_class (string|null, default: entity_class) — the class submitted values are
 *     mapped onto. Pass explicitly (including null) to bind to something other than
 *     entity_class itself.
 *   is_root (bool, default: true) — set to false for child forms inside LiveCollectionType
 *   entity_instance (object|null, default: null) — the actual entity being edited/created.
 *     Passed straight through to FieldEditabilityResolverInterface, so implementations
 *     that need a concrete instance (e.g. to evaluate a per-row expression) can do so.
 *     For new entities, pass a fresh instance. For child forms in LiveCollectionType,
 *     entity_instance is typically passed from the parent.
 *
 * @see Editability\FieldEditabilityResolverInterface for how field-level editability is resolved
 * @extends AbstractType<object>
 */
class DynamicEntityFormType extends AbstractType
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DoctrineFormTypeMapper $mapper,
        private readonly FieldEditabilityResolverInterface $editabilityResolver,
    ) {}

    /**
     * @param array{entity_class: class-string, is_root?: bool, entity_instance?: object|null} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var class-string $entityClass */
        $entityClass = $options['entity_class'];
        $isRoot      = (bool) ($options['is_root'] ?? true);
        $entity      = $options['entity_instance'] ?? null;
        $metadata    = $this->em->getClassMetadata($entityClass);
        $idField     = $metadata->getSingleIdentifierFieldName();

        // ── Scalar fields ──────────────────────────────────────────────────────

        foreach ($metadata->getFieldNames() as $fieldName) {
            $this->addScalarField($builder, $metadata, $entityClass, $fieldName, $idField, $entity);
        }

        // ── Associations ───────────────────────────────────────────────────────

        foreach ($metadata->getAssociationNames() as $assocName) {
            $this->addAssociationField($builder, $metadata, $entityClass, $assocName, $isRoot, $entity);
        }

        // ── Editability re-check listener ─────────────────────────────────────
        // Attach a listener that re-asks the editability resolver once form data
        // is actually bound. This is essential for child forms created by
        // LiveCollectionType, where the entity instance is only available after
        // the form structure is built — see FieldEditabilityResolverInterface's
        // docblock and DynamicFormEditabilityListener for the exact contract.
        $listener = new DynamicFormEditabilityListener(
            $this->editabilityResolver,
            $entityClass,
        );
        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            [$listener, 'onPreSetData'],
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('entity_class');
        $resolver->setAllowedTypes('entity_class', 'string');

        // Lazy default: only evaluated when the caller omits data_class entirely.
        // An explicit value — including an explicit null, for an unmapped form bound
        // to a plain array/DTO instead of the entity itself — always wins and this
        // closure never runs. See the class docblock for the full rationale.
        $resolver->setDefault('data_class', static function (Options $options): string {
            /** @var string $entityClass */
            $entityClass = $options['entity_class'];

            return $entityClass;
        });

        $resolver->setDefault('is_root', true);
        $resolver->setAllowedTypes('is_root', 'bool');

        $resolver->setDefault('entity_instance', null);
        $resolver->setAllowedTypes('entity_instance', ['object', 'null']);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * @param FormBuilderInterface<object|null> $builder
     * @param ClassMetadata<object> $metadata
     * @param class-string $entityClass
     */
    private function addScalarField(FormBuilderInterface $builder, ClassMetadata $metadata, string $entityClass, string $fieldName, ?string $idField, ?object $entity): void
    {
        if ($fieldName === $idField) {
            return;
        }

        if (!$this->editabilityResolver->canEdit($entityClass, $fieldName, $entity)) {
            return;
        }

        $config = $this->mapper->getFieldConfig($metadata, $fieldName);
        if ($config === null) {
            return; // unsupported type — skip silently
        }

        $builder->add($fieldName, $config['type'], $config['options']);

        if ($config['requiresValueGuard'] ?? false) {
            $builder->get($fieldName)->addModelTransformer(new RequiredValueTransformer());
        }
    }

    /**
     * @param FormBuilderInterface<object|null> $builder
     * @param ClassMetadata<object> $metadata
     * @param class-string $entityClass
     */
    private function addAssociationField(FormBuilderInterface $builder, ClassMetadata $metadata, string $entityClass, string $assocName, bool $isRoot, ?object $entity): void
    {
        if (!$this->editabilityResolver->canEdit($entityClass, $assocName, $entity)) {
            return;
        }

        $isCollection = $metadata->isCollectionValuedAssociation($assocName);

        if ($isCollection && !$isRoot) {
            return;
        }

        if ($this->shouldSkipInverseSide($metadata, $assocName, $entityClass, $isCollection, $isRoot, $entity)) {
            return;
        }

        if ($this->isBackReferenceToParent($metadata, $assocName, $entityClass, $entity) && !$isCollection) {
            return;
        }

        $config = $this->mapper->getAssociationConfig($metadata, $assocName);
        if ($config === null) {
            return;
        }

        $builder->add($assocName, $config['type'], $config['options']);
    }

    /**
     * Returns true when an inverse-side association should be skipped.
     *
     * Inverse-side associations (mappedBy set) are skipped by default to avoid
     * redundant controls, EXCEPT OneToMany collections in root forms are kept.
     *
     * @param ClassMetadata<object> $metadata
     * @param class-string $entityClass
     */
    private function shouldSkipInverseSide(ClassMetadata $metadata, string $assocName, string $entityClass, bool $isCollection, bool $isRoot, ?object $entity): bool
    {
        if (!$metadata->hasAssociation($assocName)) {
            return false;
        }

        $mapping  = $metadata->getAssociationMapping($assocName);
        $mappedBy = $mapping->mappedBy ?? null;

        // No mappedBy → this is an owning-side association, don't skip
        if ($mappedBy === null || $mappedBy === '') {
            return false;
        }

        // This is an inverse-side association (has mappedBy)
        // Keep OneToMany collections in root forms; skip everything else
        if ($isCollection && $isRoot && $mapping instanceof OneToManyAssociationMapping) {
            return false; // OneToMany in root form: include
        }

        // For anything else (OneToOne inverse, ManyToMany inverse, collections in child forms),
        // check for an explicit override
        return !$this->editabilityResolver->isExplicitOverride($entityClass, $assocName, $entity);
    }

    /**
     * Returns true when a single-valued association is a back-reference to a parent entity.
     *
     * A back-reference occurs when:
     *   - The association is single-valued (ManyToOne, OneToOne inverse)
     *   - It has `inversedBy` set (ManyToOne pointing to a parent's OneToMany collection)
     *
     * Such associations are managed by the parent form and should not be included
     * in child forms to avoid confusing UI. Opt back in via
     * FieldEditabilityResolverInterface::isExplicitOverride().
     *
     * @param ClassMetadata<object> $metadata
     * @param class-string $entityClass
     */
    private function isBackReferenceToParent(ClassMetadata $metadata, string $assocName, string $entityClass, ?object $entity): bool
    {
        if (!$metadata->hasAssociation($assocName)) {
            return false;
        }

        $mapping = $metadata->getAssociationMapping($assocName);

        // Check for inversedBy (ManyToOne pointing to a parent's OneToMany collection)
        $inversedBy = $mapping->inversedBy ?? null;
        if ($inversedBy !== null && $inversedBy !== '') {
            return !$this->editabilityResolver->isExplicitOverride($entityClass, $assocName, $entity);
        }

        return false;
    }
}
