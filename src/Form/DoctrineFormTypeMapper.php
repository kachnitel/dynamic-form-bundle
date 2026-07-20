<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Form;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Kachnitel\DynamicFormBundle\Form\Exception\NullabilityMismatchException;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormTypeGuesserInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\Guess\Guess;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

class DoctrineFormTypeMapper
{
    /**
     * $typeGuesser defaults to null so every existing bare `new
     * DoctrineFormTypeMapper()` call site — including every unit test in this
     * suite that doesn't test guessing specifically — keeps its current,
     * guessing-off behaviour unchanged. The bundle's own services.yaml wires
     * a real guesser (Symfony's form.type_guesser.validator) for the service
     * actually used at runtime; see docs/TYPE_GUESSING.md.
     */
    public function __construct(
        private readonly ?FormTypeGuesserInterface $typeGuesser = null,
        private readonly int $minimumGuessConfidence = Guess::HIGH_CONFIDENCE,
    ) {}

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
            return $this->buildEnumConfig($fieldName, $enumType, $nullable);
        }

        $hasOwnConstraint = $this->hasExistingValidatorConstraint($metadata, $fieldName);

        $guessedConfig = $this->guessFieldConfig($metadata, $fieldName, $mapping->type, $nullable, $hasOwnConstraint);
        if ($guessedConfig !== null) {
            return $guessedConfig;
        }

        return match ($mapping->type) {
            'string' => [
                'type'    => TextType::class,
                'options' => $this->scalarOptions($fieldName, $nullable, hasOwnConstraint: $hasOwnConstraint),
            ],
            'text' => [
                'type'    => TextareaType::class,
                'options' => $this->scalarOptions($fieldName, $nullable, hasOwnConstraint: $hasOwnConstraint),
            ],
            'integer', 'smallint', 'bigint' => [
                'type'               => IntegerType::class,
                'options'            => $this->scalarOptions($fieldName, $nullable, guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            'decimal', 'float' => [
                'type'               => NumberType::class,
                'options'            => $this->scalarOptions($fieldName, $nullable, ['html5' => true], guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            'boolean' => [
                'type'    => CheckboxType::class,
                'options' => ['required' => false],
            ],
            'date' => [
                'type'               => DateType::class,
                'options'            => $this->scalarOptions($fieldName, $nullable, ['widget' => 'single_text', 'input' => 'datetime'], guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            'date_immutable' => [
                'type'               => DateType::class,
                'options'            => $this->scalarOptions($fieldName, $nullable, ['widget' => 'single_text', 'input' => 'datetime_immutable'], guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            'datetime', 'datetimetz' => [
                'type'               => DateTimeType::class,
                'options'            => $this->scalarOptions($fieldName, $nullable, ['widget' => 'single_text', 'input' => 'datetime'], guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            'datetime_immutable', 'datetimetz_immutable' => [
                'type'               => DateTimeType::class,
                'options'            => $this->scalarOptions($fieldName, $nullable, ['widget' => 'single_text', 'input' => 'datetime_immutable'], guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            'time' => [
                'type'               => TimeType::class,
                'options'            => $this->scalarOptions($fieldName, $nullable, ['widget' => 'single_text', 'input' => 'datetime'], guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            'time_immutable' => [
                'type'               => TimeType::class,
                'options'            => $this->scalarOptions($fieldName, $nullable, ['widget' => 'single_text', 'input' => 'datetime_immutable'], guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            default => null,
        };
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @return array{type: class-string<FormTypeInterface<object>>, options: array<string, mixed>}|null
     */
    public function getAssociationConfig(ClassMetadata $metadata, string $associationName): ?array
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

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Consults the injected FormTypeGuesserInterface (Symfony's own — see
     * docs/TYPE_GUESSING.md) for a more specific type than the generic
     * TextType a Doctrine `string` column would otherwise produce.
     *
     * Scoped to Doctrine `string` columns only:
     *   - date/datetime/time columns are already handled correctly by the
     *     match() in getFieldConfig() (deriving the matching `input` suffix);
     *     consulting the guesser for those risks a HIGH_CONFIDENCE
     *     Assert\Date-family guess overwriting that with `input: 'string'`.
     *   - integer/float/boolean columns gain nothing from the guesser within
     *     our own default HIGH_CONFIDENCE threshold (ValidatorTypeGuesser
     *     only offers those at MEDIUM_CONFIDENCE) while bypassing this
     *     class's own empty_data/requiresValueGuard machinery for no benefit.
     *   - enum-backed fields are handled by buildEnumConfig() before this is
     *     ever called (see getFieldConfig()).
     *
     * A `string` column carrying e.g. #[Assert\Date] (dates stored as
     * varchar) is not excluded by this scoping and is intentionally allowed
     * through: the guesser's own `input: 'string'` option is exactly correct
     * for a genuinely string-typed property, with no TypeError risk.
     *
     * @param ClassMetadata<object> $metadata
     * @return array{type: class-string<FormTypeInterface<object>>, options: array<string, mixed>}|null
     *   Null when guessing is disabled ($typeGuesser is null), the Doctrine
     *   type isn't `string`, the guesser had no opinion, or its confidence
     *   fell below $minimumGuessConfidence — callers fall through to the
     *   ordinary Doctrine-type-driven match().
     */
    private function guessFieldConfig(
        ClassMetadata $metadata,
        string $fieldName,
        string $doctrineType,
        bool $nullable,
        bool $hasOwnConstraint,
    ): ?array {
        if ($doctrineType !== 'string' || $this->typeGuesser === null) {
            return null;
        }

        $guess = $this->typeGuesser->guessType($metadata->getName(), $fieldName);
        if ($guess === null || $guess->getConfidence() < $this->minimumGuessConfidence) {
            return null;
        }

        $guessedOptions = $guess->getOptions();

        // Symfony deprecated leaving default_protocol unset as of 7.1; its
        // non-null defaults mutate a submitted value by prepending a scheme.
        // null disables that auto-fixup instead of silently rewriting a value
        // that merely doesn't look like a URL — the safe default for a type
        // arrived at by inference rather than an explicit developer choice.
        if ($guess->getType() === UrlType::class && !array_key_exists('default_protocol', $guessedOptions)) {
            $guessedOptions['default_protocol'] = null;
        }

        /** @var class-string<FormTypeInterface<object>> $type */
        $type = $guess->getType(); // TypeGuess::getType() is typed `string`; narrow for PHPStan

        /** @var array<string, mixed> $options */
        $options = array_merge(
            $this->scalarOptions($fieldName, $nullable, hasOwnConstraint: $hasOwnConstraint),
            $guessedOptions, // deliberately second — a real validator constraint's
                                // required/etc. should win over schema-derived defaults
        );

        return [
            'type'    => $type,
            'options' => $options,
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function scalarOptions(string $fieldName, bool $nullable, array $extra = [], bool $guarded = false, bool $hasOwnConstraint = false): array
    {
        $options = array_merge([
            'required'   => !$nullable,
            'empty_data' => '',
        ], $extra);

        if ($nullable) {
            return $options;
        }

        if ($guarded) {
            $options['invalid_message'] = sprintf('%s is required.', ucfirst($fieldName));
        } elseif (!$hasOwnConstraint) {
            $options['constraints'] = [new NotBlank(message: sprintf('%s is required.', ucfirst($fieldName)))];
        }

        return $options;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function hasExistingValidatorConstraint(ClassMetadata $metadata, string $fieldName): bool
    {
        $reflectionClass = $metadata->getReflectionClass();
        /** @phpstan-ignore identical.alwaysFalse */
        if ($reflectionClass === null || !$reflectionClass->hasProperty($fieldName)) {
            return false;
        }

        foreach ($reflectionClass->getProperty($fieldName)->getAttributes() as $attribute) {
            if (is_a($attribute->getName(), Constraint::class, true)) {
                return true;
            }
        }

        return false;
    }

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

    /**
     * @param class-string<\BackedEnum> $enumClass
     * @return array{type: class-string<FormTypeInterface<object>>, options: array<string, mixed>, requiresValueGuard: bool}
     */
    private function buildEnumConfig(string $fieldName, string $enumClass, bool $nullable): array
    {
        $label = fn (\BackedEnum $case): string => method_exists($case, 'displayValue') ? $case->displayValue() : $case->name; // @phpstan-ignore return.type

        $options = [
            'class'        => $enumClass,
            'required'     => !$nullable,
            'placeholder'  => $nullable ? '' : false,
            'choice_label' => $label,
            'empty_data'   => '',
        ];

        if (!$nullable) {
            $options['invalid_message'] = sprintf('%s is required.', ucfirst($fieldName));
        }

        return [
            'type'               => EnumType::class,
            'options'            => $options,
            'requiresValueGuard' => !$nullable,
        ];
    }
}
