#!/usr/bin/env python3
"""Generate the HAIKUFIT Shopify product-import CSV.

Edit the catalogue below (prices, colours, haiku, descriptions), then run:

    python3 shopify/tools/build_products_csv.py --branch <branch-with-images>

Import the CSV in Shopify admin under Products > Import. Image URLs point at
shopify/product-images in this public GitHub repo; Shopify copies them on import.

Every product starts its description with a "design story" block (kanji,
meaning, haiku). The theme shows it as a styled card on the product page and
as a red seal on product cards.
"""

import argparse
import csv
import html
import os

REPO_RAW = "https://raw.githubusercontent.com/naisampksam/sam_test/{branch}/shopify/product-images/"
SIZES = ["S", "M", "L", "XL", "XXL"]

OVERSIZED_SPEC = "<ul><li>240 GSM, 100% combed cotton</li><li>Bio-washed &amp; pre-shrunk</li><li>Drop-shoulder oversized fit</li><li>Haiku card in every box</li></ul>"
GRAPHIC_SPEC = "<ul><li>240 GSM, 100% combed cotton oversized tee</li><li>Back print with haiku, small chest print</li><li>Bio-washed &amp; pre-shrunk</li><li>Haiku card in every box</li></ul>"


def story(kanji, name, haiku):
    lines = "<br>".join(html.escape(line) for line in haiku)
    return (f'<div class="design-story"><p class="design-story__kanji">{kanji}</p>'
            f'<p class="design-story__name">{html.escape(name)}</p>'
            f'<p class="design-story__haiku">{lines}</p></div>')


def warrior(handle, title, kanji, name, haiku, intro, colors, tags, sku, price="899.00", compare="1499.00",
            ptype="Graphic T-Shirt"):
    return {
        "handle": handle, "title": title, "type": ptype, "tags": tags, "price": price, "compare_at": compare,
        "grams": 260, "sku": sku,
        "body": story(kanji, name, haiku) + f"<p>{intro}</p>" + GRAPHIC_SPEC,
        "colors": colors,
    }


BUSHIDO = [
    ("gi", "義", "Gi", "Rectitude", ["Walk the honest path", "though no eye watches the road", "my blade remains straight"],
     "Gi is the first virtue of Bushidō: doing what is right, even when no one is watching."),
    ("yu", "勇", "Yū", "Courage", ["Fear stands at the gate", "I step through with open eyes", "courage is a choice"],
     "Yū is courage. Not the absence of fear, but the decision to move forward anyway."),
    ("jin", "仁", "Jin", "Compassion", ["Strong hands lift the weak", "a fighter's heart is not stone", "mercy is power"],
     "Jin is compassion: real strength is used to protect, not to dominate."),
    ("rei", "礼", "Rei", "Respect", ["Bow before the fight", "respect the one you will face", "honor the dojo"],
     "Rei is respect, the bow before and after every fight."),
    ("makoto", "誠", "Makoto", "Honesty", ["My word is my steel", "spoken once, it does not bend", "truth needs no armor"],
     "Makoto is honesty and sincerity. A warrior's word needs no contract."),
    ("meiyo", "名誉", "Meiyo", "Honour", ["Name carved in the wind", "what I do when no one sees", "that is my honor"],
     "Meiyo is honour: your name is built by your actions, every single day."),
    ("chugi", "忠義", "Chūgi", "Loyalty", ["Through winter and war", "I stand beside my brothers", "loyal to the end"],
     "Chūgi is loyalty to your people, your crew, your dojo."),
]

PRODUCTS = [
    # ---- Warrior designs (sample artwork; replace photos when printed) ----
    warrior("ronin-oversized-t-shirt", "Rōnin 浪人 Oversized T-Shirt", "浪人", "Rōnin · The masterless warrior",
            ["No lord, no banner", "only the road and my blade", "I answer to none"],
            "The rōnin had no master and no banner, only his own code. For those who walk their own road.",
            {"Black": ["ronin-black-back", "ronin-black-front"], "White": ["ronin-white-back", "ronin-white-front"]},
            "warrior, graphic, oversized, bestseller, new, unisex", "RON"),
    warrior("shinobi-stealth-oversized-t-shirt", "Shinobi 忍 Stealth Oversized T-Shirt", "忍", "Shinobi · The hidden one",
            ["Unseen in the dark", "silence is my sharpest blade", "the night keeps my name"],
            "Tonal black-on-black print with a single red slash. Quiet, until it isn't.",
            {"Black": ["shinobi-black-back", "shinobi-black-front"]},
            "warrior, graphic, oversized, new, unisex", "SHI"),
    warrior("oni-oversized-t-shirt", "Oni 鬼 Oversized T-Shirt", "鬼", "Oni · The demon within",
            ["Flames behind the mask", "each rep feeds the hungry beast", "I become the storm"],
            "For the last rep and the last round. Let the oni out.",
            {"Black": ["oni-black-back", "oni-black-front"]},
            "warrior, graphic, oversized, bestseller, gym, unisex", "ONI"),
    warrior("nana-korobi-ya-oki-oversized-t-shirt", "Nana Korobi Ya Oki 七転び八起き Oversized T-Shirt", "七転び八起き",
            "Nana korobi ya oki · Fall seven times, rise eight",
            ["Seven times I fell", "eight times I rose from the dust", "still I stand again"],
            "The Japanese proverb of resilience, written vertically down the back with a red hanko seal.",
            {"White": ["nana-korobi-white-back", "nana-korobi-white-front"], "Black": ["nana-korobi-black-back", "nana-korobi-black-front"]},
            "warrior, graphic, oversized, bestseller, new, unisex", "NKO"),
    warrior("fudoshin-acid-wash-oversized-t-shirt", "Fudōshin 不動心 Acid Wash Oversized T-Shirt", "不動心",
            "Fudōshin · The immovable mind",
            ["Storms may shake the pine", "the mountain does not answer", "my mind stands like stone"],
            "Fudōshin is the calm, unshakable mind of a master. Printed on our 250 GSM vintage acid-wash tee.",
            {"Black": ["fudoshin-black-back"]},
            "warrior, graphic, acid-wash, oversized, new, unisex", "FUD", price="999.00", compare="1699.00",
            ptype="Acid Wash T-Shirt"),
] + [
    warrior(f"bushido-{key}-oversized-t-shirt", f"Bushidō {kanji} {romaji} Oversized T-Shirt", kanji,
            f"{romaji} · {meaning}", haiku, intro,
            {"Black": [f"{key}-black-back", f"{key}-black-front"], "White": [f"{key}-white-back", f"{key}-white-front"]},
            "warrior, bushido, graphic, oversized, unisex" + (", bestseller" if key in ("gi", "yu") else ""),
            f"B{key[:3].upper()}")
    for key, kanji, romaji, meaning, haiku, intro in BUSHIDO
] + [
    # ---- Essentials (your blank tees) ----
    {
        "handle": "essential-oversized-t-shirt", "title": "Essential Oversized T-Shirt | 240 GSM",
        "type": "Oversized T-Shirt", "tags": "essentials, oversized, bestseller, plain, unisex",
        "price": "699.00", "compare_at": "1199.00", "grams": 260, "sku": "OVS",
        "body": story("基本", "Kihon · The fundamentals", ["Heavy cotton weight", "simple armor for the day", "train, rest, rise again"])
        + "<p>The foundation of every warrior's wardrobe: our heavyweight oversized tee in nine colours.</p>" + OVERSIZED_SPEC,
        "colors": {
            "Black": ["oversized-black-front", "oversized-black-back"],
            "White": ["oversized-white-front", "oversized-white-back"],
            "Beige": ["oversized-beige-front", "oversized-beige-back"],
            "Bottle Green": ["oversized-bottle-green-front", "oversized-bottle-green-back"],
            "Chocolate": ["oversized-chocolate-front", "oversized-chocolate-back"],
            "Lavender": ["oversized-lavender-front", "oversized-lavender-back"],
            "Navy": ["oversized-navy-front", "oversized-navy-back"],
            "Royal Blue": ["oversized-royal-blue-front", "oversized-royal-blue-back"],
            "Red": ["oversized-red-front", "oversized-red-back"],
        },
    },
    {
        "handle": "acid-wash-essential-oversized-t-shirt", "title": "Acid Wash Essential Oversized T-Shirt | 250 GSM",
        "type": "Acid Wash T-Shirt", "tags": "essentials, acid-wash, oversized, unisex",
        "price": "899.00", "compare_at": "1499.00", "grams": 280, "sku": "ACW",
        "body": story("侘寂", "Wabi-sabi · Beauty in imperfection", ["Washed by rain and time", "each fade a scar from battle", "worn like a legend"])
        + "<p>Vintage-washed heavyweight tee. No two pieces fade exactly alike.</p>"
        + "<ul><li>250 GSM, 100% cotton</li><li>Acid wash finish</li><li>Drop-shoulder oversized fit</li></ul>",
        "colors": {
            "Black": ["acid-wash-black-back"],
            "Royal Blue": ["acid-wash-royal-blue-front", "acid-wash-royal-blue-back"],
            "Bottle Green": ["acid-wash-bottle-green-front", "acid-wash-bottle-green-back"],
        },
    },
    {
        "handle": "essential-regular-fit-t-shirt", "title": "Essential Regular Fit T-Shirt | 180 GSM",
        "type": "Regular Fit T-Shirt", "tags": "essentials, regular-fit, plain, unisex",
        "price": "449.00", "compare_at": "799.00", "grams": 190, "sku": "REG",
        "body": story("日常", "Nichijō · The everyday", ["Plain cloth, steady breath", "the daily road is the path", "walk it with intent"])
        + "<p>A clean crew-neck tee with a true-to-size regular fit.</p><ul><li>180 GSM, 100% cotton</li><li>Bio-washed</li><li>Regular fit</li></ul>",
        "colors": {
            "Black": ["regular-black-front", "regular-black-alt"],
            "White": ["regular-white-front", "regular-white-back"],
            "Red": ["regular-red-front", "regular-red-back"],
        },
    },
    {
        "handle": "essential-full-sleeve-oversized-t-shirt", "title": "Essential Full Sleeve Oversized T-Shirt | 240 GSM",
        "type": "Full Sleeve T-Shirt", "tags": "essentials, full-sleeve, oversized, unisex",
        "price": "849.00", "compare_at": "1399.00", "grams": 320, "sku": "FSL",
        "body": story("鎧", "Yoroi · Armour", ["Sleeves down to the wrist", "armour for the colder dawn", "the training goes on"])
        + "<p>Our oversized tee with full sleeves, for layering and colder mornings.</p>"
        + "<ul><li>240 GSM, 100% cotton</li><li>Drop-shoulder oversized fit</li><li>Ribbed cuffs &amp; neckline</li></ul>",
        "colors": {"Black": ["full-sleeve-black-front", "full-sleeve-black-back"]},
    },
    # ---- Japanese graphic samples ----
    {
        "handle": "fuji-puff-print-oversized-t-shirt", "title": "Fuji 富士 Puff Print Oversized T-Shirt",
        "type": "Graphic T-Shirt", "tags": "graphic, puff-print, acid-wash, new, unisex",
        "price": "1099.00", "compare_at": "1799.00", "grams": 290, "sku": "FUJ",
        "body": story("富士", "Fuji · The sacred mountain", ["Red sun, silent peak", "clouds gather at Fuji's feet", "the city wakes up"])
        + "<p>Mt. Fuji and the red sun in raised 3D puff print on a vintage acid-wash tee.</p>"
        + "<ul><li>250 GSM acid wash cotton</li><li>Raised puff print</li><li>Drop-shoulder oversized fit</li></ul>",
        "colors": {"Black": ["print-puff"]},
    },
    {
        "handle": "tokyo-screen-print-oversized-t-shirt", "title": "Tōkyō 東京 Screen Print Oversized T-Shirt",
        "type": "Graphic T-Shirt", "tags": "graphic, screen-print, acid-wash, new, unisex",
        "price": "999.00", "compare_at": "1699.00", "grams": 290, "sku": "TKS",
        "body": story("東京", "Tōkyō · The eastern capital", ["Neon in the rain", "a lone figure walks the streets", "the blade sleeps tonight"])
        + "<p>Front and back Tokyo artwork, screen printed on a royal blue acid-wash tee.</p>"
        + "<ul><li>250 GSM acid wash cotton</li><li>Front + back screen print</li><li>Drop-shoulder oversized fit</li></ul>",
        "colors": {"Royal Blue": ["print-screen"]},
    },
    # ---- Combo packs (tag "no-offer" hides the bundle line on cards) ----
    {
        "handle": "oversized-t-shirt-combo-pack-of-3", "title": "Nakama Combo | Pack of 3 Oversized T-Shirts",
        "type": "Combo", "tags": "combo, no-offer, bestseller, oversized, unisex", "option_name": "Combo",
        "price": "1799.00", "compare_at": "3597.00", "grams": 780, "sku": "CB3",
        "body": story("仲間", "Nakama · Comrades", ["Three blades, one oath sworn", "together we hold the line", "no one fights alone"])
        + "<p>Three essential oversized tees in a curated colour combo, at our best price.</p>"
        + "<ul><li>3 x 240 GSM, 100% cotton oversized tees</li><li>Same size for all 3 tees</li></ul>",
        "colors": {
            "Black + White + Beige": ["combo-3-black-white-beige"],
            "Black + Bottle Green + Navy": ["combo-3-black-green-navy"],
            "Lavender + Beige + White": ["combo-3-lavender-beige-white"],
            "Chocolate + Black + Red": ["combo-3-chocolate-black-red"],
        },
    },
    {
        "handle": "regular-fit-t-shirt-combo-pack-of-2", "title": "Nakama Combo | Pack of 2 Regular Fit T-Shirts",
        "type": "Combo", "tags": "combo, no-offer, regular-fit, unisex", "option_name": "Combo",
        "price": "799.00", "compare_at": "1598.00", "grams": 380, "sku": "CB2",
        "body": story("仲間", "Nakama · Comrades", ["Two blades, one oath sworn", "together we hold the line", "no one fights alone"])
        + "<p>Two classic regular-fit tees for everyday rotation.</p><ul><li>2 x 180 GSM, 100% cotton tees</li><li>Same size for both tees</li></ul>",
        "colors": {
            "Black + White": ["combo-2-regular-black-white"],
            "Black + Red": ["combo-2-regular-black-red"],
            "White + Red": ["combo-2-regular-white-red"],
        },
    },
]

# Custom printing product: options are Print Method x Size.
CUSTOM_PRINT = {
    "handle": "custom-printed-t-shirt",
    "title": "Custom Printed T-Shirt | Your Design",
    "type": "Custom T-Shirt",
    "tags": "custom, print-on-demand",
    "grams": 280,
    "sku": "CUS",
    "body": story("創造", "Sōzō · Creation", ["Your mark, your own path", "ink becomes a fighter's crest", "wear what you create"])
    + "<p>Upload your own design (your dojo, gym or team logo) and we'll print it on our 240 GSM oversized tee. "
    "Our team confirms the mock-up with you before printing.</p>"
    "<ul><li><strong>DTF</strong>: full colour, photo-quality detail</li>"
    "<li><strong>Screen print</strong>: bold solid colours, best for bulk</li>"
    "<li><strong>Puff print</strong>: raised 3D texture</li>"
    "<li><strong>HD print</strong>: high-density tonal finish</li>"
    "<li><strong>Embroidery</strong>: stitched, premium look</li></ul>",
    "methods": {
        "DTF Print": ("999.00", "1499.00", "print-dtf"),
        "Screen Print": ("1099.00", "1599.00", "print-screen"),
        "Puff Print": ("1299.00", "1799.00", "print-puff"),
        "HD Print": ("1199.00", "1699.00", "print-hd"),
        "Embroidery": ("1399.00", "1999.00", "print-embroidery"),
    },
}

HEADERS = [
    "Handle", "Title", "Body (HTML)", "Vendor", "Type", "Tags", "Published",
    "Option1 Name", "Option1 Value", "Option2 Name", "Option2 Value",
    "Variant SKU", "Variant Grams", "Variant Inventory Tracker", "Variant Inventory Policy",
    "Variant Fulfillment Service", "Variant Price", "Variant Compare At Price",
    "Variant Requires Shipping", "Variant Taxable", "Image Src", "Image Position",
    "Image Alt Text", "Variant Image", "Variant Weight Unit", "SEO Title", "SEO Description",
    "Status",
]


def slug(text):
    """Short SKU code: initials for multi-word names, else first 3 letters."""
    words = text.upper().replace("+", " ").split()
    return "".join(w[0] for w in words) if len(words) > 1 else words[0][:3]


def variant_row(option_name, value, size, sku, grams, price, compare, image):
    return {
        "Option1 Name": option_name, "Option1 Value": value, "Option2 Name": "Size", "Option2 Value": size,
        "Variant SKU": sku, "Variant Grams": grams, "Variant Inventory Policy": "deny",
        "Variant Fulfillment Service": "manual", "Variant Price": price, "Variant Compare At Price": compare,
        "Variant Requires Shipping": "TRUE", "Variant Taxable": "TRUE", "Variant Image": image,
        "Variant Weight Unit": "g",
    }


def build_rows(base_url, vendor):
    rows = []

    def url(name):
        return f"{base_url}{name}.jpg"

    for p in PRODUCTS:
        option = p.get("option_name", "Color")
        images = [(img, color) for color, imgs in p["colors"].items() for img in imgs]
        variants = [
            variant_row(option, color, size, f"{p['sku']}-{slug(color)}-{size}", p["grams"],
                        p["price"], p["compare_at"], url(imgs[0]))
            for color, imgs in p["colors"].items() for size in SIZES
        ]
        rows.extend(merge(p, vendor, variants, images, url))

    c = CUSTOM_PRINT
    images, variants = [], []
    for method, (price, compare, img) in c["methods"].items():
        images.append((img, method))
        variants += [variant_row("Print Method", method, size, f"{c['sku']}-{slug(method)}-{size}",
                                 c["grams"], price, compare, url(img)) for size in SIZES]
    rows.extend(merge(c, vendor, variants, images, url))
    return rows


def merge(p, vendor, variant_rows, images, url):
    """Combine variant rows and image rows the way Shopify expects."""
    out = []
    for i in range(max(len(variant_rows), len(images))):
        row = {"Handle": p["handle"]}
        if i == 0:
            row.update({
                "Title": p["title"], "Body (HTML)": p["body"], "Vendor": vendor, "Type": p["type"],
                "Tags": p["tags"], "Published": "TRUE", "SEO Title": f"{p['title']} | HAIKUFIT",
                "SEO Description": f"{p['title']}: Japanese warrior-inspired streetwear by HAIKUFIT. "
                                   "Heavyweight cotton, a haiku with every tee, COD available.",
                "Status": "active",
            })
        if i < len(variant_rows):
            row.update(variant_rows[i])
        if i < len(images):
            img, alt = images[i]
            row.update({"Image Src": url(img), "Image Position": i + 1, "Image Alt Text": alt})
        out.append(row)
    return out


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--branch", default="master", help="Git branch the images are on (default: master)")
    parser.add_argument("--base-url", help="Override the image base URL (must end with /)")
    parser.add_argument("--vendor", default="HAIKUFIT", help="Vendor / brand name")
    parser.add_argument("--out", default=os.path.join(os.path.dirname(__file__), "..", "products.csv"))
    args = parser.parse_args()

    rows = build_rows(args.base_url or REPO_RAW.format(branch=args.branch), args.vendor)
    with open(args.out, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=HEADERS)
        writer.writeheader()
        writer.writerows(rows)

    products = len({r["Handle"] for r in rows})
    variants = sum(1 for r in rows if r.get("Option1 Value"))
    print(f"Wrote {os.path.normpath(args.out)}: {products} products, {variants} variants")


if __name__ == "__main__":
    main()
