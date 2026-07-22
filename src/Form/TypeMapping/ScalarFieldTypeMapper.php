<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Form\TypeMapping;

use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormTypeInterface;

/**
 * Maps non-temporal Doctrine scalar types — string, text, the integer
 * family, decimal/float, boolean — to their Symfony form type + options.
 *
 * Extracted from DoctrineFormTypeMapper::getFieldConfig()'s match(): these
 * five arms, ported verbatim, with identical options. See
 * docs/FIELD_MAPPING.md for the full type table and the
 * empty_data/requiresValueGuard rationale this preserves unchanged.
 *
 * Returns null for any Doctrine type it doesn't own (temporal types,
 * unsupported types) — DoctrineFormTypeMapper falls through to
 * TemporalFieldTypeMapper next, then to its own null default.
 */
final class ScalarFieldTypeMapper
{
    public function __construct(
        private readonly FieldOptionsBuilder $optionsBuilder = new FieldOptionsBuilder(),
    ) {}

    /**
     * @return array{type: class-string<FormTypeInterface<object>>, options: array<string, mixed>, requiresValueGuard?: bool}|null
     */
    public function map(string $doctrineType, string $fieldName, bool $nullable, bool $hasOwnConstraint): ?array
    {
        return match ($doctrineType) {
            'string' => [
                'type'    => TextType::class,
                'options' => $this->optionsBuilder->scalarOptions($fieldName, $nullable, hasOwnConstraint: $hasOwnConstraint),
            ],
            'text' => [
                'type'    => TextareaType::class,
                'options' => $this->optionsBuilder->scalarOptions($fieldName, $nullable, hasOwnConstraint: $hasOwnConstraint),
            ],
            'integer', 'smallint', 'bigint' => [
                'type'               => IntegerType::class,
                'options'            => $this->optionsBuilder->scalarOptions($fieldName, $nullable, guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            'decimal', 'float' => [
                'type'               => NumberType::class,
                'options'            => $this->optionsBuilder->scalarOptions($fieldName, $nullable, ['html5' => true], guarded: true),
                'requiresValueGuard' => !$nullable,
            ],
            'boolean' => [
                'type'    => CheckboxType::class,
                'options' => ['required' => false],
            ],
            default => null,
        };
    }
}
