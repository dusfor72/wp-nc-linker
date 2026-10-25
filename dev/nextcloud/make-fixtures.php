<?php
/**
 * Generates the test files for the seed (no binary fixtures in the repository).
 *
 * Usage: php make-fixtures.php <target dir>
 */

declare(strict_types=1);

$target = $argv[1] ?? '';
if ($target === '' || !is_dir($target)) {
	fwrite(STDERR, "Target directory missing.\n");
	exit(1);
}

/**
 * Writes a gradient image with a label.
 */
function make_image(string $file, int $width, int $height, array $from, array $to, string $label): void {
	$image = imagecreatetruecolor($width, $height);
	for ($y = 0; $y < $height; $y++) {
		$t = $y / max(1, $height - 1);
		$color = imagecolorallocate(
			$image,
			(int)round($from[0] + ($to[0] - $from[0]) * $t),
			(int)round($from[1] + ($to[1] - $from[1]) * $t),
			(int)round($from[2] + ($to[2] - $from[2]) * $t),
		);
		imageline($image, 0, $y, $width - 1, $y, $color);
	}
	$white = imagecolorallocate($image, 255, 255, 255);
	imagestring($image, 5, 20, 20, $label . " {$width}x{$height}", $white);

	match (pathinfo($file, PATHINFO_EXTENSION)) {
		'png' => imagepng($image, $file),
		default => imagejpeg($image, $file, 90),
	};
}

/**
 * Writes a JPEG of random noise that is larger than 50 MB.
 */
function make_large_jpeg(string $file): void {
	$width = 6000;
	$height = 4000;
	$ppm = "P6\n{$width} {$height}\n255\n" . random_bytes($width * $height * 3);
	$image = new Imagick();
	$image->readImageBlob($ppm);
	$image->setImageFormat('jpeg');
	$image->setImageCompressionQuality(100);
	$image->writeImage($file);
	$image->clear();
	if (filesize($file) <= 50 * 1024 * 1024) {
		fwrite(STDERR, "Large original is not larger than 50 MB.\n");
		exit(1);
	}
}

/**
 * Writes a minimal one-page PDF with correct cross-reference offsets.
 */
function make_pdf(string $file, string $text): void {
	$stream = "BT /F1 24 Tf 72 720 Td ({$text}) Tj ET";
	$objects = [
		'<< /Type /Catalog /Pages 2 0 R >>',
		'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
		'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
		'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
		'<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
	];
	$pdf = "%PDF-1.4\n";
	$offsets = [];
	foreach ($objects as $i => $object) {
		$offsets[] = strlen($pdf);
		$pdf .= ($i + 1) . " 0 obj\n{$object}\nendobj\n";
	}
	$xref = strlen($pdf);
	$pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
	foreach ($offsets as $offset) {
		$pdf .= sprintf("%010d 00000 n \n", $offset);
	}
	$pdf .= 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
	file_put_contents($file, $pdf);
}

$files = [
	'content/Photos/landscape.jpg' => fn (string $f) => make_image($f, 1600, 1067, [30, 90, 160], [240, 170, 60], 'Landscape'),
	'content/Photos/portrait.jpg' => fn (string $f) => make_image($f, 1067, 1600, [20, 120, 80], [200, 230, 120], 'Portrait'),
	'content/Photos/square.png' => fn (string $f) => make_image($f, 800, 800, [120, 40, 140], [250, 120, 160], 'Square'),
	'content/Photos/large-original.jpg' => fn (string $f) => make_large_jpeg($f),
	'content/Shared With Linker/forest.jpg' => fn (string $f) => make_image($f, 2400, 1600, [10, 60, 20], [90, 160, 70], 'Forest'),
	'content/Shared With Linker/harbor.jpg' => fn (string $f) => make_image($f, 1200, 800, [10, 40, 90], [140, 190, 230], 'Harbor'),
	'content/Shared With Linker/sample.pdf' => fn (string $f) => make_pdf($f, 'WP Nextcloud Linker sample PDF'),
	'content/Shared With Linker/Subfolder/night.png' => fn (string $f) => make_image($f, 640, 960, [5, 5, 30], [60, 40, 110], 'Night'),
	'content/Hidden Download/no-download.jpg' => fn (string $f) => make_image($f, 1024, 768, [90, 10, 10], [220, 80, 60], 'Hidden download'),
	'linker/Own/linker-photo.jpg' => fn (string $f) => make_image($f, 1280, 960, [60, 60, 60], [200, 200, 200], 'Linker own'),
];

foreach ($files as $relative => $generator) {
	$file = $target . '/' . $relative;
	if (!is_dir(dirname($file))) {
		mkdir(dirname($file), 0750, true);
	}
	$generator($file);
	echo $relative, ' (', filesize($file), " bytes)\n";
}
