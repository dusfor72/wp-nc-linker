<?php
/**
 * Development only: trust the system CA store, which contains the development CA.
 *
 * TLS verification stays on; only the trusted CA bundle is changed.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

add_filter(
	'wpncl_ca_bundle',
	static fn (): string => '/etc/ssl/certs/ca-certificates.crt'
);
