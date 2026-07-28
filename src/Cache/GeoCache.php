<?php
/**
 * Derived-context cache: salted-hash keys, epoch invalidation.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Cache;

use UniversalGeo\Model\VisitorContext;

/**
 * Revision 3 §9 Layer 3 — the optional derived-context cache. The only file
 * in the plugin that hashes an IP address (Revision 3 §2's guard-test
 * table). Unconditionally injected into ContextResolver; degrades to an
 * honest no-op internally rather than requiring the caller to branch on
 * whether caching is actually active.
 *
 * Key: "{epoch}:{config_sig}:ip:{hash}", hash = the first 32 hex characters
 * of hash_hmac('sha256', $ip, $salt) — exactly Revision 3 §9's format.
 * Group: 'universal_geo'. $ip is trusted to already be normalized by the
 * caller (ContextResolver, after ClientIpResolverInterface::resolve());
 * this class does not call IpUtils itself.
 *
 * Salt and epoch are read directly via get_option()/update_option() —
 * salt is "32 random bytes, generated on first use" (§9): lazily created
 * and persisted the first time it's needed, never eagerly, matching the
 * plugin-wide lazy-resolution principle. Epoch is "an autoloaded integer
 * option bumped on every settings save" by other code (the future admin
 * save handler) — this class only reads it, never mutates it.
 *
 * Negative results (VisitorContext::is_known() === false) get a fixed,
 * internal 300s TTL rather than the configured TTL, so a failing provider
 * chain is not retried on every request (§9). This is decided here, not by
 * the caller: the negative-result TTL is explicitly "internal" per §9, not
 * one of the two settings-exposed knobs (enabled, TTL).
 *
 * @internal
 * @final
 */
final class GeoCache {

	/**
	 * WordPress object-cache group.
	 */
	private const CACHE_GROUP = 'universal_geo';

	/**
	 * Option holding the cache-key salt.
	 */
	private const SALT_OPTION = 'universal_geo_cache_salt';

	/**
	 * Option holding the invalidation epoch.
	 */
	private const EPOCH_OPTION = 'universal_geo_cache_epoch';

	/**
	 * Epoch value used when the option has never been initialized.
	 */
	private const DEFAULT_EPOCH = 1;

	/**
	 * Salt length in bytes, per Revision 3 §9 ("32 random bytes").
	 */
	private const SALT_BYTES = 32;

	/**
	 * Fixed TTL for negative (unknown) results, per Revision 3 §9. Internal:
	 * not one of the two settings-exposed knobs.
	 */
	private const NEGATIVE_RESULT_TTL = 300;

	/**
	 * Stores the pre-decided configuration this cache operates under.
	 *
	 * @param bool   $enabled     The 'derived_cache_enabled' setting, extracted by the composition root.
	 * @param int    $ttl_seconds The 'derived_cache_ttl' setting in seconds, extracted by the composition root.
	 * @param string $config_sig  A pre-computed hash of the settings that affect resolution; recomputed by the composition root whenever those settings change.
	 */
	public function __construct(
		private readonly bool $enabled,
		private readonly int $ttl_seconds,
		private readonly string $config_sig
	) {
	}

	/**
	 * Looks up a previously cached context for the given IP.
	 *
	 * Returns null on a miss, when caching is inactive, or when the stored
	 * entry is malformed or from an incompatible schema — a corrupt cache
	 * entry must never break resolution, so any of those cases is treated
	 * identically to an ordinary miss.
	 *
	 * On a hit, the returned context always has is_cached = true (Revision
	 * 3 §5 step 4: "hit: mark is_cached = true") — applied here, not left
	 * to the caller, since a lookup that found something unambiguously
	 * knows it came from the cache.
	 *
	 * @param string $ip An already-normalized client IP.
	 *
	 * @return VisitorContext|null
	 */
	public function get( string $ip ): ?VisitorContext {
		if ( ! $this->is_active() ) {
			return null;
		}

		$raw = wp_cache_get( $this->key( $ip ), self::CACHE_GROUP );

		if ( ! is_array( $raw ) ) {
			return null;
		}

		if ( ! isset( $raw['schema_version'] ) || VisitorContext::SCHEMA_VERSION !== $raw['schema_version'] ) {
			return null;
		}

		return VisitorContext::from_array( $raw )->with_cached( true );
	}

	/**
	 * Stores a resolved context for the given IP.
	 *
	 * Does nothing when caching is inactive. Always persists with
	 * is_cached forced to false — the stored payload represents a fresh
	 * resolution; a stale true flag must never carry into its next life,
	 * since get() applies the true flag itself on lookup.
	 *
	 * @param string         $ip      An already-normalized client IP.
	 * @param VisitorContext $context The context to store.
	 *
	 * @return void
	 */
	public function set( string $ip, VisitorContext $context ): void {
		if ( ! $this->is_active() ) {
			return;
		}

		$ttl = $context->is_known() ? $this->ttl_seconds : self::NEGATIVE_RESULT_TTL;

		wp_cache_set( $this->key( $ip ), $context->with_cached( false )->to_array(), self::CACHE_GROUP, $ttl );
	}

	/**
	 * Whether this instance should actually touch the object cache.
	 *
	 * Requires both the administrator's setting and a real persistent
	 * object cache (Revision 3 §9): without one, wp_cache_* is per-request
	 * only, so there is nothing to gain and no reason to spend the hashing
	 * cost.
	 *
	 * @return bool
	 */
	private function is_active(): bool {
		return $this->enabled && wp_using_ext_object_cache();
	}

	/**
	 * Builds the exact Revision 3 §9 cache key for an IP.
	 *
	 * @param string $ip An already-normalized client IP.
	 *
	 * @return string
	 */
	private function key( string $ip ): string {
		$hash = substr( hash_hmac( 'sha256', $ip, $this->salt() ), 0, 32 );

		return sprintf( '%d:%s:ip:%s', $this->epoch(), $this->config_sig, $hash );
	}

	/**
	 * Reads the persisted salt, generating and persisting one on first use.
	 *
	 * @return string
	 */
	private function salt(): string {
		$stored = get_option( self::SALT_OPTION, false );

		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}

		// Not obfuscation: encoding cryptographically random bytes into a
		// DB-safe ASCII string for storage as a wp_options value, so raw
		// binary can't be mangled by column charset/collation handling.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$salt = base64_encode( random_bytes( self::SALT_BYTES ) );

		update_option( self::SALT_OPTION, $salt );

		return $salt;
	}

	/**
	 * Reads the current invalidation epoch. Never writes it — bumping the
	 * epoch belongs to the future settings-save handler, not this class.
	 *
	 * @return int
	 */
	private function epoch(): int {
		$epoch = get_option( self::EPOCH_OPTION, self::DEFAULT_EPOCH );

		return is_int( $epoch ) ? $epoch : self::DEFAULT_EPOCH;
	}
}
