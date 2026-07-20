# Type Guessing

`DoctrineFormTypeMapper` maps a Doctrine `string` column to `TextType` by default — reasonable, since Doctrine's own metadata can't tell an email address apart from a product SKU. This page covers the two ways that generic default gets upgraded to something more specific, and how to control both.

> **Working on `kachnitel/admin-bundle`?** Jump straight to [Real-World Example: kachnitel/admin-bundle](#real-world-example-kachniteladmin-bundle) — it's written to stand on its own.

## Table of Contents

- [The Two Layers](#the-two-layers)
- [Layer 1: Constraint-Driven Guessing (the default)](#layer-1-constraint-driven-guessing-the-default)
- [`Country`/`Language`/`Currency`/`Locale` need `symfony/intl`](#countrylanguagecurrencylocale-need-symfonyintl)
- [Why Scoped to Doctrine `string` Columns](#why-scoped-to-doctrine-string-columns)
- [What Constraint-Driven Guessing Can't Cover](#what-constraint-driven-guessing-cant-cover)
- [Layer 2: `ConventionalFieldTypeGuesser` (opt-in)](#layer-2-conventionalfieldtypeguesser-opt-in)
- [Enabling Naming-Convention Guessing](#enabling-naming-convention-guessing)
- [Writing Your Own Guesser](#writing-your-own-guesser)
- [Passwords: Deferred](#passwords-deferred)
- [Real-World Example: kachnitel/admin-bundle](#real-world-example-kachniteladmin-bundle)
- [Testing](#testing)

## The Two Layers

| Layer | Mechanism | Wired by default? | Covers |
|---|---|---|---|
| 1 | Symfony's own `Symfony\Component\Form\FormTypeGuesserInterface`, bound to `form.type_guesser.validator` | Yes | Any field with a matching `#[Assert\...]` constraint |
| 2 | `Kachnitel\DynamicFormBundle\Form\TypeGuessing\ConventionalFieldTypeGuesser`, ships in this bundle | No — opt-in | `tel`, `color`, `search`, plus `email`/`url` as a naming-only fallback |

Both layers implement the exact same, standard Symfony interface. `DoctrineFormTypeMapper` doesn't know or care which one (or which combination) it's talking to — see [Writing Your Own Guesser](#writing-your-own-guesser).

This is deliberately **not** the same thing as Symfony's own [form type guessing](https://symfony.com/doc/current/forms.html#form-type-guessing) feature (the one that fires when you call `$builder->add('field')` with no type). `DynamicEntityFormType` always resolves a type explicitly via `DoctrineFormTypeMapper` first, rather than delegating to Symfony's app-wide guesser chain, because:

- No confidence gate — any guess at any confidence level is used.
- No scope control — unrelated bundles' guessers would silently apply here.
- Wrong merge precedence — guessed options win over our schema-derived ones, not the other way around.
- No post-processing seam — the `UrlType` → `default_protocol: null` fix has nowhere to hook.
- No Doctrine-type scoping — it would also fire for `date`/`datetime` columns, breaking `input: 'datetime_immutable'` handling.

`DoctrineFormTypeMapper` orchestrates the guessing using the same Symfony primitives (`TypeGuess`, `ValidatorTypeGuesser`, `FormTypeGuesserChain`) — it just retains the decisions rather than handing control to Symfony's opaque, whole-app resolution path.

## Layer 1: Constraint-Driven Guessing (the default)

`DoctrineFormTypeMapper` accepts an optional `FormTypeGuesserInterface` in its constructor:

```php
public function __construct(
    private readonly ?FormTypeGuesserInterface $typeGuesser = null,
    private readonly int $minimumGuessConfidence = Guess::HIGH_CONFIDENCE,
) {}
```

This bundle's own `config/services.yaml` binds `$typeGuesser` to Symfony's `form.type_guesser.validator` — `Symfony\Component\Form\Extension\Validator\ValidatorTypeGuesser` — so any Doctrine `string` field whose property carries a matching validator constraint is upgraded automatically, with no configuration:

| Constraint | Form type | Confidence |
|---|---|---|
| `#[Assert\Email]` | `EmailType` | `HIGH_CONFIDENCE` |
| `#[Assert\Url]` | `UrlType` (see note below) | `HIGH_CONFIDENCE` |
| `#[Assert\Country]` | `CountryType` | `HIGH_CONFIDENCE` |
| `#[Assert\Currency]` | `CurrencyType` | `HIGH_CONFIDENCE` |
| `#[Assert\Language]` | `LanguageType` | `HIGH_CONFIDENCE` |
| `#[Assert\Locale]` | `LocaleType` | `HIGH_CONFIDENCE` |
| `#[Assert\Date]` / `#[Assert\DateTime]` / `#[Assert\Time]` | `DateType`/`DateTimeType`/`TimeType` with `input: 'string'` | `HIGH_CONFIDENCE` |
| `#[Assert\File]` / `#[Assert\Image]` | `FileType` | `HIGH_CONFIDENCE` |
| `#[Assert\Type('bool')]`, `#[Assert\Type('int')]`, etc. | `CheckboxType`, `IntegerType`, etc. | `MEDIUM_CONFIDENCE` |
| `#[Assert\Length]`, `#[Assert\Regex]` | `TextType` (no-op) | `LOW_CONFIDENCE` |

This table was verified directly against `ValidatorTypeGuesser`'s actual source, not inferred from documentation — the `Assert\Date`-family row is a genuine, if narrow, case: a `string` Doctrine column storing a date as a raw value (legacy schemas do this) with `#[Assert\Date]` on it correctly gets `input: 'string'`, which is exactly right for a genuinely string-typed property. It is **not** consulted for actual Doctrine `date`/`datetime`/`time` **columns** — see the next section for why that distinction matters.

By default, only `HIGH_CONFIDENCE` guesses are applied. Construct the mapper with `Guess::MEDIUM_CONFIDENCE` to also unlock the `Assert\Type`-based rows — most of these have limited value within the `string`-only scope described below, so this is left as an opt-in rather than a default.

### `url`'s `default_protocol`

`ValidatorTypeGuesser` returns `UrlType` with no options at all. Symfony deprecated leaving `default_protocol` unset as of 7.1, because its non-null default silently mutates a submitted value by prepending a scheme. `DoctrineFormTypeMapper` post-processes any `UrlType` guess that doesn't already specify `default_protocol`, setting it to `null` — disabling that auto-fixup rather than risking a rewritten value the guesser can't be certain is even a mistake. If your own guesser (or a future Symfony constraint) already sets `default_protocol` explicitly, that value is left alone.

### `Country`/`Language`/`Currency`/`Locale` need `symfony/intl`

`#[Assert\Country]`, `#[Assert\Language]`, `#[Assert\Currency]`, and `#[Assert\Locale]` all require the `symfony/intl` component at runtime — without it, Symfony throws `Symfony\Component\Validator\Exception\LogicException` ("The Intl component is required to use the Country constraint. Try running `composer require symfony/intl`."). This is a `symfony/validator` requirement, independent of this bundle — the same exception fires the moment anything validates that property, not just when guessing its form type.

One non-obvious consequence worth knowing: this exception surfaces the moment metadata is loaded for the **whole class** the property belongs to, not just the property being guessed. `LazyLoadingMetadataFactory` eagerly instantiates every constraint declared anywhere on a class as soon as any of its properties are queried. Guessing a type for an *unrelated* field on an entity that happens to also have `#[Assert\Country]` somewhere on it will still throw if `symfony/intl` isn't installed.

This bundle's own `require-dev` includes `symfony/intl` so its test suite exercises the `Country` row of the table above reliably, and `composer.json` lists it under `suggest` for consuming apps. It is not a hard `require` — most apps don't use these four constraints, and the bundle's own operation doesn't touch `symfony/intl` at all otherwise.

## Why Scoped to Doctrine `string` Columns

The guesser is only ever consulted when `$mapping->type === 'string'`:

- **`date`/`datetime`/`time` columns** are already handled correctly by `getFieldConfig()`'s own `match()`, which derives the exact `input` suffix (`datetime` vs `datetime_immutable`) the column's PHP type needs — see [Field Mapping](FIELD_MAPPING.md#date--time-input-option). Consulting the guesser for these risks a `HIGH_CONFIDENCE` `Assert\Date`-family guess overwriting that with `input: 'string'`, which is wrong for an actual `\DateTimeImmutable`-typed property.
- **`integer`/`float`/`boolean` columns** gain nothing at the default `HIGH_CONFIDENCE` threshold (`ValidatorTypeGuesser` only offers those at `MEDIUM_CONFIDENCE`) while bypassing this class's own `empty_data`/`requiresValueGuard` machinery — see [Field Mapping](FIELD_MAPPING.md) — for no benefit.
- **Enum-backed fields** are resolved before the guesser is ever reached; `getFieldConfig()`'s enum check runs first and returns early.

## What Constraint-Driven Guessing Can't Cover

`ValidatorTypeGuesser`'s mapping is a closed list — there is no `#[Assert\Password]`-equivalent constraint, and no built-in constraint maps to `TelType`, `ColorType`, or `SearchType` either. That's what Layer 2 exists for — with one deliberate exception: a field named `password` with no constraint on it (the overwhelmingly common shape — you validate a password's *strength*, not that it *is* one) still renders as plain `TextType` even with Layer 2 enabled. See [Passwords: Deferred](#passwords-deferred).

## Layer 2: `ConventionalFieldTypeGuesser` (opt-in)

`Kachnitel\DynamicFormBundle\Form\TypeGuessing\ConventionalFieldTypeGuesser` is a naming-convention guesser, shipped in this bundle but **not** wired into `DoctrineFormTypeMapper`'s default `$typeGuesser`. It implements the same standard `FormTypeGuesserInterface` as `ValidatorTypeGuesser` — nothing bundle-specific to learn.

| Field-name words | Position | → Type | Extra options | Confidence |
|---|---|---|---|---|
| `email` | anywhere | `EmailType` | — | `HIGH_CONFIDENCE` |
| `phone`, `telephone`, `tel`, `mobile`, `fax` | anywhere | `TelType` | — | `HIGH_CONFIDENCE` |
| `url`, `website`, `homepage`, `link` | **last word only** | `UrlType` | `default_protocol: null` | `HIGH_CONFIDENCE` |
| `color`, `colour` | last word only | `ColorType` | — | `HIGH_CONFIDENCE` |
| `search`, `query` | last word only | `SearchType` | — | `MEDIUM_CONFIDENCE` |

Field names are split into lowercase camelCase "words" before matching (`contactEmail` → `['contact', 'email']`).

**Why confidence never exceeds `HIGH_CONFIDENCE`.** A naming convention is not more trustworthy than an explicit constraint the developer actually wrote — it's a peer signal, not a superior one, so it's held to the same ceiling `ValidatorTypeGuesser` uses for its own confident, constraint-based guesses. `search` stays lower still, at `MEDIUM_CONFIDENCE` — a cosmetic, lower-certainty match.

**Why `url`/`color`/`search` are anchored to the last word but `email`/`tel` aren't.** `urlSlug` and `urlPath` are common field names that are not URLs — anchoring to the last word excludes them while still matching `websiteUrl`/`profileUrl`. No comparably common `string`-typed counter-example was found for `email`/`tel` once non-`string` Doctrine columns are already excluded upstream (a boolean `sendEmailReminder` flag, for instance, never reaches this guesser at all).

**Why `password` isn't here at all.** See [Passwords: Deferred](#passwords-deferred) below.

## Enabling Naming-Convention Guessing

Because `DoctrineFormTypeMapper` depends on Symfony's own `FormTypeGuesserInterface`, composing guessers together uses Symfony's own combinator — `Symfony\Component\Form\FormTypeGuesserChain` — rather than anything bundle-specific:

```yaml
# your app's (or a sibling bundle's) services.yaml
services:
    Kachnitel\DynamicFormBundle\Form\DoctrineFormTypeMapper:
        arguments:
            $typeGuesser: '@Symfony\Component\Form\FormTypeGuesserChain'

    Symfony\Component\Form\FormTypeGuesserChain:
        arguments:
            - ['@form.type_guesser.validator', '@Kachnitel\DynamicFormBundle\Form\TypeGuessing\ConventionalFieldTypeGuesser']
```

`ConventionalFieldTypeGuesser` is already an autowired service (it lives under this bundle's `src/Form/` resource scan) — nothing else to register. This is the whole opt-in: two service definitions, both stock Symfony classes.

If you're doing this from a sibling **bundle** rather than an application, a plain `services.yaml` argument override like this can lose a load-order race the same way a plain alias can — see [Real-World Example: kachnitel/admin-bundle](#real-world-example-kachniteladmin-bundle) for the compiler-pass version that doesn't.

## Writing Your Own Guesser

Any naming rule, not just tel/color/search, works the same way: implement `Symfony\Component\Form\FormTypeGuesserInterface` — the exact interface [Symfony's own custom-guesser cookbook](https://symfony.com/doc/current/reference/forms/types.html) teaches — and list it in the chain array above alongside or instead of `ConventionalFieldTypeGuesser`:

```php
namespace App\Form\TypeGuesser;

use Symfony\Component\Form\FormTypeGuesserInterface;
use Symfony\Component\Form\Guess\{Guess, TypeGuess, ValueGuess};
use App\Form\Type\IbanType;

final class IbanFieldGuesser implements FormTypeGuesserInterface
{
    public function guessType(string $class, string $property): ?TypeGuess
    {
        return str_ends_with(strtolower($property), 'iban')
            ? new TypeGuess(IbanType::class, [], Guess::HIGH_CONFIDENCE)
            : null;
    }

    public function guessRequired(string $class, string $property): ?ValueGuess { return null; }
    public function guessMaxLength(string $class, string $property): ?ValueGuess { return null; }
    public function guessPattern(string $class, string $property): ?ValueGuess { return null; }
}
```

`DoctrineFormTypeMapper` only ever calls `guessType()` — the other three methods exist purely to satisfy the interface Symfony defines; returning `null` from them is a complete, correct implementation. (They're not entirely inert, though: Symfony auto-tags any `FormTypeGuesserInterface` implementation with `form.type_guesser` via `registerForAutoconfiguration()`, so a guesser written this way also becomes available to your own hand-written forms elsewhere in the app, for free, if you ever call `$builder->add('field')` with an omitted type.)

## Passwords: Deferred

`password`/`plainPassword`/etc. are deliberately **not** in `ConventionalFieldTypeGuesser`'s pattern table, even though `PasswordType` would otherwise be the most obviously useful entry in it.

A nicer widget alone isn't the whole story for a field that maps directly to a password hash column, and Symfony doesn't currently offer a simple, built-in mechanism to pair widget-guessing with the hashing/submission-safety side of it — `PasswordType` (checked directly against its source, not just its docs) has exactly three options: `always_empty`, `trim`, `invalid_message`. No hashing hook, no "don't overwrite on blank" behavior. Specifically:

- `always_empty: true` stops a stored hash from ever being echoed back into the rendered HTML on an edit form — a real win, but purely cosmetic.
- It does **not** stop a blank submission from being treated as a valid empty string. If the column is `nullable: true`, a blank submission on an edit form can silently overwrite an existing hash. If it's `nullable: false`, this bundle's own `NotBlank` constraint prevents that specific outcome, but at the cost of forcing the user to retype a full password on every edit of *any* field on the entity — since `always_empty` means the box always renders blank regardless of whether a password is already set.
- Nothing hashes the submitted value. A raw, unhashed string written straight into a hash column breaks authentication for that record.

None of this is new risk *introduced* by naming-convention guessing — a `password`-named `string` column is already a fully mapped, directly-editable field today, rendered as plain `TextType`, which puts the actual current hash into the page's HTML on every edit. Guessing `PasswordType` for it would be a strict improvement on that status quo, not a regression. But shipping a guess that actively encourages treating a hash column as an ordinary form field — without also solving (or at least clearly steering people away from) the submission-safety problem above — was judged not worth it for now.

**What to do instead, today:** exclude a genuine password-hash column from the generated form entirely via `FieldEditabilityResolverInterface::canEdit()`, and handle it through the standard Symfony pattern outside `DynamicEntityFormType` — an unmapped `plainPassword` field, hashed via `UserPasswordHasherInterface` and written explicitly once the form is valid, only when non-blank. This bundle has no dependency on `symfony/security` and isn't the right place to own that flow regardless of whether naming-convention guessing ever covers `password`.

This is revisited if/when there's a clean way to address the hashing and blank-submission concerns together — a naming-convention guesser could still return `PasswordType` for the widget question alone, but pairing that with a hard warning or requiring an explicit opt-in specifically for password-shaped fields is a reasonable middle ground worth designing properly rather than bolting on now.

## Real-World Example: kachnitel/admin-bundle

This section is written to be actionable on its own — you shouldn't need the rest of this page to follow it, though the background above explains the *why* behind each piece.

**Decision**: `kachnitel/dynamic-form-bundle` ships `ConventionalFieldTypeGuesser` but leaves it off by default (see [Layer 2](#layer-2-conventionalfieldtypeguesser-opt-in) above — a naming convention shouldn't be forced on every consumer). `kachnitel/admin-bundle` is the bundle that opts its users into it by default, the same way it already wins the `FieldEditabilityResolverInterface` alias race today via `OverrideEditabilityResolversPass` (see [Editability](EDITABILITY.md#real-world-example-kachniteladmin-bundle)) rather than relying on a plain `services.yaml` alias that a sibling bundle's load order could silently override.

**Requirement**: this needs `kachnitel/dynamic-form-bundle` at the version that introduces `DoctrineFormTypeMapper`'s `$typeGuesser`/`$minimumGuessConfidence` constructor parameters and the `ConventionalFieldTypeGuesser` class. Bump `composer.json`'s constraint accordingly before adding the pass below — without it, `setArgument('$typeGuesser', ...)` on an older `DoctrineFormTypeMapper` definition (one with no such named argument) will throw at container-compile time.

**1. Add the compiler pass**, mirroring `OverrideEditabilityResolversPass`:

```php
<?php

declare(strict_types=1);

namespace Kachnitel\AdminBundle\DependencyInjection\Compiler;

use Kachnitel\DynamicFormBundle\Form\DoctrineFormTypeMapper;
use Kachnitel\DynamicFormBundle\Form\TypeGuessing\ConventionalFieldTypeGuesser;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Form\FormTypeGuesserChain;

/**
 * Opts admin-bundle's users into naming-convention type guessing
 * (tel/color/search/...) by default, layered on top of
 * dynamic-form-bundle's own constraint-driven guessing
 * (form.type_guesser.validator). dynamic-form-bundle ships this off by
 * default — see its docs/TYPE_GUESSING.md — admin-bundle is the bundle
 * that opts in, the same way OverrideEditabilityResolversPass already wins
 * the FieldEditabilityResolverInterface alias race regardless of bundle
 * registration order.
 */
final class OverrideTypeGuesserPass implements CompilerPassInterface
{
    private const MAPPER_SERVICE_ID = DoctrineFormTypeMapper::class;
    private const CHAIN_SERVICE_ID = 'kachnitel_admin_bundle.form_type_guesser_chain';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::MAPPER_SERVICE_ID)) {
            // dynamic-form-bundle isn't registered — nothing to wire.
            return;
        }

        $container->register(self::CHAIN_SERVICE_ID, FormTypeGuesserChain::class)
            ->setArguments([[
                new Reference('form.type_guesser.validator'),
                new Reference(ConventionalFieldTypeGuesser::class),
                // Add admin-bundle-specific naming guessers here, e.g. one that
                // reads #[AdminColumn] hints — see "Writing Your Own Guesser"
                // above. Order doesn't matter for confidence-based resolution;
                // it only decides which guess wins on an exact confidence tie.
            ]]);

        $container->getDefinition(self::MAPPER_SERVICE_ID)
            ->setArgument('$typeGuesser', new Reference(self::CHAIN_SERVICE_ID));
    }
}
```

**2. Register it** in `KachnitelAdminBundle::build()`, alongside the existing pass:

```php
class KachnitelAdminBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new OverrideEditabilityResolversPass());
        $container->addCompilerPass(new OverrideTypeGuesserPass());
    }
}
```

**3. Verify it.** A container-compilation test is the most direct proof this is wired correctly — build the container with both bundles registered, then assert on the resolved `DoctrineFormTypeMapper` service (or on the `$typeGuesser` argument of its definition, pre-compilation) that a `mobilePhone`-named field guesses `TelType` (a case only `ConventionalFieldTypeGuesser` has an opinion about — see [What Constraint-Driven Guessing Can't Cover](#what-constraint-driven-guessing-cant-cover)). This mirrors the pattern already used in `tests/Integration/Form/DoctrineFormTypeMapperValidatorGuesserTest.php` in dynamic-form-bundle itself (`composedChainAppliesConventionalGuessingWhereValidatorGuessingHasNoOpinion`), which composes the same two guessers by hand without a container at all — a useful reference for the assertions themselves, even though the wiring under test here is different.

**If admin-bundle wants its own additional naming rules** (for example, respecting an existing `#[AdminColumn]` attribute's own name-based hints) beyond what `ConventionalFieldTypeGuesser` covers: write a normal `FormTypeGuesserInterface` implementation (see [Writing Your Own Guesser](#writing-your-own-guesser) above) and add it to the array passed to `FormTypeGuesserChain` in step 1. Since every guesser in the chain either returns a confident guess or stays silent (`null`), and `ConventionalFieldTypeGuesser` never exceeds `HIGH_CONFIDENCE` on principle (see [Layer 2](#layer-2-conventionalfieldtypeguesser-opt-in)), adding more guessers to this array is safe — nothing here can silently override an explicit `#[Assert\...]`-driven guess.

## Testing

```bash
vendor/bin/phpunit --group type-guessing
```

| File | Covers |
|---|---|
| `tests/Unit/Form/TypeGuessing/ConventionalFieldTypeGuesserTest.php` | `ConventionalFieldTypeGuesser` in isolation |
| `tests/Unit/Form/DoctrineFormTypeMapperTypeGuessingTest.php` | The integration point in `DoctrineFormTypeMapper::getFieldConfig()`, against a mocked `FormTypeGuesserInterface` |
| `tests/Integration/Form/DoctrineFormTypeMapperValidatorGuesserTest.php` | The same integration point against a real, unmocked `ValidatorTypeGuesser`, plus the composed-chain override recipe end-to-end |
