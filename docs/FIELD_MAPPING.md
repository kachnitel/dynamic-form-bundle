# Field Mapping

How `DoctrineFormTypeMapper` turns a single Doctrine field mapping into a Symfony form field config, and the non-obvious rules behind it.

## Table of Contents

- [Type Table](#type-table)
- [Unsupported Types](#unsupported-types)
- [Nullability Cross-Check](#nullability-cross-check)
- [Why `empty_data` Is Always `''`](#why-empty_data-is-always-)
- [`RequiredValueTransformer`](#requiredvaluetransformer)
- [Avoiding Duplicate Validation Messages](#avoiding-duplicate-validation-messages)
- [Enum Fields](#enum-fields)
- [Date / Time `input` Option](#date--time-input-option)

## Type Table

| Doctrine type | Symfony form type | Widget options |
|---|---|---|
| `string` | `TextType` | — |
| `text` | `TextareaType` | — |
| `integer`, `smallint`, `bigint` | `IntegerType` | — |
| `decimal`, `float` | `NumberType` | `html5: true` |
| `boolean` | `CheckboxType` | `required: false` (always) |
| `date` | `DateType` | `widget: single_text`, `input: datetime` |
| `date_immutable` | `DateType` | `widget: single_text`, `input: datetime_immutable` |
| `datetime`, `datetimetz` | `DateTimeType` | `widget: single_text`, `input: datetime` |
| `datetime_immutable`, `datetimetz_immutable` | `DateTimeType` | `widget: single_text`, `input: datetime_immutable` |
| `time` | `TimeType` | `widget: single_text`, `input: datetime` |
| `time_immutable` | `TimeType` | `widget: single_text`, `input: datetime_immutable` |
| Backed PHP enum (via Doctrine's `enumType` mapping) | `EnumType` | `class`, `choice_label`, `placeholder` |

Associations (`ManyToOne`, `OneToOne`, `ManyToMany`, `OneToMany`) are covered separately in [Associations](ASSOCIATIONS.md).

> **The `string` row isn't always the final answer.** Before any of the rules below run, `getFieldConfig()` first asks an injected `FormTypeGuesserInterface` whether a `string` column deserves something more specific than `TextType` — a matching `#[Assert\Email]`/`#[Assert\Url]`/etc. constraint upgrades it automatically, and an optional naming-convention guesser can cover a few more cases (`tel`, `color`, `search`). Everything below this point — nullability, `empty_data`, required-field handling — still applies to whatever type comes out of that step; guessing only changes *which* type is built, not how its options are derived. See [Type Guessing](TYPE_GUESSING.md) for the full mechanism.

## Unsupported Types

`json`, `array`, `simple_array`, `object`, `blob`, `binary` have no sensible Symfony form widget. `getFieldConfig()` returns `null` for these, and `DynamicEntityFormType` skips them silently — no error, no field.

## Nullability Cross-Check

Before building a field config, the mapper compares Doctrine's DB-level `nullable` flag against the PHP property's own type nullability (read via reflection — the same mechanism Doctrine itself uses to validate typed-property mappings):

| Doctrine `nullable` | PHP property allows `null`? | Result |
|---|---|---|
| `true` | yes | Fine — genuinely optional field, no guard needed |
| `true` | **no** | **`NullabilityMismatchException`** at form-build time |
| `false` | yes or no | Fine — see [`RequiredValueTransformer`](#requiredvaluetransformer) below |

The one combination that throws is real trouble waiting to happen: if the database can hold `NULL` but the PHP property type can't represent it, a `NULL` row crashes the moment it's hydrated — regardless of anything the form layer does. Rather than let that surface later as an unexplained `TypeError`, the mismatch is caught at form-build time, naming the exact field:

```
App\Entity\Product::$description is mapped nullable: true in Doctrine but its PHP
property type does not allow null. A NULL database row would crash on hydration.
Either widen the property to a nullable type, or set nullable: false on the
#[ORM\Column] mapping so the two agree.
```

Fix it on the entity, not around it: widen the property to `?string` (or whatever the type is), or tighten the column to `nullable: false`.

The reverse shape — Doctrine `nullable: false` with a PHP-nullable property — is normal and never throws. It's exactly what a "new entity, field not filled in yet" form needs.

## Why `empty_data` Is Always `''`

Every non-boolean scalar type in the table above — string through enum — uses `empty_data: ''`, never a literal `null`, regardless of whether the field is nullable.

This looks like it shouldn't matter (surely every transformer treats a blank string and a literal null the same?), but two of Symfony's own core transformers disagree:

- `DateTimeToHtml5LocalDateTimeTransformer::reverseTransform()` (used by `DateType`/`DateTimeType`/`TimeType`'s `single_text` widget) and `ChoiceToValueTransformer::reverseTransform()` (used by `EnumType`) both open with a guard that rejects anything that isn't a string outright. Only `''` reaches their own "return null for empty input" branch — a literal `null` fails the `is_string()` check first and throws a generic `TransformationFailedException` ("Please enter a valid date and time.") on a field that has nothing wrong with it.
- `NumberToLocalizedStringTransformer` (int/decimal/float) treats `null` and `''` identically, so this specific mismatch never showed up there — but that asymmetry isn't something worth relying on type-by-type. `''` is the one value confirmed safe across every affected transformer.

So `empty_data` is unconditionally `''` for every guarded type, and `RequiredValueTransformer` (below) is what actually enforces "this is required" — it only ever has to reject the one value every transformer safely resolves `''` to on its own: `null`.

## `RequiredValueTransformer`

Attached as a **model transformer** (`addModelTransformer()`) whenever `getFieldConfig()` returns `requiresValueGuard: true` — that's every guarded type (`integer`/`decimal`/`float`/date family/`time` family/enum) when the Doctrine column is `nullable: false`.

The submit-time flow for a blank required field:

1. View data (`''`, from `empty_data`) reaches the field type's own **view** transformer (`NumberToLocalizedStringTransformer`, `DateTimeToHtml5LocalDateTimeTransformer`, `ChoiceToValueTransformer`, …).
2. That transformer safely resolves `''` → `null` — its own documented behaviour, not a bug.
3. `RequiredValueTransformer`, a **model** transformer, runs next in the view→model direction and sees that `null`. It throws `TransformationFailedException`.
4. Symfony marks the field **not synchronized**. `PropertyAccessor` never gets a chance to write `null` onto the entity's non-nullable typed property — no raw `TypeError`.
5. The field's `invalid_message` option (e.g. `"Name is required."`) becomes the visible validation error.

`transform()` is the identity function — this transformer only ever guards the view→model direction, and it never sees the raw HTML input directly; it only ever receives what the type's own transformer already decided was blank.

## Avoiding Duplicate Validation Messages

`string`/`text` fields are **not** guarded by `RequiredValueTransformer` — `''` is already a type-safe value for a string property, so there's no `TypeError` risk. Required string/text fields get an actual `NotBlank` constraint instead — **but only if the entity property doesn't already declare its own validator constraint.**

Without that check, a property with its own `#[Assert\NotBlank]` would get a second, differently-worded constraint stacked on top, producing two separate error messages for the same blank field ("This value should not be blank." from the entity's own constraint, "Name is required." from the mapper). If the property has no constraint of its own, the mapper's `NotBlank` remains the default — plenty of entities are happy to let the bundle provide that behaviour rather than declaring it themselves.

This check only applies to string/text. Guarded types (int/decimal/date/time/enum) never reach their own `constraints` option once `RequiredValueTransformer` marks the field not-synchronized — `FormValidator` skips it in that case — so there's no equivalent duplication risk to check for there.

## Enum Fields

A field maps to `EnumType` when Doctrine's field mapping carries an `enumType` (i.e. `#[ORM\Column(enumType: Status::class)]`). `choice_label` resolves per-case via `displayValue()` if the enum defines it, falling back to `->name` otherwise. `placeholder` is `''` when nullable, `false` (no placeholder) when required.

Enum fields are resolved before any type guessing runs — see [Type Guessing](TYPE_GUESSING.md#why-scoped-to-doctrine-string-columns) — so an enum-backed column is never affected by a validator constraint or naming convention, even a coincidentally matching one.

## Date / Time `input` Option

`DateType`/`DateTimeType`/`TimeType`'s `input` option controls what PHP type `reverseTransform()` produces. Without setting it, the default (`'datetime'`) throws a `TypeError` when writing a `\DateTime` onto a `\DateTimeImmutable`-typed property. The mapper derives `input` straight from the Doctrine type suffix — `_immutable` → `datetime_immutable`, otherwise `datetime` — so mutable and immutable Doctrine types always land on the matching PHP class.

This derivation only ever runs for actual Doctrine `date`/`datetime`/`time` **columns**. A `string` column carrying `#[Assert\Date]` (see [Type Guessing](TYPE_GUESSING.md#layer-1-constraint-driven-guessing-the-default)) gets `input: 'string'` instead, from the guesser — correctly, since there's no Doctrine-derived PHP date type to match in that case.
