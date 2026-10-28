<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Kachnitel\DynamicFormBundle\Editability\FieldEditabilityResolverInterface;
use Kachnitel\DynamicFormBundle\Form\DoctrineFormTypeMapper;
use Kachnitel\DynamicFormBundle\Form\DynamicEntityFormType;
use Kachnitel\DynamicFormBundle\Form\DynamicFormEditabilityListener;
use Kachnitel\DynamicFormBundle\Form\DynamicFormViewEditabilityFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;

#[CoversClass(DynamicEntityFormType::class)]
#[UsesClass(DynamicFormEditabilityListener::class)]
#[UsesClass(DynamicFormViewEditabilityFilter::class)]
#[Group('readonly-form')]
class DynamicEntityFormTypeReadOnlyTest extends TestCase
{
    private const TEXT_TYPE = 'Symfony\Component\Form\Extension\Core\Type\TextType';
    private const ENTITY_TYPE = 'Symfony\Bridge\Doctrine\Form\Type\EntityType';

    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;

    /** @var DoctrineFormTypeMapper&MockObject */
    private DoctrineFormTypeMapper $mapper;

    protected function setUp(): void
    {
        $this->em     = $this->createMock(EntityManagerInterface::class);
        $this->mapper = $this->createMock(DoctrineFormTypeMapper::class);
    }

    #[Test]
    public function readOnlyScalarFieldIsAddedDisabled(): void
    {
        $options = $this->buildAndCollectOptions(fields: ['id', 'name', 'code'], readOnly: ['code']);

        $this->assertArrayHasKey('code', $options, 'A read-only field must still be added to the form');
        $this->assertTrue($options['code']['disabled'] ?? false, 'A read-only field must be disabled');
    }

    #[Test]
    public function editableScalarFieldIsNotDisabled(): void
    {
        $options = $this->buildAndCollectOptions(fields: ['id', 'name', 'code'], readOnly: ['code']);

        $this->assertArrayHasKey('name', $options);
        $this->assertArrayNotHasKey('disabled', $options['name']);
    }

    #[Test]
    public function readOnlyFieldPreservesMapperOptions(): void
    {
        $options = $this->buildAndCollectOptions(fields: ['id', 'code'], readOnly: ['code']);

        $this->assertSame('Code', $options['code']['label']);
    }

    #[Test]
    public function readOnlyAssociationIsAddedDisabled(): void
    {
        $metadata = $this->makeMetadata(fields: ['id'], assocNames: ['owner']);
        $this->em->method('getClassMetadata')->willReturn($metadata);
        $this->mapper->method('getAssociationConfig')
            ->willReturn(['type' => self::ENTITY_TYPE, 'options' => ['class' => \stdClass::class]]);

        $added = $this->createAddedCollector();
        $builder = $this->makeBuilder($added);

        $this->makeFormType(readOnly: ['owner'])
            ->buildForm($builder, ['entity_class' => ReadOnlyFixtureEntity::class]);

        $this->assertArrayHasKey('owner', $added->getArrayCopy());
        $this->assertTrue($added['owner']['disabled'] ?? false);
        $this->assertSame(\stdClass::class, $added['owner']['class']);
    }

    #[Test]
    public function fieldRejectedByCanEditIsStillExcludedEvenIfReadOnly(): void
    {
        $options = $this->buildAndCollectOptions(fields: ['id', 'code'], readOnly: ['code'], blocked: ['code']);

        $this->assertArrayNotHasKey('code', $options, 'canEdit() false remains the exclusion gate');
    }

    /**
     * @param list<string> $fields
     * @param list<string> $readOnly
     * @param list<string> $blocked
     * @return array<string, array<array-key, mixed>>
     */
    private function buildAndCollectOptions(array $fields, array $readOnly, array $blocked = []): array
    {
        $metadata = $this->makeMetadata(fields: $fields);
        $this->em->method('getClassMetadata')->willReturn($metadata);
        $this->mapper->method('getFieldConfig')
            ->willReturnCallback(static fn (ClassMetadata $m, string $f): array => [
                'type'    => self::TEXT_TYPE,
                'options' => ['label' => ucfirst($f)],
            ]);

        $added = $this->createAddedCollector();
        $this->makeFormType($readOnly, $blocked)
            ->buildForm($this->makeBuilder($added), ['entity_class' => ReadOnlyFixtureEntity::class]);

        return $added->getArrayCopy();
    }

    /**
     * @param list<string> $readOnly
     * @param list<string> $blocked
     */
    private function makeFormType(array $readOnly, array $blocked = []): DynamicEntityFormType
    {
        /** @var FieldEditabilityResolverInterface&MockObject $resolver */
        $resolver = $this->createMock(FieldEditabilityResolverInterface::class);
        $resolver->method('canEdit')
            ->willReturnCallback(static fn (string $c, string $p, ?object $e = null): bool => !in_array($p, $blocked, true));
        $resolver->method('isExplicitOverride')->willReturn(false);
        $resolver->method('isReadOnly')
            ->willReturnCallback(static fn (string $c, string $p, ?object $e = null): bool => in_array($p, $readOnly, true));

        return new DynamicEntityFormType($this->em, $this->mapper, $resolver);
    }

    /**
    * @param \ArrayObject<string, array<array-key, mixed>> $added field name => options passed to add()
     * @return FormBuilderInterface<object|null>&MockObject
     */
    private function makeBuilder(\ArrayObject $added): FormBuilderInterface
    {
        /** @var FormBuilderInterface<object|null>&MockObject $builder */
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')
            ->willReturnCallback(function (string $name, ?string $type = null, array $options = []) use ($builder, $added): FormBuilderInterface {
                $added[$name] = $options;

                return $builder;
            });

        return $builder;
    }

    /**
     * @return \ArrayObject<string, array<array-key, mixed>>
     */
    private function createAddedCollector(): \ArrayObject
    {
        return new \ArrayObject();
    }

    /**
     * @param list<string> $fields
     * @param list<string> $assocNames
     * @return ClassMetadata<object>&MockObject
     */
    private function makeMetadata(array $fields, array $assocNames = []): ClassMetadata
    {
        /** @var ClassMetadata<object>&MockObject $metadata */
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getSingleIdentifierFieldName')->willReturn('id');
        $metadata->method('getFieldNames')->willReturn($fields);
        $metadata->method('getAssociationNames')->willReturn($assocNames);
        $metadata->method('isCollectionValuedAssociation')->willReturn(false);
        $metadata->method('hasAssociation')->willReturn(false);

        return $metadata;
    }
}

/** Plain entity; the mock resolver decides everything. */
class ReadOnlyFixtureEntity
{
    private int $id = 0;
    private string $name = '';
    private string $code = '';
}
