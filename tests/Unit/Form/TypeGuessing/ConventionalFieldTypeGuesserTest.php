<?php

declare(strict_types=1);

namespace Kachnitel\DynamicFormBundle\Tests\Unit\Form\TypeGuessing;

use Kachnitel\DynamicFormBundle\Form\TypeGuessing\ConventionalFieldTypeGuesser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Guess\Guess;

/**
 * ConventionalFieldTypeGuesser is the bundle's optional, opt-in guesser for
 * the handful of built-in Symfony types ValidatorTypeGuesser has no
 * constraint mapping for at all (tel/color/search), plus email/url as a
 * naming-only fallback when no matching constraint is declared. Not wired
 * into DoctrineFormTypeMapper's default $typeGuesser — see
 * docs/TYPE_GUESSING.md for how it's composed in via FormTypeGuesserChain.
 *
 * password is deliberately not covered — see the class-under-test's own
 * docblock for why.
 *
 * @group type-guessing
 */
#[CoversClass(ConventionalFieldTypeGuesser::class)]
#[Group('type-guessing')]
class ConventionalFieldTypeGuesserTest extends TestCase
{
    private ConventionalFieldTypeGuesser $guesser;

    protected function setUp(): void
    {
        $this->guesser = new ConventionalFieldTypeGuesser();
    }

    // ── email (anywhere) ────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('emailLikeNamesProvider')]
    public function emailLikeNamesGuessEmailType(string $fieldName): void
    {
        $guess = $this->guesser->guessType('App\Entity\User', $fieldName);

        $this->assertNotNull($guess);
        $this->assertSame(EmailType::class, $guess->getType());
        $this->assertSame(Guess::HIGH_CONFIDENCE, $guess->getConfidence());
    }

    /**
     * @return array<string, array{0: non-empty-string}>
     */
    public static function emailLikeNamesProvider(): array
    {
        return [
            'exact'        => ['email'],
            'contactEmail' => ['contactEmail'],
            'billingEmail' => ['billingEmail'],
            'emailAddress' => ['emailAddress'],
        ];
    }

    // ── tel (anywhere) ──────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('telLikeNamesProvider')]
    public function telLikeNamesGuessTelType(string $fieldName): void
    {
        $guess = $this->guesser->guessType('App\Entity\Contact', $fieldName);

        $this->assertNotNull($guess);
        $this->assertSame(TelType::class, $guess->getType());
    }

    /**
     * @return array<string, array{0: non-empty-string}>
     */
    public static function telLikeNamesProvider(): array
    {
        return [
            'phone'           => ['phone'],
            'mobilePhone'     => ['mobilePhone'],
            'faxNumber'       => ['faxNumber'],
            'telephoneNumber' => ['telephoneNumber'],
        ];
    }

    // ── url (last word only) ────────────────────────────────────────────────

    #[Test]
    #[DataProvider('urlLikeNamesProvider')]
    public function urlLikeNamesGuessUrlTypeWithNullDefaultProtocol(string $fieldName): void
    {
        $guess = $this->guesser->guessType('App\Entity\Company', $fieldName);

        $this->assertNotNull($guess);
        $this->assertSame(UrlType::class, $guess->getType());
        // default_protocol must be explicitly null: Symfony deprecated leaving
        // it unset (as of 7.1) and its non-null defaults mutate the submitted
        // value by prepending a scheme — unsafe for a purely name-based guess.
        $this->assertArrayHasKey('default_protocol', $guess->getOptions());
        $this->assertNull($guess->getOptions()['default_protocol']);
    }

    /**
     * @return array<string, array{0: non-empty-string}>
     */
    public static function urlLikeNamesProvider(): array
    {
        return [
            'exact'      => ['url'],
            'websiteUrl' => ['websiteUrl'],
            'profileUrl' => ['profileUrl'],
            'homepage'   => ['homepage'],
        ];
    }

    /**
     * "url" as the FIRST camelCase word (not the last) must not match — this
     * is precisely why the url pattern is anchored to the last word rather
     * than matched anywhere, unlike password/email/tel.
     */
    #[Test]
    #[DataProvider('urlPrefixedNamesProvider')]
    public function urlPrefixedNamesDoNotMatch(string $fieldName): void
    {
        $this->assertNull($this->guesser->guessType('App\Entity\Company', $fieldName));
    }

    /**
     * @return array<string, array{0: non-empty-string}>
     */
    public static function urlPrefixedNamesProvider(): array
    {
        return [
            'urlSlug' => ['urlSlug'],
            'urlPath' => ['urlPath'],
            'urlKey'  => ['urlKey'],
        ];
    }

    // ── color (last word only) ──────────────────────────────────────────────

    #[Test]
    #[DataProvider('colorLikeNamesProvider')]
    public function colorLikeNamesGuessColorType(string $fieldName): void
    {
        $guess = $this->guesser->guessType('App\Entity\Theme', $fieldName);

        $this->assertNotNull($guess);
        $this->assertSame(ColorType::class, $guess->getType());
    }

    /**
     * @return array<string, array{0: non-empty-string}>
     */
    public static function colorLikeNamesProvider(): array
    {
        return [
            'color'            => ['color'],
            'americanSpelling' => ['themeColor'],
            'britishSpelling'  => ['backgroundColour'],
        ];
    }

    // ── search (last word only, medium confidence) ──────────────────────────

    #[Test]
    public function searchSuffixedNameGuessesSearchTypeAtMediumConfidence(): void
    {
        $guess = $this->guesser->guessType('App\Entity\Product', 'productSearch');

        $this->assertNotNull($guess);
        $this->assertSame(SearchType::class, $guess->getType());
        $this->assertSame(Guess::MEDIUM_CONFIDENCE, $guess->getConfidence());
    }

    // ── no match ─────────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('unmatchedNamesProvider')]
    public function unrelatedNamesReturnNull(string $fieldName): void
    {
        $this->assertNull($this->guesser->guessType('App\Entity\Product', $fieldName));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unmatchedNamesProvider(): array
    {
        return [
            'name'        => ['name'],
            'status'      => ['status'],
            'reference'   => ['reference'],
            'description' => ['description'],
            'empty'       => [''],
            // password guessing is deliberately deferred — see class docblock —
            // these are locked in as "not (yet) matched" rather than left
            // untested, so a future accidental reintroduction is caught.
            'password'         => ['password'],
            'plainPassword'    => ['plainPassword'],
        ];
    }

    // ── the guess does not depend on the entity class ───────────────────────

    #[Test]
    public function guessDoesNotVaryByEntityClass(): void
    {
        $first  = $this->guesser->guessType('App\Entity\User', 'email');
        $second = $this->guesser->guessType('App\Entity\SomethingUnrelated', 'email');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->getType(), $second->getType());
    }

    // ── the other three FormTypeGuesserInterface methods are deliberate no-ops ─

    #[Test]
    public function guessRequiredAlwaysReturnsNull(): void
    {
        $this->assertNull($this->guesser->guessRequired('App\Entity\User', 'password'));
    }

    #[Test]
    public function guessMaxLengthAlwaysReturnsNull(): void
    {
        $this->assertNull($this->guesser->guessMaxLength('App\Entity\User', 'password'));
    }

    #[Test]
    public function guessPatternAlwaysReturnsNull(): void
    {
        $this->assertNull($this->guesser->guessPattern('App\Entity\User', 'password'));
    }
}
