# Image → DST Embroidery Converter (PHP)

Upload an image, adjust settings, and download a Tajima `.dst` file ready for the embroidery machine.

## Run
Needs PHP 8+ with the GD extension (standard on almost every host).

```
cd dst-converter
php -S localhost:8000
```
Open http://localhost:8000. Or upload `index.php` + `digitizer.php` to any PHP web host.

## How it digitizes (like a digitizer would)
Every color is split into separate shapes and each shape gets the right stitch type:

| Shape | Stitch | Underlay |
|---|---|---|
| Wide areas | Tatami fill (brick pattern, global grid) | Edge run + crossed tatami |
| Narrow shapes – letters, borders, strokes | Satin column that turns with the shape (split above 7 mm) | Centre run + zigzag |
| Hair-thin lines | Bold triple (bean) running stitch on the centre line | – |

Plus: Lab color matching, removal of specks and blurry-edge slivers, color overlap (no gaps),
pull compensation, nearest-first sewing order, hidden travel stitches inside shapes, lock stitches,
trims, no stitch under 0.3 mm or over 7 mm, valid DST header.

## Best results
* Use a **clean logo** with flat colors (PNG with transparent background is ideal). Photos give noisy results with many trims.
* Pick **Coverage → "Safe – no thread breaks"** for smooth sewing. Use **Heavy/Maximum** only for full coverage on stable fabric (sew slower, use firm backing).
* Set **Thread colors** to the real number of colors in the design, or type your own thread colors as hex.
* Text should be at least **5 mm tall**. Smaller text can't be sewn cleanly by anyone.
* Read any ⚠ warnings shown after converting. They point out things that can cause thread breaks or poor results.
* Always do a test sew on the same fabric before production.

Note: DST stores only stitches and color stops, not thread colors. Use the thread table on the result page.
