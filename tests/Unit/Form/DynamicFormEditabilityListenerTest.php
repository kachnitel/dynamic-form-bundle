<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form;

use Kachnitel\DynamicFormBundle\Editability\FieldEditabilityResolverInterface;
use Kachnitel\DynamicFormBundle\Form\DynamicFormEditabilityListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

/**
 * @group dynamic-form
 */
#[CoversClass(DynamicFormEditabilityListener::class)]
class DynamicFormEditabilityListenerTest extends TestCase
{
    // ── getSubscribedEvents() ──────────────────────────────────────────────────

    #[Test]
    public function subscribesToPreSetData(): void
    {
        $events = DynamicFormEditabilityListener::getSubscribedEvents();

        $this->assertArrayHasKey(FormEvents::PRE_SET_DATA, $events);
        $this->assertSame('onPreSetData', $events[FormEvents::PRE_SET_DATA]);
    }

    // ── onPreSetData(): guard clauses never touch the form or the resolver ─────

    #[Test]
    public function doesNothingWhenEventDataIsNull(): void
    {
        $resolver = $this->createMock(FieldEditabilityResolverInterface::class);
        $resolver->expects($this->never())->method('canEdit');

        $form = $this->createMock(FormInterface::class);
        $form->expects($this->never())->method('all');

        $listener = new DynamicFormEditabilityListener($resolver, ListenerFixtureEntity::class);
        $listener->onPreSetData(new FormEvent($form, null));
    }

    #[Test]
    public function doesNothingWhenEventDataIsNotAnObject(): void
    {
        $resolver = $this->createMock(FieldEditabilityResolverInterface::class);
        $resolver->expects($this->never())->method('canEdit');

        $form = $this->createMock(FormInterface::class);
        $form->expects($this->never())->method('all');

        $listener = new DynamicFormEditabilityListener($resolver, ListenerFixtureEntity::class);
        $listener->onPreSetData(new FormEvent($form, ['not' => 'an object']));
    }

    #[Test]
    public function doesNothingWhenEntityIsNotAnInstanceOfTheConfiguredClass(): void
    {
        $resolver = $this->createMock(FieldEditabilityResolverInterface::class);
        $resolver->expects($this->never())->method('canEdit');

        $form = $this->createMock(FormInterface::class);
        $form->expects($this->never())->method('all');

        $listener = new DynamicFormEditabilityListener($resolver, ListenerFixtureEntity::class);
        $listener->onPreSetData(new FormEvent($form, new \stdClass()));
    }

    // ── onPreSetData(): removal behaviour ───────────────────────────────────────

    #[Test]
    public function removesAFieldTheResolverNowRejects(): void
    {
        $entity = new ListenerFixtureEntity();

        $resolver = $this->createMock(FieldEditabilityResolverInterface::class);
        $resolver->method('canEdit')
            ->willReturnCallback(
                static fn (string $entityClass, string $property, ?object $e = null): bool => $property !== 'blockedField'
            );

        $nameField    = $this->createMock(FormInterface::class);
        $blockedField = $this->createMock(FormInterface::class);

        $form = $this->createMock(FormInterface::class);
        $form->method('all')->willReturn(['name' => $nameField, 'blockedField' => $blockedField]);
        $form->expects($this->once())->method('remove')->with('blockedField');

        $listener = new DynamicFormEditabilityListener($resolver, ListenerFixtureEntity::class);
        $listener->onPreSetData(new FormEvent($form, $entity));
    }

    #[Test]
    public function keepsEveryFieldWhenTheResolverPermitsAll(): void
    {
        $entity = new ListenerFixtureEntity();

        $resolver = $this->createMock(FieldEditabilityResolverInterface::class);
        $resolver->method('canEdit')->willReturn(true);

        $nameField = $this->createMock(FormInterface::class);

        $form = $this->createMock(FormInterface::class);
        $form->method('all')->willReturn(['name' => $nameField]);
        $form->expects($this->never())->method('remove');

        $listener = new DynamicFormEditabilityListener($resolver, ListenerFixtureEntity::class);
        $listener->onPreSetData(new FormEvent($form, $entity));
    }

    #[Test]
    public function passesTheBoundEntityAndEntityClassToTheResolver(): void
    {
        $entity = new ListenerFixtureEntity();

        $resolver = $this->createMock(FieldEditabilityResolverInterface::class);
        $resolver->expects($this->once())
            ->method('canEdit')
            ->with(ListenerFixtureEntity::class, 'name', $entity)
            ->willReturn(true);

        $nameField = $this->createMock(FormInterface::class);

        $form = $this->createMock(FormInterface::class);
        $form->method('all')->willReturn(['name' => $nameField]);

        $listener = new DynamicFormEditabilityListener($resolver, ListenerFixtureEntity::class);
        $listener->onPreSetData(new FormEvent($form, $entity));
    }
}

class ListenerFixtureEntity
{
    public string $name = '';
    public string $blockedField = '';
}
