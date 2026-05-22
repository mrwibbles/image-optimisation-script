#!/usr/bin/env php
<?php

const MAX_WIDTH       = 1920;
const MAX_HEIGHT      = 1080;
const DEFAULT_MAX_KB  = 300;
const MIN_QUALITY     = 50;   // never go below this to avoid unusable output
const PNG_COMPRESSION = 9;

function usage(): void {
    echo "Usage: optimise.php <image> [--output <file>] [--webp] [--max-width N] [--max-height N] [--max-kb N]\n";
    echo "  --output     Output path (default: <name>.optimised.<ext>)\n";
    echo "  --webp       Convert output to WebP\n";
    echo "  --max-width  Max width in pixels (default: " . MAX_WIDTH . ")\n";
    echo "  --max-height Max height in pixels (default: " . MAX_HEIGHT . ")\n";
    echo "  --max-kb     Target maximum file size in KB (default: " . DEFAULT_MAX_KB . ")\n";
    exit(1);
}

function fail(string $msg): never {
    fwrite(STDERR, "Error: $msg\n");
    exit(1);
}

function bytes(int $b): string {
    if ($b >= 1048576) return round($b / 1048576, 2) . ' MB';
    if ($b >= 1024)    return round($b / 1024, 1) . ' KB';
    return "$b B";
}

function resample(\GdImage $src, int $newW, int $newH, int $origW, int $origH): \GdImage {
    $dst = imagecreatetruecolor($newW, $newH);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    return $dst;
}

function saveToBuffer(\GdImage $img, string $format, int $quality): string {
    ob_start();
    match ($format) {
        'jpeg' => imagejpeg($img, null, $quality),
        'webp' => imagewebp($img, null, $quality),
        'png'  => imagepng($img, null, PNG_COMPRESSION),
        'gif'  => imagegif($img),
    };
    return ob_get_clean();
}

// Parse args
$opts  = [];
$input = null;
$args  = array_slice($argv, 1);

for ($i = 0; $i < count($args); $i++) {
    match ($args[$i]) {
        '--webp'       => $opts['webp'] = true,
        '--output'     => $opts['output'] = $args[++$i] ?? fail('--output requires a value'),
        '--max-width'  => $opts['max_width'] = (int)($args[++$i] ?? fail('--max-width requires a value')),
        '--max-height' => $opts['max_height'] = (int)($args[++$i] ?? fail('--max-height requires a value')),
        '--max-kb'     => $opts['max_kb'] = (int)($args[++$i] ?? fail('--max-kb requires a value')),
        default        => $input = $args[$i],
    };
}

if (!$input) usage();
if (!file_exists($input)) fail("File not found: $input");

$maxW   = $opts['max_width']  ?? MAX_WIDTH;
$maxH   = $opts['max_height'] ?? MAX_HEIGHT;
$maxKB  = $opts['max_kb']     ?? DEFAULT_MAX_KB;
$maxBytes = $maxKB * 1024;
$toWebP = $opts['webp'] ?? false;

// Detect input type
$mime = mime_content_type($input);
$src = match ($mime) {
    'image/jpeg' => imagecreatefromjpeg($input),
    'image/png'  => imagecreatefrompng($input),
    'image/webp' => imagecreatefromwebp($input),
    'image/gif'  => imagecreatefromgif($input),
    default      => fail("Unsupported image type: $mime"),
};

if (!$src) fail("Could not load image.");

// Auto-rotate JPEG based on EXIF orientation
if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
    $exif = @exif_read_data($input);
    $orientation = $exif['Orientation'] ?? 1;
    $src = match ($orientation) {
        3 => imagerotate($src, 180, 0),
        6 => imagerotate($src, -90, 0),
        8 => imagerotate($src, 90, 0),
        default => $src,
    };
}

$origW = imagesx($src);
$origH = imagesy($src);

$saveFormat = $toWebP ? 'webp' : match ($mime) {
    'image/jpeg' => 'jpeg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    default      => 'jpeg',
};

$ext = $toWebP ? 'webp' : match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    default      => 'jpg',
};

$outputPath = $opts['output'] ?? (function() use ($input, $ext) {
    $info = pathinfo($input);
    return ($info['dirname'] !== '.' ? $info['dirname'] . '/' : '')
         . $info['filename'] . '.optimised.' . $ext;
})();

// --- Optimisation loop ---
// Strategy:
//   1. Start at max allowed dimensions with quality 85.
//   2. Binary-search quality down to MIN_QUALITY.
//   3. If still over budget, scale dimensions down in 10% steps and repeat.

$scaleFactor = 1.0;
$finalW = $origW;
$finalH = $origH;
$finalQuality = 85;
$finalData = null;

while ($scaleFactor >= 0.1) {
    // Apply dimension cap then scale factor
    $targetW = (int)round(min($origW, $maxW) * $scaleFactor);
    $targetH = (int)round(min($origH, $maxH) * $scaleFactor);

    // Maintain aspect ratio within cap
    $ratio = $origW / $origH;
    $newW = $targetW;
    $newH = (int)round($newW / $ratio);
    if ($newH > $targetH) {
        $newH = $targetH;
        $newW = (int)round($newH * $ratio);
    }
    $newW = max(1, $newW);
    $newH = max(1, $newH);

    $canvas = ($newW !== $origW || $newH !== $origH)
        ? resample($src, $newW, $newH, $origW, $origH)
        : $src;

    if (in_array($saveFormat, ['jpeg', 'webp'])) {
        // Binary search on quality
        $lo = MIN_QUALITY;
        $hi = 85;
        $bestData = null;
        $bestQ = $hi;

        while ($lo <= $hi) {
            $mid = (int)(($lo + $hi) / 2);
            $data = saveToBuffer($canvas, $saveFormat, $mid);
            if (strlen($data) <= $maxBytes) {
                $bestData = $data;
                $bestQ = $mid;
                $lo = $mid + 1; // try higher quality
            } else {
                $hi = $mid - 1; // need smaller
            }
        }
    } else {
        // PNG/GIF: lossless, quality param unused
        $bestData = saveToBuffer($canvas, $saveFormat, 0);
        $bestQ = 0;
    }

    if ($canvas !== $src) imagedestroy($canvas);

    if ($bestData !== null && strlen($bestData) <= $maxBytes) {
        $finalData    = $bestData;
        $finalW       = $newW;
        $finalH       = $newH;
        $finalQuality = $bestQ;
        break;
    }

    // Still over budget — try stepping up from MIN_QUALITY result anyway as best so far
    if ($bestData !== null && ($finalData === null || strlen($bestData) < strlen($finalData))) {
        $finalData    = $bestData;
        $finalW       = $newW;
        $finalH       = $newH;
        $finalQuality = $bestQ;
    }

    $scaleFactor -= 0.1;
}

imagedestroy($src);

if ($finalData === null) fail("Could not produce output image.");

if (file_put_contents($outputPath, $finalData) === false) {
    fail("Failed to write output: $outputPath");
}

// Report
$inSize  = filesize($input);
$outSize = strlen($finalData);
$saving  = $inSize > 0 ? round((1 - $outSize / $inSize) * 100, 1) : 0;
$metTarget = $outSize <= $maxBytes;

echo "Input:   $input (" . bytes($inSize) . ", {$origW}x{$origH})\n";
echo "Output:  $outputPath (" . bytes($outSize) . ", {$finalW}x{$finalH})\n";
echo "Saving:  {$saving}%\n";
if ($finalW !== $origW || $finalH !== $origH) {
    echo "Resized: {$origW}x{$origH} → {$finalW}x{$finalH}\n";
}
if (in_array($saveFormat, ['jpeg', 'webp'])) {
    echo "Quality: {$finalQuality}\n";
}
if (!$metTarget) {
    echo "Warning: could not reach target {$maxKB} KB — output is " . bytes($outSize) . " at minimum quality\n";
}
