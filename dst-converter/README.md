# Image → DST Embroidery Converter (PHP)

Upload an image, adjust settings, and download a Tajima `.dst` file ready for the embroidery machine.

## Run
Needs PHP 8+ with the GD extension (standard on almost every host).

```
cd dst-converter
php -S localhost:8000
```
Open http://localhost:8000. Or upload `index.php` + `digitizer.php` to any PHP web host.

## Best results
* Use a **clean logo** with flat colors (PNG with transparent background is ideal). Photos give noisy results with many trims.
* Pick **Coverage → "Safe – no thread breaks"** for smooth sewing. Use **Heavy/Maximum** only for full coverage on stable fabric (sew slower, use firm backing).
* Set **Thread colors** to the real number of colors in the design, or type your own thread colors as hex.
* Read any ⚠ warnings shown after converting. They point out settings that can cause thread breaks.

## What it does
Color reduction → speckle cleanup → background removal → per color: underlay + tatami fill
(narrow areas become satin) → pull compensation, color overlap, lock stitches, trims
→ short-stitch removal → DST encoding (centered, 12.1 mm max per record, valid header).

Note: DST stores only stitches and color stops, not thread colors. Use the thread table on the result page.
