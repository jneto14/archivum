<?php

declare(strict_types=1);

namespace App\Console\Commands;

use GdImage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Renders Archivum's favicons and touch icon from a single description of the
 * "Folio" monogram, so every icon stays in sync with the mark and with the
 * brand colour.
 *
 * The geometry here is the same as `resources/js/components/app-logo-icon.tsx`
 * — the component is the in-app copy, this is the asset copy, and
 * `GenerateBrandIconsTest` fails if the committed files drift from what this
 * command produces. Change the mark in both places, then re-run the command and
 * commit the result.
 *
 * The mark is redrawn in GD rather than rasterised from the SVG because the
 * toolchain has no SVG rasteriser (Imagick ships without an SVG delegate).
 * Drawing at 8x and downsampling also antialiases better than GD's own polygon
 * smoothing, which does not antialias filled polygons at all.
 */
#[Signature('brand:icons {--path= : Directory to write the icons into, defaults to public/}')]
#[Description('Regenerate favicon.svg, favicon.ico and apple-touch-icon.png from the brand mark')]
class GenerateBrandIcons extends Command
{
    /**
     * Supersampling factor used before downscaling, which is where the
     * antialiasing comes from.
     */
    private const int SUPERSAMPLE = 8;

    /** The square grid the mark is described on — the SVG's `viewBox`. */
    private const float GRID = 32.0;

    /**
     * The mark's outline, as the points of the component's `<path>`.
     *
     * @var list<array{0: float, 1: float}>
     */
    private const array OUTLINE = [
        [16.0, 3.2], [3.4, 29.0], [9.5, 29.0], [16.0, 15.2], [22.5, 29.0], [28.6, 29.0],
    ];

    /** The crossbar, as the component's `<rect>`: x, y, width, height, radius. */
    private const array CROSSBAR = [11.0, 19.5, 10.0, 4.0, 2.0];

    /**
     * Fraction of the tile the mark's own 32-unit box occupies. Below roughly
     * 0.8 the glyph looks lost in the tile; above it, the legs touch the
     * rounded corners.
     */
    private const float COVERAGE = 0.82;

    /** Corner radius of the tile, as a fraction of its side. */
    private const float TILE_RADIUS = 7 / 32;

    /**
     * The brand colour in OKLCh: lightness, chroma, hue.
     *
     * Must match `--primary` in `resources/css/app.css`. That value is still
     * the placeholder ARC-88 introduced, so this is expected to change once the
     * brand hue is decided — re-run this command when it does.
     */
    private const array BRAND_OKLCH = [0.48, 0.12, 255.0];

    /**
     * @return int The command's exit code.
     *
     * @throws RuntimeException If GD cannot allocate the brand colours.
     */
    public function handle(): int
    {
        $directory = mb_rtrim($this->option('path') ?? public_path(), '/');
        $brand = $this->brandRgb();
        $hex = vsprintf('#%02x%02x%02x', $brand);

        file_put_contents($directory . '/favicon.svg', $this->markSvg($hex));

        $pngs = [];
        foreach ([16, 32, 48] as $size) {
            $icon = $this->renderIcon($size, true, $brand);
            $pngs[$size] = $this->encodePng($icon);
            imagedestroy($icon);
        }
        file_put_contents($directory . '/favicon.ico', $this->packIco($pngs));

        $touchIcon = $this->renderIcon(180, false, $brand);
        file_put_contents($directory . '/apple-touch-icon.png', $this->encodePng($touchIcon));
        imagedestroy($touchIcon);

        $this->info("Wrote favicon.svg, favicon.ico and apple-touch-icon.png to {$directory} in {$hex}.");

        return self::SUCCESS;
    }

    /**
     * Convert the brand colour from OKLCh to 8-bit sRGB.
     *
     * @return array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>} Red, green and blue.
     */
    private function brandRgb(): array
    {
        [$lightness, $chroma, $hue] = self::BRAND_OKLCH;

        $a = $chroma * cos(deg2rad($hue));
        $b = $chroma * sin(deg2rad($hue));

        $long = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $medium = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $short = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $linear = [
            4.0767416621 * $long - 3.3077115913 * $medium + 0.2309699292 * $short,
            -1.2684380046 * $long + 2.6097574011 * $medium - 0.3413193965 * $short,
            -0.0041960863 * $long - 0.7034186147 * $medium + 1.7076147010 * $short,
        ];

        // Clamped after the cast rather than before it, so the range is carried
        // in the type: an out-of-gamut OKLCh value would otherwise reach GD as a
        // negative or over-bright channel.
        $channels = array_map(function (float $channel): int {
            $encoded = $channel <= 0.0031308
                ? 12.92 * $channel
                : 1.055 * $channel ** (1 / 2.4) - 0.055;

            return max(0, min(255, (int) round($encoded * 255)));
        }, $linear);

        return [$channels[0], $channels[1], $channels[2]];
    }

    /**
     * Build the standalone SVG favicon: the mark knocked out of a brand tile.
     *
     * It is a filled tile rather than a bare glyph in `currentColor` because a
     * favicon does not inherit colour from the page, and a tile stays legible
     * against both a light and a dark tab bar.
     *
     * @param string $hex The brand colour as a CSS hex triplet.
     *
     * @return string The SVG document.
     */
    private function markSvg(string $hex): string
    {
        $scale = self::COVERAGE;
        $offset = round(self::GRID * (1 - $scale) / 2, 3);
        $radius = round(self::GRID * self::TILE_RADIUS, 3);
        [$x, $y, $width, $height, $rx] = self::CROSSBAR;

        $outline = 'M16 3.2 3.4 29h6.1L16 15.2 22.5 29h6.1L16 3.2Z';

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32">
                <title>Archivum</title>
                <rect width="32" height="32" rx="{$radius}" fill="{$hex}"/>
                <g fill="#ffffff" transform="translate({$offset} {$offset}) scale({$scale})">
                    <path d="{$outline}"/>
                    <rect x="{$x}" y="{$y}" width="{$width}" height="{$height}" rx="{$rx}"/>
                </g>
            </svg>

            SVG;
    }

    /**
     * Render one square icon.
     *
     * @param positive-int $size The output side in pixels.
     * @param bool $rounded Whether the tile gets rounded, transparent corners. False for
     *                      the touch icon, which iOS masks itself and so must be full-bleed.
     * @param array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>} $brand The tile colour.
     *
     * @return GdImage The rendered icon.
     *
     * @throws RuntimeException If GD cannot allocate one of the two colours.
     */
    private function renderIcon(int $size, bool $rounded, array $brand): GdImage
    {
        $large = $size * self::SUPERSAMPLE;

        $canvas = imagecreatetruecolor($large, $large);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $large - 1, $large - 1, $this->allocate($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        $tile = $this->allocate($canvas, $brand[0], $brand[1], $brand[2]);
        $glyph = $this->allocate($canvas, 255, 255, 255);

        if ($rounded) {
            $this->fillRoundedRect($canvas, 0, 0, $large - 1, $large - 1, $large * self::TILE_RADIUS, $tile);
        } else {
            imagefilledrectangle($canvas, 0, 0, $large - 1, $large - 1, $tile);
        }

        $this->drawMark($canvas, (float) $large, $glyph);

        $icon = imagecreatetruecolor($size, $size);
        imagealphablending($icon, false);
        imagesavealpha($icon, true);
        imagecopyresampled($icon, $canvas, 0, 0, 0, 0, $size, $size, $large, $large);
        imagedestroy($canvas);

        return $icon;
    }

    /**
     * Allocate a colour on an image.
     *
     * GD reports allocation failure by returning false, which cannot happen on
     * the truecolor images used here but is worth failing loudly on rather than
     * passing a false into every later drawing call.
     *
     * @param GdImage $image The image to allocate on.
     * @param int<0, 255> $red The red channel.
     * @param int<0, 255> $green The green channel.
     * @param int<0, 255> $blue The blue channel.
     * @param int<0, 127> $alpha The alpha channel, 0 opaque to 127 transparent.
     *
     * @return int The allocated colour.
     *
     * @throws RuntimeException If GD refuses the allocation.
     */
    private function allocate(GdImage $image, int $red, int $green, int $blue, int $alpha = 0): int
    {
        $colour = imagecolorallocatealpha($image, $red, $green, $blue, $alpha);

        if ($colour === false) {
            throw new RuntimeException('Unable to allocate a colour for the brand icon.');
        }

        return $colour;
    }

    /**
     * Draw the mark centred inside a square.
     *
     * @param GdImage $image The image to draw on.
     * @param float $size The side of the square, in pixels.
     * @param int $colour An allocated GD colour.
     *
     * @return void
     */
    private function drawMark(GdImage $image, float $size, int $colour): void
    {
        $unit = $size / self::GRID * self::COVERAGE;
        $inset = ($size - self::GRID * $unit) / 2;

        $points = [];
        foreach (self::OUTLINE as [$x, $y]) {
            $points[] = (int) round($inset + $x * $unit);
            $points[] = (int) round($inset + $y * $unit);
        }
        imagefilledpolygon($image, $points, $colour);

        [$x, $y, $width, $height, $radius] = self::CROSSBAR;
        $this->fillRoundedRect(
            $image,
            $inset + $x * $unit,
            $inset + $y * $unit,
            $width * $unit,
            $height * $unit,
            $radius * $unit,
            $colour,
        );
    }

    /**
     * Fill a rounded rectangle, which GD has no primitive for.
     *
     * @param GdImage $image The image to draw on.
     * @param float $x The left edge, in pixels.
     * @param float $y The top edge, in pixels.
     * @param float $width The width, in pixels.
     * @param float $height The height, in pixels.
     * @param float $radius The corner radius, in pixels.
     * @param int $colour An allocated GD colour.
     *
     * @return void
     */
    private function fillRoundedRect(GdImage $image, float $x, float $y, float $width, float $height, float $radius, int $colour): void
    {
        $radius = min($radius, $width / 2, $height / 2);
        $diameter = (int) round($radius * 2);

        imagefilledrectangle($image, (int) round($x + $radius), (int) round($y), (int) round($x + $width - $radius), (int) round($y + $height), $colour);
        imagefilledrectangle($image, (int) round($x), (int) round($y + $radius), (int) round($x + $width), (int) round($y + $height - $radius), $colour);

        $corners = [
            [$x + $radius, $y + $radius],
            [$x + $width - $radius, $y + $radius],
            [$x + $radius, $y + $height - $radius],
            [$x + $width - $radius, $y + $height - $radius],
        ];

        foreach ($corners as [$centreX, $centreY]) {
            imagefilledellipse($image, (int) round($centreX), (int) round($centreY), $diameter, $diameter, $colour);
        }
    }

    /**
     * Encode an image as PNG.
     *
     * @param GdImage $image The image to encode.
     *
     * @return string The PNG payload.
     */
    private function encodePng(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    /**
     * Pack PNG payloads into a multi-resolution .ico container.
     *
     * .ico entries may hold either a BMP or, since Vista, a whole PNG — the
     * latter, used here, keeps the alpha channel without hand-rolling a BMP
     * mask.
     *
     * @param array<int, string> $pngs PNG payloads keyed by their square size in pixels.
     *
     * @return string The .ico file contents.
     */
    private function packIco(array $pngs): string
    {
        $entries = '';
        $payload = '';
        $offset = 6 + 16 * count($pngs);

        foreach ($pngs as $size => $png) {
            // '8bit' is not optional: these are byte offsets into a binary
            // container, and Pint's mb_str_functions rule rewrites strlen() to
            // mb_strlen(), which would otherwise count PNG bytes as UTF-8
            // characters and write a directory that points nowhere.
            $length = mb_strlen($png, '8bit');

            $entries .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, $length, $offset);
            $payload .= $png;
            $offset += $length;
        }

        return pack('vvv', 0, 1, count($pngs)) . $entries . $payload;
    }
}
