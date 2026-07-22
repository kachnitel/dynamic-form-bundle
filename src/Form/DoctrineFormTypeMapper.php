<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Form;

use Doctrine\ORM\Mapping\ClassMetadata;
use Kachnitel\DynamicFormBundle\Form\Exception\NullabilityMismatchException;
use Kachnitel\DynamicFormBundle\Form\TypeGuessing\TypeGuessingCoordinator;
use Kachnitel\DynamicFormBundle\Form\TypeMapping\AssociationFieldTypeMapper;
use Kachnitel\DynamicFormBundle\Form\TypeMapping\EnumFieldTypeMapper;
use Kachnitel\DynamicFormBundle\Form\TypeMapping\FieldOptionsBuilder;
use Kachnitel\DynamicFormBundle\Form\TypeMapping\ScalarFieldTypeMapper;
use Kachnitel\DynamicFormBundle\Form\TypeMapping\TemporalFieldTypeMapper;
use Symfony\Component\Form\FormTypeGuesserInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\Guess\Guess;

/**
 * Maps a single Doctrine field or association mapping to a Symfony form
 * field config (type + options) — see docs/FIELD_MAPPING.md and
 * docs/ASSOCIATIONS.md for the full behaviour.
 *
 * This class is an orchestrator: it owns nullability cross-checking
 * (assertNullabilityAgrees()) and the dispatch order between its
 * collaborators, while the per-Doctrine-type mapping logic itself lives in:
 *
 *   - EnumFieldTypeMapper        — backed-enum fields
 *   - TypeGuessingCoordinator    — constraint-/naming-driven upgrades of
 *                                  `string` columns (see docs/TYPE_GUESSING.md)
 *   - ScalarFieldTypeMapper      — string/text/integer family/decimal/float/boolean
 *   - TemporalFieldTypeMapper    — date/datetime/time (mutable + immutable)
 *   - AssociationFieldTypeMapper — ManyToOne/OneToOne/ManyToMany/OneToMany
 *   - FieldOptionsBuilder        — the shared required/empty_data/constraints
 *                                  option set several of the above depend on
 *
 * Split out from a single, more heavily-imported class so each piece's own
 * coupling stays small and independently testable — see each collaborator's
 * own docblock for what it owns. getFieldConfig()/getAssociationConfig()'s
 * signatures, return shapes, and dispatch order (nullability check → enum →
 * guessing → scalar → temporal → unsupported-type null) are unchanged from
 * before this split.
 */
class DoctrineFormTypeMapper
{
    private readonly TypeGuessingCoordinator $guessingCoordinator;

    /**
     * $typeGuesser defaults to null so every existing bare `new
     * DoctrineFormTypeMapper()` call site — including every unit test in this
     * suite that doesn't test guessing specifically — keeps its current,
     * guessing-off behaviour unchanged. The bundle's own services.yaml wires
     * a real guesser (Symfony's form.type_guesser.validator) for the service
     * actually used at runtime; see docs/TYPE_GUESSING.md.
     *
     * The five collaborator parameters below all default to a bare `new
     * Xxx()` (PHP 8.1+ "new in initializer") for the identical reason: every
     * existing `new DoctrineFormTypeMapper(...)` call site across the test
     * suite passes at most $typeGuesser/$minimumGuessConfidence today and
     * must keep compiling and behaving unchanged. Symfony autowires real,
     * container-managed instances into these params at runtime, the same
     * way it already does for $typeGuesser — see config/services.yaml.
     */
    public function __construct(
        private readonly ?FormTypeGuesserInterface $typeGuesser = null,
        private readonly int $minimumGuessConfidence = Guess::HIGH_CONFIDENCE,
        private readonly FieldOptionsBuilder $optionsBuilder = new FieldOptionsBuilder(),
        private readonly ScalarFieldTypeMapper $scalarMapper = new ScalarFieldTypeMapper(),
        private readonly TemporalFieldTypeMapper $temporalMapper = new TemporalFieldTypeMapper(),
        private readonly EnumFieldTypeMapper $enumMapper = new EnumFieldTypeMapper(),
        private readonly AssociationFieldTypeMapper $associationMapper = new AssociationFieldTypeMapper(),
    ) {
        // Built here, not constructor-promoted/injected: TypeGuessingCoordinator
        // needs the exact same $typeGuesser/$minimumGuessConfidence this class
        // itself received, not an independently-autowired instance — see
        // config/services.yaml's exclusion of TypeGuessingCoordinator from the
        // Form/ resource scan for why a second, independently-autowired
        // instance isn't registered at all.
        $this->guessingCoordinator = new TypeGuessingCoordinator($typeGuesser, $minimumGuessConfidence, $optionsBuilder);
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @return array{type: class-string<FormTypeInterface<object>>, options: array<string, mixed>, requiresValueGuard?: bool}|null
     * @throws NullabilityMismatchException see class docblock.
     */
    public function getFieldConfig(ClassMetadata $metadata, string $fieldName): ?array
    {
        if (!$metadata->hasField($fieldName)) {
            return null;
        }

        $mapping  = $metadata->getFieldMapping($fieldName);
        $nullable = $mapping->nullable ?? false;

        $this->assertNullabilityAgrees($metadata, $fieldName, $nullable);

        // Backed enum — use a ChoiceType built from the enum cases
        $enumType = $mapping->enumType ?? null;
        if ($enumType !== null && enum_exists($enumType)) {
            return $this->enumMapper->map($fieldName, $enumType, $nullable);
        }

        $hasOwnConstraint = $this->optionsBuilder->hasExistingValidatorConstraint($metadata, $fieldName);

        $guessedConfig = $this->guessingCoordinator->guess($metadata, $fieldName, $mapping->type, $nullable, $hasOwnConstraint);
        if ($guessedConfig !== null) {
            return $guessedConfig;
        }

        return $this->scalarMapper->map($mapping->type, $fieldName, $nullable, $hasOwnConstraint)
            ?? $this->temporalMapper->map($mapping->type, $fieldName, $nullable);
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @return array{type: class-string<FormTypeInterface<object>>, options: array<string, mixed>}|null
     */
    public function getAssociationConfig(ClassMetadata $metadata, string $associationName): ?array
    {
        return $this->associationMapper->map($metadata, $associationName);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * @param ClassMetadata<object> $metadata
     * @throws NullabilityMismatchException when Doctrine permits null but the
     *   PHP property type cannot represent it.
     */
    private function assertNullabilityAgrees(ClassMetadata $metadata, string $fieldName, bool $dbNullable): void
    {
        if (!$dbNullable) {
            return;
        }

        if ($this->phpAllowsNull($metadata, $fieldName)) {
            return;
        }

        throw NullabilityMismatchException::forField($metadata->getName(), $fieldName);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function phpAllowsNull(ClassMetadata $metadata, string $fieldName): bool
    {
        $reflectionClass = $metadata->getReflectionClass();
        if ($reflectionClass === null) { // @phpstan-ignore identical.alwaysFalse
            return true;
        }

        try {
            $type = $reflectionClass->getProperty($fieldName)->getType();
        } catch (\ReflectionException) {
            return true;
        }

        return $type === null || $type->allowsNull();
    }
}
