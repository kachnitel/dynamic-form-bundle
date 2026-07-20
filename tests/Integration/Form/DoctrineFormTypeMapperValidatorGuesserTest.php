<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Integration\Form;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Kachnitel\DynamicFormBundle\Form\DoctrineFormTypeMapper;
use Kachnitel\DynamicFormBundle\Form\TypeGuessing\ConventionalFieldTypeGuesser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Extension\Validator\ValidatorTypeGuesser;
use Symfony\Component\Form\FormTypeGuesserChain;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory;
use Symfony\Component\Validator\Mapping\Loader\AttributeLoader;

/**
 * Smoke-tests DoctrineFormTypeMapper against a REAL, unmocked
 * Symfony\Component\Form\Extension\Validator\ValidatorTypeGuesser — the
 * mocked-guesser unit tests in
 * tests/Unit/Form/DoctrineFormTypeMapperTypeGuessingTest.php cover the
 * integration point's own branching in isolation; this file proves the
 * actual wire-up produces the right type from a real #[Assert\...]
 * constraint, with no mocks standing in for Symfony's own guessing logic.
 *
 * Also proves the documented override recipe (docs/TYPE_GUESSING.md,
 * "Enabling naming-convention guessing"): composing ValidatorTypeGuesser
 * with ConventionalFieldTypeGuesser via a real FormTypeGuesserChain.
 *
 * requires symfony/intl (require-dev) for assertCountryConstraintProducesCountryType()
 * specifically — see docs/TYPE_GUESSING.md "Country/Language/Currency/Locale
 * need symfony/intl".
 *
 * @group type-guessing
 * @group integration
 */
#[CoversClass(DoctrineFormTypeMapper::class)]
#[UsesClass(ConventionalFieldTypeGuesser::class)]
#[Group('type-guessing')]
#[Group('integration')]
class DoctrineFormTypeMapperValidatorGuesserTest extends TestCase
{
    private ValidatorTypeGuesser $validatorGuesser;

    protected function setUp(): void
    {
        // The minimal, standard way to obtain a MetadataFactoryInterface backed
        // by attribute-based constraint loading, without needing the full
        // ValidatorBuilder/ValidatorInterface machinery this test has no other
        // use for.
        $metadataFactory = new LazyLoadingMetadataFactory(new AttributeLoader());
        $this->validatorGuesser = new ValidatorTypeGuesser($metadataFactory);
    }

    #[Test]
    public function assertEmailConstraintProducesEmailType(): void
    {
        $mapper   = new DoctrineFormTypeMapper($this->validatorGuesser);
        $metadata = $this->makeMetadata(['email' => ['type' => 'string', 'nullable' => true]]);

        $config = $mapper->getFieldConfig($metadata, 'email');

        $this->assertNotNull($config);
        $this->assertSame(EmailType::class, $config['type']);
    }

    #[Test]
    public function assertUrlConstraintProducesUrlTypeWithNulledDefaultProtocol(): void
    {
        $mapper   = new DoctrineFormTypeMapper($this->validatorGuesser);
        $metadata = $this->makeMetadata(['homepage' => ['type' => 'string', 'nullable' => true]]);

        $config = $mapper->getFieldConfig($metadata, 'homepage');

        $this->assertNotNull($config);
        $this->assertSame(UrlType::class, $config['type']);
        // ValidatorTypeGuesser itself returns UrlType with no options at all —
        // the null default_protocol comes from DoctrineFormTypeMapper's own
        // post-processing, exercised here against a real TypeGuess object.
        $this->assertArrayHasKey('default_protocol', $config['options']);
        $this->assertNull($config['options']['default_protocol']);
    }

    #[Test]
    public function unconstrainedFieldFallsThroughToPlainTextType(): void
    {
        $mapper   = new DoctrineFormTypeMapper($this->validatorGuesser);
        $metadata = $this->makeMetadata(['nickname' => ['type' => 'string', 'nullable' => true]]);

        $config = $mapper->getFieldConfig($metadata, 'nickname');

        $this->assertNotNull($config);
        $this->assertSame(TextType::class, $config['type']);
    }

    /**
     * The documented override recipe: composing ValidatorTypeGuesser with
     * ConventionalFieldTypeGuesser via a real FormTypeGuesserChain. tel has
     * no validator-constraint equivalent at all (see docs/TYPE_GUESSING.md),
     * so only the naming-convention guesser has an opinion here — proving
     * the chain correctly falls through from one real guesser to the other
     * rather than the two conflicting.
     */
    #[Test]
    public function composedChainAppliesConventionalGuessingWhereValidatorGuessingHasNoOpinion(): void
    {
        $chain  = new FormTypeGuesserChain([$this->validatorGuesser, new ConventionalFieldTypeGuesser()]);
        $mapper = new DoctrineFormTypeMapper($chain);

        $metadata = $this->makeMetadata(['mobilePhone' => ['type' => 'string', 'nullable' => true]]);
        $config   = $mapper->getFieldConfig($metadata, 'mobilePhone');

        $this->assertNotNull($config);
        $this->assertSame(TelType::class, $config['type']);
    }

    /**
     * Same composed chain, but for a field the validator guesser DOES have
     * an opinion about — proving the two guessers don't fight when they
     * overlap, and the constraint-based, HIGH_CONFIDENCE-from-a-declared-
     * constraint result is what wins (both would agree here regardless).
     */
    #[Test]
    public function composedChainStillHonoursAConstraintBasedGuess(): void
    {
        $chain  = new FormTypeGuesserChain([$this->validatorGuesser, new ConventionalFieldTypeGuesser()]);
        $mapper = new DoctrineFormTypeMapper($chain);

        $metadata = $this->makeMetadata(['email' => ['type' => 'string', 'nullable' => true]]);
        $config   = $mapper->getFieldConfig($metadata, 'email');

        $this->assertNotNull($config);
        $this->assertSame(EmailType::class, $config['type']);
    }

    // ── Country: isolated in its own class, see below ───────────────────────

    /**
     * Deliberately its own test method against its own fixture class
     * (CountryConstraintFixtureEntity, not ValidatorGuesserFixtureEntity) —
     * see that class's docblock for why sharing a fixture with the other
     * tests here would be a mistake.
     */
    #[Test]
    public function assertCountryConstraintProducesCountryType(): void
    {
        $mapper   = new DoctrineFormTypeMapper($this->validatorGuesser);
        $metadata = $this->makeMetadata(
            ['countryCode' => ['type' => 'string', 'nullable' => true]],
            reflectionClass: CountryConstraintFixtureEntity::class,
        );

        $config = $mapper->getFieldConfig($metadata, 'countryCode');

        $this->assertNotNull($config);
        $this->assertSame(CountryType::class, $config['type']);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @param array<string, array{type: string, nullable: bool}> $fields
     * @return ClassMetadata<object>
     */
    private function makeMetadata(array $fields, string $reflectionClass = ValidatorGuesserFixtureEntity::class): ClassMetadata
    {
        /** @var ClassMetadata<object>&MockObject $metadata */
        $metadata = $this->createMock(ClassMetadata::class);

        $metadata->method('getFieldMapping')
            ->willReturnCallback(function (string $field) use ($fields): FieldMapping {
                $data    = $fields[$field] ?? ['type' => 'string', 'nullable' => false];
                $mapping = new FieldMapping(
                    type: $data['type'],
                    fieldName: $field,
                    columnName: $field,
                );
                $mapping->nullable = $data['nullable'];

                return $mapping;
            });
        $metadata->method('hasField')
            ->willReturnCallback(fn (string $field) => isset($fields[$field]));
        // The real class name here is essential: AttributeLoader resolves
        // constraints by reflecting on it, unlike the mocked-guesser tests
        // where the class name is never actually inspected.
        $metadata->method('getName')->willReturn($reflectionClass);
        $metadata->method('getReflectionClass')
            ->willReturn(new \ReflectionClass($reflectionClass));

        return $metadata;
    }
}

/**
 * Real validator constraints, actually loaded by AttributeLoader via
 * reflection — not stand-ins. mobilePhone and nickname deliberately carry
 * no constraint at all: mobilePhone is the case ValidatorTypeGuesser can't
 * help with (see docs/TYPE_GUESSING.md), used to prove the composed-chain
 * tests above; nickname proves the unconstrained-field fallthrough.
 *
 * Deliberately excludes anything requiring symfony/intl (Country, Language,
 * Currency, Locale) — see CountryConstraintFixtureEntity below for why that
 * needs a fixture of its own rather than living here.
 */
class ValidatorGuesserFixtureEntity
{
    #[Assert\Email]
    public ?string $email = null;

    #[Assert\Url]
    public ?string $homepage = null;

    public ?string $nickname = null;

    public ?string $mobilePhone = null;
}

/**
 * #[Assert\Country] requires symfony/intl at runtime (Symfony throws
 * Symfony\Component\Validator\Exception\LogicException without it — this is
 * a Symfony/Validator requirement, unrelated to this bundle). Crucially,
 * that exception surfaces the moment metadata for the WHOLE class is
 * loaded — LazyLoadingMetadataFactory eagerly instantiates every declared
 * constraint on a class, not just the one for the property being queried —
 * so putting #[Assert\Country] on the same fixture as email/homepage/etc.
 * would make symfony/intl a hidden, blast-radius dependency for tests that
 * have nothing to do with countries. Isolated here so only
 * assertCountryConstraintProducesCountryType() pays that cost.
 */
class CountryConstraintFixtureEntity
{
    #[Assert\Country]
    public ?string $countryCode = null;
}
