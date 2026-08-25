<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form;

use Kachnitel\DynamicFormBundle\Editability\FieldEditabilityResolverInterface;
use Kachnitel\DynamicFormBundle\Form\DynamicFormViewEditabilityFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

#[CoversClass(DynamicFormViewEditabilityFilter::class)]
class DynamicFormViewEditabilityFilterTest extends TestCase
{
    #[Test]
    public function removesAFieldTheResolverRejectsForTheBoundEntity(): void
    {
        $entity = new ViewFilterFixtureEntity();

        $resolver = $this->createMock(FieldEditabilityResolverInterface::class);
        $resolver->method('canEdit')
            ->willReturnCallback(
                static fn (string $entityClass, string $property, ?object $boundEntity = null): bool => $property !== 'blocked'
            );

        $view = new FormView();
        $view->children['name']    = new FormView();
        $view->children['blocked'] = new FormView();

        $form = $this->createMock(FormInterface::class);
        $form->method('getData')->willReturn($entity);

        (new DynamicFormViewEditabilityFilter($resolver))->filter($view, $form, ViewFilterFixtureEntity::class);

        $this->assertArrayHasKey('name', $view->children);
        $this->assertArrayNotHasKey('blocked', $view->children);
    }
}

class ViewFilterFixtureEntity
{
    public string $name = '';
    public string $blocked = '';
}
