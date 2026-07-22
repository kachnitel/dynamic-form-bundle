<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form\TypeGuessing;

use Doctrine\ORM\Mapping\ClassMetadata;
use Kachnitel\DynamicFormBundle\Form\TypeGuessing\TypeGuessingCoordinator;
use Kachnitel\DynamicFormBundle\Form\TypeMapping\FieldOptionsBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormTypeGuesserInterface;
use Symfony\Component\Form\Guess\Guess;
use Symfony\Component\Form\Guess\TypeGuess;

/**
 * TypeGuessingCoordinator owns the FormTypeGuesserInterface integration
 * point extracted from DoctrineFormTypeMapper::guessFieldConfig() — see
 * docs/TYPE_GUESSING.md. The companion test using a REAL, unmocked
 * ValidatorTypeGuesser against the full DoctrineFormTypeMapper still lives
 * in tests/Integration/Form/DoctrineFormTypeMapperValidatorGuesserTest.php;
 * everything here injects a mocked FormTypeGuesserInterface directly to
 * exercise this class's own branching in isolation.
 *
 * @group type-guessing
 */
#[CoversClass(TypeGuessingCoordinator::class)]
#[UsesClass(FieldOptionsBuilder::class)]
#[Group('type-guessing')]
class TypeGuessingCoordinatorTest extends TestCase
{
    // ── Standard upgrade ─────────────────────────────────────────────────────

    #[Test]
    public function guesserUpgradesStringFieldToAConfidentType(): void
    {
        $guesser     = $this->guesserReturning('email', new TypeGuess(EmailType::class, [], Guess::HIGH_CONFIDENCE));
        $coordinator = new TypeGuessingCoordinator($guesser);

        $config = $coordinator->guess($this->makeMetadata(), 'email', 'string', nullable: false, hasOwnConstraint: false);

        $this->assertNotNull($config);
        $this->assertSame(EmailType::class, $config['type']);
        // The usual nullability-driven baseline still applies underneath the guess.
        $this->assertSame('', $config['options']['empty_data']);
    }

    // ── Merge precedence ─────────────────────────────────────────────────────

    #[Test]
    public function guessedOptionsWinOverScalarOptionsOnCollision(): void
    {
        $guesser = $this->guesserReturning(
            'email',
            new TypeGuess(EmailType::class, ['required' => true], Guess::HIGH_CONFIDENCE),
        );
        $coordinator = new TypeGuessingCoordinator($guesser);

        // A nullable field would ordinarily make the baseline required: false —
        // a guess that explicitly says required: true must win.
        $config = $coordinator->guess($this->makeMetadata(), 'email', 'string', nullable: true, hasOwnConstraint: false);

        $this->assertNotNull($config);
        $this->assertTrue($config['options']['required']);
    }

    // ── url default_protocol ─────────────────────────────────────────────────

    #[Test]
    public function urlGuessWithoutDefaultProtocolGetsNulled(): void
    {
        $guesser     = $this->guesserReturning('website', new TypeGuess(UrlType::class, [], Guess::HIGH_CONFIDENCE));
        $coordinator = new TypeGuessingCoordinator($guesser);

        $config = $coordinator->guess($this->makeMetadata(), 'website', 'string', nullable: true, hasOwnConstraint: false);

        $this->assertNotNull($config);
        $this->assertSame(UrlType::class, $config['type']);
        $this->assertArrayHasKey('default_protocol', $config['options']);
        $this->assertNull($config['options']['default_protocol']);
    }

    #[Test]
    public function urlGuessWithExplicitDefaultProtocolIsPreserved(): void
    {
        $guesser = $this->guesserReturning(
            'website',
            new TypeGuess(UrlType::class, ['default_protocol' => 'https'], Guess::HIGH_CONFIDENCE),
        );
        $coordinator = new TypeGuessingCoordinator($guesser);

        $config = $coordinator->guess($this->makeMetadata(), 'website', 'string', nullable: true, hasOwnConstraint: false);

        $this->assertNotNull($config);
        $this->assertSame('https', $config['options']['default_protocol']);
    }

    // ── Confidence threshold ─────────────────────────────────────────────────

    #[Test]
    public function guessBelowMinimumConfidenceIsIgnored(): void
    {
        $guesser     = $this->guesserReturning('note', new TypeGuess(TextType::class, [], Guess::MEDIUM_CONFIDENCE));
        $coordinator = new TypeGuessingCoordinator($guesser); // default threshold: Guess::HIGH_CONFIDENCE

        $config = $coordinator->guess($this->makeMetadata(), 'note', 'string', nullable: false, hasOwnConstraint: false);

        $this->assertNull($config, 'A below-threshold guess must be ignored, letting the caller fall through to its own mapping');
    }

    #[Test]
    public function minimumGuessConfidenceIsConfigurable(): void
    {
        $guesser     = $this->guesserReturning('note', new TypeGuess(TextType::class, ['attr' => ['data-medium' => '1']], Guess::MEDIUM_CONFIDENCE));
        $coordinator = new TypeGuessingCoordinator($guesser, Guess::MEDIUM_CONFIDENCE);

        $config = $coordinator->guess($this->makeMetadata(), 'note', 'string', nullable: false, hasOwnConstraint: false);

        $this->assertNotNull($config, 'Lowering the threshold must let the MEDIUM_CONFIDENCE guess through');
        $this->assertArrayHasKey('attr', $config['options']);
    }

    // ── Scoping: string Doctrine columns only ───────────────────────────────

    #[Test]
    public function guesserIsNeverConsultedForNonStringDoctrineTypes(): void
    {
        /** @var FormTypeGuesserInterface&MockObject $guesser */
        $guesser = $this->createMock(FormTypeGuesserInterface::class);
        $guesser->expects($this->never())->method('guessType');

        $coordinator = new TypeGuessingCoordinator($guesser);

        $config = $coordinator->guess($this->makeMetadata(), 'createdAt', 'datetime', nullable: false, hasOwnConstraint: false);

        $this->assertNull($config);
    }

    // ── Disabled guessing ─────────────────────────────────────────────────────

    #[Test]
    public function bareConstructedCoordinatorNeverGuesses(): void
    {
        $coordinator = new TypeGuessingCoordinator(); // no guesser injected

        $config = $coordinator->guess($this->makeMetadata(), 'email', 'string', nullable: false, hasOwnConstraint: false);

        $this->assertNull($config);
    }

    // ── No opinion ───────────────────────────────────────────────────────────

    #[Test]
    public function guesserReturningNullYieldsNull(): void
    {
        $guesser     = $this->guesserReturning('nickname', null);
        $coordinator = new TypeGuessingCoordinator($guesser);

        $config = $coordinator->guess($this->makeMetadata(), 'nickname', 'string', nullable: false, hasOwnConstraint: false);

        $this->assertNull($config);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @return FormTypeGuesserInterface&MockObject
     */
    private function guesserReturning(string $forField, ?TypeGuess $guess): FormTypeGuesserInterface
    {
        /** @var FormTypeGuesserInterface&MockObject $guesser */
        $guesser = $this->createMock(FormTypeGuesserInterface::class);
        $guesser->method('guessType')
            ->willReturnCallback(
                static fn (string $class, string $property): ?TypeGuess => $property === $forField ? $guess : null,
            );

        return $guesser;
    }

    /**
     * @return ClassMetadata<object>&MockObject
     */
    private function makeMetadata(): ClassMetadata
    {
        /** @var ClassMetadata<object>&MockObject $metadata */
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn(TypeGuessingCoordinatorFixtureEntity::class);

        return $metadata;
    }
}

/**
 * Only used as the "class" string TypeGuessingCoordinator forwards to
 * $typeGuesser->guessType($class, $property) — the mocked guessers in this
 * file never actually reflect on it, so an empty fixture is enough.
 */
class TypeGuessingCoordinatorFixtureEntity
{
}
