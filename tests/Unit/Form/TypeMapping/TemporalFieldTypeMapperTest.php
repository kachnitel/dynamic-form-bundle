<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form\TypeMapping;

use Kachnitel\DynamicFormBundle\Form\TypeMapping\FieldOptionsBuilder;
use Kachnitel\DynamicFormBundle\Form\TypeMapping\TemporalFieldTypeMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;

/**
 * TemporalFieldTypeMapper owns the date/datetime/time arms extracted from
 * DoctrineFormTypeMapper::getFieldConfig()'s match() — see
 * docs/FIELD_MAPPING.md#date--time-input-option for the mutable/immutable
 * `input` derivation this class implements unchanged.
 *
 * @group type-mapping
 */
#[CoversClass(TemporalFieldTypeMapper::class)]
#[UsesClass(FieldOptionsBuilder::class)]
#[Group('type-mapping')]
class TemporalFieldTypeMapperTest extends TestCase
{
    private TemporalFieldTypeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new TemporalFieldTypeMapper();
    }

    // ── date ──────────────────────────────────────────────────────────────────

    #[Test]
    public function dateMapsToDateTypeWithMutableInput(): void
    {
        $config = $this->mapper->map('date', 'dob', nullable: false);

        $this->assertNotNull($config);
        $this->assertSame(DateType::class, $config['type']);
        $this->assertSame('single_text', $config['options']['widget']);
        $this->assertSame('datetime', $config['options']['input']);
    }

    #[Test]
    public function dateImmutableMapsToDateTypeWithImmutableInput(): void
    {
        $config = $this->mapper->map('date_immutable', 'dob', nullable: false);

        $this->assertNotNull($config);
        $this->assertSame(DateType::class, $config['type']);
        $this->assertSame('datetime_immutable', $config['options']['input']);
    }

    // ── datetime ──────────────────────────────────────────────────────────────

    /**
     * @param non-empty-string $doctrineType
     */
    #[Test]
    #[DataProvider('mutableDatetimeTypeProvider')]
    public function datetimeAndDatetimetzMapToDateTimeTypeWithMutableInput(string $doctrineType): void
    {
        $config = $this->mapper->map($doctrineType, 'createdAt', nullable: false);

        $this->assertNotNull($config);
        $this->assertSame(DateTimeType::class, $config['type']);
        $this->assertSame('datetime', $config['options']['input']);
    }

    /**
     * @return array<string, array{0: non-empty-string}>
     */
    public static function mutableDatetimeTypeProvider(): array
    {
        return [
            'datetime'   => ['datetime'],
            'datetimetz' => ['datetimetz'],
        ];
    }

    /**
     * @param non-empty-string $doctrineType
     */
    #[Test]
    #[DataProvider('immutableDatetimeTypeProvider')]
    public function datetimeImmutableAndDatetimetzImmutableMapToDateTimeTypeWithImmutableInput(string $doctrineType): void
    {
        $config = $this->mapper->map($doctrineType, 'createdAt', nullable: false);

        $this->assertNotNull($config);
        $this->assertSame(DateTimeType::class, $config['type']);
        $this->assertSame('datetime_immutable', $config['options']['input']);
    }

    /**
     * @return array<string, array{0: non-empty-string}>
     */
    public static function immutableDatetimeTypeProvider(): array
    {
        return [
            'datetime_immutable'   => ['datetime_immutable'],
            'datetimetz_immutable' => ['datetimetz_immutable'],
        ];
    }

    // ── time ──────────────────────────────────────────────────────────────────

    #[Test]
    public function timeMapsToTimeTypeWithMutableInput(): void
    {
        $config = $this->mapper->map('time', 'startsAt', nullable: false);

        $this->assertNotNull($config);
        $this->assertSame(TimeType::class, $config['type']);
        $this->assertSame('datetime', $config['options']['input']);
    }

    #[Test]
    public function timeImmutableMapsToTimeTypeWithImmutableInput(): void
    {
        $config = $this->mapper->map('time_immutable', 'startsAt', nullable: false);

        $this->assertNotNull($config);
        $this->assertSame(TimeType::class, $config['type']);
        $this->assertSame('datetime_immutable', $config['options']['input']);
    }

    // ── requiresValueGuard: present for every temporal arm, driven by nullability ─

    /**
     * Unlike ScalarFieldTypeMapper's boolean arm, every temporal arm always
     * sets requiresValueGuard — there is no temporal type that omits it.
     */
    #[Test]
    #[DataProvider('allTemporalTypeProvider')]
    public function everyTemporalTypeAlwaysIncludesTheRequiresValueGuardKey(string $doctrineType): void
    {
        $config = $this->mapper->map($doctrineType, 'when', nullable: true);

        $this->assertNotNull($config);
        $this->assertArrayHasKey('requiresValueGuard', $config); // @phpstan-ignore method.alreadyNarrowedType
    }

    #[Test]
    #[DataProvider('allTemporalTypeProvider')]
    public function nonNullableTemporalFieldsRequireAValueGuard(string $doctrineType): void
    {
        $config = $this->mapper->map($doctrineType, 'when', nullable: false);

        $this->assertNotNull($config);
        $this->assertTrue($config['requiresValueGuard']);
    }

    #[Test]
    #[DataProvider('allTemporalTypeProvider')]
    public function nullableTemporalFieldsDoNotRequireAValueGuard(string $doctrineType): void
    {
        $config = $this->mapper->map($doctrineType, 'when', nullable: true);

        $this->assertNotNull($config);
        $this->assertFalse($config['requiresValueGuard']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allTemporalTypeProvider(): array
    {
        return [
            'date'                 => ['date'],
            'date_immutable'       => ['date_immutable'],
            'datetime'             => ['datetime'],
            'datetimetz'           => ['datetimetz'],
            'datetime_immutable'   => ['datetime_immutable'],
            'datetimetz_immutable' => ['datetimetz_immutable'],
            'time'                 => ['time'],
            'time_immutable'       => ['time_immutable'],
        ];
    }

    // ── Types this mapper does not own ───────────────────────────────────────

    /**
     * Scalar types belong to ScalarFieldTypeMapper — this mapper must stay
     * silent (null) for them.
     */
    #[Test]
    #[DataProvider('scalarTypeProvider')]
    public function returnsNullForScalarTypesItDoesNotOwn(string $doctrineType): void
    {
        $this->assertNull($this->mapper->map($doctrineType, 'field', nullable: true));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function scalarTypeProvider(): array
    {
        return [
            'string'  => ['string'],
            'text'    => ['text'],
            'integer' => ['integer'],
            'decimal' => ['decimal'],
            'boolean' => ['boolean'],
        ];
    }

    #[Test]
    public function returnsNullForAnUnsupportedType(): void
    {
        $this->assertNull($this->mapper->map('json', 'payload', nullable: true));
    }
}
