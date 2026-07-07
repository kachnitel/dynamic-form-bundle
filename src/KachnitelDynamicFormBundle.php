<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Zero-configuration Symfony form generation from Doctrine entity metadata.
 *
 * Ships DynamicEntityFormType and its supporting services. Field-level
 * editability is resolved through FieldEditabilityResolverInterface, bound by
 * default to the permissive AlwaysEditableFieldResolver — override the alias
 * in your own services.yaml to enforce your own policy, exactly as
 * kachnitel/admin-bundle does via AdminColumnEditabilityResolver.
 *
 * No configuration tree is defined yet — this bundle has no user-facing
 * config knobs at this stage.
 *
 * @see Editability\FieldEditabilityResolverInterface
 */
class KachnitelDynamicFormBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * @param array<string, mixed> $config
     *
     * $config and $builder are unused for now — this bundle has no
     * configuration tree and no container parameters to set yet. Both
     * parameters are mandated by AbstractBundle::loadExtension()'s signature
     * and can't be dropped from the override.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.yaml');
    }
}
