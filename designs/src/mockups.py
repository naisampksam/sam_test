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


_maps = {}


def fabric(photo, key):
    """Shading and fold maps from a shirt photo, so a print can take on its lighting, folds and knit."""
    if key not in _maps:
        L = photo.convert('L')
        a = np.asarray(L).astype(np.float32)
        broad = np.asarray(L.filter(ImageFilter.GaussianBlur(max(8, L.width / 17)))).astype(np.float32)
        mid = np.asarray(L.filter(ImageFilter.GaussianBlur(max(1.5, L.width / 250)))).astype(np.float32)
        eps = 28.0  # keeps ratios stable on dark shirts
        h, w = a.shape
        ref = np.median(a[int(h * 0.4):int(h * 0.7), int(w * 0.3):int(w * 0.7)])
        light = ((broad + eps) / (ref + eps)) ** 0.9   # overall light falloff across the shirt
        folds = ((mid + eps) / (broad + eps)) ** 2.2   # creases darker, raised fabric lighter (exaggerated: studio blanks are flat)
        knit = ((a + eps) / (mid + eps)) ** 1.6        # weave from the photo showing through the ink
        # fine cotton jersey texture: knit columns plus fibre noise, scaled to the image size
        yy, xx = np.mgrid[0:h, 0:w].astype(np.float32)
        period = max(2.2, w / 420)
        jersey = 1 + 0.035 * np.sin(xx * 2 * np.pi / period) * np.sin(yy * np.pi / period) + np.random.default_rng(7).normal(0, 0.022, (h, w))
        shade = np.clip(light * folds * knit * jersey, 0.45, 1.5)
        fold_src = np.asarray(L.filter(ImageFilter.GaussianBlur(max(4, w / 90)))).astype(np.float32)
        gy, gx = np.gradient(fold_src / (ref + eps))  # warp follows broad folds only, not the knit
        _maps[key] = (shade, gx, gy)
    return _maps[key]


def paste_real(base, art_im, xy, maps, warp=40.0):
    """Composite art onto a shirt photo like a real print: it bends with the folds and is lit by the fabric."""
    shade, gx, gy = maps
    x0, y0 = xy
    H, W = shade.shape
    ax0, ay0 = max(0, -x0), max(0, -y0)
    ax1, ay1 = min(art_im.width, W - x0), min(art_im.height, H - y0)
    if ax1 <= ax0 or ay1 <= ay0:
        return
    a = np.asarray(art_im).astype(np.float32)
    sl = (slice(y0 + ay0, y0 + ay1), slice(x0 + ax0, x0 + ax1))
    yy, xx = np.mgrid[ay0:ay1, ax0:ax1].astype(np.float32)
    # bilinear sample of the art, displaced along the fold gradient
    sx = np.clip(xx - gx[sl] * warp, 0, art_im.width - 1.001)
    sy = np.clip(yy - gy[sl] * warp, 0, art_im.height - 1.001)
    x_0, y_0 = sx.astype(int), sy.astype(int)
    fx, fy = (sx - x_0)[..., None], (sy - y_0)[..., None]
    out = (a[y_0, x_0] * (1 - fx) * (1 - fy) + a[y_0, x_0 + 1] * fx * (1 - fy)
           + a[y_0 + 1, x_0] * (1 - fx) * fy + a[y_0 + 1, x_0 + 1] * fx * fy)
    s = shade[sl][..., None]
    rgb = out[..., :3]
    # ink absorbed into cotton: a touch less saturated and slightly softer than on screen
    grey = rgb.mean(-1, keepdims=True)
    rgb = grey + (rgb - grey) * 0.9
    # darken by the fabric shading; where the fabric is lit, lift the ink slightly (screen)
    out[..., :3] = np.where(s < 1, rgb * s, rgb + (255 - rgb) * (s - 1) * 0.6)
    patch = Image.fromarray(np.clip(out, 0, 255).astype(np.uint8), 'RGBA').filter(ImageFilter.GaussianBlur(0.45))
    base.alpha_composite(patch, (x0 + ax0, y0 + ay0))


def compose(blank, pieces, mode, view):
    photo = Image.open(os.path.join(ROOT, blank)).convert('RGBA')
    maps = fabric(photo, blank)
    for p in pieces:
        if p['view'] == view:
            paste_real(photo, art(p['file'], mode, p['w']), (round(p['x']), round(p['y'])), maps)
    return photo


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
                paste_real(crop, big, (round((chest['x'] - round(cx)) * ZOOM), round((chest['y'] - round(cy)) * ZOOM)),
                           fabric(crop, f'close-{front}-{key}'), warp=60.0)
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
