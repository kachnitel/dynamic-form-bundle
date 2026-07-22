<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Form\TypeGuessing;

use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormTypeGuesserInterface;
use Symfony\Component\Form\Guess\Guess;
use Symfony\Component\Form\Guess\TypeGuess;
use Symfony\Component\Form\Guess\ValueGuess;

/**
 * Optional, opt-in naming-convention guesser for the handful of built-in
 * Symfony types that have no corresponding validator constraint at all —
 * ValidatorTypeGuesser has no mapping for tel/color/search — plus
 * email/url as a naming-only fallback for fields with no matching
 * constraint declared.
 *
 * password is deliberately NOT covered here. A nicer widget
 * (PasswordType) is only half the story for a real password-hash column —
 * see docs/TYPE_GUESSING.md's "Passwords" section for the nullable/
 * non-nullable submission-safety problems a widget choice alone can't
 * solve, and Symfony has no built-in, simple hashing mechanism to pair
 * this with (PasswordType has no such option as of the versions this
 * bundle supports). Deferred until there's a clean way to address that
 * rather than shipping a guess that invites treating a hash column as an
 * ordinary mapped field.
 *
 * Not registered as DoctrineFormTypeMapper's default $typeGuesser. Compose
 * it into a Symfony\Component\Form\FormTypeGuesserChain alongside
 * form.type_guesser.validator to enable it — see docs/TYPE_GUESSING.md
 * ("Enabling naming-convention guessing").
 *
 * Confidence levels deliberately never exceed Guess::HIGH_CONFIDENCE, the
 * same ceiling ValidatorTypeGuesser uses for its own constraint-based
 * guesses (Assert\Email, Assert\Url, ...). A naming convention is not more
 * trustworthy than a constraint the developer actually wrote; it should be
 * a peer signal, not one engineered to win every tie via a higher tier.
 * search stays at MEDIUM_CONFIDENCE — a cosmetic, lower-certainty match.
 *
 * Matching runs against the field name split into lowercase camelCase
 * "words" (e.g. 'contactEmail' -> ['contact', 'email']). Each pattern
 * below is checked at one of two positions:
 *   - 'any':  the keyword may appear anywhere among the words. Used for
 *             email/tel, where — once non-string Doctrine columns are
 *             already excluded by the caller — no realistic string-typed
 *             counter-example was found.
 *   - 'last': the keyword must be the final word. Used for url/color/search
 *             specifically to exclude prefix-style false positives such as
 *             'urlSlug' or 'urlPath', which are not URLs despite containing
 *             the word.
 */
final class ConventionalFieldTypeGuesser implements FormTypeGuesserInterface
{
    /**
     * @var list<array{type: class-string, words: list<string>, position: 'any'|'last', options: array<string, mixed>, confidence: int}>
     */
    private const PATTERNS = [
        [
            'type' => EmailType::class,
            'words' => ['email'],
            'position' => 'any',
            'options' => [],
            'confidence' => Guess::HIGH_CONFIDENCE,
        ],
        [
            'type' => TelType::class,
            'words' => ['phone', 'telephone', 'tel', 'mobile', 'fax'],
            'position' => 'any',
            'options' => [],
            'confidence' => Guess::HIGH_CONFIDENCE,
        ],
        [
            'type' => UrlType::class,
            'words' => ['url', 'website', 'homepage', 'link'],
            'position' => 'last',
            // Symfony deprecated leaving default_protocol unset as of 7.1; its
            // non-null defaults mutate a submitted value by prepending a
            // scheme. null disables that auto-fixup — the safe choice for a
            // guess based on naming alone rather than a declared constraint.
            'options' => ['default_protocol' => null],
            'confidence' => Guess::HIGH_CONFIDENCE,
        ],
        [
            'type' => ColorType::class,
            'words' => ['color', 'colour'],
            'position' => 'last',
            'options' => [],
            'confidence' => Guess::HIGH_CONFIDENCE,
        ],
        [
            'type' => SearchType::class,
            'words' => ['search', 'query'],
            'position' => 'last',
            'options' => [],
            'confidence' => Guess::MEDIUM_CONFIDENCE,
        ],
    ];

    /**
     * $class is intentionally unused — guessDoesNotVaryByEntityClass() (see
     * the test suite) locks this in: naming-convention guessing depends
     * only on the property name, never on the entity it belongs to. Kept
     * as a real parameter (not renamed or dropped) because
     * FormTypeGuesserInterface mandates it.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function guessType(string $class, string $property): ?TypeGuess
    {
        $words = $this->words($property);

        foreach (self::PATTERNS as $pattern) {
            if ($this->matches($words, $pattern['words'], $pattern['position'])) {
                return new TypeGuess($pattern['type'], $pattern['options'], $pattern['confidence']);
            }
        }

        return null;
    }

    // Naming tells us nothing about required/maxLength/pattern — leave those
    // to the validator/Doctrine-nullability guessers.

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function guessRequired(string $class, string $property): ?ValueGuess
    {
        return null;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function guessMaxLength(string $class, string $property): ?ValueGuess
    {
        return null;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function guessPattern(string $class, string $property): ?ValueGuess
    {
        return null;
    }

    /**
     * @return list<string>
     */
    private function words(string $property): array
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $property) ?? $property;

        return array_map(strtolower(...), preg_split('/[\s_]+/', $spaced, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * @param list<string> $words
     * @param list<string> $keywords
     * @param 'any'|'last' $position
     */
    private function matches(array $words, array $keywords, string $position): bool
    {
        if ($words === []) {
            return false;
        }

        return match ($position) {
            'any' => array_intersect($words, $keywords) !== [],
            'last' => in_array($words[array_key_last($words)], $keywords, true),
        };
    }
}
