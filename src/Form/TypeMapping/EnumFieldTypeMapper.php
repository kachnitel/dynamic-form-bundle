<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Form\TypeMapping;

use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormTypeInterface;

/**
 * Builds the form field config for a Doctrine field backed by a PHP backed
 * enum (i.e. `#[ORM\Column(enumType: Status::class)]`) — see
 * docs/FIELD_MAPPING.md#enum-fields.
 *
 * Extracted from DoctrineFormTypeMapper::buildEnumConfig(), ported
 * verbatim — this class only relocates that method, it does not alter its
 * logic or options.
 */
final class EnumFieldTypeMapper
{
    /**
     * @param class-string<\BackedEnum> $enumClass
     * @return array{type: class-string<FormTypeInterface<object>>, options: array<string, mixed>, requiresValueGuard: bool}
     */
    public function map(string $fieldName, string $enumClass, bool $nullable): array
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
