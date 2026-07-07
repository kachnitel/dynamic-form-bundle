<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Editability;

use Kachnitel\DynamicFormBundle\Editability\AlwaysEditableFieldResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @group editability
 */
#[CoversClass(AlwaysEditableFieldResolver::class)]
class AlwaysEditableFieldResolverTest extends TestCase
{
    private AlwaysEditableFieldResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AlwaysEditableFieldResolver();
    }

    // ── canEdit() ──────────────────────────────────────────────────────────────

    #[Test]
    public function canEditReturnsTrueWithNoEntityInstance(): void
    {
        $this->assertTrue($this->resolver->canEdit('App\Entity\Product', 'name'));
    }

    #[Test]
    public function canEditReturnsTrueWithAnEntityInstance(): void
    {
        $entity = new class {
            public string $name = '';
        };

        $this->assertTrue($this->resolver->canEdit($entity::class, 'name', $entity));
    }

    #[Test]
    public function canEditIsIndifferentToPropertyOrClassName(): void
    {
        $this->assertTrue($this->resolver->canEdit('Anything\At\All', 'whateverProperty'));
    }

    // ── isExplicitOverride() ───────────────────────────────────────────────────

    #[Test]
    public function isExplicitOverrideReturnsTrueWithNoEntityInstance(): void
    {
        $this->assertTrue($this->resolver->isExplicitOverride('App\Entity\OrderLineItem', 'order'));
    }

    #[Test]
    public function isExplicitOverrideReturnsTrueWithAnEntityInstance(): void
    {
        $entity = new class {
            public ?object $order = null;
        };

        $this->assertTrue($this->resolver->isExplicitOverride($entity::class, 'order', $entity));
    }
}
