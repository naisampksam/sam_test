#!/usr/bin/env python3
"""Realistic mockups for the HaikuFit packaging kit (reads print/*.png from build-pack.mjs).

  mockups/01-unboxing-flatlay.jpg   open mailer box with tissue, sticker, card, hang tag
  mockups/02-thank-you-cards.jpg    card front and back
  mockups/03-mailer-box-3d.jpg      closed box in 3/4 view
  mockups/04-poly-mailer.jpg        mailer bag front and back
  mockups/05-tissue-sticker.jpg     tissue wrap sealed with the round sticker
  mockups/06-hang-tag.jpg           hang tag on a tee
  mockups/07-neck-labels.jpg        printed neck label inside the collar, dark and light tee
"""
import math
import os
import sys

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))
PRINT, OUT = os.path.join(HERE, 'print'), os.path.join(HERE, 'mockups')
sys.path.insert(0, os.path.join(ROOT, 'designs', 'src'))
from mockups import fabric, paste_real  # noqa: E402

BLEED = 38  # 0.125 in at 300 dpi
RNG = np.random.default_rng(3)


def noise_field(w, h, layers, seed=1):
    rng = np.random.default_rng(seed)
    n = np.zeros((h, w), np.float32)
    for scale, amp in layers:
        small = rng.normal(0, 1, (max(2, h // scale), max(2, w // scale))).astype(np.float32)
        img = Image.fromarray(np.clip(small * 40 + 128, 0, 255).astype(np.uint8)).resize((w, h), Image.BICUBIC)
        n += (np.asarray(img).astype(np.float32) - 128) / 40 * amp
    return n


def surface(w, h, base=(38, 35, 33), seed=1):
    """Dark slate tabletop with soft vignette."""
    n = noise_field(w, h, [(600, 4), (160, 2.5), (40, 2), (3, 3.5)], seed)  # fine slate grain, no clouds
    arr = np.array(base, np.float32)[None, None, :] + n[..., None]
    yy, xx = np.mgrid[0:h, 0:w]
    d = np.clip(((xx - w / 2) / (w / 2)) ** 2 + ((yy - h / 2) / (h / 2)) ** 2, 0, 2)
    arr *= (1 - 0.18 * d / 2)[..., None]
    return Image.fromarray(np.clip(arr, 0, 255).astype(np.uint8)).convert('RGBA')


def load(name, width=None, trim=True):
    im = Image.open(os.path.join(PRINT, f'{name}.png')).convert('RGBA')
    if trim:
        im = im.crop((BLEED, BLEED, im.width - BLEED, im.height - BLEED))
    if width:
        im = im.resize((round(width), round(im.height * width / im.width)), Image.LANCZOS)
    return im


def cardstock(im, amp=5, seed=2):
    """Uncoated paper tooth on printed card."""
    a = np.asarray(im).astype(np.float32)
    n = noise_field(im.width, im.height, [(240, amp * 0.15), (2, amp * 0.6)], seed)  # fine tooth, no cloudy patches
    a[..., :3] = np.clip(a[..., :3] * (1 + n[..., None] / 255 * 1.6), 0, 255)
    return Image.fromarray(a.astype(np.uint8), 'RGBA')


def rounded(im, r):
    m = Image.new('L', im.size, 0)
    ImageDraw.Draw(m).rounded_rectangle((0, 0, im.width - 1, im.height - 1), r, fill=255)
    out = im.copy()
    out.putalpha(Image.fromarray(np.minimum(np.asarray(im.split()[3]), np.asarray(m))))
    return out


def safe_composite(canvas, layer, x, y):
    x0, y0 = max(0, x), max(0, y)
    x1, y1 = min(canvas.width, x + layer.width), min(canvas.height, y + layer.height)
    if x1 <= x0 or y1 <= y0:
        return
    canvas.alpha_composite(layer.crop((x0 - x, y0 - y, x1 - x, y1 - y)), (x0, y0))


def place(canvas, im, cx, cy, angle=0.0, blur=16, off=(12, 20), alpha=0.55):
    rot = im.rotate(angle, resample=Image.BICUBIC, expand=True)
    pad = blur * 3
    sh = Image.new('RGBA', (rot.width + pad * 2, rot.height + pad * 2), (0, 0, 0, 0))
    a = rot.split()[3].point(lambda v: int(v * alpha))
    sh.paste((0, 0, 0, 255), (pad, pad), a)
    sh = sh.filter(ImageFilter.GaussianBlur(blur))
    x, y = round(cx - rot.width / 2), round(cy - rot.height / 2)
    safe_composite(canvas, sh, x - pad + off[0], y - pad + off[1])
    safe_composite(canvas, rot, x, y)


def coeffs(dst, src):
    m = []
    for (x, y), (u, v) in zip(dst, src):
        m.append([x, y, 1, 0, 0, 0, -u * x, -u * y])
        m.append([0, 0, 0, x, y, 1, -v * x, -v * y])
    return np.linalg.solve(np.array(m, float), np.array(src, float).reshape(8))


def warp(img, quad, size):
    """Map img onto quad (TL, TR, BR, BL) in a canvas of `size`, supersampled 2x."""
    W, H = size
    q2 = [(x * 2, y * 2) for x, y in quad]
    w, h = img.size
    big = img.transform((W * 2, H * 2), Image.PERSPECTIVE, coeffs(q2, [(0, 0), (w, 0), (w, h), (0, h)]), Image.BICUBIC)
    return big.resize((W, H), Image.LANCZOS)


def shade(im, factor):
    a = np.asarray(im).astype(np.float32)
    a[..., :3] *= factor
    return Image.fromarray(np.clip(a, 0, 255).astype(np.uint8), 'RGBA')


def string_line(canvas, pts, width=5, colour=(214, 205, 192, 255)):
    sh = Image.new('RGBA', canvas.size, (0, 0, 0, 0))
    ImageDraw.Draw(sh).line([(x + 6, y + 9) for x, y in pts], fill=(0, 0, 0, 120), width=width + 2, joint='curve')
    canvas.alpha_composite(sh.filter(ImageFilter.GaussianBlur(5)))
    ImageDraw.Draw(canvas).line(pts, fill=colour, width=width, joint='curve')


def bezier(p0, p1, p2, p3, n=60):
    return [tuple((1 - t) ** 3 * np.array(p0) + 3 * (1 - t) ** 2 * t * np.array(p1) + 3 * (1 - t) * t ** 2 * np.array(p2) + t ** 3 * np.array(p3)) for t in np.linspace(0, 1, n)]


def hang_tag(width):
    tag = cardstock(load('hangtag-front', width))
    m = Image.new('L', tag.size, 255)
    r = width * 0.0625  # 0.25 in hole on a 2 in tag
    hx, hy = width / 2, width * 0.225
    ImageDraw.Draw(m).ellipse((hx - r, hy - r, hx + r, hy + r), fill=0)
    tag.putalpha(Image.fromarray(np.minimum(np.asarray(tag.split()[3]), np.asarray(m))))
    return rounded(tag, int(width * 0.05)), (hx, hy)


def tissue_sheet(w, h, tile_px, seed=5):
    tile = load('tissue-pattern-tile', tile_px, trim=False)
    sheet = Image.new('RGBA', (w, h))
    for y in range(0, h, tile.height):
        for x in range(0, w, tile.width):
            sheet.alpha_composite(tile, (x, y))
    a = np.asarray(sheet).astype(np.float32)
    crinkle = noise_field(w, h, [(260, 3), (70, 2), (12, 1.5)], seed)
    a[..., :3] = np.clip(a[..., :3] * (1 + crinkle[..., None] / 255 * 1.6), 0, 255)
    return Image.fromarray(a.astype(np.uint8), 'RGBA')


def tissue_parcel(w, h, tile_px, seed=5):
    """Two tissue flaps folded over to meet at the centre; returns image and seam x."""
    base = tissue_sheet(w, h, tile_px, seed)
    seam = w // 2
    right = tissue_sheet(w - seam + 40, h, tile_px, seed + 1)
    shadow = Image.new('RGBA', (60, h), (0, 0, 0, 0))
    g = np.zeros((h, 60, 4), np.uint8)
    g[..., 3] = np.linspace(90, 0, 60)[None, :].astype(np.uint8)
    shadow = Image.fromarray(g, 'RGBA')
    base.alpha_composite(shadow, (seam - 40, 0)) if seam - 40 >= 0 else None
    base.alpha_composite(right, (seam - 40, 0))
    return base, seam - 40


# ---------------- scenes ----------------

def thank_you():
    c = surface(2400, 2400, seed=11)
    front = rounded(cardstock(load('thankyou-card-front', 880)), 10)
    back = rounded(cardstock(load('thankyou-card-back', 880), amp=4), 10)
    place(c, back, 1520, 1260, -6)
    place(c, front, 860, 1150, 7)
    place(c, load('sticker-round', 330, trim=False).crop((0, 0, 330, 330)), 1950, 470, 12, blur=10, off=(6, 10))
    return c


def box_3d():
    W, H = 2400, 2000
    c = surface(W, H, seed=12)
    h = 250
    A, B, C, D = (430, 1010), (1470, 1240), (1990, 880), (950, 690)
    Ah, Bh, Ch = (A[0], A[1] + h), (B[0], B[1] + h), (C[0], C[1] + h)
    sh = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    ImageDraw.Draw(sh).polygon([(Ah[0] + 30, Ah[1] - 20), (Bh[0] + 60, Bh[1] + 20), (Ch[0] + 140, Ch[1] + 20), (C[0] + 120, C[1] + 60)], fill=(0, 0, 0, 170))
    c.alpha_composite(sh.filter(ImageFilter.GaussianBlur(45)))
    lid = cardstock(load('mailer-box-lid-outside'), amp=3)
    front = cardstock(load('mailer-box-front'), amp=3)
    side = cardstock(load('mailer-box-side'), amp=3)
    c.alpha_composite(shade(warp(side, [B, C, Ch, Bh], (W, H)), 0.62))
    c.alpha_composite(shade(warp(front, [A, B, Bh, Ah], (W, H)), 0.86))
    c.alpha_composite(shade(warp(lid, [D, C, B, A], (W, H)), 1.04))
    edge = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    ImageDraw.Draw(edge).line([A, B, C], fill=(255, 255, 255, 70), width=3)
    ImageDraw.Draw(edge).line([B, Bh], fill=(255, 255, 255, 40), width=2)
    c.alpha_composite(edge.filter(ImageFilter.GaussianBlur(1)))
    # a strip of branded tape across the front edge
    tape = load('packing-tape-strip', 1040, trim=False).crop((0, 0, 520, 174))
    tape_top = [(A[0] + 400, A[1] + 92 - 160), (A[0] + 520, A[1] + 118 - 160), (A[0] + 520, A[1] + 118), (A[0] + 400, A[1] + 92)]
    return c


def unboxing():
    S = 2400
    c = surface(S, S, seed=13)
    ppi = 80
    bw, bh = 12 * ppi, 9 * ppi
    x0, y0 = (S - bw) // 2, 1080
    # lid opened flat behind the box, inside facing up
    lid = cardstock(load('mailer-box-lid-inside', bw), amp=3)
    g = np.ones((lid.height, 1), np.float32) * np.linspace(0.8, 1.0, 120)[None, :].repeat(1, 0)[:, :1].T[:, :1]
    place(c, lid, x0 + bw / 2, y0 - bh / 2 - 6, 0, blur=12, off=(8, 10), alpha=0.5)
    # box base: outer lip and dark interior with inner shadow
    base = Image.new('RGBA', (bw, bh), (24, 22, 21, 255))
    inner = np.full((bh - 44, bw - 44, 4), (14, 13, 13, 255), np.uint8)
    base.alpha_composite(Image.fromarray(inner, 'RGBA'), (22, 22))
    place(c, base, x0 + bw / 2, y0 + bh / 2, 0, blur=20, off=(10, 22), alpha=0.7)
    parcel, seam = tissue_parcel(bw - 120, bh - 110, 4 * ppi, seed=21)
    place(c, parcel, x0 + bw / 2, y0 + bh / 2 + 6, 0, blur=10, off=(4, 8), alpha=0.6)
    place(c, load('sticker-round', 2 * ppi + 10, trim=False), x0 + 60 + seam + 20, y0 + bh / 2 + 6, -10, blur=6, off=(3, 5))
    card = rounded(cardstock(load('thankyou-card-front', 4 * ppi)), 6)
    place(c, card, x0 + 250, y0 + bh / 2 + 70, -11, blur=10, off=(6, 10))
    # loose items around the box
    tag, (hx, hy) = hang_tag(2 * ppi + 20)
    tag_r = tag.rotate(18, resample=Image.BICUBIC, expand=True)
    place(c, tag, 2010, 820, 18, blur=8, off=(5, 8))
    string_line(c, bezier((2010 - 40, 820 - 120), (2100, 600), (1980, 520), (1840, 470)), width=4)
    for (x, y, a) in ((1960, 1640, 14), (430, 1720, -20), (2080, 1900, -4)):
        place(c, load('sticker-round', 2 * ppi + 10, trim=False), x, y, a, blur=6, off=(3, 6))
    back = rounded(cardstock(load('thankyou-card-back', 4 * ppi), amp=4), 6)
    place(c, back, 420, 1250, 9, blur=10, off=(6, 10))
    tape = load('packing-tape-strip', 8 * ppi, trim=False)
    place(c, tape, 1200, 2130, -3, blur=6, off=(3, 5), alpha=0.45)
    return c


def poly_mailer():
    c = surface(2400, 2400, seed=14)

    def bag(name):
        im = load(name, 1150)
        a = np.asarray(im).astype(np.float32)
        h, w = a.shape[:2]
        yy, xx = np.mgrid[0:h, 0:w].astype(np.float32)
        pillow = 1 + 0.07 * (1 - ((xx - w / 2) / (w / 2)) ** 2) * (1 - ((yy - h / 2) / (h / 2)) ** 2) - 0.03
        wrinkle = 1 + noise_field(w, h, [(200, 3), (50, 1.5)], seed=len(name)) / 255 * 1.4
        sheen = 1 + 0.12 * np.exp(-(((xx - yy * 0.6) - w * 0.25) / (w * 0.12)) ** 2)
        a[..., :3] = np.clip(a[..., :3] * (pillow * wrinkle)[..., None] + (sheen[..., None] - 1) * 120, 0, 255)
        out = Image.fromarray(a.astype(np.uint8), 'RGBA')
        d = ImageDraw.Draw(out)
        fy = int(h * 2 / 15.5)
        d.line([(0, fy), (w, fy)], fill=(0, 0, 0, 160), width=4)
        d.line([(0, fy + 4), (w, fy + 4)], fill=(255, 255, 255, 30), width=2)
        for x in (10, w - 14):
            d.line([(x, 0), (x, h)], fill=(0, 0, 0, 90), width=6)
        return rounded(out, 26)

    place(c, bag('poly-mailer-back'), 860, 1130, -9, blur=22, off=(14, 24))
    place(c, bag('poly-mailer-front'), 1520, 1270, 6, blur=22, off=(14, 24))
    return c


def tissue_close():
    c = surface(2400, 2400, seed=15)
    parcel, seam = tissue_parcel(2000, 1600, 1000, seed=31)
    place(c, parcel, 1200, 1200, 0, blur=24, off=(10, 20), alpha=0.6)
    place(c, load('sticker-round', 420, trim=False), 200 + seam + 20, 1200, -8, blur=8, off=(4, 8))
    return c


def hang_tag_on_tee():
    tee = Image.open(os.path.join(ROOT, 'designs', 'mockups', 'senbontorii-black-front.jpg')).convert('RGBA')
    crop = tee.crop((300, 230, 780, 710)).resize((2400, 2400), Image.LANCZOS)  # 5x close-up of collar and chest
    c = crop
    tag, (hx, hy) = hang_tag(320)
    angle = -7
    cx, cy = 900, 1260  # hang on the empty side of the chest, clear of the embroidery
    place(c, tag, cx, cy, angle, blur=14, off=(10, 16), alpha=0.6)
    # hole position after rotation
    t = math.radians(-angle)
    dx, dy = hx - tag.width / 2, hy - tag.height / 2
    hole = (cx + dx * math.cos(t) - dy * math.sin(t), cy + dx * math.sin(t) + dy * math.cos(t))
    string_line(c, bezier((1080, 430), (1010, 640), (hole[0] + 40, hole[1] - 260), hole), width=5)
    return c


def jersey(w, h, colour, seed=8):
    """Procedural cotton jersey close-up with a collar rib band and stitch line."""
    yy, xx = np.mgrid[0:h, 0:w].astype(np.float32)
    p = 9.0
    col = (xx / p) % 1
    knit = np.cos(2 * np.pi * (yy / p * 1.3 + np.abs(col - 0.5) * 0.9)) * 0.5 + 0.5
    ribs_x = np.cos(2 * np.pi * xx / p) * 0.5 + 0.5
    tex = 0.55 * knit + 0.45 * ribs_x
    n = noise_field(w, h, [(300, 2), (80, 1.5), (2, 5)], seed)
    band_bottom = 300 + 120 * ((xx - w / 2) / (w / 2)) ** 2
    rib = np.cos(2 * np.pi * xx / 16) * 0.5 + 0.5
    in_band = yy < band_bottom
    tex = np.where(in_band, 0.3 + 0.7 * rib, tex)
    base = np.array(colour, np.float32)
    lum = 1 + (tex - 0.5) * (0.16 if sum(colour) > 380 else 0.42) + n / 255 * 1.4
    shadow = 1 - 0.35 * np.exp(-((yy - band_bottom) / 26) ** 2) * (yy > band_bottom)
    arr = base[None, None, :] * (lum * shadow)[..., None]
    img = Image.fromarray(np.clip(arr, 0, 255).astype(np.uint8)).convert('RGBA')
    d = ImageDraw.Draw(img)
    stitch = [(x, 300 + 120 * ((x - w / 2) / (w / 2)) ** 2 + 30) for x in range(0, w, 4)]
    for i in range(0, len(stitch) - 4, 6):
        d.line(stitch[i:i + 4], fill=tuple(int(v * (1.25 if sum(colour) < 380 else 0.82)) for v in colour) + (255,), width=4)
    return img


def neck_labels():
    panels = []
    for colour, label in (((24, 24, 24), 'neck-label-M-dark-tee'), ((238, 238, 240), 'neck-label-M-light-tee')):
        img = jersey(1200, 1300, colour)
        art = load(label, 720, trim=False)
        paste_real(img, art, (240, 560), fabric(img, f'jersey-{label}'), warp=10)
        panels.append(img)
    c = Image.new('RGBA', (2400, 1300))
    c.alpha_composite(panels[0], (0, 0))
    c.alpha_composite(panels[1], (1200, 0))
    return c


def phone_img(screen_name, width):
    scr = Image.open(os.path.join(PRINT, f'{screen_name}.png')).convert('RGBA')
    b = int(width * 0.045)
    sw = width - 2 * b
    shot = scr.resize((sw, round(sw * scr.height / scr.width)), Image.LANCZOS)
    scr = Image.new('RGBA', shot.size, (243, 239, 230, 255))  # status bar above the page, as on a real phone
    scr.alpha_composite(shot.crop((0, 0, sw, shot.height - int(sw * 0.1))), (0, int(sw * 0.1)))
    scr = rounded(scr, int(sw * 0.11))
    h = scr.height + 2 * b
    ph = Image.new('RGBA', (width, h), (0, 0, 0, 0))
    d = ImageDraw.Draw(ph)
    d.rounded_rectangle((0, 0, width - 1, h - 1), int(width * 0.14), fill=(11, 10, 10, 255), outline=(74, 69, 66, 255), width=max(2, width // 90))
    ph.alpha_composite(scr, (b, b))
    d.rounded_rectangle((int(width * 0.39), b + int(width * 0.022), int(width * 0.61), b + int(width * 0.077)), int(width * 0.0275), fill=(11, 10, 10, 255))
    sheen = np.zeros((h, width, 4), np.uint8)
    yy, xx = np.mgrid[0:h, 0:width]
    sheen[..., :3] = 255
    sheen[..., 3] = (18 * np.clip(1 - np.abs((xx - yy * 0.5) - width * 0.2) / (width * 0.35), 0, 1)).astype(np.uint8)
    ph.alpha_composite(Image.fromarray(sheen, 'RGBA'))
    return ph


def game_phones():
    c = surface(2400, 2400, seed=16)
    for i, k in enumerate(('start', 'play', 'reward')):
        place(c, phone_img(f'game-screen-{k}', 640), 480 + i * 720, 1200, 0, blur=26, off=(14, 26), alpha=0.55)
    return c


def main():
    os.makedirs(OUT, exist_ok=True)
    scenes = [('01-unboxing-flatlay', unboxing), ('02-thank-you-cards', thank_you), ('03-mailer-box-3d', box_3d),
              ('04-poly-mailer', poly_mailer), ('05-tissue-sticker', tissue_close), ('06-hang-tag', hang_tag_on_tee),
              ('07-neck-labels', neck_labels), ('08-game-screens-phones', game_phones)]
    only = sys.argv[1:]
    for name, fn in scenes:
        if only and not any(o in name for o in only):
            continue
        fn().convert('RGB').save(os.path.join(OUT, f'{name}.jpg'), quality=90)
        print('mockup', name)


if __name__ == '__main__':
    main()
