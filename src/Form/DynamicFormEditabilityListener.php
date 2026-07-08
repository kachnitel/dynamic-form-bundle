<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Form;

use Kachnitel\DynamicFormBundle\Editability\FieldEditabilityResolverInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * Form event listener that re-checks field editability once a form's data is
 * actually bound.
 *
 * DynamicEntityFormType::buildForm() must sometimes decide whether to include a
 * field before any entity instance is available (e.g. a "new entity" form, or a
 * LiveCollectionType child entry that hasn't been bound to a row yet) — see
 * FieldEditabilityResolverInterface::canEdit()'s docblock for how implementations
 * are expected to handle that case. This listener gives the resolver a second
 * chance to exclude a field once a real (even freshly-`new`'d) entity instance is
 * available, by running on PRE_SET_DATA and removing any field it now rejects.
 *
 * This only ever REMOVES fields already present on the form — it cannot add a
 * field that buildForm() didn't add in the first place. An association skipped
 * at build time because FieldEditabilityResolverInterface::isExplicitOverride()
 * couldn't be resolved without an entity instance stays skipped; this listener
 * does not revisit that decision. This mirrors the original AdminColumn-reading
 * implementation's behaviour exactly: only the general canEdit() gate is
 * reconsidered here, never the narrower structural override.
 *
 * Every field currently on the form is re-checked, not just the ones whose
 * inclusion depended on a not-yet-resolvable condition — this is deliberately
 * broader than strictly necessary but behaviourally equivalent for a
 * deterministic resolver: a field canEdit() already agreed to include at build
 * time will still be included when asked again with the same (or a now-
 * hydrated) entity.
 */
final class DynamicFormEditabilityListener implements EventSubscriberInterface
{
    /**
     * @param class-string $entityClass
     */
    public function __construct(
        private readonly FieldEditabilityResolverInterface $editabilityResolver,
        private readonly string $entityClass,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::PRE_SET_DATA => 'onPreSetData',
        ];
    }

    public function onPreSetData(FormEvent $event): void
    {
        $entity = $event->getData();

        if (!is_object($entity) || !$entity instanceof $this->entityClass) {
            return;
        }

        $form = $event->getForm();

        foreach ($form->all() as $fieldName => $child) {
            if (!$this->editabilityResolver->canEdit($this->entityClass, $fieldName, $entity)) {
                $form->remove($fieldName);
            }
        }
    }
}
