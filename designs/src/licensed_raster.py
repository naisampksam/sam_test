#!/usr/bin/env python3
"""300 dpi PNG print files for the licensed artwork, keeping all painted detail (no tracing).

Each image is upscaled to its print width at 300 dpi, then the background colour is turned into
transparency with a colour-to-alpha unmix: every painted pixel keeps its exact look when printed on a
shirt of the background's colour (white/cream art -> light tees, dark art -> black tees). Smoke,
soft edges and brush texture survive; only near-background noise below a small floor is cleared.

Usage: python3 licensed_raster.py <images_dir>  -> designs/licensed/print/<key>.png + mockups
"""
import os
import sys

import numpy as np
from PIL import Image, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))
OUT = os.path.join(ROOT, 'designs', 'licensed')
sys.path.insert(0, HERE)
from mockups import fabric, paste_real  # noqa: E402

DPI, WIDTH_IN = 300, 12
# key: (file, tee, placement, background, feather edges)
#   background 'paper': light art -> paper colour pushed to white (92nd percentile), then white removed
#   background 'ink':   dark art  -> near-black pushed to black (8th percentile), then black removed
#   or an exact RGB tuple to remove as-is
ART = {
    'crow-ronin': ('1.jpg', 'white', 'back-full', 'paper', True),
    'red-lily-samurai': ('2.jpg', 'white', 'back-center', 'paper', True),
    'samurai-stance': ('3.jpg', 'white', 'back-center', 'paper', True),
    'red-blade': ('4.jpg', 'black', 'back-full', 'ink', True),
    'red-beam': ('5.jpg', 'black', 'back-full', 'ink', True),
    'red-dragon': ('6.jpg', 'white', 'back-full', 'paper', True),
    'dragon-sun-ronin': ('7.jpg', 'beige', 'back-full', 'paper', True),
    'banner-ronin': ('9.jpg', 'black', 'back-full', 'ink', True),
    'you-are-enough': ('10.jpg', 'black', 'back-center', (0, 0, 0), False),
}
BLANKS = {'white': 'white back.PNG', 'black': 'back back.PNG', 'beige': 'beige back.PNG'}
PX, ANCHOR_Y = 30, {'back-full': 300, 'back-center': 318}
NOISE_FLOOR = 0.05


def colour_to_alpha(rgb, bg):
    """GIMP-style colour-to-alpha: the most transparent colour that composites back over bg to rgb."""
    p, b = rgb / 255.0, np.asarray(bg, np.float32) / 255.0
    with np.errstate(divide='ignore', invalid='ignore'):
        up = np.where(b < 1, (p - b) / (1 - b), 0)
        down = np.where(b > 0, (b - p) / b, 0)
    a = np.clip(np.where(p > b, up, down), 0, 1).max(-1)
    with np.errstate(divide='ignore', invalid='ignore'):
        col = np.where(a[..., None] > 0, (p - b) / a[..., None] + b, b)
    a = np.clip((a - NOISE_FLOOR) / (1 - NOISE_FLOOR), 0, 1)
    return np.clip(col, 0, 1), a


def convert(key, src_dir):
    file, tee, place, bg, feather = ART[key]
    im = Image.open(os.path.join(src_dir, file)).convert('RGB')
    a = np.asarray(im).astype(np.float32)
    if bg == 'paper':  # levels: the paper tone becomes pure white, darker detail is kept
        wp = np.percentile(a.reshape(-1, 3), 92, axis=0)
        im = Image.fromarray(np.clip(a * 255 / np.maximum(wp, 1), 0, 255).astype(np.uint8))
        bg = (255, 255, 255)
    elif bg == 'ink':  # levels: the near-black backdrop becomes pure black, lighter detail is kept
        bp = np.percentile(a.reshape(-1, 3), 8, axis=0)
        im = Image.fromarray(np.clip((a - bp) * 255 / np.maximum(255 - bp, 1), 0, 255).astype(np.uint8))
        bg = (0, 0, 0)
    W = WIDTH_IN * DPI
    H = round(im.height * W / im.width)
    big = im.resize((W, H), Image.LANCZOS).filter(ImageFilter.UnsharpMask(radius=2.5, percent=70, threshold=2))
    rgb = np.asarray(big).astype(np.float32)
    out = np.zeros((H, W, 4), np.uint8)
    for y in range(0, H, 512):  # in strips to keep memory low
        col, a = colour_to_alpha(rgb[y:y + 512], bg)
        out[y:y + 512, :, :3] = (col * 255).round().astype(np.uint8)
        out[y:y + 512, :, 3] = (a * 255).round().astype(np.uint8)
    if feather:  # fade the outer 0.2 in so full-bleed areas end softly on the shirt instead of a hard box
        f = int(0.2 * DPI)
        ramp = np.minimum(np.arange(W) / f, 1)[None, :] * np.minimum(np.arange(W)[::-1] / f, 1)[None, :]
        ramp = ramp * (np.minimum(np.arange(H) / f, 1) * np.minimum(np.arange(H)[::-1] / f, 1))[:, None]
        out[..., 3] = (out[..., 3] * ramp).astype(np.uint8)
    os.makedirs(os.path.join(OUT, 'print'), exist_ok=True)
    path = os.path.join(OUT, 'print', f'{key}.png')
    img = Image.fromarray(out, 'RGBA')
    img.save(path, dpi=(DPI, DPI))
    # realistic mockup on the matching tee
    blank = Image.open(os.path.join(ROOT, BLANKS[tee])).convert('RGBA')
    wpx = WIDTH_IN * PX
    art = img.resize((wpx, round(H * wpx / W)), Image.LANCZOS)
    paste_real(blank, art, (512 - wpx // 2, ANCHOR_Y[place]), fabric(blank, BLANKS[tee]))
    os.makedirs(os.path.join(OUT, 'mockups'), exist_ok=True)
    blank.convert('RGB').save(os.path.join(OUT, 'mockups', f'{key}-{tee}-back.jpg'), quality=88)
    return path, (W, H), tuple(int(v) for v in bg)


def main():
    for key in (sys.argv[2:] or ART):
        path, size, bg = convert(key, sys.argv[1])
        print(f'{key}: {size[0]}x{size[1]} px = {size[0] / DPI:g} x {size[1] / DPI:.1f} in @ {DPI} dpi, background {bg} removed')


if __name__ == '__main__':
    main()
