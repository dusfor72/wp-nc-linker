<?php
/**
 * Tests for the registration of the settings option.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Config;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use WpNcLinker\Config\Crypto;
use WpNcLinker\Config\Settings;
use WpNcLinker\Config\SettingsRegistration;
use WpNcLinker\Config\SettingsSanitizer;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * The option is registered with its sanitize callback in every context.
 */
#[CoversClass( SettingsRegistration::class )]
final class SettingsRegistrationTest extends TestCase {

	public function test_registers_on_init_with_sanitizer(): void {
		$crypto       = new Crypto( static fn (): string => 'salt' );
		$sanitizer    = new SettingsSanitizer( new Settings( new FakeConstants(), $crypto ), $crypto, static fn (): bool => true );
		$registration = new SettingsRegistration( $sanitizer );

		Actions\expectAdded( 'init' )->once()->with( array( $registration, 'register_setting' ), 0 );
		$registration->register();

		Functions\expect( 'register_setting' )
			->once()
			->with(
				'wpncl',
				Settings::OPTION,
				\Mockery::on(
					static fn ( array $args ): bool => array( $sanitizer, 'sanitize' ) === $args['sanitize_callback']
						&& false === $args['show_in_rest']
						&& Settings::defaults() === $args['default']
				)
			);
		$registration->register_setting();
	}
}
