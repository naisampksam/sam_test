#!/usr/bin/env python3
"""Build the Shopify import CSV for the 20-design "Etsy listing images" collection.

    python3 shopify/tools/build_etsy_collection_csv.py --src <unzipped Etsy-listing-images> --branch <branch>

1. Optimises each design's listing images into shopify/product-images/etsy/<design>/
   and cuts one photo per colour out of the "choose your colour" image, so picking a
   colour on the product page shows that colour.
2. Writes shopify/products-etsy-collection.csv (Products > Import in Shopify admin).
   Image URLs point at this public GitHub repo; Shopify copies them on import.

Meaning images whose Japanese text rendered as empty boxes are left out (the meaning
is in the description instead); see BROKEN_MEANING.
"""

import argparse
import csv
import html
import os

REPO_RAW = "https://raw.githubusercontent.com/naisampksam/sam_test/{branch}/shopify/product-images/etsy/"
SIZES = ["XS", "S", "M", "L", "XL", "XXL"]
OUT_DIR = os.path.join(os.path.dirname(__file__), "..", "product-images", "etsy")

# meaning cards with missing-glyph boxes (and, for samurai, an internal note) in the source image
BROKEN_MEANING = {"bushido", "ichigoichie", "ken", "ronin", "sakura", "samurai", "torii"}

# quadrants of the 2000x2000 "06-colours" image, in reading order
COLOUR_BOXES = [(220, 250, 800, 830), (1220, 250, 1800, 830), (220, 1075, 800, 1655), (1220, 1075, 1800, 1655)]

BACK = "back"    # large DTF back print + embroidered left-chest kanji
FRONT = "front"  # large DTF front print + sleeve kanji

# key, title name, kanji, romaji, meaning, story, haiku, colours (06 image order), print, back size, chest
DESIGNS = [
    ("ame", "Ame", "雨", "ame", "Rain.",
     "Rain falls the full length of the back, from collar to hem. A lone samurai walks away beneath a red paper umbrella, its ribs cut out so the shirt shows through.",
     ["Rain on the long road", "a red umbrella, one man", "he does not look back"],
     ["White", "Black", "Beige", "Navy"], BACK, "Full back 12 × 24 in", "Sleeve kanji 雨"),
    ("bushido", "Bushidō", "武士道", "bushidō", "The way of the warrior: the samurai code of conduct.",
     "A katana runs down a vertical red brush stripe, with 武士道 on one side and the seven virtues of bushidō on the other: 義 勇 仁 礼 誠 名誉 忠義 (rectitude, courage, benevolence, respect, honesty, honour, loyalty).",
     ["Seven virtues kept", "one blade drawn down the spine", "the road has a code"],
     ["White", "Black", "Navy", "Beige"], BACK, "Center back 14 × 21 in", "Embroidered left chest 武士道"),
    ("duel", "The Duel", "決闘", "kettō", "A duel: a fight decided between two people.",
     "Two samurai face off across a slash of red ink: one in chūdan stance with the blade pointed forward, one with the sword raised overhead. Cherry petals blow between them.",
     ["Two blades, one breath held", "cherry petals cross the gap", "only one walks home"],
     ["Black", "White", "Bottle Green", "Beige"], BACK, "Center back 14 × 21 in", "Embroidered left chest 決闘"),
    ("fudoshin", "Fudōshin", "不動心", "fudōshin", "The unmoving mind: calm that does not shake under pressure. A core idea in Japanese martial arts.",
     "A single red beam drops from the collar onto a samurai seen from behind. On black tees the figure is the shirt itself, outlined only by a red rim of light.",
     ["The storm hits the stone", "the stone does not hear the storm", "my mind will not move"],
     ["Black", "Navy", "White", "Beige"], BACK, "Full back 12 × 24 in", "Embroidered left chest 不動心 + sleeve"),
    ("gekka", "Gekka", "月下", "gekka", "Beneath the moon.",
     "A front-print design. A samurai raises his sword on a grassy hill against a huge moon, which is red on light tees and pale with a red brush rim on dark ones. Small kanji on the sleeve and nape complete it.",
     ["Under the full moon", "a sword lifts against the sky", "the grass holds its breath"],
     ["Black", "White", "Bottle Green", "Beige"], FRONT, "Full front 12 × 12 in", "Sleeve + nape kanji 月下"),
    ("higanbana", "Higanbana", "彼岸花", "higanbana", "Red spider lily, \"the flower of the other shore\". In Japan it blooms at the autumn equinox and is linked with farewells and the afterlife.",
     "A samurai stands in a field of red spider lilies as petals drift on the wind.",
     ["Red lilies at dusk", "the far shore calls the warrior", "he stands a while more"],
     ["White", "Black", "Beige", "Navy"], BACK, "Center back 12 × 18 in", "Embroidered left chest 彼岸花"),
    ("hitoritabi", "Hitori Tabi", "一人旅", "hitori tabi", "Travelling alone: a journey taken by yourself.",
     "Ink mountains rise under a red sun at the top of the back, and the print runs all the way down the spine. Far below, a small red-robed swordsman walks toward the peaks, leaving footprints in the snow.",
     ["Snow on the far peaks", "footprints only of my own", "the sun leads the way"],
     ["White", "Beige", "Black", "Navy"], BACK, "Full back 12 × 24 in", "Embroidered left chest 一人旅 + sleeve"),
    ("ichigoichie", "Ichigo Ichie", "一期一会", "ichigo ichie", "One time, one meeting: treasure every encounter, because it will never happen the same way again.",
     "A red brush banner hangs from the collar with 一期一会 cut out of it, plus 一生に一度 (\"once in a lifetime\") in small type. Below, a ronin in a kasa hat rests his hands on a red katana.",
     ["This cup, this moment", "it will not come round again", "so I bow fully"],
     ["Black", "White", "Navy", "Beige"], BACK, "Full back 12 × 24 in", "Embroidered left chest 一期一会 + sleeve"),
    ("ittoryodan", "Ittō Ryōdan", "一刀両断", "ittō ryōdan", "To cut something in two with a single stroke. Figuratively: decisive action, no hesitation.",
     "A red sword slash cuts a Zen ensō circle in half, and the two halves drift apart. The four-character idiom runs down the right.",
     ["One stroke, no second", "the circle falls into two", "doubt left on the ground"],
     ["White", "Black", "Chocolate", "Beige"], BACK, "Center back 14 × 21 in", "Embroidered left chest 一刀両断"),
    ("karasu", "Karasu", "鴉", "karasu", "Crow. In Japan the crow is a messenger and a watcher, and the three-legged Yatagarasu guided the first emperor.",
     "A crow stands guard on the hilt of a katana planted in the earth, framed by a huge red moon while more crows circle overhead.",
     ["A crow on the hilt", "the red moon keeps the long watch", "the blade stays in earth"],
     ["White", "Black", "Beige", "Navy"], BACK, "Center back 12 × 18 in", "Embroidered left chest 鴉 + nape"),
    ("ken", "Ken", "剣", "ken", "Sword. The same character as in kendō (剣道), \"the way of the sword\".",
     "A katana runs from the collar almost to the hem, cutting through a giant red brush-written 剣.",
     ["Steel from neck to hem", "the red brush stroke split in two", "the way of the sword"],
     ["White", "Black", "Beige", "Navy"], BACK, "Full back 12 × 24 in", "Sleeve kanji 剣"),
    ("koi", "Koi", "鯉", "koi", "Carp. In legend, a koi that swims up the waterfall becomes a dragon, so it stands for perseverance.",
     "A red koi and a black koi circle a vertical katana like yin and yang, inside rings of rippling water.",
     ["Against the waterfall", "the koi does not ask the way", "it climbs, and becomes"],
     ["White", "Black", "Chocolate", "Beige"], BACK, "Center back 12 × 16 in", "Embroidered left chest 鯉"),
    ("nanakorobi", "Nana Korobi Ya Oki", "七転八起", "nana korobi ya oki", "Fall seven times, rise eight: a Japanese proverb about resilience.",
     "A front print. A katana broken in two lies across an ink ensō circle, with the proverb brushed down the side and repeated on the sleeve.",
     ["Seven times I fell", "the broken blade still has an edge", "the eighth time I rise"],
     ["White", "Black", "Beige", "Navy"], FRONT, "Full front 12 × 12 in", "Sleeve kanji 七転八起"),
    ("ronin", "Rōnin", "浪人", "rōnin", "A masterless samurai: a warrior who wanders alone after losing his lord.",
     "A lone ronin in a straw kasa hat stands against a huge brush-painted red sun. Wind catches the red scarf; 浪人 runs down the side with a 侍 seal.",
     ["No lord, no banner", "only the road and my blade", "I answer to none"],
     ["White", "Black", "Beige", "Navy"], BACK, "Center back 14 × 21 in", "Embroidered left chest 浪人"),
    ("ryu", "Ryū", "龍", "ryū", "Dragon. In Japan, a symbol of power, wisdom and water.",
     "A ronin stands on still water. In the red sun behind him, a dragon is cut out in negative space, and his reflection breaks into horizontal ripples below.",
     ["Still water, red sun", "the dragon hides in the light", "ripples tell the truth"],
     ["Black", "White", "Chocolate", "Beige"], BACK, "Center back 12 × 18 in", "Embroidered left chest 龍 + nape"),
    ("sakura", "Chiru Sakura", "散る桜", "chiru sakura", "\"The cherry blossoms that fall, and those that remain, are falling blossoms too.\" (散る桜 残る桜も 散る桜) A verse attributed to the monk Ryōkan.",
     "A bare katana lies beneath a sakura branch. Petals drift down and are sliced as they pass the edge. Ryōkan's verse runs in three columns, read right to left.",
     ["Petals cross the edge", "those that fall and those that stay", "all are falling still"],
     ["Black", "Chocolate", "White", "Beige"], BACK, "Center back 14 × 21 in", "Embroidered left chest 散る桜"),
    ("samurai", "Samurai", "侍", "samurai", "Samurai. From the old verb saburau (侍う): \"to serve\".",
     "A samurai silhouette stands in front of a giant brush-written red 侍: black figure, red brush, real kanji, bold and simple.",
     ["To serve is the root", "the red brush writes what I am", "I stand in its stroke"],
     ["White", "Black", "Beige", "Navy"], BACK, "Center back 14 × 21 in", "Embroidered left chest 侍"),
    ("senbontorii", "Senbon Torii", "千本鳥居", "senbon torii", "A thousand torii: the famous tunnels of vermilion gates at Fushimi Inari shrine in Kyoto.",
     "A tunnel of vermilion torii runs from collar to hem, each gate with the black cap and black feet of the real Fushimi Inari gates. A lone samurai walks toward the vanishing point.",
     ["A thousand red gates", "each one a step further in", "the path keeps its word"],
     ["White", "Black", "Beige", "Navy"], BACK, "Full back 12 × 24 in", "Embroidered left chest 千本鳥居"),
    ("torii", "Torii", "鳥居", "torii", "The gate of a Shinto shrine: the line between the everyday world and the sacred one.",
     "A torii stands on a hill against a huge red sun, and a lone ronin waits beneath it. 鳥居 runs down the side, and the same word sits small under the back collar.",
     ["Beneath the red gate", "the ronin waits for the sun", "one step to the sacred"],
     ["White", "Black", "Beige", "Navy"], BACK, "Center back 12 × 16 in", "Embroidered left chest 鳥居 + nape"),
    ("tsukikage", "Tsukikage", "月影", "tsukikage", "Moonlight; the shape of the moon reflected on water.",
     "A ronin poles a small boat across still water under a red moon. Below, the moon and the boat break into horizontal ripples.",
     ["A boat on dark glass", "the red moon follows below", "ripples carry both"],
     ["White", "Black", "Navy", "Beige"], BACK, "Center back 12 × 18 in", "Embroidered left chest 月影"),
]

PRICE = {BACK: ("999.00", "1699.00"), FRONT: ("899.00", "1499.00")}
COLOUR_TAGS = {"Bottle Green": "green"}


def slug(text):
    return "".join(w[0] for w in text.upper().split()) if " " in text else text.upper()[:3]


def body(d):
    key, name, kanji, romaji, meaning, story, haiku, colours, kind, back, chest = d
    lines = "<br>".join(html.escape(x) for x in haiku)
    where = "Front print" if kind == FRONT else "Back print"
    return (
        f'<div class="design-story"><p class="design-story__kanji">{kanji}</p>'
        f'<p class="design-story__name">{html.escape(name)} · {html.escape(romaji)}</p>'
        f'<p class="design-story__haiku">{lines}</p></div>'
        f'<p><strong>What it means:</strong> {html.escape(meaning)}</p>'
        f'<p>{html.escape(story)}</p>'
        "<ul>"
        "<li>Oversized drop-shoulder fit, 100% heavyweight cotton</li>"
        f"<li>{where}: {html.escape(back)} (DTF print)</li>"
        f"<li>{html.escape(chest)}</li>"
        "<li>Real Japanese, meaning checked</li>"
        "<li>Bio-washed &amp; pre-shrunk · Haiku card in every box</li>"
        "</ul>"
    )


def prepare_images(src):
    from PIL import Image  # only needed when (re)building images
    for d in DESIGNS:
        key, colours = d[0], d[7]
        os.makedirs(os.path.join(OUT_DIR, key), exist_ok=True)
        for name in ["01-hero", "02-scroll", "03-meaning", "04-placement", "05-detail"]:
            if name == "03-meaning" and key in BROKEN_MEANING:
                continue
            im = Image.open(os.path.join(src, key, f"{name}.jpg")).convert("RGB")
            im.thumbnail((1600, 1600))
            im.save(os.path.join(OUT_DIR, key, f"{name}.jpg"), quality=84, optimize=True, progressive=True)
        sheet = Image.open(os.path.join(src, key, "06-colours.jpg")).convert("RGB")
        for colour, box in zip(colours, COLOUR_BOXES):
            sheet.crop(box).resize((1200, 1200), Image.LANCZOS).save(
                os.path.join(OUT_DIR, key, f"colour-{colour.lower().replace(' ', '-')}.jpg"), quality=86, optimize=True, progressive=True)


def rows(base_url):
    out = []
    for d in DESIGNS:
        key, name, kanji, romaji, meaning, story, haiku, colours, kind, back, chest = d
        handle = f"{key}-oversized-tee"
        price, compare = PRICE[kind]
        url = lambda f: f"{base_url}{key}/{f}.jpg"  # noqa: E731
        colour_img = {c: url(f"colour-{c.lower().replace(' ', '-')}") for c in colours}
        images = [(url("01-hero"), f"{name} {kanji} oversized tee, {kind} print")]
        images += [(colour_img[c], f"{name} oversized tee in {c}") for c in colours]
        images += [(url("02-scroll"), f"{name} {kanji} design")]
        if key not in BROKEN_MEANING:
            images.append((url("03-meaning"), f"What {kanji} ({romaji}) means"))
        images += [(url("04-placement"), f"{name} print placement and sizes"), (url("05-detail"), f"{name} print / embroidery detail")]
        variants = []
        for c in colours:
            for s in SIZES:
                variants.append({
                    "Option1 Name": "Color", "Option1 Value": c, "Option2 Name": "Size", "Option2 Value": s,
                    "Variant SKU": f"HF-{key.upper()[:6]}-{slug(c)}-{s}", "Variant Grams": 280,
                    "Variant Inventory Policy": "deny", "Variant Fulfillment Service": "manual",
                    "Variant Price": price, "Variant Compare At Price": compare,
                    "Variant Requires Shipping": "TRUE", "Variant Taxable": "TRUE",
                    "Variant Image": colour_img[c], "Variant Weight Unit": "g",
                })
        tags = ", ".join(["warrior", "japanese", "kanji", "oversized", "new", "unisex", f"{kind}-print", key]
                         + [COLOUR_TAGS.get(c, c.lower()) for c in colours])
        for i in range(max(len(variants), len(images))):
            row = {"Handle": handle}
            if i == 0:
                title = f"{name} {kanji} Oversized T-Shirt"
                row.update({
                    "Title": title, "Body (HTML)": body(d), "Vendor": "HAIKUFIT", "Type": "Oversized T-Shirt",
                    "Tags": tags, "Published": "TRUE", "SEO Title": f"{name} {kanji} Oversized T-Shirt | HAIKUFIT",
                    "SEO Description": f"{name} ({kanji}, {romaji}): {meaning} Japanese warrior oversized tee by HAIKUFIT. Real kanji, heavyweight cotton, COD available."[:320],
                    "Status": "active",
                })
            if i < len(variants):
                row.update(variants[i])
            if i < len(images):
                row.update({"Image Src": images[i][0], "Image Position": i + 1, "Image Alt Text": images[i][1]})
            out.append(row)
    return out


FIELDS = ["Handle", "Title", "Body (HTML)", "Vendor", "Type", "Tags", "Published", "Option1 Name", "Option1 Value",
          "Option2 Name", "Option2 Value", "Variant SKU", "Variant Grams", "Variant Inventory Tracker",
          "Variant Inventory Policy", "Variant Fulfillment Service", "Variant Price", "Variant Compare At Price",
          "Variant Requires Shipping", "Variant Taxable", "Image Src", "Image Position", "Image Alt Text",
          "Variant Image", "Variant Weight Unit", "SEO Title", "SEO Description", "Status"]


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--src", help="Unzipped Etsy-listing-images folder (rebuilds the images)")
    ap.add_argument("--branch", default="master")
    ap.add_argument("--out", default=os.path.join(os.path.dirname(__file__), "..", "products-etsy-collection.csv"))
    a = ap.parse_args()
    if a.src:
        prepare_images(a.src)
    data = rows(REPO_RAW.format(branch=a.branch))
    with open(a.out, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=FIELDS)
        w.writeheader()
        w.writerows(data)
    print(f"{len(DESIGNS)} products, {sum(1 for r in data if r.get('Option1 Value'))} variants -> {os.path.normpath(a.out)}")


if __name__ == "__main__":
    main()
