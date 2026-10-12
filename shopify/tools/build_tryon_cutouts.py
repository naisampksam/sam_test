#!/usr/bin/env python3
"""Build transparent try-on cutouts for the live camera "Try it on" feature.

For every launch product and colour, the front and back photos in
shopify/product-images are cut out from their white studio background and saved
as theme assets (theme/assets/tryon-<photo>.png). A Liquid snippet with the
product -> colour -> {front, back} mapping is written to theme/snippets/tryon-map.liquid.

    python3 shopify/tools/build_tryon_cutouts.py

Products without a clean studio photo (e.g. lifestyle shots) are skipped; the
try-on button only appears for products that have a cutout. A shop owner can
also add one per product with the metafields custom.tryon_front / custom.tryon_back.
"""

import json
import os
import sys

from PIL import Image, ImageChops, ImageDraw, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
sys.path.insert(0, HERE)
import build_products_csv as catalogue  # noqa: E402

PHOTOS = os.path.join(ROOT, "product-images")
ASSETS = os.path.join(ROOT, "theme", "assets")
SNIPPET = os.path.join(ROOT, "theme", "snippets", "tryon-map.liquid")
OUT_WIDTH = 560


def pixels(img):
    return img.get_flattened_data() if hasattr(img, "get_flattened_data") else img.getdata()


def own_mask(im):
    r, g, b = im.split()
    low = ImageChops.darker(ImageChops.darker(r, g), b)
    high = ImageChops.lighter(ImageChops.lighter(r, g), b)
    spread = ImageChops.subtract(high, low)
    # background = bright and neutral
    bright = low.point(lambda v: 255 if v > 238 else 0)
    neutral = spread.point(lambda v: 255 if v < 14 else 0)
    cand = ImageChops.multiply(bright, neutral)
    w, h = cand.size
    seeds = [(0, 0), (w - 1, 0), (0, h - 1), (w - 1, h - 1), (w // 2, 0), (w // 2, h - 1), (0, h // 2), (w - 1, h // 2)]
    for s in seeds:
        if cand.getpixel(s) == 255:
            ImageDraw.floodfill(cand, s, 128)
    bg = cand.point(lambda v: 255 if v == 128 else 0)
    alpha = ImageChops.invert(bg)
    # remove specks, soften the edge a touch
    alpha = alpha.filter(ImageFilter.MinFilter(3)).filter(ImageFilter.MaxFilter(3))
    return alpha.filter(ImageFilter.GaussianBlur(0.8))


def is_light(im):
    """White/cream tees can't be separated from a white backdrop by colour alone."""
    centre = im.convert("L").crop((im.width * 3 // 10, im.height * 4 // 10, im.width * 7 // 10, im.height * 6 // 10))
    values = sorted(pixels(centre))
    return values[len(values) // 2] > 185


def borrowed_mask(im, ref_path):
    """Map the clean silhouette of a dark tee photo onto the light tee's bounding box."""
    ref_alpha = own_mask(Image.open(ref_path).convert("RGB"))
    ref_box = ref_alpha.point(lambda v: 255 if v > 40 else 0).getbbox()
    target_box = im.convert("L").point(lambda v: 255 if v < 247 else 0).filter(ImageFilter.MinFilter(3)).getbbox()
    alpha = Image.new("L", im.size, 0)
    shape = ref_alpha.crop(ref_box).resize((target_box[2] - target_box[0], target_box[3] - target_box[1]), Image.LANCZOS)
    alpha.paste(shape, target_box[:2])
    return alpha


def cutout(path, ref_path=None):
    im = Image.open(path).convert("RGB")
    alpha = borrowed_mask(im, ref_path) if ref_path and is_light(im) else own_mask(im)
    # trim the 1-2px fringe where the white studio backdrop blends into the fabric edge
    alpha = alpha.filter(ImageFilter.MinFilter(5)).filter(ImageFilter.GaussianBlur(0.7))
    bbox = alpha.point(lambda v: 255 if v > 40 else 0).getbbox()
    if not bbox:
        return None, 0
    coverage = sum(pixels(alpha.crop(bbox).point(lambda v: 1 if v > 128 else 0))) / ((bbox[2] - bbox[0]) * (bbox[3] - bbox[1]))
    rgba = im.copy()
    rgba.putalpha(alpha)
    rgba = rgba.crop(bbox)
    ratio = OUT_WIDTH / rgba.width
    rgba = rgba.resize((OUT_WIDTH, round(rgba.height * ratio)), Image.LANCZOS)
    return rgba, coverage


def reference_for(product, img, side):
    """A dark photo of the same design and side, else the black essential blank."""
    for imgs in product["colors"].values():
        for other in imgs:
            if other != img and other.endswith("-" + side) and ("black" in other or "navy" in other):
                path = os.path.join(PHOTOS, other + ".jpg")
                if os.path.exists(path):
                    return path
    return os.path.join(PHOTOS, f"oversized-black-{side}.jpg")


def main():
    mapping = {}
    written = 0
    for p in catalogue.PRODUCTS:
        colors = {}
        for color, imgs in p["colors"].items():
            sides = {}
            for img in imgs:
                side = "front" if img.endswith("-front") else "back" if img.endswith("-back") else None
                if not side:
                    continue
                src = os.path.join(PHOTOS, img + ".jpg")
                if not os.path.exists(src):
                    continue
                ref = reference_for(p, img, side)
                rgba, coverage = cutout(src, ref)
                # a studio tee photo fills roughly 55-85% of its bounding box
                if rgba is None or not 0.45 < coverage < 0.92:
                    print(f"  skip {img}: not a clean studio photo (coverage {coverage:.2f})")
                    continue
                name = f"tryon-{img}.png"
                rgba.quantize(colors=255, method=Image.Quantize.FASTOCTREE).save(os.path.join(ASSETS, name), optimize=True)
                sides[side] = name
                written += 1
            if sides:
                colors[color.lower()] = sides
        if colors:
            mapping[p["handle"]] = colors
        else:
            print(f"  no try-on images for {p['handle']}")

    lines = [
        "{%- comment -%}",
        "  Generated by shopify/tools/build_tryon_cutouts.py: product handle -> colour -> try-on cutouts.",
        "  Outputs the JSON map for one product. Accepts: handle",
        "{%- endcomment -%}",
        "{%- case handle -%}",
    ]
    for handle, colors in mapping.items():
        lines.append(f"  {{%- when '{handle}' -%}}")
        lines.append("    " + json.dumps(colors, ensure_ascii=False))
    lines += ["  {%- else -%}", "    {}", "{%- endcase -%}", ""]
    with open(SNIPPET, "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines))
    print(f"Wrote {written} cutouts for {len(mapping)} products and {os.path.relpath(SNIPPET, ROOT)}")


if __name__ == "__main__":
    main()
