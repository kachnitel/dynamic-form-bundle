# Associations

`DynamicEntityFormType` generates form fields for every Doctrine association on an entity, not just scalar fields — including collection-valued ones (`OneToMany`, `ManyToMany`). No hand-written `FormType` is required for any of it.

## Table of Contents

- [Association Types](#association-types)
- [Quick Example](#quick-example)
- [Requirements for `OneToMany`](#requirements-for-onetomany)
- [Preventing Infinite Recursion](#preventing-infinite-recursion)
- [Auto-Skip Rules](#auto-skip-rules)
- [Opting Associations In or Out](#opting-associations-in-or-out)
- [Using a Hand-Written `FormType` for a Child Entity](#using-a-hand-written-formtype-for-a-child-entity)
- [Troubleshooting](#troubleshooting)

## Association Types

| Doctrine type | Form field | UI |
|---|---|---|
| `ManyToOne` / `OneToOne` (owning) | `EntityType` | Autocomplete dropdown |
| `ManyToMany` (owning side) | `EntityType` with `multiple: true` | Autocomplete multi-select |
| `OneToMany` | `LiveCollectionType` with recursive `DynamicEntityFormType` | Add / remove rows |

`EntityType` configs for single-valued and `ManyToMany` associations get `attr: ['data-admin-entity-class' => $targetClass]` — a hook for consumers that want to render an inline "+ Add" affordance next to the autocomplete field. `OneToMany` doesn't get it, since `LiveCollectionType` already has its own add/remove UI. `kachnitel/admin-bundle`'s `EntityTypeAddButton` component is the reference consumer of this hook.

## Quick Example

```php
#[ORM\Entity]
class Order
{
    #[ORM\Column]
    private string $reference = '';

    // ManyToMany → multi-select autocomplete, no extra code needed
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    private Collection $tags;

    // OneToMany → add/remove rows (requires cascade + adder/remover — see below)
    #[ORM\OneToMany(
        targetEntity: OrderLine::class,
        mappedBy: 'order',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $lines;

    public function addLine(OrderLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines[] = $line;
            $line->setOrder($this);
        }
        return $this;
    }

    public function removeLine(OrderLine $line): self
    {
        if ($this->lines->removeElement($line)) {
            if ($line->getOrder() === $this) {
                $line->setOrder(null);
            }
        }
        return $this;
    }
}
```

Building `DynamicEntityFormType::class` against `Order` produces a full form with a tag multi-select and an add/remove line-item interface, with no `FormType` written.

---

## Requirements for `OneToMany`

### `cascade: ['persist', 'remove']`

Saving typically means one `$em->persist($order); $em->flush();` call. Without `cascade: ['persist']`, newly added child entities aren't tracked by Doctrine and the flush either ignores them or errors.

```php
// ✅ Required
#[ORM\OneToMany(
    targetEntity: OrderLine::class,
    mappedBy: 'order',
    cascade: ['persist', 'remove'],  // ← required
    orphanRemoval: true,             // ← required for remove to work
)]
private Collection $lines;
```

### `orphanRemoval: true`

When a row is removed from the `LiveCollectionType` UI, it's removed from the PHP collection. Without `orphanRemoval: true`, Doctrine leaves the row in the database; with it, the row is deleted on flush.

### Adder and Remover Methods

`DynamicEntityFormType` passes `by_reference: false` to `LiveCollectionType`, which tells Symfony Form to call **adder** and **remover** methods instead of replacing the whole collection. Symfony derives the method names by singularising the field name:

| Field name | Expected adder | Expected remover |
|---|---|---|
| `lines` | `addLine()` | `removeLine()` |
| `lineItems` | `addLineItem()` | `removeLineItem()` |
| `tags` | `addTag()` | `removeTag()` |

**Missing methods throw a `LogicException` at submit time.** The adder must also set the child's back-reference (the `mappedBy` field), and the remover must clear it — otherwise Doctrine persists the child without its FK value and the relationship is silently lost:

```php
public function addLine(OrderLine $line): self
{
    if (!$this->lines->contains($line)) {
        $this->lines[] = $line;
        $line->setOrder($this);  // ← sync back-reference
    }
    return $this;
}

public function removeLine(OrderLine $line): self
{
    if ($this->lines->removeElement($line)) {
        if ($line->getOrder() === $this) {
            $line->setOrder(null);  // ← break back-reference for orphanRemoval
        }
    }
    return $this;
}
```

---

## Preventing Infinite Recursion

The `is_root` option (default `true`) stops `DynamicEntityFormType` from recursing forever through bidirectional relationships:

- **Root form** (`is_root: true`) — includes everything, collections included.
- **Child form** (`is_root: false`) — skips collection-valued associations entirely.

`LiveCollectionType`'s `entry_options` sets `is_root: false` automatically when `DynamicEntityFormType` is the `entry_type` — you never set this yourself.

```
Order form (is_root: true)
├── reference          ← scalar
├── tags               ← ManyToMany → EntityType(multiple, autocomplete)
└── lines              ← OneToMany  → LiveCollectionType
    └── OrderLine child form (is_root: false)
        ├── description  ← scalar
        ├── quantity     ← scalar
        └── order        ← skipped automatically (back-reference — see below)
```

---

## Auto-Skip Rules

Two independent rules keep generated forms free of redundant or confusing controls.

### Rule 1 — Inverse-side associations (`mappedBy` set)

| Association | Has `mappedBy`? | Skipped? | Rationale |
|---|---|---|---|
| `OneToMany` | always | **Kept** | Managing child rows *is* the point of the parent form |
| `OneToOne` inverse | yes | Skipped | Managed by the owning side |
| `ManyToMany` inverse | yes | Skipped | Managed by the owning side |

### Rule 2 — Parent back-references (`inversedBy` set on a single-valued association)

A single-valued association with `inversedBy` marks this entity as the FK owner of a relationship whose other end is a collection — i.e. this is a child pointing back at its parent.

| Association | Has `inversedBy`? | Skipped? |
|---|---|---|
| `ManyToOne` without `inversedBy` | no | Kept — standalone relationship |
| `ManyToOne` with `inversedBy` | yes | Skipped — back-reference to parent's `OneToMany` |
| `OneToOne` owning with `inversedBy` | yes | Skipped — back-reference to parent |

```php
class OrderLine
{
    // Skipped automatically — inversedBy signals this is a parent back-reference.
    // The parent Order form owns this relationship; an Order dropdown inside the
    // OrderLine child form would be redundant and confusing.
    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'lines')]
    private ?Order $order = null;
}
```

## Opting Associations In or Out

Both rules — and collection inclusion generally — are decided by whatever `FieldEditabilityResolverInterface` implementation you've bound. `canEdit()` returning `false` excludes a field/association outright; `isExplicitOverride()` returning `true` re-includes an association that Rule 1 or Rule 2 would otherwise auto-skip.

```php
final class MyFieldEditabilityResolver implements FieldEditabilityResolverInterface
{
    public function canEdit(string $entityClass, string $property, ?object $entity = null): bool
    {
        // Exclude a large, internal-only collection from every generated form
        return !($entityClass === Product::class && $property === 'auditLog');
    }

    public function isExplicitOverride(string $entityClass, string $property, ?object $entity = null): bool
    {
        // Opt a specific back-reference back in
        return $entityClass === UserProfile::class && $property === 'user';
    }
}
```

If you're using `kachnitel/admin-bundle`, this is exactly what `#[AdminColumn(editable: false)]` and `#[AdminColumn(editable: true)]` do under the hood — see its [Forms guide](https://github.com/kachnitel/FrdAdminBundle/blob/master/docs/FORMS.md) and [Editability](EDITABILITY.md) in this repo for the resolver contract itself.

## Using a Hand-Written `FormType` for a Child Entity

For full control over a child entity's form — including deliberately showing a back-reference, or custom validation — write a normal `FormType` for the child. Wherever you resolve form types for child entities (e.g. `kachnitel/admin-bundle`'s `GenericAdminController` does this via a priority chain), a hand-written `FormType` takes priority over `DynamicEntityFormType` and gets picked up as the `entry_type` automatically:

```php
class OrderLineFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('description')
            ->add('quantity')
            // Explicit — DynamicEntityFormType would skip this automatically:
            ->add('order', EntityType::class, ['class' => Order::class]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OrderLine::class]);
    }
}
```

## Troubleshooting

### Items appear doubled after saving or adding

**Most common cause: the adder doesn't sync the back-reference.** If `addLine()` adds `$line` to the collection but never calls `$line->setOrder($this)`, Doctrine persists the child without its FK value. It can't be fetched back as part of `$order->getLines()` on the next load, and a fresh copy may get persisted on every save — an ever-growing list of orphaned rows. Fix: make sure the adder syncs the back-reference, as shown above.

**Missing `cascade: ['persist']`:** newly created children exist in the PHP collection but aren't tracked by Doctrine, so a flush ignores them — and if your own code separately persists them from a stale reference, you can end up with doubles.

**Missing `orphanRemoval: true`:** a removed row disappears from the PHP collection but stays in the database, and reappears on the next page load.

**LiveComponent DOM morphing without stable IDs:** LiveComponent morphs re-rendered HTML using element `id` attributes as anchors. If a custom form theme drops the `id="{{ form.vars.id }}"` on each collection entry, morphing may create new elements instead of updating existing ones — visual doubling that a hard refresh "fixes". Each entry needs a unique id (`order_lines_0`, `order_lines_1`, …).

### Remove button has no effect

1. Check `orphanRemoval: true` is set on the `OneToMany` mapping.
2. Check the remover clears the back-reference (`$child->setParent(null)`) — without it, the FK column still points at the parent and Doctrine won't treat the row as an orphan even with `orphanRemoval: true`.

### `LogicException: Unable to set value of the collection` at submit

Symfony Form can't find the adder/remover methods. Double check they match Symfony's singularisation of the field name (`lines` → `addLine()`/`removeLine()`, `lineItems` → `addLineItem()`/`removeLineItem()`). For irregular field names, check `StringUtil::singularify()` or fall back to a hand-written `FormType` where you control collection handling explicitly.

### New children are lost after flush

Check `cascade: ['persist']` is actually set — without it, `$em->persist($parent); $em->flush();` silently ignores unmanaged child objects.
