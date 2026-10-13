#!/usr/bin/env python3
"""Turns the filter-based SVGs in ../svg into flat, DTF-ready vector SVGs in ../dtf.

The brush/rough looks in ../svg come from SVG filters, which CorelDRAW, Illustrator and most RIPs
ignore. Here each design is rendered at high resolution, split into its two inks (red and
black/off-white), and traced with potrace into plain filled paths. Specks smaller than ~0.6 mm are
dropped (they do not transfer on DTF film) and there is no semi-transparency anywhere.

Needs: potrace, node + Playwright (via render.mjs), Pillow, numpy.
Usage: python3 vectorize.py
"""
import glob
import os
import re
import subprocess
import tempfile

import numpy as np
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
SVG = os.path.join(HERE, '..', 'svg')
OUT = os.path.join(HERE, '..', 'dtf')
RED = '#D7261E'
INK = {'light': '#141212', 'dark': '#EFE8DC'}
MIN_DOT_MM = 0.6


def render(tmp, scale, name_filter):
    subprocess.run(['node', os.path.join(HERE, 'render.mjs'), str(scale), tmp, name_filter],
                   check=True, stdout=subprocess.DEVNULL)


def dilate(mask, r):
    out = mask.copy()
    for dy in range(-r, r + 1):
        for dx in range(-r, r + 1):
            if dx * dx + dy * dy <= r * r:
                out |= np.roll(np.roll(mask, dy, 0), dx, 1)
    return out


def trace(mask, turdsize, work):
    Image.fromarray(np.where(mask, 0, 255).astype(np.uint8)).convert('1').save(work + '.pbm')
    subprocess.run(['potrace', work + '.pbm', '-s', '-o', work + '.svg', '-t', str(turdsize),
                    '-a', '0.6', '-O', '0.2'], check=True)
    src = open(work + '.svg').read()
    g = re.search(r'<g transform="([^"]+)"[^>]*>(.*?)</g>', src, re.S)
    paths = re.findall(r'<path d="([^"]+)"', g.group(2)) if g else []
    return (g.group(1) if g else ''), paths


def vectorize(png, svg_src, out):
    a = np.asarray(Image.open(png).convert('RGBA')).astype(int)
    painted = a[..., 3] > 127
    reddish = (a[..., 0] - a[..., 1]) > 70
    red, ink = painted & reddish, painted & ~reddish
    h, w = painted.shape
    src = open(svg_src).read()
    inches = [float(v) for v in re.search(r'data-print-in="([^"]+)"', src).group(1).split('x')]
    dpi = w / inches[0]
    turd = int(round(3.1416 * ((MIN_DOT_MM / 25.4 * dpi) / 2) ** 2))
    mode = 'dark' if svg_src.endswith('-dark.svg') else 'light'
    with tempfile.TemporaryDirectory() as t:
        # red sits underneath, grown by 2 px so it tucks under the ink with no hairline gaps
        rt, rp = trace(dilate(red, 2) if red.any() else red, turd, os.path.join(t, 'r'))
        it, ip = trace(ink, turd, os.path.join(t, 'i'))
    layer = lambda tr, ps, c: f'<g fill="{c}" transform="{tr}">' + ''.join(f'<path d="{d}"/>' for d in ps) + '</g>' if ps else ''
    with open(out, 'w') as f:
        f.write(f'<svg xmlns="http://www.w3.org/2000/svg" width="{inches[0]:g}in" height="{inches[1]:g}in" viewBox="0 0 {w} {h}">\n'
                f'<!-- DTF print: 2 inks ({RED} red, {INK[mode]} {"black" if mode == "light" else "off-white"}), flat paths, no effects -->\n'
                f'{layer(rt, rp, RED)}\n{layer(it, ip, INK[mode])}\n</svg>\n')


SMALL = ('-chest-', '-sleeve-', '-nape-')  # small pieces are traced at 800 dpi for smooth kanji


def main():
    os.makedirs(OUT, exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        big, small = os.path.join(tmp, 'big'), os.path.join(tmp, 'small')
        render(big, 3, '')  # 1200-unit-wide canvases -> 3600 px
        for tag in ('chest', 'sleeve', 'nape'):
            render(small, 8, tag)
        for svg in sorted(glob.glob(os.path.join(SVG, '*.svg'))):
            name = os.path.basename(svg)
            if 'embroidery' in name:
                continue
            png = os.path.join(small if any(t in name for t in SMALL) else big, name.replace('.svg', '.png'))
            vectorize(png, svg, os.path.join(OUT, name))
            print('vectorized', name)


if __name__ == '__main__':
    main()
