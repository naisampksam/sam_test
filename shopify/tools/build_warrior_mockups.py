#!/usr/bin/env python3
"""Generate SAMPLE warrior-design mockups on the blank tee photos.

These are placeholders so the store looks complete; replace them with real
product photos once the designs are printed. Fonts (SIL Open Font License,
from Google Fonts) are needed in FONT_DIR:
  YujiSyuku-Regular.ttf, ShipporiMinchoB1-ExtraBold.ttf, Oswald[wght].ttf

  FONT_DIR=/path/to/fonts python3 shopify/tools/build_warrior_mockups.py
"""

import math
import os
import random

from PIL import Image, ImageChops, ImageDraw, ImageFilter, ImageFont

ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
IMG = os.path.join(ROOT, "product-images")
FONT_DIR = os.environ.get("FONT_DIR", os.path.join(ROOT, "fonts"))
BRUSH = os.path.join(FONT_DIR, "YujiSyuku-Regular.ttf")
SERIF = os.path.join(FONT_DIR, "ShipporiMinchoB1-ExtraBold.ttf")
SANS = os.path.join(FONT_DIR, "Oswald[wght].ttf")

RED = (200, 16, 46)
WHITE = (240, 236, 228)
INK = (18, 18, 18)

# key: (kanji, romaji, meaning, haiku, options)
DESIGNS = {
    "ronin": ("浪人", "RŌNIN", "THE MASTERLESS WARRIOR",
              ["No lord, no banner", "only the road and my blade", "I answer to none"], {"enso": True}),
    "shinobi": ("忍", "SHINOBI", "THE HIDDEN ONE",
                ["Unseen in the dark", "silence is my sharpest blade", "the night keeps my name"],
                {"tonal": True, "slash": True}),
    "oni": ("鬼", "ONI", "THE DEMON WITHIN",
            ["Flames behind the mask", "each rep feeds the hungry beast", "I become the storm"],
            {"ink_dark": RED, "enso_color_dark": WHITE, "enso": True}),
    "nana-korobi": ("七転び八起き", "NANA KOROBI YA OKI", "FALL SEVEN TIMES, RISE EIGHT",
                    ["Seven times I fell", "eight times I rose from the dust", "still I stand again"],
                    {"seal": True, "size": 92}),
    "fudoshin": ("不動心", "FUDŌSHIN", "THE IMMOVABLE MIND",
                 ["Storms may shake the pine", "the mountain does not answer", "my mind stands like stone"],
                 {"sun": True}),
    "gi": ("義", "GI", "RECTITUDE", ["Walk the honest path", "though no eye watches the road", "my blade remains straight"], {"virtue": True}),
    "yu": ("勇", "YŪ", "COURAGE", ["Fear stands at the gate", "I step through with open eyes", "courage is a choice"], {"virtue": True}),
    "jin": ("仁", "JIN", "COMPASSION", ["Strong hands lift the weak", "a fighter's heart is not stone", "mercy is power"], {"virtue": True}),
    "rei": ("礼", "REI", "RESPECT", ["Bow before the fight", "respect the one you will face", "honor the dojo"], {"virtue": True}),
    "makoto": ("誠", "MAKOTO", "HONESTY", ["My word is my steel", "spoken once, it does not bend", "truth needs no armor"], {"virtue": True}),
    "meiyo": ("名誉", "MEIYO", "HONOUR", ["Name carved in the wind", "what I do when no one sees", "that is my honor"], {"virtue": True}),
    "chugi": ("忠義", "CHŪGI", "LOYALTY", ["Through winter and war", "I stand beside my brothers", "loyal to the end"], {"virtue": True}),
}

# design key -> list of (tee colour, back photo, front photo or None)
VARIANTS = {
    "ronin": [("black", "oversized-black-back", "oversized-black-front"), ("white", "oversized-white-back", "oversized-white-front")],
    "shinobi": [("black", "oversized-black-back", "oversized-black-front")],
    "oni": [("black", "oversized-black-back", "oversized-black-front")],
    "nana-korobi": [("white", "oversized-white-back", "oversized-white-front"), ("black", "oversized-black-back", "oversized-black-front")],
    "fudoshin": [("black", "acid-wash-black-back", None)],
}
for _v in ("gi", "yu", "jin", "rei", "makoto", "meiyo", "chugi"):
    VARIANTS[_v] = [("black", "oversized-black-back", "oversized-black-front"), ("white", "oversized-white-back", "oversized-white-front")]


def font(path, size, weight=None):
    f = ImageFont.truetype(path, size)
    if weight:
        try:
            f.set_variation_by_axes([weight])
        except (OSError, ValueError):
            pass
    return f


def enso(size, color, seed, width=0.11):
    """Hand-brushed ensō circle on a transparent canvas."""
    rnd = random.Random(seed)
    big = size * 2
    layer = Image.new("L", (big, big), 0)
    d = ImageDraw.Draw(layer)
    cx = cy = big / 2
    r = big * 0.4
    start = rnd.uniform(0, 360)
    sweep = rnd.uniform(315, 335)
    steps = 900
    base_w = big * width
    for i in range(steps):
        t = i / steps
        ang = math.radians(start + sweep * t)
        rr = r * (1 + 0.025 * math.sin(t * 9 + seed))
        w = base_w * (0.35 + 0.75 * math.sin(math.pi * min(1, t * 1.15)) ** 0.6) * (1 - 0.55 * t ** 3)
        x, y = cx + rr * math.cos(ang), cy + rr * math.sin(ang)
        d.ellipse((x - w / 2, y - w / 2, x + w / 2, y + w / 2), fill=255)
    # dry-brush streaks
    for _ in range(26):
        off = rnd.uniform(-base_w * 0.45, base_w * 0.45)
        a0 = start + sweep * rnd.uniform(0.45, 0.8)
        a1 = start + sweep * rnd.uniform(0.85, 1.0)
        rr = r + off
        d.arc((cx - rr, cy - rr, cx + rr, cy + rr), a0, a1, fill=0, width=max(1, int(big * 0.004)))
    layer = layer.filter(ImageFilter.GaussianBlur(1.2)).resize((size, size), Image.LANCZOS)
    out = Image.new("RGBA", (size, size), color + (0,))
    out.putalpha(layer)
    return out


def text_layer(canvas, xy, text, fnt, fill, anchor="mm"):
    ImageDraw.Draw(canvas).text(xy, text, font=fnt, fill=fill + (255,), anchor=anchor)


def vertical(canvas, cx, top, text, fnt, fill, step):
    for i, ch in enumerate(text):
        text_layer(canvas, (cx, top + i * step), ch, fnt, fill)


def seal(size, char, color):
    s = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(s)
    d.rounded_rectangle((2, 2, size - 3, size - 3), radius=size // 10, fill=color + (255,))
    d.text((size / 2, size / 2), char, font=font(SERIF, int(size * 0.62)), fill=(0, 0, 0, 0), anchor="mm")
    # knock the character out of the seal
    mask = Image.new("L", (size, size), 0)
    ImageDraw.Draw(mask).text((size / 2, size / 2), char, font=font(SERIF, int(size * 0.62)), fill=255, anchor="mm")
    s.putalpha(ImageChops.subtract(s.getchannel("A"), mask))
    return s.rotate(-4, resample=Image.BICUBIC)


def onto_fabric(photo, art):
    """Composite artwork so it follows the fabric's folds and shading."""
    base = photo.convert("RGB")
    lum = base.convert("L").filter(ImageFilter.GaussianBlur(2))
    # average brightness of the fabric under the print only (not the white backdrop)
    mask = art.getchannel("A").point(lambda a: 255 if a > 0 else 0)
    hist = lum.histogram(mask=mask)
    mean = sum(i * c for i, c in enumerate(hist)) / max(1, sum(hist))
    shade = lum.point(lambda v: max(0, min(255, int(128 + (v - mean) * 1.6))))
    art_rgb = Image.new("RGB", base.size, (0, 0, 0))
    art_rgb.paste(art.convert("RGB"), (0, 0), art)
    shaded = ImageChops.overlay(art_rgb, Image.merge("RGB", (shade, shade, shade)))
    alpha = art.getchannel("A").point(lambda a: int(a * 0.94))
    out = base.copy()
    out.paste(shaded, (0, 0), alpha)
    return out


def back_art(key, tee):
    kanji, romaji, meaning, haiku, o = DESIGNS[key]
    dark = tee == "black"
    ink = WHITE if dark else INK
    if o.get("tonal"):
        ink = (74, 74, 74)
    if dark and o.get("ink_dark"):
        ink = o["ink_dark"]
    text_ink = WHITE if dark and o.get("ink_dark") else ink
    accent = RED
    art = Image.new("RGBA", (800, 1200), (0, 0, 0, 0))
    cx = 400
    seed = sum(map(ord, key))

    if o.get("virtue"):
        e = enso(400, RED, seed)
        art.alpha_composite(e, (cx - 200, 300))
        size = 230 if len(kanji) == 1 else 150
        if len(kanji) == 1:
            text_layer(art, (cx, 500), kanji, font(BRUSH, size), ink)
        else:
            vertical(art, cx, 425, kanji, font(BRUSH, size), ink, 150)
        text_layer(art, (cx, 745), f"{romaji}  ·  {meaning}", font(SANS, 30, 600), ink)
        haiku_top = 800
    elif o.get("sun"):
        d = ImageDraw.Draw(art)
        d.ellipse((cx - 150, 310, cx + 150, 610), fill=RED + (255,))
        vertical(art, cx, 360, kanji, font(BRUSH, 150), ink, 150)
        text_layer(art, (cx, 790), f"{romaji}  ·  {meaning}", font(SANS, 28, 600), ink)
        haiku_top = 840
    else:
        size = o.get("size", 170)
        step = int(size * 0.98)
        top = 330 if len(kanji) <= 2 else 300
        if o.get("enso"):
            col = o.get("enso_color_dark", RED) if dark else RED
            art.alpha_composite(enso(420, col, seed), (cx - 210, top - 70))
        if len(kanji) == 1:
            text_layer(art, (cx, 480), kanji, font(BRUSH, 300), ink)
            last = 640
        else:
            vertical(art, cx, top + size // 2, kanji, font(BRUSH, size), ink, step)
            last = top + step * len(kanji)
        if o.get("slash"):
            d = ImageDraw.Draw(art)
            d.polygon([(230, 600), (575, 380), (580, 388), (236, 610)], fill=RED + (255,))
        if o.get("seal"):
            art.alpha_composite(seal(70, "起", RED), (cx + 70, last - 60))
        text_layer(art, (cx, last + 30), f"{romaji}  ·  {meaning}", font(SANS, 26, 600), text_ink)
        haiku_top = last + 80
    hf = font(SERIF, 19)
    for i, line in enumerate(haiku):
        text_layer(art, (cx, haiku_top + i * 27), line, hf, text_ink)
    text_layer(art, (cx, haiku_top + 3 * 27 + 18), "HAIKUFIT", font(SANS, 15, 500), accent if not o.get("tonal") else ink)
    return art


def front_art(key, tee):
    kanji, romaji, _, _, o = DESIGNS[key]
    dark = tee == "black"
    ink = WHITE if dark else INK
    if o.get("tonal"):
        ink = (74, 74, 74)
    if dark and o.get("ink_dark"):
        ink = o["ink_dark"]
    art = Image.new("RGBA", (800, 1200), (0, 0, 0, 0))
    x, y = 520, 360  # wearer's left chest
    chars = kanji if len(kanji) <= 3 else kanji[:2]
    if len(chars) == 1:
        art.alpha_composite(enso(120, RED, sum(map(ord, key))), (x - 60, y - 60))
        text_layer(art, (x, y), chars, font(BRUSH, 70), ink)
    else:
        vertical(art, x, y - 25, chars, font(BRUSH, 52), ink, 52)
    text_layer(art, (x, y + 30 + 52 * max(0, len(chars) - 1)), romaji, font(SANS, 14, 600), ink)
    return art


def main():
    for key, variants in VARIANTS.items():
        for tee, back, front in variants:
            photo = Image.open(os.path.join(IMG, back + ".jpg"))
            onto_fabric(photo, back_art(key, tee)).save(
                os.path.join(IMG, f"{key}-{tee}-back.jpg"), "JPEG", quality=84, optimize=True, progressive=True)
            if front:
                photo = Image.open(os.path.join(IMG, front + ".jpg"))
                onto_fabric(photo, front_art(key, tee)).save(
                    os.path.join(IMG, f"{key}-{tee}-front.jpg"), "JPEG", quality=84, optimize=True, progressive=True)
            print("made", key, tee)


if __name__ == "__main__":
    main()
