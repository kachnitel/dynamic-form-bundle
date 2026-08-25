<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form;

use Doctrine\ORM\EntityManagerInterface;
use Kachnitel\DynamicFormBundle\Editability\FieldEditabilityResolverInterface;
use Kachnitel\DynamicFormBundle\Form\DoctrineFormTypeMapper;
use Kachnitel\DynamicFormBundle\Form\DynamicEntityFormType;
use Kachnitel\DynamicFormBundle\Form\DynamicFormViewEditabilityFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

#[CoversClass(DynamicEntityFormType::class)]
#[UsesClass(DynamicFormViewEditabilityFilter::class)]
class DynamicEntityFormTypeFinishViewTest extends TestCase
{
    #[Test]
    public function filtersTheViewForTheBoundEntity(): void
    {
        $entity = new FinishViewFixtureEntity();

        $resolver = $this->createMock(FieldEditabilityResolverInterface::class);
        $resolver->method('canEdit')
            ->willReturnCallback(
                static fn (string $entityClass, string $property, ?object $boundEntity = null): bool => $property !== 'blocked'
            );

        $formType = new DynamicEntityFormType(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(DoctrineFormTypeMapper::class),
            $resolver,
        );

        $view = new FormView();
        $view->children['name']    = new FormView();
        $view->children['blocked'] = new FormView();

        $form = $this->createMock(FormInterface::class);
        $form->method('getData')->willReturn($entity);

        $formType->finishView($view, $form, ['entity_class' => FinishViewFixtureEntity::class]);

        $this->assertArrayHasKey('name', $view->children);
        $this->assertArrayNotHasKey('blocked', $view->children);
    }
}

class FinishViewFixtureEntity
{
    public string $name = '';
    public string $blocked = '';
}
