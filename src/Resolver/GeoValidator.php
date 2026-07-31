<?php
/**
 * Pure country and region validation/normalization.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Resolver;

/**
 * One pure static class for the geographic validation and normalization
 * every provider's candidate goes through uniformly in the resolver loop
 * (Revision 3 §7) — never per-provider.
 *
 * Structural and set-membership validation only. It does not know about
 * GeoCandidate, providers, confidence, source attribution, or WordPress.
 * Both methods operate on raw strings and return raw strings, exactly per
 * Revision 3 §4.4 — not on/to a GeoCandidate instance.
 *
 * @internal
 * @final
 */
final class GeoValidator {

	/**
	 * Embedded ISO 3166-1 alpha-2 allowlist, plus 'XK' (Kosovo,
	 * user-assigned, explicitly included by Revision 3 §8). Never derived
	 * from WC()->countries or any other commercial/sales list — geographic
	 * evidence must not be filtered by policy. Keyed by code for O(1)
	 * lookup; the value is unused.
	 *
	 * Deliberately excludes exceptionally-reserved / user-assigned codes
	 * commonly seen as GeoIP placeholders — EU, AP, XX, ZZ, T1, A1, A2, O1
	 * — none of which are real ISO 3166-1 countries. A structural regex
	 * alone (`^[A-Z]{2}$`) would accept every one of them; only a real
	 * membership check rejects them.
	 */
	private const COUNTRY_CODES = array(
		'AD' => true,
		'AE' => true,
		'AF' => true,
		'AG' => true,
		'AI' => true,
		'AL' => true,
		'AM' => true,
		'AO' => true,
		'AQ' => true,
		'AR' => true,
		'AS' => true,
		'AT' => true,
		'AU' => true,
		'AW' => true,
		'AX' => true,
		'AZ' => true,
		'BA' => true,
		'BB' => true,
		'BD' => true,
		'BE' => true,
		'BF' => true,
		'BG' => true,
		'BH' => true,
		'BI' => true,
		'BJ' => true,
		'BL' => true,
		'BM' => true,
		'BN' => true,
		'BO' => true,
		'BQ' => true,
		'BR' => true,
		'BS' => true,
		'BT' => true,
		'BV' => true,
		'BW' => true,
		'BY' => true,
		'BZ' => true,
		'CA' => true,
		'CC' => true,
		'CD' => true,
		'CF' => true,
		'CG' => true,
		'CH' => true,
		'CI' => true,
		'CK' => true,
		'CL' => true,
		'CM' => true,
		'CN' => true,
		'CO' => true,
		'CR' => true,
		'CU' => true,
		'CV' => true,
		'CW' => true,
		'CX' => true,
		'CY' => true,
		'CZ' => true,
		'DE' => true,
		'DJ' => true,
		'DK' => true,
		'DM' => true,
		'DO' => true,
		'DZ' => true,
		'EC' => true,
		'EE' => true,
		'EG' => true,
		'EH' => true,
		'ER' => true,
		'ES' => true,
		'ET' => true,
		'FI' => true,
		'FJ' => true,
		'FK' => true,
		'FM' => true,
		'FO' => true,
		'FR' => true,
		'GA' => true,
		'GB' => true,
		'GD' => true,
		'GE' => true,
		'GF' => true,
		'GG' => true,
		'GH' => true,
		'GI' => true,
		'GL' => true,
		'GM' => true,
		'GN' => true,
		'GP' => true,
		'GQ' => true,
		'GR' => true,
		'GS' => true,
		'GT' => true,
		'GU' => true,
		'GW' => true,
		'GY' => true,
		'HK' => true,
		'HM' => true,
		'HN' => true,
		'HR' => true,
		'HT' => true,
		'HU' => true,
		'ID' => true,
		'IE' => true,
		'IL' => true,
		'IM' => true,
		'IN' => true,
		'IO' => true,
		'IQ' => true,
		'IR' => true,
		'IS' => true,
		'IT' => true,
		'JE' => true,
		'JM' => true,
		'JO' => true,
		'JP' => true,
		'KE' => true,
		'KG' => true,
		'KH' => true,
		'KI' => true,
		'KM' => true,
		'KN' => true,
		'KP' => true,
		'KR' => true,
		'KW' => true,
		'KY' => true,
		'KZ' => true,
		'LA' => true,
		'LB' => true,
		'LC' => true,
		'LI' => true,
		'LK' => true,
		'LR' => true,
		'LS' => true,
		'LT' => true,
		'LU' => true,
		'LV' => true,
		'LY' => true,
		'MA' => true,
		'MC' => true,
		'MD' => true,
		'ME' => true,
		'MF' => true,
		'MG' => true,
		'MH' => true,
		'MK' => true,
		'ML' => true,
		'MM' => true,
		'MN' => true,
		'MO' => true,
		'MP' => true,
		'MQ' => true,
		'MR' => true,
		'MS' => true,
		'MT' => true,
		'MU' => true,
		'MV' => true,
		'MW' => true,
		'MX' => true,
		'MY' => true,
		'MZ' => true,
		'NA' => true,
		'NC' => true,
		'NE' => true,
		'NF' => true,
		'NG' => true,
		'NI' => true,
		'NL' => true,
		'NO' => true,
		'NP' => true,
		'NR' => true,
		'NU' => true,
		'NZ' => true,
		'OM' => true,
		'PA' => true,
		'PE' => true,
		'PF' => true,
		'PG' => true,
		'PH' => true,
		'PK' => true,
		'PL' => true,
		'PM' => true,
		'PN' => true,
		'PR' => true,
		'PS' => true,
		'PT' => true,
		'PW' => true,
		'PY' => true,
		'QA' => true,
		'RE' => true,
		'RO' => true,
		'RS' => true,
		'RU' => true,
		'RW' => true,
		'SA' => true,
		'SB' => true,
		'SC' => true,
		'SD' => true,
		'SE' => true,
		'SG' => true,
		'SH' => true,
		'SI' => true,
		'SJ' => true,
		'SK' => true,
		'SL' => true,
		'SM' => true,
		'SN' => true,
		'SO' => true,
		'SR' => true,
		'SS' => true,
		'ST' => true,
		'SV' => true,
		'SX' => true,
		'SY' => true,
		'SZ' => true,
		'TC' => true,
		'TD' => true,
		'TF' => true,
		'TG' => true,
		'TH' => true,
		'TJ' => true,
		'TK' => true,
		'TL' => true,
		'TM' => true,
		'TN' => true,
		'TO' => true,
		'TR' => true,
		'TT' => true,
		'TV' => true,
		'TW' => true,
		'TZ' => true,
		'UA' => true,
		'UG' => true,
		'UM' => true,
		'US' => true,
		'UY' => true,
		'UZ' => true,
		'VA' => true,
		'VC' => true,
		'VE' => true,
		'VG' => true,
		'VI' => true,
		'VN' => true,
		'VU' => true,
		'WF' => true,
		'WS' => true,
		'XK' => true, // Kosovo — user-assigned, not officially ISO 3166-1, explicitly included per Revision 3 §8.
		'YE' => true,
		'YT' => true,
		'ZA' => true,
		'ZM' => true,
		'ZW' => true,
	);

	/**
	 * Validates and normalizes a raw provider-reported country code.
	 *
	 * Trims whitespace, uppercases, then checks membership in the embedded
	 * ISO 3166-1 alpha-2 allowlist (plus 'XK'). Membership alone enforces
	 * the two-letter uppercase shape — nothing outside that shape can be a
	 * key in the allowlist — so no separate regex step is needed.
	 *
	 * @param string|null $raw The country code as reported by a provider, in any case, possibly with whitespace.
	 *
	 * @return string|null The normalized code, or null when $raw is null, empty, or not a recognized country.
	 */
	public static function country( ?string $raw ): ?string {
		if ( null === $raw ) {
			return null;
		}

		$value = strtoupper( trim( $raw ) );

		return isset( self::COUNTRY_CODES[ $value ] ) ? $value : null;
	}

	/**
	 * Returns every supported ISO 3166-1 alpha-2 country code.
	 *
	 * @return string[]
	 */
	public static function country_codes(): array {
		return array_keys( self::COUNTRY_CODES );
	}

	/**
	 * Validates and normalizes a raw provider-reported region code.
	 *
	 * Trims whitespace, uppercases, strips a leading "{$country}-" prefix
	 * if present (e.g. country 'SE', raw 'SE-AB' → 'AB'), then requires the
	 * remainder to match `^[A-Z0-9]{1,3}$` — syntactic only in v1; no ISO
	 * 3166-2 membership table exists yet (Revision 3 §8, lands in 1.1).
	 *
	 * $country is trusted to already be a validated, normalized code (the
	 * output of country(), never independently re-validated here).
	 *
	 * @param string|null $raw     The region code as reported by a provider, in any case, possibly with whitespace.
	 * @param string      $country The already-validated country the region belongs to, used only for prefix-stripping.
	 *
	 * @return string|null The normalized region, or null when $raw is null, empty, or does not match the syntactic form.
	 */
	public static function region( ?string $raw, string $country ): ?string {
		if ( null === $raw ) {
			return null;
		}

		$value = strtoupper( trim( $raw ) );

		if ( '' === $value ) {
			return null;
		}

		$prefix = $country . '-';

		if ( str_starts_with( $value, $prefix ) ) {
			$value = substr( $value, strlen( $prefix ) );
		}

		return 1 === preg_match( '/^[A-Z0-9]{1,3}$/', $value ) ? $value : null;
	}
}
