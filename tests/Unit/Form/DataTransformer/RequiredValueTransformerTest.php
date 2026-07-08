<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form\DataTransformer;

use Kachnitel\DynamicFormBundle\Form\DataTransformer\RequiredValueTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * @group form-transformers
 */
#[CoversClass(RequiredValueTransformer::class)]
class RequiredValueTransformerTest extends TestCase
{
    private RequiredValueTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new RequiredValueTransformer();
    }

    // ── transform() is the identity function ──────────────────────────────────

    #[Test]
    public function transformReturnsTheValueUnchanged(): void
    {
        $this->assertSame('some value', $this->transformer->transform('some value'));
    }

    #[Test]
    public function transformReturnsNullUnchanged(): void
    {
        $this->assertNull($this->transformer->transform(null));
    }

    #[Test]
    public function transformReturnsNonStringValuesUnchanged(): void
    {
        $this->assertSame(42, $this->transformer->transform(42));
    }

    // ── reverseTransform() rejects null, passes through everything else ────────

    #[Test]
    public function reverseTransformThrowsOnNull(): void
    {
        $this->expectException(TransformationFailedException::class);

        $this->transformer->reverseTransform(null);
    }

    #[Test]
    public function reverseTransformPassesThroughAnyNonNullValue(): void
    {
        $this->assertSame('2030-06-15', $this->transformer->reverseTransform('2030-06-15'));
    }

    #[Test]
    public function reverseTransformPassesThroughEmptyString(): void
    {
        // Only a literal null is rejected — '' is a value like any other to this
        // transformer. It's DoctrineFormTypeMapper's empty_data: '' plus Symfony's
        // own core transformers that turn a blank submission into '' and then null
        // upstream of this class; this class itself has no special case for it.
        $this->assertSame('', $this->transformer->reverseTransform(''));
    }

    #[Test]
    public function reverseTransformPassesThroughZero(): void
    {
        $this->assertSame(0, $this->transformer->reverseTransform(0));
    }

    #[Test]
    public function reverseTransformPassesThroughFalse(): void
    {
        $this->assertFalse($this->transformer->reverseTransform(false));
    }
}
