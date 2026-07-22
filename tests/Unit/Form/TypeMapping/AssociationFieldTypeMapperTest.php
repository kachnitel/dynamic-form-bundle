<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form\TypeMapping;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Kachnitel\DynamicFormBundle\Form\DynamicEntityFormType;
use Kachnitel\DynamicFormBundle\Form\TypeMapping\AssociationFieldTypeMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * AssociationFieldTypeMapper owns association-to-form-field mapping,
 * extracted verbatim from DoctrineFormTypeMapper::getAssociationConfig()
 * and its three private builders — see docs/ASSOCIATIONS.md.
 *
 * @group type-mapping
 */
#[CoversClass(AssociationFieldTypeMapper::class)]
#[Group('type-mapping')]
class AssociationFieldTypeMapperTest extends TestCase
{
    /** @var ClassMetadata<object>&MockObject */
    private ClassMetadata $metadata;

    private AssociationFieldTypeMapper $mapper;

    protected function setUp(): void
    {
        $this->metadata = $this->createMock(ClassMetadata::class);
        $this->mapper   = new AssociationFieldTypeMapper();
    }

    // ── Single-valued (ManyToOne / OneToOne owning) ────────────────────────────

    #[Test]
    public function singleValuedAssociationReturnsEntityType(): void
    {
        $this->metadata->method('hasAssociation')->with('category')->willReturn(true);
        $this->metadata->method('isSingleValuedAssociation')->with('category')->willReturn(true);
        $this->metadata->method('getAssociationTargetClass')->with('category')->willReturn('App\Entity\Category');

        $config = $this->mapper->map($this->metadata, 'category');

        $this->assertNotNull($config);
        $this->assertSame(EntityType::class, $config['type']);
        $this->assertSame('App\Entity\Category', $config['options']['class']);
    }

    #[Test]
    public function singleValuedAssociationIsNotRequired(): void
    {
        $this->metadata->method('hasAssociation')->with('category')->willReturn(true);
        $this->metadata->method('isSingleValuedAssociation')->with('category')->willReturn(true);
        $this->metadata->method('getAssociationTargetClass')->with('category')->willReturn('App\Entity\Category');

        $config = $this->mapper->map($this->metadata, 'category');

        $this->assertNotNull($config);
        $this->assertFalse($config['options']['required']);
    }

    #[Test]
    public function singleValuedAssociationHasAutocompleteAndAdminEntityClassAttr(): void
    {
        $this->metadata->method('hasAssociation')->with('category')->willReturn(true);
        $this->metadata->method('isSingleValuedAssociation')->with('category')->willReturn(true);
        $this->metadata->method('getAssociationTargetClass')->with('category')->willReturn('App\Entity\Category');

        $config = $this->mapper->map($this->metadata, 'category');

        $this->assertNotNull($config);
        $this->assertTrue($config['options']['autocomplete']);
        /** @phpstan-ignore offsetAccess.nonOffsetAccessible */
        $this->assertSame('App\Entity\Category', $config['options']['attr']['data-admin-entity-class']);
    }

    // ── ManyToMany (owning side) ───────────────────────────────────────────────

    #[Test]
    public function manyToManyReturnsEntityTypeWithMultipleTrue(): void
    {
        $mapping = new ManyToManyOwningSideMapping('tags', 'App\Entity\Product', 'App\Entity\Tag');

        $this->metadata->method('hasAssociation')->with('tags')->willReturn(true);
        $this->metadata->method('isSingleValuedAssociation')->with('tags')->willReturn(false);
        $this->metadata->method('getAssociationMapping')->with('tags')->willReturn($mapping);
        $this->metadata->method('getAssociationTargetClass')->with('tags')->willReturn('App\Entity\Tag');

        $config = $this->mapper->map($this->metadata, 'tags');

        $this->assertNotNull($config);
        $this->assertSame(EntityType::class, $config['type']);
        $this->assertTrue($config['options']['multiple']);
        $this->assertFalse($config['options']['required']);
        $this->assertTrue($config['options']['autocomplete']);
        /** @phpstan-ignore offsetAccess.nonOffsetAccessible */
        $this->assertSame('App\Entity\Tag', $config['options']['attr']['data-admin-entity-class']);
    }

    // ── OneToMany → LiveCollectionType ─────────────────────────────────────────

    #[Test]
    public function oneToManyReturnsLiveCollectionType(): void
    {
        $mapping           = new OneToManyAssociationMapping('items', 'App\Entity\Order', 'App\Entity\OrderItem');
        $mapping->mappedBy = 'order';

        $this->metadata->method('hasAssociation')->with('items')->willReturn(true);
        $this->metadata->method('isSingleValuedAssociation')->with('items')->willReturn(false);
        $this->metadata->method('getAssociationMapping')->with('items')->willReturn($mapping);
        $this->metadata->method('getAssociationTargetClass')->with('items')->willReturn('App\Entity\OrderItem');

        $config = $this->mapper->map($this->metadata, 'items');

        $this->assertNotNull($config);
        $this->assertSame(LiveCollectionType::class, $config['type']);
    }

    #[Test]
    public function oneToManyUsesDynamicEntityFormTypeAsARecursiveEntryTypeWithChildFormOptions(): void
    {
        $mapping           = new OneToManyAssociationMapping('items', 'App\Entity\Order', 'App\Entity\OrderItem');
        $mapping->mappedBy = 'order';

        $this->metadata->method('hasAssociation')->with('items')->willReturn(true);
        $this->metadata->method('isSingleValuedAssociation')->with('items')->willReturn(false);
        $this->metadata->method('getAssociationMapping')->with('items')->willReturn($mapping);
        $this->metadata->method('getAssociationTargetClass')->with('items')->willReturn('App\Entity\OrderItem');

        $config = $this->mapper->map($this->metadata, 'items');

        $this->assertNotNull($config);
        $this->assertSame(DynamicEntityFormType::class, $config['options']['entry_type']);
        /** @phpstan-ignore offsetAccess.nonOffsetAccessible */
        $this->assertSame('App\Entity\OrderItem', $config['options']['entry_options']['entity_class']);
        /** @phpstan-ignore offsetAccess.nonOffsetAccessible */
        $this->assertSame('App\Entity\OrderItem', $config['options']['entry_options']['data_class']);
        /** @phpstan-ignore offsetAccess.nonOffsetAccessible */
        $this->assertFalse($config['options']['entry_options']['is_root']);
    }

    #[Test]
    public function oneToManyAllowsAddDeleteAndDisablesByReference(): void
    {
        $mapping           = new OneToManyAssociationMapping('items', 'App\Entity\Order', 'App\Entity\OrderItem');
        $mapping->mappedBy = 'order';

        $this->metadata->method('hasAssociation')->with('items')->willReturn(true);
        $this->metadata->method('isSingleValuedAssociation')->with('items')->willReturn(false);
        $this->metadata->method('getAssociationMapping')->with('items')->willReturn($mapping);
        $this->metadata->method('getAssociationTargetClass')->with('items')->willReturn('App\Entity\OrderItem');

        $config = $this->mapper->map($this->metadata, 'items');

        $this->assertNotNull($config);
        $this->assertTrue($config['options']['allow_add']);
        $this->assertTrue($config['options']['allow_delete']);
        $this->assertFalse($config['options']['by_reference']);
    }

    #[Test]
    public function oneToManyDoesNotIncludeTheAdminEntityClassAttr(): void
    {
        $mapping           = new OneToManyAssociationMapping('items', 'App\Entity\Order', 'App\Entity\OrderItem');
        $mapping->mappedBy = 'order';

        $this->metadata->method('hasAssociation')->with('items')->willReturn(true);
        $this->metadata->method('isSingleValuedAssociation')->with('items')->willReturn(false);
        $this->metadata->method('getAssociationMapping')->with('items')->willReturn($mapping);
        $this->metadata->method('getAssociationTargetClass')->with('items')->willReturn('App\Entity\OrderItem');

        $config = $this->mapper->map($this->metadata, 'items');

        $this->assertNotNull($config);
        $this->assertArrayNotHasKey('attr', $config['options']);
    }

    // ── Nonexistent association ──────────────────────────────────────────────

    #[Test]
    public function nonExistentAssociationReturnsNull(): void
    {
        $this->metadata->method('hasAssociation')->with('nonexistent')->willReturn(false);

        $config = $this->mapper->map($this->metadata, 'nonexistent');

        $this->assertNull($config);
    }
}
