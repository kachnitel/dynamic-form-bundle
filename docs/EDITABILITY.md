# Editability

`DynamicEntityFormType` has no built-in concept of "this field shouldn't be editable" beyond two hard-coded, structural cases: the entity's identifier field, and Doctrine types with no form equivalent (see [Field Mapping](FIELD_MAPPING.md#unsupported-types)). Every other inclusion/exclusion decision — attribute-driven, role-driven, expression-driven, whatever your app needs — goes through one interface.

## Table of Contents

- [The Interface](#the-interface)
- [Why Two Methods](#why-two-methods)
- [The Default: `AlwaysEditableFieldResolver`](#the-default-alwayseditablefieldresolver)
- [Writing Your Own Resolver](#writing-your-own-resolver)
- [When `$entity` Is `null`](#when-entity-is-null)
- [`DynamicFormEditabilityListener`](#dynamicformeditabilitylistener)
- [Real-World Example: `kachnitel/admin-bundle`](#real-world-example-kachniteladmin-bundle)

## The Interface

```php
namespace Kachnitel\DynamicFormBundle\Editability;

interface FieldEditabilityResolverInterface
{
    public function canEdit(string $entityClass, string $property, ?object $entity = null): bool;

    public function isExplicitOverride(string $entityClass, string $property, ?object $entity = null): bool;
}
```

`DynamicEntityFormType` and `DynamicFormEditabilityListener` depend only on this interface — neither has any knowledge of attributes, `ExpressionLanguage`, or a security checker. That coupling lives entirely in whatever implementation you bind.

## Why Two Methods

`canEdit()` and `isExplicitOverride()` answer genuinely different questions and must not be collapsed into one:

- **`canEdit()`** is the general include/exclude gate for an ordinary field. Implementations are expected to fall back to some entity-level default (e.g. "included unless this specific field is blocked") when there's no per-property override.
- **`isExplicitOverride()`** is consulted only to decide whether a *structurally* auto-skipped association — the inverse side of a bidirectional relationship, or a `ManyToOne` back-reference to a parent's collection, see [Associations](ASSOCIATIONS.md#auto-skip-rules) — should be added back into the form. It must return `true` only for an explicit, per-property opt-in, **never** by falling back to an entity-level default. An entity with a permissive `canEdit()` default must not silently pull every auto-skipped back-reference into the generated form just because that default happens to be permissive.

## The Default: `AlwaysEditableFieldResolver`

```php
final class AlwaysEditableFieldResolver implements FieldEditabilityResolverInterface
{
    public function canEdit(string $entityClass, string $property, ?object $entity = null): bool
    {
        return true;
    }

    public function isExplicitOverride(string $entityClass, string $property, ?object $entity = null): bool
    {
        return true;
    }
}
```

This is the out-of-the-box binding — every field is editable, matching the bundle's zero-config philosophy. Note that it returns `true` for `isExplicitOverride()` too, which means auto-skipped back-references **are** included by default when nothing else is bound. If you want the structural auto-skip rules to actually apply, you need a resolver that distinguishes the two — the permissive default doesn't.

## Writing Your Own Resolver

```php
// src/Form/MyFieldEditabilityResolver.php
namespace App\Form;

use Kachnitel\DynamicFormBundle\Editability\FieldEditabilityResolverInterface;

final class MyFieldEditabilityResolver implements FieldEditabilityResolverInterface
{
    private const BLOCKED = ['internalNotes', 'legacyId'];

    public function canEdit(string $entityClass, string $property, ?object $entity = null): bool
    {
        return !in_array($property, self::BLOCKED, true);
    }

    public function isExplicitOverride(string $entityClass, string $property, ?object $entity = null): bool
    {
        return false; // no structural back-references opted back in, for now
    }
}
```

```yaml
# config/services.yaml
services:
    Kachnitel\DynamicFormBundle\Editability\FieldEditabilityResolverInterface:
        alias: App\Form\MyFieldEditabilityResolver
```

That's the whole integration surface — one interface, one alias. (If a sibling bundle in your app *also* ships a default alias for this same interface, a plain alias can be overridden by load order — see the admin-bundle example below for how to make your override unconditional.)

## When `$entity` Is `null`

`$entity` is `null` when no instance exists yet to evaluate against: building a "new entity" form before anything is persisted, or a `LiveCollectionType` child form before it's been bound to a row. Implementations that need a concrete instance to evaluate a per-row condition (an `ExpressionLanguage` string, for example) should treat `null` as "can't resolve yet, default to include" for `canEdit()` — see the next section for how that gets re-checked once a real entity is available.

## `DynamicFormEditabilityListener`

A `FormEvents::PRE_SET_DATA` listener, manually instantiated inside `DynamicEntityFormType::buildForm()` (it's excluded from service autowiring — see `config/services.yaml` — because it needs the specific `$entityClass` and resolver instance from that build call, not a generically autowired one).

It exists to give `canEdit()` a second chance once a real entity instance is actually bound to the form — essential for `LiveCollectionType` child forms, where `buildForm()` runs before any row's data exists, so a per-row `canEdit()` check made at build time may have had nothing to evaluate against. Once `PRE_SET_DATA` fires with a genuine (even freshly-`new`'d) entity, the listener re-asks `canEdit()` for every field currently on the form and removes any it now rejects.

Two things it deliberately does **not** do:

- It only ever **removes** fields — it can't add back a field `buildForm()` didn't add in the first place. An association skipped at build time because `isExplicitOverride()` couldn't be resolved without an entity stays skipped; this listener never revisits that decision.
- It only re-checks `canEdit()`, never `isExplicitOverride()` — the narrower structural-override question is answered once, at build time, and isn't reconsidered here.

## Real-World Example: `kachnitel/admin-bundle`

[`kachnitel/admin-bundle`](https://github.com/kachnitel/FrdAdminBundle) overrides the interface with `AdminColumnEditabilityResolver`, which reads a single `#[AdminColumn(editable: ...)]` attribute:

**`canEdit()` precedence:**

1. No `#[AdminColumn]` attribute → included (permissive default)
2. `editable: false` → excluded, short-circuits everything else
3. `editable: 'some expression'` → evaluated against the entity; if no entity is available yet, included provisionally (re-checked by `DynamicFormEditabilityListener` once one is bound)
4. `editable: true` → included

**`isExplicitOverride()` precedence:**

1. No attribute → not overridden
2. `editable: false` → not overridden
3. `editable: true` → overridden (bypasses the structural auto-skip)
4. `editable: 'some expression'` → evaluated if an entity is available, otherwise not overridden yet

One deliberate design choice worth calling out: **this resolver never reads `#[Admin(enableInlineEdit: ...)]`.** admin-bundle has a *second*, separate editability interface (from `kachnitel/entity-components-bundle`) for its list-view inline-edit feature, and that one's resolver *does* fall back to `enableInlineEdit` as an entity-level default — because inline editing is opt-in. Form generation is not opt-in: a property with no `#[AdminColumn]` at all must still appear on the New/Edit form. If `AdminColumnEditabilityResolver` also consulted `enableInlineEdit`, every entity without that flag set would lose every field from its generated form. Two attributes, two different fallback semantics, on purpose — a useful reminder that "editable" can mean genuinely different things to different features consuming the same entity metadata.

**On the binding itself:** admin-bundle doesn't set this alias in `services.yaml`. Both `kachnitel/dynamic-form-bundle` (this package, via `AlwaysEditableFieldResolver`) and `kachnitel/entity-components-bundle` ship their own default alias for the interface they each own, and Symfony bundle extensions all load before any compiler pass runs — so whichever bundle's `services.yaml` happens to load last would otherwise win a plain-alias race, which is fragile and dependent on `config/bundles.php` order. admin-bundle instead registers a `CompilerPassInterface` in its own `Bundle::build()` that unconditionally re-asserts both aliases after every bundle extension has already loaded. If you're building something that needs its override to win no matter what order sibling packages happen to register in, this is the pattern:

```php
final class OverrideEditabilityResolversPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->setAlias(
            FieldEditabilityResolverInterface::class,
            AdminColumnEditabilityResolver::class,
        );
    }
}
```

```php
class KachnitelAdminBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new OverrideEditabilityResolversPass());
    }
}
```

For a simpler app with no sibling bundle shipping a competing default, a plain `services.yaml` alias (as in [Writing Your Own Resolver](#writing-your-own-resolver) above) is all you need — reach for a compiler pass only once ordering actually becomes a problem.
