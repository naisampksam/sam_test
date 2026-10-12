#!/usr/bin/env python3
"""Converts licensed artwork (bitmaps) into DTF-ready vector SVGs.

Each image is upscaled, smoothed, split into a few solid ink layers by colour rules, and traced with
potrace. The background is dropped so the shirt shows through (light backgrounds -> light tees,
dark backgrounds -> black tees). Specks under ~0.6 mm are removed; no semi-transparency anywhere.

Usage: python3 licensed.py <images_dir>   -> designs/licensed/svg/*.svg + mockups
"""
import os
import re
import subprocess
import sys
import tempfile

import numpy as np
from PIL import Image, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))
OUT = os.path.join(ROOT, 'designs', 'licensed')
UPSCALE = 4
MIN_DOT_MM = 0.6
PX = 30  # mockup pixels per inch, same as mockups.py

# Colour rules work on float arrays r, g, b (0-255); helper values: lum, sat, redness.
RED = lambda c: (c['redness'] > 60) & (c['r'] > 110)
DEEP_RED = lambda c: (c['redness'] > 32) & (c['r'] > 70)
DARK = lambda t: (lambda c: (c['lum'] < t) & (c['redness'] < 40))
WHITE = lambda c: (c['lum'] > 200) & (c['sat'] < 45)
GREY = lambda c: (c['lum'] > 105) & (c['lum'] < 195) & (c['sat'] < 45)
TAN = lambda c: (c['lum'] > 70) & (c['lum'] < 200) & (c['redness'] < 40)

# key: (file, title, shirt, placement, layers bottom->top as (name, rule))
ART = {
    'crow-ronin': ('1.jpg', 'Crow Ronin', 'white', 'back-full', [('red', RED), ('ink', DARK(95))]),
    'red-lily-samurai': ('2.jpg', 'Red Lily Samurai', 'white', 'back-center', [('red', RED), ('ink', DARK(70))]),
    'samurai-stance': ('3.jpg', 'Samurai Stance', 'white', 'back-center', [('red', RED), ('ink', DARK(110))]),
    'red-blade': ('4.jpg', 'Red Blade', 'black', 'back-full', [('red', DEEP_RED)]),
    'red-beam': ('5.jpg', 'Red Beam', 'black', 'back-full', [('red', DEEP_RED)]),
    'red-dragon': ('6.jpg', 'Red Dragon', 'white', 'back-full', [('red', RED), ('ink', DARK(62))]),
    'dragon-sun-ronin': ('7.jpg', 'Dragon Sun Ronin', 'beige', 'back-full', [('grey', GREY), ('red', DEEP_RED), ('ink', DARK(95))]),
    'banner-ronin': ('9.jpg', 'Banner Ronin', 'black', 'back-full', [('tan', TAN), ('red', DEEP_RED)]),
    'you-are-enough': ('10.jpg', 'You Are Enough', 'black', 'back-center', [('red', RED), ('white', WHITE)]),
}
BLANKS = {'white': 'white back.PNG', 'black': 'back back.PNG', 'beige': 'beige back.PNG'}
ANCHOR_Y = {'back-full': 300, 'back-center': 318}


def channels(a):
    r, g, b = a[..., 0], a[..., 1], a[..., 2]
    mx, mn = a.max(-1), a.min(-1)
    return {'r': r, 'g': g, 'b': b, 'lum': 0.299 * r + 0.587 * g + 0.114 * b, 'sat': mx - mn, 'redness': r - np.maximum(g, b)}


def dilate(mask, r):
    out = mask.copy()
    for dy in range(-r, r + 1):
        for dx in range(-r, r + 1):
            if dx * dx + dy * dy <= r * r:
                out |= np.roll(np.roll(mask, dy, 0), dx, 1)
    return out


def trace(mask, turd, work):
    Image.fromarray(np.where(mask, 0, 255).astype(np.uint8)).convert('1').save(work + '.pbm')
    subprocess.run(['potrace', work + '.pbm', '-s', '-o', work + '.svg', '-t', str(turd), '-a', '1.1', '-O', '0.5'], check=True)
    src = open(work + '.svg').read()
    g = re.search(r'<g transform="([^"]+)"[^>]*>(.*?)</g>', src, re.S)
    return (g.group(1), re.findall(r'<path d="([^"]+)"', g.group(2))) if g else ('', [])


def convert(key, src_dir):
    file, title, shirt, place, layers = ART[key]
    im = Image.open(os.path.join(src_dir, file)).convert('RGB')
    im = im.resize((im.width * UPSCALE, im.height * UPSCALE), Image.LANCZOS).filter(ImageFilter.MedianFilter(7))
    a = np.asarray(im).astype(float)
    c = channels(a)
    h, w = a.shape[:2]
    inches = (12, round(12 * h / w, 1))
    dpi = w / inches[0]
    turd = int(round(3.1416 * ((MIN_DOT_MM / 25.4 * dpi) / 2) ** 2))
    # top layers win where rules overlap; each lower layer is grown 2 px to tuck under the next
    masks, taken = [], np.zeros((h, w), bool)
    for name, rule in reversed(layers):
        m = rule(c) & ~taken
        taken |= m
        masks.insert(0, (name, m))
    groups = []
    with tempfile.TemporaryDirectory() as t:
        for i, (name, m) in enumerate(masks):
            if not m.any():
                continue
            colour = '#%02X%02X%02X' % tuple(int(v) for v in a[m].mean(0))
            tr, paths = trace(dilate(m, 2) if i < len(masks) - 1 else m, turd, os.path.join(t, name))
            groups.append(f'<g id="{name}" fill="{colour}" transform="{tr}">' + ''.join(f'<path d="{d}"/>' for d in paths) + '</g>')
    svg_dir = os.path.join(OUT, 'svg')
    os.makedirs(svg_dir, exist_ok=True)
    out = os.path.join(svg_dir, f'{key}.svg')
    with open(out, 'w') as f:
        f.write(f'<svg xmlns="http://www.w3.org/2000/svg" width="{inches[0]}in" height="{inches[1]}in" viewBox="0 0 {w} {h}">\n'
                f'<!-- {title}: licensed artwork, DTF print for {shirt} tees, {place}. {len(groups)} solid inks, flat paths -->\n'
                + '\n'.join(groups) + '\n</svg>\n')
    return out, inches, shirt, place


def mockup(key, svg_path, inches, shirt, place):
    """Renders the SVG with Chromium and places it on the matching blank tee."""
    png = svg_path.replace('.svg', '.png').replace(os.sep + 'svg' + os.sep, os.sep + 'preview' + os.sep)
    os.makedirs(os.path.dirname(png), exist_ok=True)
    html = png.replace('.png', '.html')
    with open(html, 'w') as f:
        f.write(f'<body style="margin:0;background:transparent"><img src="file://{svg_path}" style="width:100vw;display:block"></body>')
    js = f"""const {{ chromium }} = require('/opt/node22/lib/node_modules/playwright');
(async () => {{ const b = await chromium.launch(); const p = await b.newPage({{ viewport: {{ width: 1200, height: {round(1200 * inches[1] / inches[0])} }} }});
await p.goto('file://{html}'); await p.waitForTimeout(300);
await p.screenshot({{ path: '{png}', omitBackground: true }}); await b.close(); }})();"""
    subprocess.run(['node', '-e', js], check=True)
    os.remove(html)
    art = Image.open(png).convert('RGBA')
    wpx = round(inches[0] * PX)
    art = art.resize((wpx, round(art.height * wpx / art.width)), Image.LANCZOS)
    blank = Image.open(os.path.join(ROOT, BLANKS[shirt])).convert('RGBA')
    blank.alpha_composite(art, (512 - wpx // 2, ANCHOR_Y[place]))
    mdir = os.path.join(OUT, 'mockups')
    os.makedirs(mdir, exist_ok=True)
    blank.convert('RGB').save(os.path.join(mdir, f'{key}-{shirt}-back.jpg'), quality=86)


def main():
    src_dir = sys.argv[1]
    for key in (sys.argv[2:] or ART):
        svg_path, inches, shirt, place = convert(key, src_dir)
        mockup(key, svg_path, inches, shirt, place)
        print(f'{key}: {inches[0]}x{inches[1]} in, {shirt} tee, {place}, {os.path.getsize(svg_path) // 1024} KB')


if __name__ == '__main__':
    main()
