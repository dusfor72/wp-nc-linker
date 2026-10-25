<?php
/**
 * Tests for the app password encryption.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use WpNcLinker\Config\Crypto;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Encryption with libsodium secretbox.
 */
#[CoversClass( Crypto::class )]
final class CryptoTest extends TestCase {

	private function crypto( string $material = 'salt-a' ): Crypto {
		return new Crypto( static fn (): string => $material );
	}

	public function test_round_trip(): void {
		$cipher = $this->crypto()->encrypt( 'AbCdE-fGhIj' );

		$this->assertTrue( Crypto::is_ciphertext( $cipher ) );
		$this->assertStringNotContainsString( 'AbCdE-fGhIj', $cipher );
		$this->assertSame( 'AbCdE-fGhIj', $this->crypto()->decrypt( $cipher ) );
	}

	public function test_random_nonce(): void {
		$this->assertNotSame( $this->crypto()->encrypt( 'secret' ), $this->crypto()->encrypt( 'secret' ) );
	}

	public function test_other_key_fails(): void {
		$cipher = $this->crypto( 'salt-a' )->encrypt( 'secret' );

		$this->assertNull( $this->crypto( 'salt-b' )->decrypt( $cipher ) );
	}

	public function test_tampered_value_fails(): void {
		$cipher = $this->crypto()->encrypt( 'secret' );
		$raw    = sodium_base642bin( substr( $cipher, strlen( 'wpncl1:' ) ), SODIUM_BASE64_VARIANT_ORIGINAL );
		$last   = strlen( $raw ) - 1;

		$raw[ $last ] = chr( ord( $raw[ $last ] ) ^ 1 );

		$this->assertNull( $this->crypto()->decrypt( 'wpncl1:' . sodium_bin2base64( $raw, SODIUM_BASE64_VARIANT_ORIGINAL ) ) );
	}

	public function test_malformed_values_fail(): void {
		$this->assertNull( $this->crypto()->decrypt( '' ) );
		$this->assertNull( $this->crypto()->decrypt( 'plain-text' ) );
		$this->assertNull( $this->crypto()->decrypt( 'wpncl1:not base64!' ) );
		$this->assertNull( $this->crypto()->decrypt( 'wpncl1:' . sodium_bin2base64( 'short', SODIUM_BASE64_VARIANT_ORIGINAL ) ) );
	}

	public function test_key_material_is_read_lazily(): void {
		$calls  = 0;
		$crypto = new Crypto(
			static function () use ( &$calls ): string {
				++$calls;
				return 'salt';
			}
		);
		$this->assertSame( 0, $calls );

		$crypto->decrypt( $crypto->encrypt( 'x' ) );
		$this->assertSame( 2, $calls );
	}
}
