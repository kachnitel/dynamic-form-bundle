<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Form\TypeMapping;

use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormTypeInterface;

/**
 * Maps Doctrine date/datetime/time types (mutable and immutable) to their
 * Symfony form type + options, deriving the `input` option straight from
 * the Doctrine type's `_immutable` suffix — see
 * docs/FIELD_MAPPING.md#date--time-input-option.
 *
 * Extracted from DoctrineFormTypeMapper::getFieldConfig()'s match(): these
 * six arms, ported verbatim, with identical options.
 *
 * Returns null for any Doctrine type it doesn't own — DoctrineFormTypeMapper
 * tries ScalarFieldTypeMapper first, this mapper second, then falls through
 * to its own null default for genuinely unsupported types.
 */
final class TemporalFieldTypeMapper
{
    public function __construct(
        private readonly FieldOptionsBuilder $optionsBuilder = new FieldOptionsBuilder(),
    ) {}

    /**
     * @return array{type: class-string<FormTypeInterface<object>>, options: array<string, mixed>, requiresValueGuard: bool}|null
     */
    public function map(string $doctrineType, string $fieldName, bool $nullable): ?array
    {
        return match ($doctrineType) {
            'date' => [
                'type'               => DateType::class,
                'options'            => $this->optionsBuilder->scalarOptions($fieldName, $nullable, ['widget' => 'single_text', 'input' => 'datetime'], guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            'date_immutable' => [
                'type'               => DateType::class,
                'options'            => $this->optionsBuilder->scalarOptions($fieldName, $nullable, ['widget' => 'single_text', 'input' => 'datetime_immutable'], guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            'datetime', 'datetimetz' => [
                'type'               => DateTimeType::class,
                'options'            => $this->optionsBuilder->scalarOptions($fieldName, $nullable, ['widget' => 'single_text', 'input' => 'datetime'], guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            'datetime_immutable', 'datetimetz_immutable' => [
                'type'               => DateTimeType::class,
                'options'            => $this->optionsBuilder->scalarOptions($fieldName, $nullable, ['widget' => 'single_text', 'input' => 'datetime_immutable'], guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            'time' => [
                'type'               => TimeType::class,
                'options'            => $this->optionsBuilder->scalarOptions($fieldName, $nullable, ['widget' => 'single_text', 'input' => 'datetime'], guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            'time_immutable' => [
                'type'               => TimeType::class,
                'options'            => $this->optionsBuilder->scalarOptions($fieldName, $nullable, ['widget' => 'single_text', 'input' => 'datetime_immutable'], guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            default => null,
        };
    }
}
