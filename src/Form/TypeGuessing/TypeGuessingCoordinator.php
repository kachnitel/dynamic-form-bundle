<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Form\TypeGuessing;

use Doctrine\ORM\Mapping\ClassMetadata;
use Kachnitel\DynamicFormBundle\Form\TypeMapping\FieldOptionsBuilder;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormTypeGuesserInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\Guess\Guess;

/**
 * Consults an injected FormTypeGuesserInterface (Symfony's own — see
 * docs/TYPE_GUESSING.md) for a more specific type than the generic TextType
 * a Doctrine `string` column would otherwise produce.
 *
 * Extracted from DoctrineFormTypeMapper::guessFieldConfig(), ported
 * verbatim. Deliberately excluded from the Form/ resource's DI autowiring
 * (see config/services.yaml) and instead built manually inside
 * DoctrineFormTypeMapper's own constructor, reusing the exact
 * $typeGuesser/$minimumGuessConfidence values that class itself received —
 * see that exclusion's comment in services.yaml for why an independently
 * autowired instance of this class isn't safe to register.
 *
 * Scoped to Doctrine `string` columns only:
 *   - date/datetime/time columns are already handled correctly by
 *     TemporalFieldTypeMapper (deriving the matching `input` suffix);
 *     consulting the guesser for those risks a HIGH_CONFIDENCE
 *     Assert\Date-family guess overwriting that with `input: 'string'`.
 *   - integer/float/boolean columns gain nothing from the guesser within
 *     our own default HIGH_CONFIDENCE threshold (ValidatorTypeGuesser only
 *     offers those at MEDIUM_CONFIDENCE) while bypassing
 *     ScalarFieldTypeMapper's own empty_data/requiresValueGuard machinery
 *     for no benefit.
 *   - enum-backed fields are handled by EnumFieldTypeMapper before this is
 *     ever consulted (see DoctrineFormTypeMapper::getFieldConfig()).
 *
 * A `string` column carrying e.g. #[Assert\Date] (dates stored as varchar)
 * is not excluded by this scoping and is intentionally allowed through: the
 * guesser's own `input: 'string'` option is exactly correct for a
 * genuinely string-typed property, with no TypeError risk.
 */
final class TypeGuessingCoordinator
{
    public function __construct(
        private readonly ?FormTypeGuesserInterface $typeGuesser = null,
        private readonly int $minimumGuessConfidence = Guess::HIGH_CONFIDENCE,
        private readonly FieldOptionsBuilder $optionsBuilder = new FieldOptionsBuilder(),
    ) {}

    /**
     * @param ClassMetadata<object> $metadata
     * @return array{type: class-string<FormTypeInterface<object>>, options: array<string, mixed>}|null
     *   Null when guessing is disabled ($typeGuesser is null), the Doctrine
     *   type isn't `string`, the guesser had no opinion, or its confidence
     *   fell below $minimumGuessConfidence — callers fall through to the
     *   ordinary Doctrine-type-driven mappers.
     */
    public function guess(
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
        // non-null default silently mutates a submitted value by
        // prepending a scheme. null disables that auto-fixup instead of
        // risking a rewritten value the guesser can't be certain is even a
        // mistake.
        if ($guess->getType() === UrlType::class && !array_key_exists('default_protocol', $guessedOptions)) {
            $guessedOptions['default_protocol'] = null;
        }

        /** @var class-string<FormTypeInterface<object>> $type */
        $type = $guess->getType(); // TypeGuess::getType() is typed `string`; narrow for PHPStan

        /** @var array<string, mixed> $options */
        $options = array_merge(
            $this->optionsBuilder->scalarOptions($fieldName, $nullable, hasOwnConstraint: $hasOwnConstraint),
            $guessedOptions, // deliberately second — a real validator constraint's
                                // required/etc. should win over schema-derived defaults
        );

        return [
            'type'    => $type,
            'options' => $options,
        ];
    }
}
