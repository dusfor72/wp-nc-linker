<?php
/**
 * Test double for the Nextcloud client.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Diagnostics;

use WpNcLinker\Config\Crypto;
use WpNcLinker\Config\Settings;
use WpNcLinker\Nextcloud\Client;
use WpNcLinker\Nextcloud\Response;
use WpNcLinker\Tests\Unit\Config\FakeConstants;

/**
 * Answers requests from a map ("METHOD target" => Response or exception) and records them.
 */
final class FakeClient extends Client {

	/**
	 * Requests made, as "METHOD target".
	 *
	 * @var list<string>
	 */
	public array $requests = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, Response|\Throwable> $answers Answers per request.
	 */
	public function __construct( private array $answers ) {
		parent::__construct( new Settings( new FakeConstants(), new Crypto( static fn (): string => 'salt' ) ) );
	}

	public function status(): Response {
		return $this->answer( 'GET /status.php' );
	}

	public function propfind( string $path, int $depth ): Response {
		return $this->answer( "PROPFIND $path $depth" );
	}

	public function search( string $type_prefix, int $limit ): Response {
		return $this->answer( "SEARCH $type_prefix" );
	}

	public function preview( int $file_id, int $width, int $height, int $max_bytes ): Response {
		return $this->answer( "PREVIEW $file_id" );
	}

	public function user(): string {
		return 'linker';
	}

	/**
	 * Looks up the answer; unknown requests get a 500.
	 *
	 * @param string $key Request key.
	 * @throws \Throwable Configured exception.
	 */
	private function answer( string $key ): Response {
		$this->requests[] = $key;
		$answer           = $this->answers[ $key ] ?? new Response( 500, array(), '' );
		if ( $answer instanceof \Throwable ) {
			throw $answer;
		}

		return $answer;
	}
}
