<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Form;

use Kachnitel\DynamicFormBundle\Editability\FieldEditabilityResolverInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

final class DynamicFormViewEditabilityFilter
{
    public function __construct(
        private readonly FieldEditabilityResolverInterface $editabilityResolver,
    ) {}

    /**
     * @param FormInterface<object> $form
     * @param class-string $entityClass
     */
    public function filter(FormView $view, FormInterface $form, string $entityClass): void
    {
        $entity = $form->getData();

        foreach (array_keys($view->children) as $fieldName) {
            if (!is_string($fieldName)) {
                continue;
            }

            if (!$this->editabilityResolver->canEdit($entityClass, $fieldName, $entity)) {
                unset($view->children[$fieldName]);
            }
        }
    }
}
