<?php
/**
 * Tests for the configuration validator.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WpNcLinker\Config\Validator;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Validation and normalisation of configuration values.
 */
#[CoversClass( Validator::class )]
final class ValidatorTest extends TestCase {

	/**
	 * URLs and their normalised form (null = invalid).
	 *
	 * @return array<string, array{string, string|null}>
	 */
	public static function urls(): array {
		return array(
			'plain'             => array( 'https://cloud.example.org', 'https://cloud.example.org' ),
			'trailing slash'    => array( 'https://cloud.example.org/', 'https://cloud.example.org' ),
			'sub-path'          => array( 'https://example.org/nextcloud/', 'https://example.org/nextcloud' ),
			'port and case'     => array( ' HTTPS://Cloud.Example.org:8443 ', 'https://cloud.example.org:8443' ),
			'ipv4'              => array( 'https://192.0.2.10', 'https://192.0.2.10' ),
			'ipv6'              => array( 'https://[2001:db8::1]', 'https://[2001:db8::1]' ),
			'http'              => array( 'http://cloud.example.org', null ),
			'no scheme'         => array( 'cloud.example.org', null ),
			'credentials'       => array( 'https://user:pass@cloud.example.org', null ),
			'query'             => array( 'https://cloud.example.org/?a=b', null ),
			'fragment'          => array( 'https://cloud.example.org/#x', null ),
			'dot segment'       => array( 'https://cloud.example.org/a/../b', null ),
			'encoded path'      => array( 'https://cloud.example.org/%2e%2e', null ),
			'empty'             => array( '', null ),
			'invalid host'      => array( 'https://exa_mple.org', null ),
			'javascript scheme' => array( 'javascript:alert(1)', null ),
		);
	}

	#[DataProvider( 'urls' )]
	public function test_url( string $input, ?string $expected ): void {
		$this->assertSame( $expected, Validator::url( $input ) );
	}

	/**
	 * User IDs (null = invalid).
	 *
	 * @return array<string, array{string, string|null}>
	 */
	public static function users(): array {
		return array(
			'simple'        => array( 'linker', 'linker' ),
			'allowed chars' => array( "web.site_linker-1@example.org O'Neil", "web.site_linker-1@example.org O'Neil" ),
			'outer space'   => array( ' linker', null ),
			'slash'         => array( 'link/er', null ),
			'umlaut'        => array( 'lünker', null ),
			'empty'         => array( '', null ),
			'too long'      => array( str_repeat( 'a', 65 ), null ),
		);
	}

	#[DataProvider( 'users' )]
	public function test_user( string $input, ?string $expected ): void {
		$this->assertSame( $expected, Validator::user( $input ) );
	}

	public function test_app_password(): void {
		$this->assertSame( 'AbCdE-fGhIj-KlMnO-pQrSt-UvWxY', Validator::app_password( ' AbCdE-fGhIj-KlMnO-pQrSt-UvWxY ' ) );
		$this->assertNull( Validator::app_password( 'with space' ) );
		$this->assertNull( Validator::app_password( "line\nbreak" ) );
		$this->assertNull( Validator::app_password( '' ) );
		$this->assertNull( Validator::app_password( str_repeat( 'a', 257 ) ) );
	}

	/**
	 * Proxy prefixes (null = invalid).
	 *
	 * @return array<string, array{string, string|null}>
	 */
	public static function prefixes(): array {
		return array(
			'default'       => array( 'nextcloud-media', 'nextcloud-media' ),
			'case, slashes' => array( '/Media/', 'media' ),
			'double hyphen' => array( 'a--b', null ),
			'nested'        => array( 'a/b', null ),
			'reserved'      => array( 'wp-content', null ),
			'reserved json' => array( 'wp-json', null ),
			'empty'         => array( '', null ),
			'dots'          => array( '..', null ),
		);
	}

	#[DataProvider( 'prefixes' )]
	public function test_proxy_prefix( string $input, ?string $expected ): void {
		$this->assertSame( $expected, Validator::proxy_prefix( $input ) );
	}

	public function test_cache_max_age(): void {
		$this->assertSame( 0, Validator::cache_max_age( '0' ) );
		$this->assertSame( 86400, Validator::cache_max_age( 86400 ) );
		$this->assertSame( 31536000, Validator::cache_max_age( '31536000' ) );
		$this->assertNull( Validator::cache_max_age( 31536001 ) );
		$this->assertNull( Validator::cache_max_age( -1 ) );
		$this->assertNull( Validator::cache_max_age( '1e3' ) );
		$this->assertNull( Validator::cache_max_age( 1.5 ) );
		$this->assertNull( Validator::cache_max_age( null ) );
	}
}
