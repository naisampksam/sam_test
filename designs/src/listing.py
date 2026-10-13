#!/usr/bin/env python3
"""Etsy listing image set (2000x2000) for every design.

  designs/listing/<design>/01-hero.jpg       shirt in front of a red sun on charcoal
  designs/listing/<design>/02-scroll.jpg     shirt hung like a kakemono scroll between wooden rods
  designs/listing/<design>/03-meaning.jpg    kanji, reading, meaning and story
  designs/listing/<design>/04-placement.jpg  front/back outline with every print position in inches
  designs/listing/<design>/05-detail.jpg     embroidery close-up (or print detail for front/back-only designs)
  designs/listing/<design>/06-colours.jpg    recommended shirt colours

Needs: content JSON exported from content.mjs (path as argv[1]), svg/designs.json, print/*.png, mockups/*.jpg.
"""
import json
import os
import sys
import textwrap

import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))
D = os.path.join(ROOT, 'designs')
OUT = os.path.join(D, 'listing')
S = 2000
CHAR, PAPER, RED, MUTED, LINE = (20, 18, 18), (239, 232, 220), (215, 38, 30), (120, 112, 104), (214, 205, 192)

F = os.path.join(HERE, 'fonts')
INTER = '/usr/share/fonts/opentype/inter/Inter-{}.otf'
font = lambda name, size: ImageFont.truetype(name, size)
BEBAS = lambda s: font(os.path.join(F, 'Bebas_Neue.ttf'), s)
BOKU = lambda s: font(os.path.join(F, 'Yuji_Boku.ttf'), s)
SANS = lambda s, w='Regular': font(INTER.format(w), s)

BLANKS = {
    'white': ('white back.PNG', 'whicte front.PNG'), 'beige': ('beige back.PNG', 'beige front.PNG'),
    'lavender': ('lavender back.PNG', 'lavender front.PNG'), 'black': ('back back.PNG', 'black front.PNG'),
    'bottle-green': ('bottle green back .PNG', 'bottle green front.PNG'), 'chocolate': ('chocolate back.PNG', 'chocolate front.PNG'),
    'navy': ('navy back.PNG', 'royal blue front .PNG'),
}
PLACE_NAME = {'back-center': 'Center back', 'back-full': 'Full back', 'front-full': 'Full front', 'chest': 'Left chest embroidery', 'sleeve': 'Sleeve', 'nape': 'Nape'}

_masks = {}


def shirt_mask(blank):
    """Alpha mask of the tee: flood-fill the near-white studio background from the borders."""
    if blank not in _masks:
        im = Image.open(os.path.join(ROOT, blank)).convert('RGB')
        a = np.asarray(im).astype(int)
        bg = (a.min(-1) >= 252)  # studio white is 253-255; shirt highlights stay below
        # keep only background connected to the border (so white shirts with bright highlights survive)
        seed = np.zeros_like(bg)
        seed[0, :] = seed[-1, :] = seed[:, 0] = seed[:, -1] = True
        region = seed & bg
        for _ in range(400):
            grown = region.copy()
            grown[1:] |= region[:-1]; grown[:-1] |= region[1:]; grown[:, 1:] |= region[:, :-1]; grown[:, :-1] |= region[:, 1:]
            grown &= bg
            if (grown == region).all():
                break
            region = grown
        m = Image.fromarray(np.where(region, 0, 255).astype(np.uint8))
        m = m.filter(ImageFilter.MaxFilter(15)).filter(ImageFilter.MinFilter(15))  # close notches in bright highlights
        m = m.filter(ImageFilter.MinFilter(7)).filter(ImageFilter.GaussianBlur(1.2))  # trim the white studio fringe
        _masks[blank] = m
    return _masks[blank]


def cutout(key, colour, view):
    blank = BLANKS[colour][0 if view == 'back' else 1]
    im = Image.open(os.path.join(D, 'mockups', f'{key}-{colour}-{view}.jpg')).convert('RGBA')
    m = shirt_mask(blank)
    # edge pixels in the photo are blended with the white studio; repaint that band in the shirt's own colour
    inner = np.asarray(m.filter(ImageFilter.MinFilter(13))) > 250
    band = (np.asarray(m) > 0) & ~inner
    a = np.asarray(im).copy()
    a[band, :3] = np.median(np.asarray(Image.open(os.path.join(ROOT, blank)).convert('RGB'))[950:1050, 250:350].reshape(-1, 3), 0)
    im = Image.fromarray(a)
    im.putalpha(m)
    return im.crop(shirt_mask(blank).getbbox())


def paste_with_shadow(canvas, im, xy, blur=18, offset=(0, 18), alpha=0.45):
    sh = Image.new('RGBA', im.size, (0, 0, 0, 0))
    sh.putalpha(im.split()[3].point(lambda v: int(v * alpha)))
    pad = blur * 3
    big = Image.new('RGBA', (im.width + pad * 2, im.height + pad * 2), (0, 0, 0, 0))
    big.alpha_composite(sh, (pad, pad))
    big = big.filter(ImageFilter.GaussianBlur(blur))
    canvas.alpha_composite(big, (xy[0] - pad + offset[0], xy[1] - pad + offset[1]))
    canvas.alpha_composite(im, xy)


def fit(im, w=None, h=None):
    r = min((w or 1e9) / im.width, (h or 1e9) / im.height)
    return im.resize((round(im.width * r), round(im.height * r)), Image.LANCZOS)


def text(draw, xy, s, fnt, fill, anchor='la', spacing=0):
    if spacing:
        x, y = xy
        total = sum(draw.textlength(c, font=fnt) for c in s) + spacing * (len(s) - 1)
        if anchor[0] == 'm':
            x -= total / 2
        elif anchor[0] == 'r':
            x -= total
        for c in s:
            draw.text((x, y), c, font=fnt, fill=fill, anchor='l' + anchor[1])
            x += draw.textlength(c, font=fnt) + spacing
    else:
        draw.text(xy, s, font=fnt, fill=fill, anchor=anchor)


def vkanji(draw, x, y, s, size, fill):
    f = BOKU(size)
    for i, c in enumerate(s):
        draw.text((x, y + i * size * 1.02), c, font=f, fill=fill, anchor='mt')


def main_view(d):
    return 'front' if d['pieces'][0]['place'] == 'front-full' else 'back'


def hero(d):
    c = Image.new('RGBA', (S, S), CHAR + (255,))
    dr = ImageDraw.Draw(c)
    dr.ellipse((1000 - 640, 860 - 640, 1000 + 640, 860 + 640), fill=RED)
    shirt = fit(cutout(d['key'], d['colours'][0], main_view(d)), h=1560)
    paste_with_shadow(c, shirt, (1000 - shirt.width // 2, 150), blur=24, alpha=0.6)
    text(dr, (110, 1830), d['name'].upper(), BEBAS(120), PAPER, 'ls', spacing=10)
    text(dr, (112, 1890), d['reading'], SANS(40, 'Italic'), (200, 190, 178), 'ls')
    vkanji(dr, 1880, 110, d['kanji'], 120 if len(d['kanji']) < 4 else 96, PAPER)
    p = d['pieces'][0]
    text(dr, (1890, 1890), f"{PLACE_NAME[p['place']].upper()} PRINT · {p['inches'][0]:g} × {p['inches'][1]:g} IN", BEBAS(48), (200, 190, 178), 'rs', spacing=4)
    return c


def scroll(d):
    c = Image.new('RGBA', (S, S), (0, 0, 0, 255))
    g = np.linspace(0, 1, S)[:, None]
    wall = (np.array([46, 40, 36]) * (1 - g * 0.35)).astype(np.uint8)
    c = Image.fromarray(np.repeat(wall[:, None, :], S, 1).reshape(S, S, 3)).convert('RGBA')
    dr = ImageDraw.Draw(c)
    shirt = fit(cutout(d['key'], d['colours'][0], main_view(d)), h=1480)
    x0, y0 = 1000 - shirt.width // 2, 300
    # cord and nail
    dr.line((1000, 120, x0 + 60, y0 - 40), fill=(150, 120, 80), width=6)
    dr.line((1000, 120, x0 + shirt.width - 60, y0 - 40), fill=(150, 120, 80), width=6)
    dr.ellipse((986, 106, 1014, 134), fill=(90, 80, 70))
    paste_with_shadow(c, shirt, (x0, y0), blur=26, offset=(14, 26), alpha=0.7)
    for y in (y0 - 52, y0 + shirt.height - 10):
        dr.rounded_rectangle((x0 - 60, y, x0 + shirt.width + 60, y + 44), 22, fill=(96, 62, 36))
        dr.rounded_rectangle((x0 - 60, y + 4, x0 + shirt.width + 60, y + 16), 6, fill=(126, 86, 52))
        for ex in (x0 - 92, x0 + shirt.width + 52):
            dr.rounded_rectangle((ex, y - 6, ex + 40, y + 50), 10, fill=(32, 26, 22))
    text(dr, (990, 1940), d['name'].upper(), BEBAS(56), (190, 180, 168), 'rs', spacing=6)
    dr.text((1020, 1940), d['kanji'], font=BOKU(50), fill=(190, 180, 168), anchor='ls')
    return c


def meaning(d):
    c = Image.new('RGBA', (S, S), PAPER + (255,))
    dr = ImageDraw.Draw(c)
    n = len(d['kanji'])
    size = 400 if n == 1 else 300 if n == 2 else 230 if n == 3 else 180
    vkanji(dr, 330, 170, d['kanji'], size, RED)
    x = 620
    text(dr, (x, 230), 'WHAT IT MEANS', BEBAS(50), MUTED, 'ls', spacing=8)
    text(dr, (x, 400), d['name'].upper(), BEBAS(170), CHAR, 'ls', spacing=6)
    text(dr, (x, 470), d['reading'], SANS(46, 'Italic'), MUTED, 'ls')
    dr.line((x, 530, 1880, 530), fill=LINE, width=3)
    y = 620
    for line in textwrap.wrap(d['meaning'], 42):
        text(dr, (x, y), line, SANS(54, 'Medium'), CHAR, 'ls'); y += 76
    y += 40
    for line in textwrap.wrap(d['story'], 52):
        text(dr, (x, y), line, SANS(42), MUTED, 'ls'); y += 62
    shirt = fit(cutout(d['key'], d['colours'][0], main_view(d)), h=520)
    paste_with_shadow(c, shirt, (1880 - shirt.width, 1880 - shirt.height), blur=14, alpha=0.35)
    dr.rounded_rectangle((x, 1790, x + 560, 1870), 8, outline=RED, width=4)
    text(dr, (x + 280, 1842), 'REAL JAPANESE · MEANING CHECKED', BEBAS(40), RED, 'ms', spacing=3)
    return c


def placement(d):
    c = Image.new('RGBA', (S, S), PAPER + (255,))
    dr = ImageDraw.Draw(c)
    text(dr, (1000, 170), 'PRINT PLACEMENT', BEBAS(96), CHAR, 'ms', spacing=10)
    text(dr, (1000, 236), 'Sizes are the real print dimensions in inches', SANS(38), MUTED, 'ms')
    scale = 1300 / 1536
    for i, view in enumerate(('back', 'front')):
        blank = BLANKS['white'][0 if view == 'back' else 1]
        m = shirt_mask(blank).resize((round(1024 * scale), 1300), Image.LANCZOS)
        ox, oy = (40 if i == 0 else 1000 - 40) + 0, 360
        sil = Image.new('RGBA', m.size, (222, 213, 200, 255)); sil.putalpha(m)
        c.alpha_composite(sil, (ox, oy))
        text(dr, (ox + m.width // 2, oy + 1330), view.upper(), BEBAS(64), MUTED, 'ms', spacing=8)
        for p in d['pieces']:
            if p['view'] != view:
                continue
            x0, y0 = ox + p['x'] * scale, oy + p['y'] * scale
            x1, y1 = x0 + p['w'] * scale, y0 + p['h'] * scale
            dr.rectangle((x0, y0, x1, y1), fill=(215, 38, 30, 60), outline=RED, width=5)
            label = f"{PLACE_NAME[p['place']]}  {p['inches'][0]:g} × {p['inches'][1]:g} in"
            ly = y1 + 44 if p['place'] not in ('nape',) else y0 - 20
            lx = min(max((x0 + x1) / 2, ox + 200), ox + m.width - 200)
            text(dr, (lx, ly), label, SANS(36, 'SemiBold'), CHAR, 'ms')
    return Image.alpha_composite(Image.new('RGBA', (S, S), PAPER + (255,)), c)


def detail(d, thread):
    colour = d['colours'][0]
    chest = next((p for p in d['pieces'] if p['place'] == 'chest'), None)
    c = Image.new('RGBA', (S, S), CHAR + (255,))
    dr = ImageDraw.Draw(c)
    mode = 'dark' if colour in ('black', 'bottle-green', 'chocolate', 'navy') else 'light'
    if chest:
        Z = 1700 / 260
        cx, cy = chest['x'] + chest['w'] / 2 - 130, chest['y'] + chest['h'] / 2 - 130
        fabric = Image.open(os.path.join(ROOT, BLANKS[colour][1])).convert('RGBA').crop((round(cx), round(cy), round(cx) + 260, round(cy) + 260))
        fabric = fabric.resize((1700, 1700), Image.LANCZOS)
        art = Image.open(os.path.join(D, 'print', f"{chest['file']}-{mode}.png")).convert('RGBA')
        art = fit(art, w=round(chest['w'] * Z))
        fabric.alpha_composite(thread(art), (round((chest['x'] - round(cx)) * Z), round((chest['y'] - round(cy)) * Z)))
        label = 'EMBROIDERED LEFT CHEST'
    else:
        p = d['pieces'][0]
        art = Image.open(os.path.join(D, 'print', f"{p['file']}-{mode}.png")).convert('RGBA')
        bb = art.getbbox(); art = art.crop(bb)
        hexc = {'white': (237, 237, 240), 'beige': (224, 212, 195), 'black': (22, 22, 22), 'navy': (22, 29, 50), 'chocolate': (73, 44, 31), 'bottle-green': (14, 60, 39), 'lavender': (183, 159, 220)}[colour]
        noise = (np.random.default_rng(1).normal(0, 4, (1700, 1700, 1)) + np.array(hexc)).clip(0, 255).astype(np.uint8)
        fabric = Image.fromarray(noise).convert('RGBA')
        crop = art.crop((art.width // 6, art.height // 8, art.width * 5 // 6, art.height // 8 + art.width * 2 // 3))
        fabric.alpha_composite(fit(crop, w=1700), (0, 0))
        label = 'DTF PRINT DETAIL'
    c.alpha_composite(fabric, (150, 120))
    dr.rectangle((150, 1820, 1850, 1940), fill=CHAR)
    text(dr, (1000, 1902), f"{label} · {d['name'].upper()}", BEBAS(64), PAPER, 'ms', spacing=6)
    return c


def colours(d, all_colours):
    cs = list(dict.fromkeys(d['colours'] + [k for k in ('white', 'black', 'beige', 'navy') if k not in d['colours']]))[:4]
    c = Image.new('RGBA', (S, S), PAPER + (255,))
    dr = ImageDraw.Draw(c)
    text(dr, (1000, 170), 'CHOOSE YOUR COLOUR', BEBAS(96), CHAR, 'ms', spacing=10)
    for i, k in enumerate(cs):
        sh = fit(cutout(d['key'], k, main_view(d)), w=440, h=700)
        cx, cy = 260 + (i % 2) * 0 + (i % 4) * 0, 0
        col, row = i % 2, i // 2
        x, y = 280 + col * 960, 300 + row * 830
        x = 500 + col * 1000 - sh.width // 2
        paste_with_shadow(c, sh, (x, y), blur=14, alpha=0.35)
        rec = ' · RECOMMENDED' if k in d['colours'] else ''
        text(dr, (500 + col * 1000, y + 760), all_colours[k]['name'].upper() + rec, BEBAS(50), CHAR if rec else MUTED, 'ms', spacing=4)
    return c


def main():
    content = json.load(open(sys.argv[1]))
    manifest = json.load(open(os.path.join(D, 'svg', 'designs.json')))
    sys.path.insert(0, HERE)
    from mockups import thread
    only = sys.argv[2:]
    for d in content['DESIGNS']:
        if only and d['key'] not in only:
            continue
        d['pieces'] = manifest[d['key']]['pieces']
        out = os.path.join(OUT, d['key'])
        os.makedirs(out, exist_ok=True)
        for name, im in (('01-hero', hero(d)), ('02-scroll', scroll(d)), ('03-meaning', meaning(d)),
                         ('04-placement', placement(d)), ('05-detail', detail(d, thread)), ('06-colours', colours(d, content['COLOURS']))):
            im.convert('RGB').save(os.path.join(out, f'{name}.jpg'), quality=88)
        print('listing set:', d['key'])


if __name__ == '__main__':
    main()
