<?php
declare( strict_types = 1 );

namespace App\Tests\Engine;

use App\Engine\TextNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase {
	/**
	 * @return array<string, array<string, array<int, array<string, mixed>>>>
	 */
	private function ruleset(): array {
		return [
			'common' => [
				'diacritics' => [
					[ 'type' => 'regex', 'pattern' => "\u{0324}", 'replacement' => "\u{0308}" ],
					[ 'type' => 'unicode', 'form' => 'NFC' ],
				],
			],
			'de' => [
				'old-letters' => [
					[ 'type' => 'unicode', 'form' => 'NFKC' ],
					[ 'type' => 'regex', 'pattern' => "\u{A75B}", 'replacement' => 'r' ],
					[ 'type' => 'regex', 'pattern' => "\u{2E17}", 'replacement' => '-' ],
				],
			],
		];
	}

	/**
	 * @return mixed[][]
	 */
	private function provideNormalize(): array {
		return [
			'combining diaeresis below is composed' => [
				[ 'de' ],
				[ 'all' ],
				"K\u{006F}\u{0324}nig",
				"K\u{00F6}nig",
			],
			'u with diaeresis below becomes u-umlaut (not U+1E73)' => [
				[],
				[ 'all' ],
				"u\u{0324}",
				"\u{00FC}",
			],
			'all groups for a language' => [
				[ 'de' ],
				[ 'all' ],
				"K\u{006F}\u{0324}nig \u{017F}tadt \u{2133}\u{A75B} \u{2E17}",
				"K\u{00F6}nig stadt Mr -",
			],
			'language-specific groups do not apply to other languages' => [
				[ 'en' ],
				[ 'all' ],
				"\u{017F}tadt",
				"\u{017F}tadt",
			],
			'regional language codes match the base language' => [
				[ 'de-AT' ],
				[ 'all' ],
				"\u{017F}",
				's',
			],
			'underscore language codes match the base language' => [
				[ 'de_old' ],
				[ 'all' ],
				"\u{017F}",
				's',
			],
			'a group that is known but does not apply to the language is skipped' => [
				[ 'en' ],
				[ 'old-letters' ],
				"\u{017F}",
				"\u{017F}",
			],
			'selected groups only' => [
				[ 'de' ],
				[ 'old-letters' ],
				"K\u{006F}\u{0324}n \u{017F}",
				"K\u{006F}\u{0324}n s",
			],
			'all wins over explicitly selected groups' => [
				[ 'de' ],
				[ 'all', 'old-letters' ],
				"\u{017F}",
				's',
			],
			'no groups selected' => [
				[ 'de' ],
				[],
				"\u{017F}\u{006F}\u{0324}",
				"\u{017F}\u{006F}\u{0324}",
			],
			'groups are applied in definition order, not selection order' => [
				[ 'de' ],
				[ 'diacritics', 'old-letters' ],
				"K\u{006F}\u{0324}nig \u{017F}tadt \u{2133}\u{A75B} \u{2E17}",
				"K\u{00F6}nig stadt Mr -",
			],
		];
	}

	/**
	 * @dataProvider provideNormalize
	 * @covers TextNormalizer::normalize
	 */
	public function testNormalize( array $langs, array $groups, string $in, string $out ): void {
		$normalizer = new TextNormalizer( $this->ruleset() );
		$this->assertSame( $out, $normalizer->normalize( $in, $langs, $groups ) );
	}

	/**
	 * @covers TextNormalizer::normalize
	 */
	public function testUnicodeForms(): void {
		$normalizer = new TextNormalizer( [
			'common' => [
				'nfd' => [ [ 'type' => 'unicode', 'form' => 'NFD' ] ],
			],
		] );
		$this->assertSame( "a\u{0308}", $normalizer->normalize( "\u{00E4}", [], [ 'nfd' ] ) );
	}

	/**
	 * @covers TextNormalizer::normalize
	 */
	public function testRulesAreAppliedInDefinitionOrder(): void {
		$normalizer = new TextNormalizer( [
			'common' => [
				'first' => [ [ 'type' => 'regex', 'pattern' => 'x', 'replacement' => 'y' ] ],
				'second' => [ [ 'type' => 'regex', 'pattern' => 'y', 'replacement' => 'z' ] ],
			],
		] );
		$this->assertSame( 'z', $normalizer->normalize( 'x', [], null ) );
		$this->assertSame( 'x', $normalizer->normalize( 'x', [], [ 'second' ] ) );
	}

	/**
	 * @covers TextNormalizer::__construct
	 * @covers TextNormalizer::normalize
	 */
	public function testDefaultRuleset(): void {
		$normalizer = new TextNormalizer();
		$this->assertSame(
			"K\u{00F6}nig stadt Mr -",
			$normalizer->normalize( "K\u{006F}\u{0324}nig \u{017F}tadt \u{2133}\u{A75B} \u{2E17}", [ 'de' ], [ 'all' ] )
		);
	}

	/**
	 * @covers TextNormalizer::getKnownGroupIds
	 */
	public function testGetKnownGroupIds(): void {
		$normalizer = new TextNormalizer( $this->ruleset() );
		$this->assertSame( [ 'diacritics', 'old-letters' ], $normalizer->getKnownGroupIds() );
	}

	/**
	 * @return mixed[]
	 */
	public function provideGetAvailableGroupIds(): array {
		return [
			[ [ 'de' ], [ 'diacritics', 'old-letters' ] ],
			[ [], [ 'diacritics' ] ],
			[ [ 'en' ], [ 'diacritics' ] ],
			[ [ 'de-AT' ], [ 'diacritics', 'old-letters' ] ],
		];
	}

	/**
	 * @dataProvider provideGetAvailableGroupIds
	 * @covers TextNormalizer::getAvailableGroupIds
	 */
	public function testGetAvailableGroupIds( array $langs, array $expected ): void {
		$this->assertSame( $expected, ( new TextNormalizer( $this->ruleset() ) )->getAvailableGroupIds( $langs ) );
	}

	/**
	 * @covers TextNormalizer::normalize
	 */
	public function testUnknownGroupThrows(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown rule group: bogus' );
		( new TextNormalizer( $this->ruleset() ) )->normalize( 'x', [ 'de' ], [ 'bogus' ] );
	}

	/**
	 * @return mixed[]
	 */
	public function provideInvalidRulesets(): array {
		return [
			'unknown rule type' => [
				[ 'common' => [ 'g' => [ [ 'type' => 'bogus' ] ] ] ],
			],
			'missing pattern' => [
				[ 'common' => [ 'g' => [ [ 'type' => 'regex', 'replacement' => 'x' ] ] ] ],
			],
			'missing replacement' => [
				[ 'common' => [ 'g' => [ [ 'type' => 'regex', 'pattern' => 'x' ] ] ] ],
			],
			'empty pattern' => [
				[ 'common' => [ 'g' => [ [ 'type' => 'regex', 'pattern' => '', 'replacement' => 'x' ] ] ] ],
			],
			'invalid pattern' => [
				[ 'common' => [ 'g' => [ [ 'type' => 'regex', 'pattern' => '(', 'replacement' => 'x' ] ] ] ],
			],
			'invalid unicode form' => [
				[ 'common' => [ 'g' => [ [ 'type' => 'unicode', 'form' => 'NFX' ] ] ] ],
			],
			'missing form' => [
				[ 'common' => [ 'g' => [ [ 'type' => 'unicode' ] ] ] ],
			],
			'empty group' => [
				[ 'common' => [ 'g' => [] ] ],
			],
		];
	}

	/**
	 * @dataProvider provideInvalidRulesets
	 * @covers TextNormalizer::__construct
	 * @param array<string, array<string, array<int, array<string, mixed>>>> $ruleset
	 */
	public function testInvalidRulesetThrows( array $ruleset ): void {
		$this->expectException( InvalidArgumentException::class );
		new TextNormalizer( $ruleset );
	}

	/**
	 * @covers TextNormalizer::getRuleset
	 */
	public function testGetRuleset(): void {
		$ruleset = $this->ruleset();
		$this->assertSame( $ruleset, ( new TextNormalizer( $ruleset ) )->getRuleset() );
	}
}
