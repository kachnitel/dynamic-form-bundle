<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form\TypeMapping;

use Doctrine\ORM\Mapping\ClassMetadata;
use Kachnitel\DynamicFormBundle\Form\TypeMapping\FieldOptionsBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * FieldOptionsBuilder is DoctrineFormTypeMapper's extracted
 * scalarOptions()/hasExistingValidatorConstraint() pair — see
 * docs/FIELD_MAPPING.md for the empty_data/required/constraints rationale
 * this class implements unchanged from before the split.
 *
 * @group type-mapping
 */
#[CoversClass(FieldOptionsBuilder::class)]
#[Group('type-mapping')]
class FieldOptionsBuilderTest extends TestCase
{
    private FieldOptionsBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new FieldOptionsBuilder();
    }

    // ── scalarOptions(): required / empty_data baseline ─────────────────────────

    #[Test]
    public function nonNullableFieldIsRequired(): void
    {
        $options = $this->builder->scalarOptions('name', nullable: false);

        $this->assertTrue($options['required']);
    }

    #[Test]
    public function nullableFieldIsNotRequired(): void
    {
        $options = $this->builder->scalarOptions('name', nullable: true);

        $this->assertFalse($options['required']);
    }

    #[Test]
    public function emptyDataIsAlwaysAnEmptyStringRegardlessOfNullability(): void
    {
        $nullableOptions    = $this->builder->scalarOptions('name', nullable: true);
        $nonNullableOptions = $this->builder->scalarOptions('name', nullable: false);

        $this->assertSame('', $nullableOptions['empty_data']);
        $this->assertSame('', $nonNullableOptions['empty_data']);
    }

    // ── scalarOptions(): $extra is merged in ─────────────────────────────────────

    #[Test]
    public function extraOptionsAreMergedIntoTheResult(): void
    {
        $options = $this->builder->scalarOptions('startsAt', nullable: true, extra: ['widget' => 'single_text', 'input' => 'datetime']);

        $this->assertSame('single_text', $options['widget']);
        $this->assertSame('datetime', $options['input']);
    }

    // ── scalarOptions(): nullable short-circuits before guard/constraint logic ──

    #[Test]
    public function nullableFieldNeverGetsAnInvalidMessageEvenWhenGuarded(): void
    {
        $options = $this->builder->scalarOptions('qty', nullable: true, guarded: true);

        $this->assertArrayNotHasKey('invalid_message', $options);
    }

    #[Test]
    public function nullableFieldNeverGetsConstraintsEvenWithoutItsOwnConstraint(): void
    {
        $options = $this->builder->scalarOptions('name', nullable: true, hasOwnConstraint: false);

        $this->assertArrayNotHasKey('constraints', $options);
    }

    // ── scalarOptions(): guarded (int/decimal/date/time/enum) path ──────────────

    #[Test]
    public function guardedNonNullableFieldGetsInvalidMessageInsteadOfConstraints(): void
    {
        $options = $this->builder->scalarOptions('qty', nullable: false, guarded: true);

        $this->assertSame('Qty is required.', $options['invalid_message']);
        $this->assertArrayNotHasKey('constraints', $options);
    }

    #[Test]
    public function invalidMessageCapitalisesTheFieldName(): void
    {
        $options = $this->builder->scalarOptions('startsAt', nullable: false, guarded: true);

        $this->assertSame('StartsAt is required.', $options['invalid_message']);
    }

    // ── scalarOptions(): unguarded (string/text) path — NotBlank constraint ─────

    #[Test]
    public function unguardedNonNullableFieldWithNoOwnConstraintGetsANotBlankConstraint(): void
    {
        $options = $this->builder->scalarOptions('name', nullable: false, guarded: false, hasOwnConstraint: false);

        $this->assertArrayHasKey('constraints', $options);
        $this->assertCount(1, $options['constraints']); // @phpstan-ignore argument.type
        $this->assertInstanceOf(NotBlank::class, $options['constraints'][0]); // @phpstan-ignore offsetAccess.nonOffsetAccessible
        $this->assertArrayNotHasKey('invalid_message', $options);
    }

    #[Test]
    public function notBlankConstraintMessageNamesTheField(): void
    {
        $options = $this->builder->scalarOptions('name', nullable: false, guarded: false, hasOwnConstraint: false);

        /** @var NotBlank $constraint */
        $constraint = $options['constraints'][0]; // @phpstan-ignore offsetAccess.nonOffsetAccessible
        $this->assertSame('Name is required.', $constraint->message);
    }

    #[Test]
    public function unguardedNonNullableFieldWithItsOwnConstraintGetsNoAdditionalConstraint(): void
    {
        $options = $this->builder->scalarOptions('name', nullable: false, guarded: false, hasOwnConstraint: true);

        $this->assertArrayNotHasKey('constraints', $options);
        $this->assertArrayNotHasKey('invalid_message', $options);
    }

    // ── hasExistingValidatorConstraint() ─────────────────────────────────────────

    #[Test]
    public function detectsAPropertyWithAnExistingValidatorConstraint(): void
    {
        $metadata = $this->makeMetadata(FieldOptionsBuilderFixtureEntity::class);

        $this->assertTrue($this->builder->hasExistingValidatorConstraint($metadata, 'name'));
    }

    #[Test]
    public function returnsFalseForAPropertyWithNoConstraint(): void
    {
        $metadata = $this->makeMetadata(FieldOptionsBuilderFixtureEntity::class);

        $this->assertFalse($this->builder->hasExistingValidatorConstraint($metadata, 'unconstrained'));
    }

    #[Test]
    public function returnsFalseForAPropertyThatDoesNotExistOnTheClass(): void
    {
        $metadata = $this->makeMetadata(FieldOptionsBuilderFixtureEntity::class);

        $this->assertFalse($this->builder->hasExistingValidatorConstraint($metadata, 'doesNotExist'));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @return ClassMetadata<object>&MockObject
     */
    private function makeMetadata(string $reflectionClass): ClassMetadata
    {
        /** @var ClassMetadata<object>&MockObject $metadata */
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getReflectionClass')->willReturn(new \ReflectionClass($reflectionClass));

        return $metadata;
    }
}

/**
 * $name carries a real #[NotBlank] (the normal way a developer would
 * declare this directly on an entity, independent of this bundle);
 * $unconstrained carries none — used to prove
 * hasExistingValidatorConstraint() tells the two apart.
 */
class FieldOptionsBuilderFixtureEntity
{
    #[NotBlank]
    public ?string $name = null;

    public ?string $unconstrained = null;
}
