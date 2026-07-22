<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form\TypeMapping;

use Kachnitel\DynamicFormBundle\Form\TypeMapping\FieldOptionsBuilder;
use Kachnitel\DynamicFormBundle\Form\TypeMapping\ScalarFieldTypeMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * ScalarFieldTypeMapper owns the non-temporal scalar arms extracted from
 * DoctrineFormTypeMapper::getFieldConfig()'s match() — string, text, the
 * integer family, decimal/float, boolean. See docs/FIELD_MAPPING.md for
 * the full type table; option-content details (empty_data, NotBlank,
 * invalid_message) are covered by FieldOptionsBuilderTest, since this
 * class only delegates to that collaborator rather than building options
 * itself.
 *
 * @group type-mapping
 */
#[CoversClass(ScalarFieldTypeMapper::class)]
#[UsesClass(FieldOptionsBuilder::class)]
#[Group('type-mapping')]
class ScalarFieldTypeMapperTest extends TestCase
{
    private ScalarFieldTypeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ScalarFieldTypeMapper();
    }

    // ── string / text ─────────────────────────────────────────────────────────

    #[Test]
    public function stringMapsToTextType(): void
    {
        $config = $this->mapper->map('string', 'name', nullable: false, hasOwnConstraint: false);

        $this->assertNotNull($config);
        $this->assertSame(TextType::class, $config['type']);
        $this->assertArrayNotHasKey('requiresValueGuard', $config);
    }

    #[Test]
    public function textMapsToTextareaType(): void
    {
        $config = $this->mapper->map('text', 'body', nullable: false, hasOwnConstraint: false);

        $this->assertNotNull($config);
        $this->assertSame(TextareaType::class, $config['type']);
    }

    // ── integer family ────────────────────────────────────────────────────────

    /**
     * @param non-empty-string $doctrineType
     */
    #[Test]
    #[DataProvider('integerTypeProvider')]
    public function integerFamilyMapsToIntegerType(string $doctrineType): void
    {
        $config = $this->mapper->map($doctrineType, 'count', nullable: false, hasOwnConstraint: false);

        $this->assertNotNull($config);
        $this->assertSame(IntegerType::class, $config['type']);
    }

    /**
     * @return array<string, array{0: non-empty-string}>
     */
    public static function integerTypeProvider(): array
    {
        return [
            'integer'  => ['integer'],
            'smallint' => ['smallint'],
            'bigint'   => ['bigint'],
        ];
    }

    #[Test]
    public function nonNullableIntegerRequiresAValueGuard(): void
    {
        $config = $this->mapper->map('integer', 'qty', nullable: false, hasOwnConstraint: false);

        $this->assertNotNull($config);
        $this->assertTrue($config['requiresValueGuard']); // @phpstan-ignore offsetAccess.notFound
    }

    #[Test]
    public function nullableIntegerDoesNotRequireAValueGuard(): void
    {
        $config = $this->mapper->map('integer', 'qty', nullable: true, hasOwnConstraint: false);

        $this->assertNotNull($config);
        $this->assertFalse($config['requiresValueGuard']); // @phpstan-ignore offsetAccess.notFound
    }

    // ── decimal / float ───────────────────────────────────────────────────────

    /**
     * @param non-empty-string $doctrineType
     */
    #[Test]
    #[DataProvider('numberTypeProvider')]
    public function decimalAndFloatMapToNumberType(string $doctrineType): void
    {
        $config = $this->mapper->map($doctrineType, 'price', nullable: false, hasOwnConstraint: false);

        $this->assertNotNull($config);
        $this->assertSame(NumberType::class, $config['type']);
    }

    /**
     * @return array<string, array{0: non-empty-string}>
     */
    public static function numberTypeProvider(): array
    {
        return [
            'decimal' => ['decimal'],
            'float'   => ['float'],
        ];
    }

    #[Test]
    public function decimalIncludesHtml5Option(): void
    {
        $config = $this->mapper->map('decimal', 'price', nullable: true, hasOwnConstraint: false);

        $this->assertNotNull($config);
        $this->assertTrue($config['options']['html5']);
    }

    // ── boolean ───────────────────────────────────────────────────────────────

    #[Test]
    public function booleanMapsToCheckboxType(): void
    {
        $config = $this->mapper->map('boolean', 'active', nullable: false, hasOwnConstraint: false);

        $this->assertNotNull($config);
        $this->assertSame(CheckboxType::class, $config['type']);
    }

    #[Test]
    public function booleanIsNeverRequiredEvenWhenNotNullable(): void
    {
        $config = $this->mapper->map('boolean', 'active', nullable: false, hasOwnConstraint: false);

        $this->assertNotNull($config);
        $this->assertFalse($config['options']['required']);
    }

    #[Test]
    public function booleanHasNoRequiresValueGuardKeyAtAll(): void
    {
        $config = $this->mapper->map('boolean', 'active', nullable: false, hasOwnConstraint: false);

        $this->assertNotNull($config);
        $this->assertArrayNotHasKey('requiresValueGuard', $config);
    }

    // ── Types this mapper does not own ───────────────────────────────────────

    /**
     * Temporal types belong to TemporalFieldTypeMapper — this mapper must
     * stay silent (null) for them so DoctrineFormTypeMapper's `??`
     * fallthrough reaches the right collaborator.
     */
    #[Test]
    #[DataProvider('temporalTypeProvider')]
    public function returnsNullForTemporalTypesItDoesNotOwn(string $doctrineType): void
    {
        $this->assertNull($this->mapper->map($doctrineType, 'when', nullable: true, hasOwnConstraint: false));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function temporalTypeProvider(): array
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

    #[Test]
    public function returnsNullForAnUnsupportedType(): void
    {
        $this->assertNull($this->mapper->map('json', 'payload', nullable: true, hasOwnConstraint: false));
    }
}
