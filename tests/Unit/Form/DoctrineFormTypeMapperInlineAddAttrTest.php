<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Kachnitel\DynamicFormBundle\Form\DoctrineFormTypeMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * Verifies that DoctrineFormTypeMapper embeds `data-admin-entity-class` in the
 * HTML attr array for EntityType-backed associations so that the
 * EntityTypeAddButton Twig component can be rendered from the form theme.
 */
#[CoversClass(DoctrineFormTypeMapper::class)]
#[Group('inline-add')]
class DoctrineFormTypeMapperInlineAddAttrTest extends TestCase
{
    /** @var ClassMetadata<object>&MockObject */
    private ClassMetadata $metadata;

    private DoctrineFormTypeMapper $mapper;

    protected function setUp(): void
    {
        $this->metadata = $this->createMock(ClassMetadata::class);
        $this->mapper   = new DoctrineFormTypeMapper();
    }

    // ── Single-valued (ManyToOne / OneToOne owning) ────────────────────────────

    #[Test]
    public function singleValuedAssociationIncludesAdminEntityClassAttr(): void
    {
        $this->metadata->method('hasAssociation')->with('category')->willReturn(true);
        $this->metadata->method('isSingleValuedAssociation')->with('category')->willReturn(true);
        $this->metadata->method('isCollectionValuedAssociation')->with('category')->willReturn(false);
        $this->metadata->method('getAssociationTargetClass')
            ->with('category')->willReturn('App\Entity\Category');

        /** @var array{options: array{attr: array{data-admin-entity-class: string}}}|null */
        $config = $this->mapper->getAssociationConfig($this->metadata, 'category');

        $this->assertNotNull($config);
        $this->assertArrayHasKey('attr', $config['options']); // @phpstan-ignore method.alreadyNarrowedType
        $this->assertSame(
            'App\Entity\Category',
            $config['options']['attr']['data-admin-entity-class'],
        );
    }

    #[Test]
    public function singleValuedAssociationAttrDoesNotAffectOtherOptions(): void
    {
        $this->metadata->method('hasAssociation')->with('category')->willReturn(true);
        $this->metadata->method('isSingleValuedAssociation')->with('category')->willReturn(true);
        $this->metadata->method('isCollectionValuedAssociation')->with('category')->willReturn(false);
        $this->metadata->method('getAssociationTargetClass')
            ->with('category')->willReturn('App\Entity\Category');

        $config = $this->mapper->getAssociationConfig($this->metadata, 'category');

        $this->assertNotNull($config);
        // Existing options must remain intact
        $this->assertTrue($config['options']['autocomplete']);
        $this->assertFalse($config['options']['required']);
    }

    // ── ManyToMany (owning side) ───────────────────────────────────────────────

    #[Test]
    public function manyToManyAssociationIncludesAdminEntityClassAttr(): void
    {
        $mapping = new ManyToManyOwningSideMapping(
            'tags',
            'App\Entity\Product',
            'App\Entity\Tag',
        );

        $this->metadata->method('hasAssociation')->with('tags')->willReturn(true);
        $this->metadata->method('isSingleValuedAssociation')->with('tags')->willReturn(false);
        $this->metadata->method('isCollectionValuedAssociation')->with('tags')->willReturn(true);
        $this->metadata->method('getAssociationMapping')->with('tags')->willReturn($mapping);
        $this->metadata->method('getAssociationTargetClass')
            ->with('tags')->willReturn('App\Entity\Tag');

        /** @var array{options: array{attr: array{data-admin-entity-class: string}}}|null */
        $config = $this->mapper->getAssociationConfig($this->metadata, 'tags');

        $this->assertNotNull($config);
        $this->assertArrayHasKey('attr', $config['options']); // @phpstan-ignore method.alreadyNarrowedType
        $this->assertSame(
            'App\Entity\Tag',
            $config['options']['attr']['data-admin-entity-class'],
        );
    }

    // ── OneToMany → LiveCollectionType (no inline-add button) ─────────────────

    #[Test]
    public function oneToManyDoesNotIncludeAdminEntityClassAttr(): void
    {
        $mapping          = new OneToManyAssociationMapping(
            'items',
            'App\Entity\Order',
            'App\Entity\OrderItem',
        );
        $mapping->mappedBy = 'order';

        $this->metadata->method('hasAssociation')->with('items')->willReturn(true);
        $this->metadata->method('isSingleValuedAssociation')->with('items')->willReturn(false);
        $this->metadata->method('isCollectionValuedAssociation')->with('items')->willReturn(true);
        $this->metadata->method('getAssociationMapping')->with('items')->willReturn($mapping);
        $this->metadata->method('getAssociationTargetClass')
            ->with('items')->willReturn('App\Entity\OrderItem');

        $config = $this->mapper->getAssociationConfig($this->metadata, 'items');

        $this->assertNotNull($config);
        // OneToMany uses LiveCollectionType — inline-add is not applicable
        $this->assertSame(LiveCollectionType::class, $config['type']);
        $this->assertArrayNotHasKey('attr', $config['options']);
    }
}
