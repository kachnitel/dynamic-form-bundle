<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Form\TypeMapping;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Kachnitel\DynamicFormBundle\Form\DynamicEntityFormType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * Maps a Doctrine association (ManyToOne, OneToOne owning, ManyToMany,
 * OneToMany) to its Symfony form type + options.
 *
 * Extracted from DoctrineFormTypeMapper::getAssociationConfig() and its
 * three private builders (buildSingleAssociationConfig(),
 * buildManyToManyConfig(), buildOneToManyConfig()), ported verbatim — see
 * docs/ASSOCIATIONS.md for the association-type table and the
 * data-admin-entity-class attr this preserves unchanged.
 *
 * References DynamicEntityFormType::class only as a class-string constant
 * (never instantiates or type-hints it), exactly as
 * DoctrineFormTypeMapper's own buildOneToManyConfig() already did — a
 * compile-time string reference, not a DI edge, so this creates no
 * circular dependency with DynamicEntityFormType (which itself depends on
 * DoctrineFormTypeMapper).
 */
final class AssociationFieldTypeMapper
{
    /**
     * @param ClassMetadata<object> $metadata
     * @return array{type: class-string<FormTypeInterface<object>>, options: array<string, mixed>}|null
     */
    public function map(ClassMetadata $metadata, string $associationName): ?array
    {
        if (!$metadata->hasAssociation($associationName)) {
            return null;
        }

        if ($metadata->isSingleValuedAssociation($associationName)) {
            return $this->buildSingleAssociationConfig($metadata, $associationName);
        }

        $mapping = $metadata->getAssociationMapping($associationName);

        if ($mapping instanceof OneToManyAssociationMapping) {
            return $this->buildOneToManyConfig($metadata, $associationName);
        }

        return $this->buildManyToManyConfig($metadata, $associationName);
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @return array{type: class-string<FormTypeInterface<object>>, options: array<string, mixed>}
     */
    private function buildSingleAssociationConfig(ClassMetadata $metadata, string $associationName): array
    {
        /** @var class-string $targetClass */
        $targetClass = $metadata->getAssociationTargetClass($associationName);

        return [
            'type'    => EntityType::class,
            'options' => [
                'class'        => $targetClass,
                'required'     => false,
                'autocomplete' => true,
                'attr'         => ['data-admin-entity-class' => $targetClass],
            ],
        ];
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @return array{type: class-string<FormTypeInterface<object>>, options: array<string, mixed>}
     */
    private function buildManyToManyConfig(ClassMetadata $metadata, string $associationName): array
    {
        /** @var class-string $targetClass */
        $targetClass = $metadata->getAssociationTargetClass($associationName);

        return [
            'type'    => EntityType::class,
            'options' => [
                'class'        => $targetClass,
                'multiple'     => true,
                'required'     => false,
                'autocomplete' => true,
                'attr'         => ['data-admin-entity-class' => $targetClass],
            ],
        ];
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @return array{type: class-string<FormTypeInterface<object>>, options: array<string, mixed>}
     */
    private function buildOneToManyConfig(ClassMetadata $metadata, string $associationName): array
    {
        /** @var class-string $targetClass */
        $targetClass = $metadata->getAssociationTargetClass($associationName);

        return [
            'type'    => LiveCollectionType::class,
            'options' => [
                'entry_type'    => DynamicEntityFormType::class,
                'entry_options' => [
                    'entity_class' => $targetClass,
                    'data_class'   => $targetClass,
                    'is_root'      => false,
                ],
                'allow_add'    => true,
                'allow_delete' => true,
                'by_reference' => false,
            ],
        ];
    }
}
