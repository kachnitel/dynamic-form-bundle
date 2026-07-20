<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Kachnitel\DynamicFormBundle\Form\DoctrineFormTypeMapper;
use Kachnitel\DynamicFormBundle\Tests\Fixtures\TestStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormTypeGuesserInterface;
use Symfony\Component\Form\Guess\Guess;
use Symfony\Component\Form\Guess\TypeGuess;

/**
 * Covers the FormTypeGuesserInterface integration point added to
 * DoctrineFormTypeMapper::getFieldConfig() — see docs/TYPE_GUESSING.md.
 *
 * All tests here inject a mocked FormTypeGuesserInterface directly; the
 * companion test using a real Symfony\Component\Form\Extension\Validator\
 * ValidatorTypeGuesser lives in
 * tests/Integration/Form/DoctrineFormTypeMapperValidatorGuesserTest.php.
 *
 * @group auto-form
 * @group type-guessing
 */
#[CoversClass(DoctrineFormTypeMapper::class)]
#[Group('auto-form')]
#[Group('type-guessing')]
class DoctrineFormTypeMapperTypeGuessingTest extends TestCase
{
    // ── Standard upgrade ─────────────────────────────────────────────────────

    #[Test]
    public function guesserUpgradesStringFieldToAConfidentType(): void
    {
        $guesser = $this->guesserReturning('email', new TypeGuess(EmailType::class, [], Guess::HIGH_CONFIDENCE));
        $mapper  = new DoctrineFormTypeMapper($guesser);

        $metadata = $this->makeMetadata(['email' => ['type' => 'string', 'nullable' => false]]);
        $config   = $mapper->getFieldConfig($metadata, 'email');

        $this->assertNotNull($config);
        $this->assertSame(EmailType::class, $config['type']);
        // The usual nullability-driven baseline still applies underneath the guess.
        $this->assertSame('', $config['options']['empty_data']);
    }

    // ── Merge precedence ─────────────────────────────────────────────────────

    #[Test]
    public function guessedOptionsWinOverScalarOptionsOnCollision(): void
    {
        // A nullable DB column would ordinarily make scalarOptions() set
        // required: false — a guess that explicitly says required: true
        // (e.g. relayed from a real #[Assert\NotBlank]) must win.
        $guesser = $this->guesserReturning(
            'email',
            new TypeGuess(EmailType::class, ['required' => true], Guess::HIGH_CONFIDENCE),
        );
        $mapper = new DoctrineFormTypeMapper($guesser);

        $metadata = $this->makeMetadata(['email' => ['type' => 'string', 'nullable' => true]]);
        $config   = $mapper->getFieldConfig($metadata, 'email');

        $this->assertNotNull($config);
        $this->assertTrue($config['options']['required']);
    }

    // ── url default_protocol ─────────────────────────────────────────────────

    #[Test]
    public function urlGuessWithoutDefaultProtocolGetsNulled(): void
    {
        $guesser = $this->guesserReturning('website', new TypeGuess(UrlType::class, [], Guess::HIGH_CONFIDENCE));
        $mapper  = new DoctrineFormTypeMapper($guesser);

        $metadata = $this->makeMetadata(['website' => ['type' => 'string', 'nullable' => true]]);
        $config   = $mapper->getFieldConfig($metadata, 'website');

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
        $mapper = new DoctrineFormTypeMapper($guesser);

        $metadata = $this->makeMetadata(['website' => ['type' => 'string', 'nullable' => true]]);
        $config   = $mapper->getFieldConfig($metadata, 'website');

        $this->assertNotNull($config);
        $this->assertSame('https', $config['options']['default_protocol']);
    }

    // ── Confidence threshold ─────────────────────────────────────────────────

    #[Test]
    public function guessBelowMinimumConfidenceIsIgnored(): void
    {
        $guesser = $this->guesserReturning('note', new TypeGuess(TextType::class, [], Guess::MEDIUM_CONFIDENCE));
        $mapper  = new DoctrineFormTypeMapper($guesser); // default threshold: Guess::HIGH_CONFIDENCE

        $metadata = $this->makeMetadata(['note' => ['type' => 'string', 'nullable' => false]]);
        $config   = $mapper->getFieldConfig($metadata, 'note');

        $this->assertNotNull($config);
        $this->assertSame(TextType::class, $config['type'], 'A below-threshold guess must fall through to the ordinary Doctrine-type mapping');
    }

    #[Test]
    public function minimumGuessConfidenceIsConfigurable(): void
    {
        $guesser = $this->guesserReturning('note', new TypeGuess(TextType::class, ['attr' => ['data-medium' => '1']], Guess::MEDIUM_CONFIDENCE));
        $mapper  = new DoctrineFormTypeMapper($guesser, Guess::MEDIUM_CONFIDENCE);

        $metadata = $this->makeMetadata(['note' => ['type' => 'string', 'nullable' => false]]);
        $config   = $mapper->getFieldConfig($metadata, 'note');

        $this->assertNotNull($config);
        $this->assertArrayHasKey('attr', $config['options'], 'Lowering the threshold must let the MEDIUM_CONFIDENCE guess through');
    }

    // ── Scoping: string Doctrine columns only ───────────────────────────────

    #[Test]
    public function guesserIsNeverConsultedForNonStringDoctrineTypes(): void
    {
        /** @var FormTypeGuesserInterface&MockObject $guesser */
        $guesser = $this->createMock(FormTypeGuesserInterface::class);
        $guesser->expects($this->never())->method('guessType');

        $mapper   = new DoctrineFormTypeMapper($guesser);
        $metadata = $this->makeMetadata(['createdAt' => ['type' => 'datetime', 'nullable' => false]]);

        $config = $mapper->getFieldConfig($metadata, 'createdAt');

        $this->assertNotNull($config);
        $this->assertSame(DateTimeType::class, $config['type']);
    }

    // ── Enum precedence ──────────────────────────────────────────────────────

    #[Test]
    public function enumMappingTakesPrecedenceAndTheGuesserIsNeverConsulted(): void
    {
        /** @var FormTypeGuesserInterface&MockObject $guesser */
        $guesser = $this->createMock(FormTypeGuesserInterface::class);
        $guesser->expects($this->never())->method('guessType');

        $mapper   = new DoctrineFormTypeMapper($guesser);
        $metadata = $this->makeMetadata(
            ['status' => ['type' => 'string', 'nullable' => false]],
            ['status' => TestStatus::class],
        );

        $config = $mapper->getFieldConfig($metadata, 'status');

        $this->assertNotNull($config);
        $this->assertSame(EnumType::class, $config['type']);
    }

    // ── Backward compatibility: bare construction never guesses ─────────────

    #[Test]
    public function bareConstructedMapperNeverGuesses(): void
    {
        $mapper   = new DoctrineFormTypeMapper(); // no guesser injected — today's behaviour, unchanged
        $metadata = $this->makeMetadata(['email' => ['type' => 'string', 'nullable' => false]]);

        $config = $mapper->getFieldConfig($metadata, 'email');

        $this->assertNotNull($config);
        $this->assertSame(TextType::class, $config['type']);
    }

    // ── No opinion ───────────────────────────────────────────────────────────

    #[Test]
    public function guesserReturningNullFallsThroughToTheOrdinaryMapping(): void
    {
        $guesser = $this->guesserReturning('nickname', null);
        $mapper  = new DoctrineFormTypeMapper($guesser);

        $metadata = $this->makeMetadata(['nickname' => ['type' => 'string', 'nullable' => false]]);
        $config   = $mapper->getFieldConfig($metadata, 'nickname');

        $this->assertNotNull($config);
        $this->assertSame(TextType::class, $config['type']);
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
     * Mirrors DoctrineFormTypeMapperTest::makeMetadata() — see that class for
     * why AllNullableTypeGuessingFixture is a safe default reflection target.
     *
     * @param array<string, array{type: string, nullable: bool}> $fields
     * @param array<string, class-string<\BackedEnum>>           $enumTypes
     * @return ClassMetadata<object>
     */
    private function makeMetadata(array $fields, array $enumTypes = []): ClassMetadata
    {
        /** @var ClassMetadata<object>&MockObject $metadata */
        $metadata = $this->createMock(ClassMetadata::class);

        $metadata->method('getFieldMapping')
            ->willReturnCallback(function (string $field) use ($fields, $enumTypes): FieldMapping {
                $data    = $fields[$field] ?? ['type' => 'string', 'nullable' => false];
                $mapping = new FieldMapping(
                    type: $data['type'],
                    fieldName: $field,
                    columnName: $field,
                );
                $mapping->nullable = $data['nullable'];
                $mapping->enumType = $enumTypes[$field] ?? null;

                return $mapping;
            });
        $metadata->method('hasField')
            ->willReturnCallback(fn (string $field) => isset($fields[$field]));
        $metadata->method('getName')->willReturn(AllNullableTypeGuessingFixture::class);
        $metadata->method('getReflectionClass')
            ->willReturn(new \ReflectionClass(AllNullableTypeGuessingFixture::class));

        return $metadata;
    }
}

/**
 * Every property PHP-nullable, so the Doctrine-vs-PHP nullability
 * cross-check in DoctrineFormTypeMapper never trips for any `nullable`
 * value a test here configures — this file isn't testing that check, see
 * DoctrineFormTypeMapperTest for those cases specifically.
 */
class AllNullableTypeGuessingFixture
{
    public ?string $email = null;
    public ?string $website = null;
    public ?string $note = null;
    public ?string $nickname = null;
    public ?string $status = null;
    public ?\DateTimeInterface $createdAt = null;
}
