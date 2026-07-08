<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form\Exception;

use Kachnitel\DynamicFormBundle\Form\Exception\NullabilityMismatchException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @group form-exceptions
 */
#[CoversClass(NullabilityMismatchException::class)]
class NullabilityMismatchExceptionTest extends TestCase
{
    #[Test]
    public function forFieldMessageNamesTheEntityClass(): void
    {
        $exception = NullabilityMismatchException::forField('App\Entity\Product', 'name');

        $this->assertStringContainsString('App\Entity\Product', $exception->getMessage());
    }

    #[Test]
    public function forFieldMessageNamesTheFieldWithSigil(): void
    {
        $exception = NullabilityMismatchException::forField('App\Entity\Product', 'name');

        $this->assertStringContainsString('$name', $exception->getMessage());
    }

    #[Test]
    public function forFieldMessageExplainsTheNullableFalseFix(): void
    {
        $exception = NullabilityMismatchException::forField('App\Entity\Product', 'name');

        $this->assertStringContainsString('nullable: false', $exception->getMessage());
    }

    #[Test]
    public function differentFieldsProduceDifferentMessages(): void
    {
        $first  = NullabilityMismatchException::forField('App\Entity\Product', 'name');
        $second = NullabilityMismatchException::forField('App\Entity\Order', 'reference');

        $this->assertNotSame($first->getMessage(), $second->getMessage());
    }
}
