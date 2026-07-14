# Kachnitel Dynamic Form Bundle

![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4?logo=php&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony-%5E6.4%7C%5E7.0%7C%5E8.0-000000?logo=symfony&logoColor=white)
![Doctrine ORM](https://img.shields.io/badge/Doctrine%20ORM-%5E3.5-FC6A31?logo=doctrine&logoColor=white)
![PHPStan](https://img.shields.io/badge/PHPStan-level%2010-brightgreen)
![License](https://img.shields.io/badge/license-MPL--2.0-blue)

Zero-configuration Symfony form generation from Doctrine entity metadata. Point `DynamicEntityFormType` at an entity and get a working create/edit form — scalar fields, associations, and [Symfony UX](https://symfony.com/bundles/ux-live-component/current/index.html) LiveComponent collections included — without writing a `FormType` class.

Extracted from [kachnitel/admin-bundle](https://github.com/kachnitel/FrdAdminBundle), where it now serves as the auto-form engine behind the generic CRUD controller. It has no dependency on admin-bundle and is usable standalone in any Symfony + Doctrine application.

> **License note:** this package is **MPL-2.0**, not MIT like admin-bundle. It's a file-level copyleft license — compatible with proprietary and closed-source use — see [License](#license) below for what it actually requires.

## Quick Start

### 1. Install

```bash
composer require kachnitel/dynamic-form-bundle
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Kachnitel\DynamicFormBundle\KachnitelDynamicFormBundle::class => ['all' => true],
];
```

No further configuration — the bundle has no config tree yet.

### 2. Build a form

```php
use Kachnitel\DynamicFormBundle\Form\DynamicEntityFormType;

$form = $this->createForm(DynamicEntityFormType::class, $product, [
    'entity_class' => Product::class,
    'data_class'   => Product::class,
]);
```

`entity_class` is a required option. `data_class` isn't set by the form type itself — pass it yourself as shown, so submitted values land on the right entity; leaving it out means Symfony has no `PropertyAccessor` target to write to.

### 3. That's it

Every scalar field and every owning-side association on `Product` gets a form field, with a type-appropriate widget, validation, and nullability handling all derived straight from Doctrine metadata.

## How It Works

Three collaborating pieces, one job each:

| Class | Responsibility |
|---|---|
| `DoctrineFormTypeMapper` | Maps a single Doctrine field/association mapping to a Symfony form field config (type + options) |
| `DynamicEntityFormType` | Walks an entity's metadata, calls the mapper for each field/association, and decides what to include |
| `FieldEditabilityResolverInterface` | The one extension point — decides whether a given property should be in the form at all |

`DynamicEntityFormType` itself has no knowledge of attributes, expressions, or permissions — every inclusion/exclusion decision beyond Doctrine's own structure (the identifier field, unsupported types) is delegated to the injected `FieldEditabilityResolverInterface`. The bundle ships a permissive default that includes everything; consumers override the service alias to plug in their own policy. See [Editability](docs/EDITABILITY.md).

## Supported Field Types

| Doctrine type | Symfony form type |
|---|---|
| `string`, `text` | `TextType` / `TextareaType` |
| `integer`, `smallint`, `bigint` | `IntegerType` |
| `decimal`, `float` | `NumberType` |
| `boolean` | `CheckboxType` |
| `date`, `date_immutable` | `DateType` (single_text) |
| `datetime`, `datetimetz`, `datetime_immutable`, `datetimetz_immutable` | `DateTimeType` (single_text) |
| `time`, `time_immutable` | `TimeType` (single_text) |
| Backed PHP enum | `EnumType` |

`json`, `array`, `simple_array`, `object`, `blob`, `binary` have no sensible form widget and are silently skipped. Nullability, `empty_data`, and required-field validation all have non-obvious behaviour driven by real Symfony transformer quirks — see [Field Mapping](docs/FIELD_MAPPING.md) for the full story.

## Associations

| Doctrine type | Form field | UI |
|---|---|---|
| `ManyToOne`, `OneToOne` (owning) | `EntityType` | Autocomplete dropdown |
| `ManyToMany` (owning side) | `EntityType` with `multiple: true` | Autocomplete multi-select |
| `OneToMany` | `LiveCollectionType` with recursive `DynamicEntityFormType` | Add / remove rows |

Inverse-side associations and parent back-references are skipped automatically to avoid confusing, redundant controls — with an opt-in escape hatch. Full detail, including the `cascade`/`orphanRemoval`/adder-remover requirements for `OneToMany`, is in [Associations](docs/ASSOCIATIONS.md).

## Controlling Field Inclusion

Every field/association inclusion decision — beyond the identifier field and unsupported Doctrine types, which are always handled internally — goes through one interface:

```php
interface FieldEditabilityResolverInterface
{
    public function canEdit(string $entityClass, string $property, ?object $entity = null): bool;
    public function isExplicitOverride(string $entityClass, string $property, ?object $entity = null): bool;
}
```

The default binding, `AlwaysEditableFieldResolver`, includes everything unconditionally — matching the bundle's zero-config philosophy out of the box. Override the service alias in your own `services.yaml` to enforce a real policy (attribute-driven, role-driven, whatever fits your app). See [Editability](docs/EDITABILITY.md) for the full contract and a worked example.

`kachnitel/admin-bundle` is a real-world consumer: it binds this interface to `AdminColumnEditabilityResolver`, which reads the `#[AdminColumn(editable: ...)]` attribute — bound via a compiler pass rather than a plain alias, since it needs its override to win regardless of bundle registration order. Worth a look if you're solving the same "my default has to beat a sibling package's default" problem.

## Documentation

| Guide | Description |
|---|---|
| [Field Mapping](docs/FIELD_MAPPING.md) | Full Doctrine → Symfony type table, nullability rules, `empty_data` behaviour, `RequiredValueTransformer` |
| [Associations](docs/ASSOCIATIONS.md) | Collection handling, `cascade`/`orphanRemoval` requirements, auto-skip rules, recursion prevention, troubleshooting |
| [Editability](docs/EDITABILITY.md) | The `FieldEditabilityResolverInterface` extension point, with a worked custom-resolver example |

## Development

```bash
composer test       # phpstan (level 10) + phpcs + phpunit + phpmd
composer phpstan
composer phpunit
composer phpmd
```

Tests are organised into feature groups — run just what you're touching:

```bash
vendor/bin/phpunit --group auto-form
vendor/bin/phpunit --group collections
vendor/bin/phpunit --group dynamic-form
vendor/bin/phpunit --group editability
vendor/bin/phpunit --group form-transformers
vendor/bin/phpunit --group form-exceptions
vendor/bin/phpunit --group inline-add
```

## Requirements

- PHP 8.2+
- Symfony 6.4 / 7.0 / 8.0 — `doctrine-bridge`, `form`, `framework-bundle`, `validator`
- Doctrine ORM 3.5+, `doctrine/doctrine-bundle` ^3.0
- Symfony UX Live Component ^2.13 (for `OneToMany` → `LiveCollectionType`)
- Symfony UX Autocomplete ^3.0 (for association `EntityType` fields)

## License

**MPL-2.0** — see [LICENSE](LICENSE).

Mozilla Public License 2.0 is a file-level copyleft: if you distribute a *modified* version of a file from this package, that specific file's source has to stay available under MPL-2.0. It does **not** require you to open source a larger application that merely depends on this package unmodified, and — unlike AGPL, this package's earlier license — it has no network-use clause, so running it as part of a SaaS/network service doesn't trigger anything extra. That's the compatibility reasoning behind the switch: it plays cleanly with `kachnitel/admin-bundle`'s MIT license and with closed-source consuming applications generally. This isn't legal advice; get your own read on it if licensing terms matter for your project.
