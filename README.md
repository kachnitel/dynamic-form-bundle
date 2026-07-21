# Kachnitel Dynamic Form Bundle
<!-- BADGES -->
![Tests](<https://img.shields.io/badge/tests-178%20passed-brightgreen>)
![Coverage](<https://img.shields.io/badge/coverage-96%25-brightgreen>)
![Assertions](<https://img.shields.io/badge/assertions-485-blue>)
![PHPStan](<https://img.shields.io/badge/PHPStan-10-brightgreen>)
![PHP](<https://img.shields.io/badge/PHP-&gt;=8.2-777BB4?logo=php&logoColor=white>)
![Symfony](<https://img.shields.io/badge/Symfony-^6.4|^7.0|^8.0-000000?logo=symfony&logoColor=white>)
<!-- BADGES -->
![Doctrine ORM](https://img.shields.io/badge/Doctrine%20ORM-%5E3.5-FC6A31?logo=doctrine&logoColor=white)
![PHPStan](https://img.shields.io/badge/PHPStan-level%2010-brightgreen)
![License](https://img.shields.io/badge/license-MPL--2.0-blue)

Zero-configuration Symfony form generation from Doctrine entity metadata. Point `DynamicEntityFormType` at an entity and get a working create/edit form — scalar fields, associations, and [Symfony UX](https://symfony.com/bundles/ux-live-component/current/index.html) LiveComponent collections included — without writing a `FormType` class.

Extracted from [kachnitel/admin-bundle](https://github.com/kachnitel/FrdAdminBundle), where it serves as the auto-form engine behind the generic CRUD controller. Usable standalone in any Symfony + Doctrine application. **License: MPL-2.0** (file-level copyleft, compatible with proprietary use — see [License](#license)).

## Quick Start

### 1. Install

```bash
composer require kachnitel/dynamic-form-bundle
```

Register in `config/bundles.php`:

```php
Kachnitel\DynamicFormBundle\KachnitelDynamicFormBundle::class => ['all' => true],
```

No further configuration — the bundle has no config tree.

### 2. Build a form

```php
use Kachnitel\DynamicFormBundle\Form\DynamicEntityFormType;

$form = $this->createForm(DynamicEntityFormType::class, $product, [
    'entity_class' => Product::class,
]);
```

`entity_class` is the only required option. `data_class` defaults to `entity_class`, so binding the form straight to `Product` needs nothing further — pass `data_class` explicitly only when it should differ (a DTO, or `null` for an unmapped form). See [Form Options](#form-options) for all options.

### 3. That's it

Every non-identifier scalar field and owning-side association on `Product` gets a form field, with type-appropriate widgets, validation, and nullability handling derived from Doctrine metadata. Doctrine `string` fields with a matching validator constraint (`#[Assert\Email]`, `#[Assert\Url]`, …) are upgraded to the corresponding widget automatically — see [Type Guessing](docs/TYPE_GUESSING.md).

## Form Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `entity_class` | `string` | **required** | Fully-qualified entity class name to introspect |
| `data_class` | `string\|null` | `entity_class` | The class submitted values are mapped onto. Lazily defaults to `entity_class` — only evaluated when omitted entirely. Pass explicitly to bind elsewhere, including `null` for an unmapped form bound to a plain array/DTO |
| `is_root` | `bool` | `true` | Set `false` for child forms inside `LiveCollectionType` to prevent collection associations from being re-added (avoids infinite recursion in bidirectional relationships) |
| `entity_instance` | `object\|null` | `null` | The entity being edited. Forwarded to `FieldEditabilityResolverInterface` for per-row editability checks; pass a fresh `new Entity()` for create forms if your resolver needs an instance |

## Supported Field Types

| Doctrine type | Form type | Widget options |
|---|---|---|
| `string` | `TextType` | Upgraded by constraint or naming convention — see [Type Guessing](docs/TYPE_GUESSING.md) |
| `text` | `TextareaType` | |
| `integer`, `smallint`, `bigint` | `IntegerType` | |
| `decimal`, `float` | `NumberType` | `html5: true` |
| `boolean` | `CheckboxType` | Always `required: false` |
| `date`, `date_immutable` | `DateType` | `widget: single_text` |
| `datetime`, `datetimetz`, `datetime_immutable`, `datetimetz_immutable` | `DateTimeType` | `widget: single_text` |
| `time`, `time_immutable` | `TimeType` | `widget: single_text` |
| Backed PHP enum (via `enumType`) | `EnumType` | |

`json`, `array`, `simple_array`, `object`, `blob`, `binary` are silently skipped — no field, no error.

Nullability, `empty_data`, and required-field validation have non-obvious behaviour driven by Symfony transformer quirks. See [Field Mapping](docs/FIELD_MAPPING.md) for the full story.

## Associations

| Doctrine type | Form type | UI |
|---|---|---|
| `ManyToOne`, `OneToOne` (owning) | `EntityType` | Autocomplete dropdown |
| `ManyToMany` (owning side) | `EntityType` with `multiple: true` | Autocomplete multi-select |
| `OneToMany` | `LiveCollectionType` with recursive `DynamicEntityFormType` | Add / remove rows |

Two categories of association are **automatically skipped** — re-include them by having `FieldEditabilityResolverInterface::isExplicitOverride()` return `true` for that property:

- Inverse-side associations (`mappedBy` set) — except `OneToMany` in root forms, which is always kept
- Single-valued associations with `inversedBy` set (a ManyToOne/OneToOne owning side pointing back at a parent's collection)

> **Default behaviour with `AlwaysEditableFieldResolver`:** both `canEdit()` and `isExplicitOverride()` return `true` unconditionally, so all associations — including the normally-skipped categories above — are included by default. The auto-skip rules only take effect once you bind a resolver that returns `false` for `isExplicitOverride()` on associations it does not explicitly opt back in.

See [Associations](docs/ASSOCIATIONS.md) for the full auto-skip table, `OneToMany` `cascade`/`orphanRemoval`/adder-remover requirements, and troubleshooting.

## Controlling Field Inclusion

```php
interface FieldEditabilityResolverInterface
{
    // General include/exclude gate. $entity is null when building a "new entity" form
    // or before LiveCollectionType binds a row — treat null as "include provisionally"
    // (DynamicFormEditabilityListener re-checks on PRE_SET_DATA once a real instance exists).
    public function canEdit(string $entityClass, string $property, ?object $entity = null): bool;

    // Opt back in to a structurally auto-skipped association (inverse side or parent back-reference).
    // Must return true ONLY for an explicit per-property override, never by entity-level default —
    // a permissive entity-wide default must not silently pull every back-reference into the form.
    public function isExplicitOverride(string $entityClass, string $property, ?object $entity = null): bool;
}
```

Default binding: `AlwaysEditableFieldResolver` — both methods return `true`. Override in `services.yaml`:

```yaml
Kachnitel\DynamicFormBundle\Editability\FieldEditabilityResolverInterface:
    alias: App\Form\MyFieldEditabilityResolver
```

See [Editability](docs/EDITABILITY.md) for the full contract, the two-method design rationale, `DynamicFormEditabilityListener` (the `PRE_SET_DATA` re-check), and a `kachnitel/admin-bundle` compiler-pass example.

## Controlling Field Widgets

Doctrine `string` fields are upgraded from `TextType` to a more specific widget when a matching validator constraint is present — `#[Assert\Email]` → `EmailType`, `#[Assert\Url]` → `UrlType`, `#[Assert\Country]` → `CountryType`, etc. Powered by Symfony's own `form.type_guesser.validator`, wired automatically.

An optional naming-convention guesser (`ConventionalFieldTypeGuesser`, ships in this bundle) covers `tel`/`color`/`search`/`email`/`url` by field name for fields with no matching constraint. Not enabled by default — see [Type Guessing](docs/TYPE_GUESSING.md) for the opt-in recipe and how to write your own guesser.

<details>
<summary>How It Works</summary>

Four collaborating pieces:

| Class | Responsibility |
|---|---|
| `DoctrineFormTypeMapper` | Maps a single Doctrine field/association mapping to a Symfony form field config (type + options) |
| `DynamicEntityFormType` | Walks entity metadata, calls the mapper for each field/association, decides what to include |
| `FieldEditabilityResolverInterface` | Extension point — decides whether a property should be in the form at all |
| `FormTypeGuesserInterface` (Symfony's own) | Extension point — upgrades a `string` field to a more specific type than `TextType` |

`DynamicEntityFormType` has no knowledge of attributes, expressions, or permissions — every inclusion decision beyond Doctrine's structural rules (the identifier field, unsupported types) is delegated to the injected `FieldEditabilityResolverInterface`. The bundle ships a permissive default; consumers override the service alias.

`DynamicFormEditabilityListener` is a `FormEvents::PRE_SET_DATA` listener manually registered inside `DynamicEntityFormType::buildForm()` (not a DI-managed service — it needs the specific entity class and resolver instance from that build call). It re-runs `canEdit()` for every field once a real entity instance is bound, covering `LiveCollectionType` child forms where `buildForm()` runs before any row data is available. It can only **remove** fields already added by `buildForm()`; it never re-adds an association that was skipped at build time.

</details>

## Documentation

| Guide | Covers |
|---|---|
| [Field Mapping](docs/FIELD_MAPPING.md) | Full type table, nullability cross-check, `empty_data` transformer quirks, `RequiredValueTransformer`, duplicate-validation prevention |
| [Associations](docs/ASSOCIATIONS.md) | Collection mapping, `cascade`/`orphanRemoval`/adder-remover requirements, auto-skip rules, infinite-recursion prevention, troubleshooting |
| [Editability](docs/EDITABILITY.md) | `FieldEditabilityResolverInterface` contract, two-method design rationale, `DynamicFormEditabilityListener`, `kachnitel/admin-bundle` real-world example |
| [Type Guessing](docs/TYPE_GUESSING.md) | Constraint-driven and naming-convention widget upgrades, `FormTypeGuesserInterface` extension point, `kachnitel/admin-bundle` opt-in recipe |

<details>
<summary>Development</summary>

```bash
composer test       # phpstan (level 10) + phpcs + phpunit + phpmd
composer phpstan
composer phpunit
composer phpmd
```

Run a specific group:

```bash
vendor/bin/phpunit --group auto-form        # DynamicEntityFormType + DoctrineFormTypeMapper core
vendor/bin/phpunit --group collections      # Association collection handling
vendor/bin/phpunit --group dynamic-form     # DynamicEntityFormType + DynamicFormEditabilityListener
vendor/bin/phpunit --group editability      # AlwaysEditableFieldResolver
vendor/bin/phpunit --group form-transformers
vendor/bin/phpunit --group form-exceptions  # NullabilityMismatchException
vendor/bin/phpunit --group inline-add       # data-admin-entity-class attr on EntityType
vendor/bin/phpunit --group type-guessing    # ConventionalFieldTypeGuesser + mapper integration
vendor/bin/phpunit --group integration      # Real ValidatorTypeGuesser smoke tests
```

</details>

<details>
<summary>Requirements</summary>

- PHP 8.2+
- Symfony 6.4 / 7.0 / 8.0 — `doctrine-bridge`, `form`, `framework-bundle`, `validator`
- Doctrine ORM 3.5+, `doctrine/doctrine-bundle` ^3.0
- Symfony UX Live Component ^2.13 (for `OneToMany` → `LiveCollectionType`)
- Symfony UX Autocomplete ^3.0 (for association `EntityType` autocomplete)
- `symfony/intl` *(suggested)* — required at runtime if any entity uses `#[Assert\Country]`, `#[Assert\Language]`, `#[Assert\Currency]`, or `#[Assert\Locale]` — see [Type Guessing](docs/TYPE_GUESSING.md)

</details>

## License

**MPL-2.0** — see [LICENSE](LICENSE).

File-level copyleft: distributing a *modified* version of a file from this package requires that file's source to remain available under MPL-2.0. Using the package unmodified in a proprietary or closed-source application is fine. No network-use clause (unlike AGPL) — running it as part of a SaaS service triggers nothing extra. Compatible with `kachnitel/admin-bundle`'s MIT license.
