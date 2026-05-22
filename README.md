# Image Optimiser

A PHP CLI script that optimises images for web delivery using PHP's built-in GD library. No external dependencies required beyond a standard PHP installation.

## Requirements

- PHP 7.4+
- PHP extensions: `gd`, `exif`

## Usage

```bash
php optimise.php <image> [options]
```

### Options

| Option | Description | Default |
|---|---|---|
| `--output <file>` | Output file path | `<name>.optimised.<ext>` |
| `--webp` | Convert output to WebP format | off |
| `--max-width <N>` | Maximum output width in pixels | 1920 |
| `--max-height <N>` | Maximum output height in pixels | 1080 |
| `--max-kb <N>` | Target maximum output file size in KB | 300 |

### Examples

```bash
# Optimise a JPEG — targets 300 KB by default
php optimise.php photo.jpg

# Set a stricter file size target
php optimise.php photo.jpg --max-kb 150

# Specify a custom output path
php optimise.php photo.jpg --output dist/photo.jpg

# Convert to WebP (typically smaller than JPEG at equal quality)
php optimise.php photo.png --webp

# Custom maximum dimensions
php optimise.php banner.jpg --max-width 2560 --max-height 1440

# Combine options
php optimise.php hero.jpg --webp --output dist/hero.webp --max-kb 200
```

### Output

The script prints a summary after processing:

```
Input:   hero.jpg (4.2 MB, 4032x3024)
Output:  hero.optimised.jpg (287.4 KB, 1920x1440)
Saving:  93.3%
Resized: 4032x3024 → 1920x1440
Quality: 74
```

If the target size cannot be reached even at minimum quality, a warning is shown but the best possible result is still saved.

## Supported Formats

| Format | Input | Output |
|---|---|---|
| JPEG | yes | yes |
| PNG | yes | yes |
| WebP | yes | yes |
| GIF | yes | yes (no animation) |

## How It Works

### 1. Argument Parsing

The script accepts a positional input path and optional named flags. Unknown arguments are treated as the input file path.

### 2. Image Loading

The MIME type of the input file is detected using `mime_content_type()`. The appropriate GD loader is called (`imagecreatefromjpeg`, `imagecreatefrompng`, etc.) to bring the image into memory as a GD resource.

### 3. EXIF Auto-Rotation (JPEG only)

Many cameras and phones embed orientation metadata in the EXIF data rather than physically rotating the pixels. Without correction, images can appear sideways or upside down in browsers that ignore EXIF. The script reads the `Orientation` tag and rotates the image resource accordingly before any further processing, so EXIF orientation is baked in and the tag is no longer needed.

### 4. Optimisation Loop

The script works to a file size budget (`--max-kb`, default 300 KB) using a two-stage strategy:

**Stage 1 — Binary search on quality.**  
Starting at the maximum allowed dimensions, the script binary-searches the quality setting (between 85 and a minimum of 20) to find the highest quality that keeps the output within budget. For JPEG and WebP this is straightforward lossy compression. PNG and GIF are lossless so quality has no effect; the script moves directly to stage 2 if they are over budget.

**Stage 2 — Dimension reduction.**  
If no quality value produces a small enough file at the current dimensions, the script reduces the canvas by 10% and repeats the quality search. This continues until the budget is met or the image has been reduced to 10% of its original size, at which point the smallest result achieved is saved with a warning.

### 5. Transparency Preservation

For formats that support an alpha channel (PNG, WebP), the destination canvas is initialised as fully transparent before resampling. `imagealphablending` and `imagesavealpha` are configured so the alpha channel survives the resize step intact.

### 6. Output

The optimised image data is written to disk and a summary is printed showing the input/output sizes, dimensions, final quality value used, and overall percentage saving. If the target size could not be met, a warning is displayed.

With `--webp`, the output is always saved as WebP regardless of the input format. WebP typically achieves smaller files than JPEG at equivalent visual quality, making it a good default choice for modern web targets.

## Default Settings

These constants at the top of the script can be edited directly:

```php
const MAX_WIDTH       = 1920;
const MAX_HEIGHT      = 1080;
const DEFAULT_MAX_KB  = 300;   // target output size in KB
const MIN_QUALITY     = 20;    // never compress below this quality
const PNG_COMPRESSION = 9;     // 0–9, lossless
```
