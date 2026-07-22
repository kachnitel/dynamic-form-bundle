<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form\TypeMapping;

use Kachnitel\DynamicFormBundle\Form\TypeMapping\EnumFieldTypeMapper;
use Kachnitel\DynamicFormBundle\Tests\Fixtures\TestStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/**
 * EnumFieldTypeMapper builds the field config for backed-enum Doctrine
 * columns — extracted verbatim from
 * DoctrineFormTypeMapper::buildEnumConfig(). See
 * docs/FIELD_MAPPING.md#enum-fields.
 *
 * @group type-mapping
 */
#[CoversClass(EnumFieldTypeMapper::class)]
#[Group('type-mapping')]
class EnumFieldTypeMapperTest extends TestCase
{
    private EnumFieldTypeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new EnumFieldTypeMapper();
    }

    #[Test]
    public function mapsToEnumType(): void
    {
        $config = $this->mapper->map('status', TestStatus::class, nullable: false);

        $this->assertSame(EnumType::class, $config['type']);
    }

    #[Test]
    public function classOptionIsTheEnumClass(): void
    {
        $config = $this->mapper->map('status', TestStatus::class, nullable: false);

        $this->assertSame(TestStatus::class, $config['options']['class']);
    }

    #[Test]
    public function requiredIsTrueWhenNotNullable(): void
    {
        $config = $this->mapper->map('status', TestStatus::class, nullable: false);

        $this->assertTrue($config['options']['required']);
    }

    #[Test]
    public function requiredIsFalseWhenNullable(): void
    {
        $config = $this->mapper->map('status', TestStatus::class, nullable: true);

        $this->assertFalse($config['options']['required']);
    }

    #[Test]
    public function nullablePlaceholderIsAnEmptyString(): void
    {
        $config = $this->mapper->map('status', TestStatus::class, nullable: true);

        $this->assertSame('', $config['options']['placeholder']);
    }

    #[Test]
    public function nonNullablePlaceholderIsFalse(): void
    {
        $config = $this->mapper->map('status', TestStatus::class, nullable: false);

        $this->assertFalse($config['options']['placeholder']);
    }

    #[Test]
    public function emptyDataIsAlwaysAnEmptyString(): void
    {
        $nullable    = $this->mapper->map('status', TestStatus::class, nullable: true);
        $nonNullable = $this->mapper->map('status', TestStatus::class, nullable: false);

        $this->assertSame('', $nullable['options']['empty_data']);
        $this->assertSame('', $nonNullable['options']['empty_data']);
    }

    #[Test]
    public function nonNullableGetsAnInvalidMessageNamingTheField(): void
    {
        $config = $this->mapper->map('status', TestStatus::class, nullable: false);

        $this->assertSame('Status is required.', $config['options']['invalid_message']);
    }

    #[Test]
    public function nullableHasNoInvalidMessage(): void
    {
        $config = $this->mapper->map('status', TestStatus::class, nullable: true);

        $this->assertArrayNotHasKey('invalid_message', $config['options']);
    }

    #[Test]
    public function requiresValueGuardIsTrueWhenNotNullable(): void
    {
        $config = $this->mapper->map('status', TestStatus::class, nullable: false);

        $this->assertTrue($config['requiresValueGuard']);
    }

    #[Test]
    public function requiresValueGuardIsFalseWhenNullable(): void
    {
        $config = $this->mapper->map('status', TestStatus::class, nullable: true);

        $this->assertFalse($config['requiresValueGuard']);
    }

    // ── choice_label closure ──────────────────────────────────────────────────

    #[Test]
    public function choiceLabelFallsBackToTheCaseNameWhenNoDisplayValueMethodExists(): void
    {
        // TestStatus (tests/Fixtures/TestStatus.php) defines label(), not
        // displayValue() — this exercises the ->name fallback branch.
        $config = $this->mapper->map('status', TestStatus::class, nullable: false);

        $this->assertIsCallable($config['options']['choice_label']);

        $label = ($config['options']['choice_label'])(TestStatus::ACTIVE);

        $this->assertSame('ACTIVE', $label);
    }

    #[Test]
    public function choiceLabelUsesDisplayValueWhenTheEnumDefinesIt(): void
    {
        $config = $this->mapper->map('state', EnumFieldTypeMapperDisplayValueFixture::class, nullable: false);

        $this->assertIsCallable($config['options']['choice_label']);

        $label = ($config['options']['choice_label'])(TestStatus::ACTIVE);

        $label = ($config['options']['choice_label'])(EnumFieldTypeMapperDisplayValueFixture::OPEN);

        $this->assertSame('Currently Open', $label);
    }
}

/**
 * A backed enum that DOES define displayValue() — proves
 * EnumFieldTypeMapper's choice_label closure prefers it over ->name when
 * available.
 */
enum EnumFieldTypeMapperDisplayValueFixture: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';

    public function displayValue(): string
    {
        return match ($this) {
            self::OPEN   => 'Currently Open',
            self::CLOSED => 'Now Closed',
        };
    }
}
