<?php
/**
 * Base class for unit tests.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

/**
 * Sets up and tears down Brain Monkey for each test.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
