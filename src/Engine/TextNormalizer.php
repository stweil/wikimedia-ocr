<?php
declare( strict_types = 1 );

namespace App\Engine;

use InvalidArgumentException;
use Normalizer;

/**
 * Applies language-specific groups of cleanup rules to OCR text.
 *
 * Rules are organized in named groups, defined per language plus an optional
 * language-neutral "common" group, and are applied in the order in which they
 * are defined. This order is significant: a rule may assume that all the
 * previous rules have already been applied.
 *
 * The default ruleset is defined in data/normalize-rules.json, and is also
 * served by the /api/normalize_rules endpoint, so that other clients (such as
 * the on-Wikisource editor toolbar, see T348829) can reuse the same
 * definitions.
 *
 * Two kinds of rules are supported. Both kinds have to stay implementable in
 * JavaScript as well, where the same definitions are applied by
 * RegExp (with the "u" flag) and String.prototype.normalize(), respectively:
 *
 * - { "type": "regex", "pattern": "...", "replacement": "..." }
 *
 *   The pattern has to be a regular expression that is valid both in PCRE2
 *   (UTF mode) and in JavaScript, and should therefore avoid
 *   engine-specific syntax; special characters are to be written as actual
 *   characters, not as escape sequences. The replacement is a literal string
 *   without backreferences, as their syntax differs between the engines.
 *
 * - { "type": "unicode", "form": "NFC" | "NFD" | "NFKC" | "NFKD" }
 */
class TextNormalizer {
	/**
	 * Map from normalized form name to the corresponding Normalizer constant.
	 * @var array<string, int>
	 */
	private const FORMS = [
		'nfc' => Normalizer::FORM_C,
		'nfd' => Normalizer::FORM_D,
		'nfkc' => Normalizer::FORM_KC,
		'nfkd' => Normalizer::FORM_KD,
	];

	/**
	 * Map from language (or "common") to rule groups, which are maps from
	 * group id to an ordered list of rules. Kept in definition order.
	 * @var array<string, array<string, array<int, array<string, mixed>>>>
	 */
	private $ruleset;

	/**
	 * All rule groups, flattened and kept in definition order.
	 * @var array<int, array{lang: string, id: string, rules: array<int, array<string, mixed>>}>
	 */
	private $groups;

	/**
	 * TextNormalizer constructor.
	 *
	 * @param array<string, array<string, array<int, array<string, mixed>>>>|null $ruleset
	 *  Map from language (or "common") to rule groups, see above.
	 *  If null, the default ruleset (data/normalize-rules.json) is loaded.
	 * @throws InvalidArgumentException for an invalid ruleset
	 */
	public function __construct( ?array $ruleset = null ) {
		$this->ruleset = $ruleset ?? self::loadDefaultRuleset();
		$this->validateRuleset( $this->ruleset );
		$this->groups = $this->flattenGroups( $this->ruleset );
	}

	/**
	 * Load the default ruleset that is shipped with the tool.
	 * @return array<string, array<string, array<int, array<string, mixed>>>>
	 * @throws InvalidArgumentException
	 */
	public static function loadDefaultRuleset(): array {
		$path = dirname( __DIR__, 2 ) . '/data/normalize-rules.json';
		$ruleset = json_decode( (string)file_get_contents( $path ), true );
		if ( !is_array( $ruleset ) || $ruleset === [] ) {
			throw new InvalidArgumentException( "Invalid normalize rules file: $path" );
		}
		return $ruleset;
	}

	/**
	 * @return array<string, array<string, array<int, array<string, mixed>>>>
	 */
	public function getRuleset(): array {
		return $this->ruleset;
	}

	/**
	 * Get the ids of all the rule groups that are defined in the ruleset.
	 * @return string[]
	 */
	public function getKnownGroupIds(): array {
		return array_values( array_unique( array_column( $this->groups, 'id' ) ) );
	}

	/**
	 * Get the ids of the rule groups that apply to the given languages,
	 * i.e. the language-neutral "common" groups plus the groups of the
	 * matching languages, in definition order.
	 * @param string[] $langs
	 * @return string[]
	 */
	public function getAvailableGroupIds( array $langs ): array {
		$ids = [];
		foreach ( $this->groups as $group ) {
			if ( $this->languageMatches( $group['lang'], $langs ) ) {
				$ids[] = $group['id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Clean up the given text by applying the selected rule groups to it.
	 *
	 * @param string $text
	 * @param string[] $langs
	 *  Language codes that language-specific groups are matched against.
	 *  A group for language "xx" matches a language code "xx", as well as
	 *  codes where it is the part before a "-" or "_" (e.g. "xx-at").
	 *  May be empty, in which case only the "common" groups apply.
	 * @param string[]|null $groupIds
	 *  The ids of the groups to apply, in any order; they will be applied in
	 *  their definition order, and a group that does not apply to the given
	 *  languages is skipped. If null, or if it contains "all", all the
	 *  applicable groups are applied.
	 * @return string
	 * @throws InvalidArgumentException for an unknown group id
	 */
	public function normalize( string $text, array $langs = [], ?array $groupIds = null ): string {
		foreach ( $this->selectGroups( $langs, $groupIds ) as $group ) {
			foreach ( $group['rules'] as $rule ) {
				$text = $this->applyRule( $text, $rule );
			}
		}
		return $text;
	}

	/**
	 * @param array<string|int, array<string|int, array<int, array<string, mixed>>>> $ruleset
	 * @throws InvalidArgumentException
	 */
	private function validateRuleset( array $ruleset ): void {
		foreach ( $ruleset as $lang => $groups ) {
			if ( !is_string( $lang ) || $lang === '' || !is_array( $groups ) ) {
				throw new InvalidArgumentException( 'Invalid normalize rules: invalid language section' );
			}
			foreach ( $groups as $id => $rules ) {
				if ( !is_string( $id ) || $id === '' || !is_array( $rules ) || $rules === []
					|| !array_is_list( $rules )
				) {
					throw new InvalidArgumentException(
						"Invalid normalize rules: invalid rule group '$id' for language '$lang'"
					);
				}
				foreach ( $rules as $rule ) {
					if ( !is_array( $rule ) || !isset( $rule['type'] ) || !is_string( $rule['type'] ) ) {
						throw new InvalidArgumentException(
							"Invalid normalize rules: invalid rule in group '$id' for language '$lang'"
						);
					}
					switch ( $rule['type'] ) {
					case 'regex':
						if ( !isset( $rule['pattern'] ) || !isset( $rule['replacement'] )
							|| !is_string( $rule['pattern'] ) || $rule['pattern'] === ''
							|| !is_string( $rule['replacement'] )
						) {
							throw new InvalidArgumentException(
								"Invalid normalize rules: regex rule in group '$id' "
								. "for language '$lang' requires 'pattern' and 'replacement'"
							);
						}
						$subject = '';
						// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
						if ( @preg_match( '/' . $rule['pattern'] . '/u', $subject ) === false ) {
							throw new InvalidArgumentException(
								"Invalid normalize rules: invalid regular expression "
								. "'{$rule['pattern']}' in group '$id' for language '$lang'"
							);
						}
						break;
					case 'unicode':
						$form = isset( $rule['form'] ) && is_string( $rule['form'] )
							? mb_strtolower( $rule['form'] ) : '';
						if ( !isset( self::FORMS[$form] ) ) {
							throw new InvalidArgumentException(
								"Invalid normalize rules: unicode rule in group '$id' "
								. "for language '$lang' requires a valid 'form'"
							);
						}
						break;
					default:
						throw new InvalidArgumentException(
							"Invalid normalize rules: unknown rule type '{$rule['type']}' "
							. "in group '$id' for language '$lang'"
						);
					}
				}
			}
		}
	}

	/**
	 * @param array<string, array<string, array<int, array<string, mixed>>>> $ruleset
	 * @return array<int, array{lang: string, id: string, rules: array<int, array<string, mixed>>}>
	 */
	private function flattenGroups( array $ruleset ): array {
		$groups = [];
		foreach ( $ruleset as $lang => $langGroups ) {
			foreach ( $langGroups as $id => $rules ) {
				$groups[] = [
					'lang' => $lang,
					'id' => $id,
					'rules' => $rules,
				];
			}
		}
		return $groups;
	}

	/**
	 * Select the rule groups to apply to texts in the given languages.
	 * @param string[] $langs
	 * @param string[]|null $groupIds
	 * @return array<int, array{lang: string, id: string, rules: array<int, array<string, mixed>>}>
	 * @throws InvalidArgumentException for an unknown group id
	 */
	private function selectGroups( array $langs, ?array $groupIds ): array {
		$all = $groupIds === null || in_array( 'all', $groupIds, true );
		$ids = $all ? [] : array_values( array_unique(
			array_filter( array_map( 'mb_strtolower', $groupIds ?: [] ), static fn( $id ): bool => $id !== '' )
		) );
		if ( !$all ) {
			$known = array_map( 'mb_strtolower', $this->getKnownGroupIds() );
			foreach ( $ids as $id ) {
				if ( !in_array( $id, $known, true ) ) {
					throw new InvalidArgumentException( "Unknown rule group: $id" );
				}
			}
		}
		$selected = [];
		foreach ( $this->groups as $group ) {
			if ( !$this->languageMatches( $group['lang'], $langs )
				|| ( !$all && !in_array( mb_strtolower( $group['id'] ), $ids, true ) )
			) {
				continue;
			}
			$selected[] = $group;
		}
		return $selected;
	}

	/**
	 * Check whether a ruleset language key matches any of the given language codes.
	 *
	 * @param string $langKey
	 * @param string[] $langs
	 * @return bool
	 */
	private function languageMatches( string $langKey, array $langs ): bool {
		if ( $langKey === 'common' ) {
			return true;
		}
		$langKey = mb_strtolower( $langKey );
		foreach ( $langs as $lang ) {
			$lang = mb_strtolower( (string)$lang );
			if ( $lang === $langKey || preg_split( '/[-_]/', $lang, 2 )[0] === $langKey ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $text
	 * @param array<string, mixed> $rule
	 * @return string
	 * @throws InvalidArgumentException
	 */
	private function applyRule( string $text, array $rule ): string {
		switch ( $rule['type'] ) {
			case 'unicode':
				$form = self::FORMS[mb_strtolower( (string)$rule['form'] )];
				$normalized = Normalizer::normalize( $text, $form );
				if ( $normalized === false ) {
					throw new InvalidArgumentException(
						'Failed to normalize text with form ' . $rule['form']
					);
				}
				return $normalized;
			case 'regex':
				$replacement = str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], (string)$rule['replacement'] );
				$result = preg_replace( '/' . $rule['pattern'] . '/u', $replacement, $text );
				if ( $result === null ) {
					throw new InvalidArgumentException( 'Invalid regular expression: ' . $rule['pattern'] );
				}
				return $result;
		}
		throw new InvalidArgumentException( 'Invalid rule type: ' . var_export( $rule['type'] ?? null, true ) );
	}
}
