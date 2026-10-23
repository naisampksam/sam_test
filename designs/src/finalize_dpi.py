#!/usr/bin/env python3
"""Tags every print/*.png as 300 dpi and checks its pixel size against the real print size in its SVG.

Chromium screenshots carry no DPI tag, so RIP/printer software would assume 72 dpi and size them wrong.
Usage: python3 finalize_dpi.py
"""
import glob
import os
import re

from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
PRINT, SVG = os.path.join(HERE, '..', 'print'), os.path.join(HERE, '..', 'svg')

bad = 0
files = sorted(glob.glob(os.path.join(PRINT, '*.png')))
for png in files:
    src = os.path.join(SVG, os.path.basename(png).replace('.png', '.svg'))
    if not os.path.exists(src):
        continue
    inches = [float(v) for v in re.search(r'data-print-in="([^"]+)"', open(src).read()).group(1).split('x')]
    im = Image.open(png)
    im.load()
    dpi = im.width / inches[0]
    if abs(dpi - 300) > 1.5:
        bad += 1
        print(f'WARNING {os.path.basename(png)}: {dpi:.0f} dpi')
    im.save(png, dpi=(300, 300))
print(f'tagged {len(files)} files at 300 dpi, {bad} size warnings')
