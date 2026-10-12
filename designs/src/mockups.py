#!/usr/bin/env python3
"""Mockups on the blank tee photos in the repo root, driven by svg/designs.json placements.

Writes
  designs/mockups/<design>-<colour>-back.jpg     every back-view piece (back print, nape)
  designs/mockups/<design>-<colour>-front.jpg    every front-view piece (front print, chest embroidery, sleeve)
  designs/mockups/<design>-<colour>-closeup.jpg  chest embroidery close-up with simulated thread
  designs/gallery/assets/...                     blanks + overlay art for the gallery page
Run after render.mjs (needs print/*.png).
"""
import json
import os

import numpy as np
from PIL import Image, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))
D = os.path.join(ROOT, 'designs')
PRINT, OUT, WEB = os.path.join(D, 'print'), os.path.join(D, 'mockups'), os.path.join(D, 'gallery', 'assets')

# colour: (back blank, front blank, ink mode)
COLOURS = {
    'white': ('white back.PNG', 'whicte front.PNG', 'light'),
    'beige': ('beige back.PNG', 'beige front.PNG', 'light'),
    'lavender': ('lavender back.PNG', 'lavender front.PNG', 'light'),
    'black': ('back back.PNG', 'black front.PNG', 'dark'),
    'bottle-green': ('bottle green back .PNG', 'bottle green front.PNG', 'dark'),
    'chocolate': ('chocolate back.PNG', 'chocolate front.PNG', 'dark'),
    'navy': ('navy back.PNG', 'royal blue front .PNG', 'dark'),
}
CLOSE = 180  # close-up crop size on the 1024x1536 blank
ZOOM = 4


def thread(img):
    """Diagonal satin texture plus a slight raised shadow, to suggest embroidery."""
    w, h = img.size
    yy, xx = np.mgrid[0:h, 0:w]
    tex = np.where(((xx + yy) // 3) % 3 == 0, 0.8, 1.0)
    a = np.asarray(img).astype(float)
    a[..., :3] *= tex[..., None]
    out = Image.fromarray(a.clip(0, 255).astype(np.uint8))
    shadow = Image.new('RGBA', (w + 8, h + 8), (0, 0, 0, 0))
    alpha = img.split()[3].point(lambda v: v * 0.7)
    shadow.paste((0, 0, 0, 255), (3, 4), alpha)
    shadow = shadow.filter(ImageFilter.GaussianBlur(2))
    shadow.alpha_composite(out, (0, 0))
    return shadow


_cache = {}


def art(file, mode, width):
    key = (file, mode, round(width))
    if key not in _cache:
        im = Image.open(os.path.join(PRINT, f'{file}-{mode}.png')).convert('RGBA')
        h = round(im.height * width / im.width)
        im = im.resize((max(1, round(width)), max(1, h)), Image.LANCZOS)
        _cache[key] = thread(im) if 'embroidery' in file else im
    return _cache[key]


def compose(blank, pieces, mode, view):
    img = Image.open(os.path.join(ROOT, blank)).convert('RGBA')
    for p in pieces:
        if p['view'] == view:
            img.alpha_composite(art(p['file'], mode, p['w']), (round(p['x']), round(p['y'])))
    return img


def main():
    os.makedirs(OUT, exist_ok=True)
    os.makedirs(WEB, exist_ok=True)
    manifest = json.load(open(os.path.join(D, 'svg', 'designs.json')))
    for colour, (back, front, mode) in COLOURS.items():
        for side, blank in (('back', back), ('front', front)):
            Image.open(os.path.join(ROOT, blank)).convert('RGB').resize((600, 900), Image.LANCZOS).save(
                os.path.join(WEB, f'blank-{colour}-{side}.jpg'), quality=80)
        for key, d in manifest.items():
            pieces = d['pieces']
            for view, blank in (('back', back), ('front', front)):
                if any(p['view'] == view for p in pieces):
                    compose(blank, pieces, mode, view).convert('RGB').save(os.path.join(OUT, f'{key}-{colour}-{view}.jpg'), quality=86)
            chest = next((p for p in pieces if p['place'] == 'chest'), None)
            if chest:
                cx, cy = chest['x'] + chest['w'] / 2 - CLOSE / 2, chest['y'] + chest['h'] / 2 - CLOSE / 2
                crop = Image.open(os.path.join(ROOT, front)).convert('RGBA').crop((round(cx), round(cy), round(cx) + CLOSE, round(cy) + CLOSE))
                crop = crop.resize((CLOSE * ZOOM, CLOSE * ZOOM), Image.LANCZOS)
                big = art(chest['file'], mode, chest['w'] * ZOOM)
                crop.alpha_composite(big, (round((chest['x'] - round(cx)) * ZOOM), round((chest['y'] - round(cy)) * ZOOM)))
                crop.convert('RGB').save(os.path.join(OUT, f'{key}-{colour}-closeup.jpg'), quality=86)
    # gallery overlays: main print at 600 px wide, small pieces at 4x their mockup size
    for key, d in manifest.items():
        for p in d['pieces']:
            for mode in ('light', 'dark'):
                width = 600 if p['place'] in ('back-center', 'back-full', 'front-full') else p['w'] * 4
                art(p['file'], mode, width).save(os.path.join(WEB, f"{p['file']}-{mode}.png"))
    print('mockups:', len(os.listdir(OUT)), 'files; gallery assets:', len(os.listdir(WEB)), 'files')


if __name__ == '__main__':
    main()
