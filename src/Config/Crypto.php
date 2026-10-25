<?php
/**
 * Encryption of the stored app password.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated encryption (libsodium secretbox) with a key derived from the WordPress salts.
 */
final class Crypto {

	private const PREFIX  = 'wpncl1:';
	private const CONTEXT = 'wp-nc-linker|app-password|v1|';

	/**
	 * Supplies the key material (called only when needed, after pluggable functions are loaded).
	 *
	 * @var \Closure(): string
	 */
	private \Closure $key_material;

	/**
	 * Constructor.
	 *
	 * @param \Closure $key_material Supplier of the secret key material.
	 * @phpstan-param \Closure(): string $key_material
	 */
	public function __construct( \Closure $key_material ) {
		$this->key_material = $key_material;
	}

	/**
	 * Uses the WordPress authentication salts as key material.
	 */
	public static function from_wordpress(): self {
		return new self( static fn (): string => wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) );
	}

	/**
	 * Encrypts a value.
	 *
	 * @param string $plaintext Value to encrypt.
	 */
	public function encrypt( string $plaintext ): string {
		$key    = $this->key();
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		sodium_memzero( $key );

		return self::PREFIX . sodium_bin2base64( $nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL );
	}

	/**
	 * Decrypts a value. Returns null if it is malformed, was tampered with or the salts changed.
	 *
	 * @param string $ciphertext Value produced by encrypt().
	 */
	public function decrypt( string $ciphertext ): ?string {
		if ( ! self::is_ciphertext( $ciphertext ) ) {
			return null;
		}

		try {
			$raw = sodium_base642bin( substr( $ciphertext, strlen( self::PREFIX ) ), SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException ) {
			return null;
		}
		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}

		$key   = $this->key();
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$key
		);
		sodium_memzero( $key );

		return false === $plain ? null : $plain;
	}

	/**
	 * Whether the value has the format produced by encrypt().
	 *
	 * @param string $value Value to check.
	 */
	public static function is_ciphertext( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	/**
	 * Derives the 32-byte key.
	 */
	private function key(): string {
		$material = ( $this->key_material )();

		return sodium_crypto_generichash( self::CONTEXT . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
