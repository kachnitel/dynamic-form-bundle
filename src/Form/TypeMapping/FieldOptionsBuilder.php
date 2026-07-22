<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Form\TypeMapping;

use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Builds the shared `required`/`empty_data`/`constraints`/`invalid_message`
 * option set every scalar, temporal, and guessed form field is built from —
 * see docs/FIELD_MAPPING.md#why-empty_data-is-always- for why `empty_data`
 * is unconditionally `''`, never a literal null.
 *
 * Extracted from DoctrineFormTypeMapper (scalarOptions() and
 * hasExistingValidatorConstraint(), ported verbatim) so
 * ScalarFieldTypeMapper, TemporalFieldTypeMapper, and
 * TypeGuessingCoordinator can each depend on this one small collaborator
 * instead of every one of them (or DoctrineFormTypeMapper alone)
 * separately importing the Validator classes involved.
 */
final class FieldOptionsBuilder
{
    /**
     * @param array<string, mixed> $extra
     * @return non-empty-array<string, mixed>
     */
    public function scalarOptions(string $fieldName, bool $nullable, array $extra = [], bool $guarded = false, bool $hasOwnConstraint = false): array
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
    public function hasExistingValidatorConstraint(ClassMetadata $metadata, string $fieldName): bool
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
}
