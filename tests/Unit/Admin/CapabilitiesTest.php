<?php
/**
 * Tests for the capability resolution.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Admin;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use WpNcLinker\Admin\Capabilities;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Filterable settings capability.
 */
#[CoversClass( Capabilities::class )]
final class CapabilitiesTest extends TestCase {

	public function test_default_is_manage_options(): void {
		$this->assertSame( 'manage_options', Capabilities::settings() );
	}

	public function test_filter_changes_capability(): void {
		Filters\expectApplied( 'wpncl_settings_capability' )->with( 'manage_options' )->andReturn( 'create_users' );
		Functions\expect( 'current_user_can' )->once()->with( 'create_users' )->andReturn( false );

		$this->assertFalse( Capabilities::can_manage_settings() );
	}

	public function test_invalid_filter_result_falls_back(): void {
		Filters\expectApplied( 'wpncl_settings_capability' )->andReturn( '' );

		$this->assertSame( 'manage_options', Capabilities::settings() );
	}
}
