#!/usr/bin/env python3
"""Generate a Shopify product-import CSV for the t-shirt catalogue.

Edit the PRODUCTS list below (prices, colors, sizes, descriptions), then run:

    python3 shopify/tools/build_products_csv.py

The CSV is written to shopify/products.csv. Import it in Shopify admin under
Products > Import. Image URLs point at the product-images folder in this
GitHub repo, so the repo must be public and the images must be on the branch
named by --branch (default: master) before you import.
"""

import argparse
import csv
import os

REPO_RAW = "https://raw.githubusercontent.com/naisampksam/sam_test/{branch}/shopify/product-images/"

SIZES = ["S", "M", "L", "XL", "XXL"]

PRODUCTS = [
    {
        "handle": "oversized-t-shirt",
        "title": "Oversized T-Shirt | 240 GSM",
        "type": "Oversized T-Shirt",
        "tags": "oversized, bestseller, new, plain, unisex",
        "price": "699.00",
        "compare_at": "1199.00",
        "grams": 260,
        "sku": "OVS",
        "body": (
            "<p>Our everyday heavyweight oversized tee. Drop shoulders, a relaxed boxy fit "
            "and a thick ribbed neckline that holds its shape wash after wash.</p>"
            "<ul><li>240 GSM, 100% combed cotton</li><li>Bio-washed &amp; pre-shrunk</li>"
            "<li>Drop-shoulder oversized fit</li><li>Unisex sizing</li></ul>"
        ),
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
        "handle": "acid-wash-oversized-t-shirt",
        "title": "Acid Wash Oversized T-Shirt | 250 GSM",
        "type": "Acid Wash T-Shirt",
        "tags": "oversized, acid-wash, new, bestseller, unisex",
        "price": "899.00",
        "compare_at": "1499.00",
        "grams": 280,
        "sku": "ACW",
        "body": (
            "<p>Vintage-washed heavyweight tee with a unique faded texture — no two pieces "
            "look exactly alike.</p>"
            "<ul><li>250 GSM, 100% cotton</li><li>Acid wash finish</li>"
            "<li>Drop-shoulder oversized fit</li><li>Unisex sizing</li></ul>"
        ),
        "colors": {
            "Black": ["acid-wash-black-back"],
            "Royal Blue": ["acid-wash-royal-blue-front", "acid-wash-royal-blue-back"],
            "Bottle Green": ["acid-wash-bottle-green-front", "acid-wash-bottle-green-back"],
        },
    },
    {
        "handle": "regular-fit-t-shirt",
        "title": "Regular Fit T-Shirt | 180 GSM",
        "type": "Regular Fit T-Shirt",
        "tags": "regular-fit, plain, unisex",
        "price": "449.00",
        "compare_at": "799.00",
        "grams": 190,
        "sku": "REG",
        "body": (
            "<p>A clean, classic crew-neck tee with a true-to-size regular fit. Soft, "
            "breathable and made for everyday wear.</p>"
            "<ul><li>180 GSM, 100% cotton</li><li>Bio-washed</li><li>Regular fit</li></ul>"
        ),
        "colors": {
            "Black": ["regular-black-front", "regular-black-alt"],
            "White": ["regular-white-front", "regular-white-back"],
            "Red": ["regular-red-front", "regular-red-back"],
        },
    },
    {
        "handle": "full-sleeve-oversized-t-shirt",
        "title": "Full Sleeve Oversized T-Shirt | 240 GSM",
        "type": "Full Sleeve T-Shirt",
        "tags": "oversized, full-sleeve, new, unisex",
        "price": "849.00",
        "compare_at": "1399.00",
        "grams": 320,
        "sku": "FSL",
        "body": (
            "<p>The oversized tee you love, now with full sleeves. Perfect for layering "
            "and cooler evenings.</p>"
            "<ul><li>240 GSM, 100% cotton</li><li>Drop-shoulder oversized fit</li>"
            "<li>Ribbed cuffs &amp; neckline</li></ul>"
        ),
        "colors": {
            "Black": ["full-sleeve-black-front", "full-sleeve-black-back"],
        },
    },
    # ---- Sample graphic tees (replace with your own designs) ----
    {
        "handle": "tokyo-puff-print-oversized-t-shirt",
        "title": "Tokyo Puff Print Oversized T-Shirt",
        "type": "Graphic T-Shirt",
        "tags": "graphic, puff-print, acid-wash, bestseller, new, unisex",
        "price": "1099.00",
        "compare_at": "1799.00",
        "grams": 290,
        "sku": "TKP",
        "body": (
            "<p>A bold Tokyo graphic in raised puff print on a vintage acid-wash oversized tee. "
            "The 3D texture makes it pop in every photo.</p>"
            "<ul><li>250 GSM acid wash cotton</li><li>Raised puff print</li>"
            "<li>Drop-shoulder oversized fit</li></ul>"
        ),
        "colors": {"Black": ["print-puff"]},
    },
    {
        "handle": "tokyo-screen-print-oversized-t-shirt",
        "title": "Tokyo Screen Print Oversized T-Shirt",
        "type": "Graphic T-Shirt",
        "tags": "graphic, screen-print, acid-wash, new, unisex",
        "price": "999.00",
        "compare_at": "1699.00",
        "grams": 290,
        "sku": "TKS",
        "body": (
            "<p>Front and back Tokyo artwork, screen printed for rich, long-lasting color on a "
            "royal blue acid-wash oversized tee.</p>"
            "<ul><li>250 GSM acid wash cotton</li><li>Front + back screen print</li>"
            "<li>Drop-shoulder oversized fit</li></ul>"
        ),
        "colors": {"Royal Blue": ["print-screen"]},
    },
    # ---- Sample combo packs (tag "no-offer" hides the bundle line on cards) ----
    {
        "handle": "oversized-t-shirt-combo-pack-of-3",
        "title": "Oversized T-Shirt Combo | Pack of 3",
        "type": "Combo",
        "tags": "combo, no-offer, bestseller, oversized, unisex",
        "option_name": "Combo",
        "price": "1799.00",
        "compare_at": "3597.00",
        "grams": 780,
        "sku": "CB3",
        "body": (
            "<p>Three of our bestselling 240 GSM oversized tees in a curated color combo, "
            "at our best price.</p>"
            "<ul><li>3 x 240 GSM, 100% cotton oversized tees</li><li>Same size for all 3 tees</li>"
            "<li>Bio-washed &amp; pre-shrunk</li></ul>"
        ),
        "colors": {
            "Black + White + Beige": ["combo-3-black-white-beige"],
            "Black + Bottle Green + Navy": ["combo-3-black-green-navy"],
            "Lavender + Beige + White": ["combo-3-lavender-beige-white"],
            "Chocolate + Black + Red": ["combo-3-chocolate-black-red"],
        },
    },
    {
        "handle": "regular-fit-t-shirt-combo-pack-of-2",
        "title": "Regular Fit T-Shirt Combo | Pack of 2",
        "type": "Combo",
        "tags": "combo, no-offer, regular-fit, unisex",
        "option_name": "Combo",
        "price": "799.00",
        "compare_at": "1598.00",
        "grams": 380,
        "sku": "CB2",
        "body": (
            "<p>Two classic regular-fit tees, ready for everyday rotation.</p>"
            "<ul><li>2 x 180 GSM, 100% cotton tees</li><li>Same size for both tees</li></ul>"
        ),
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
    "title": "Custom Printed T-Shirt",
    "type": "Custom T-Shirt",
    "tags": "custom, print-on-demand",
    "grams": 280,
    "sku": "CUS",
    "body": (
        "<p>Upload your own design and we'll print it on our premium 240 GSM oversized tee. "
        "Choose a print method, pick your tee color and add instructions — our team will "
        "confirm the mock-up with you before printing.</p>"
        "<ul><li><strong>DTF</strong> — full color, photo-quality detail</li>"
        "<li><strong>Screen print</strong> — bold solid colors, best for bulk</li>"
        "<li><strong>Puff print</strong> — raised 3D texture</li>"
        "<li><strong>HD print</strong> — high-density tonal finish</li>"
        "<li><strong>Embroidery</strong> — stitched, premium look</li></ul>"
    ),
    # method: (price, compare_at, image)
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


def build_rows(base_url, vendor):
    rows = []

    def url(name):
        return f"{base_url}{name}.jpg"

    for p in PRODUCTS:
        images = [(img, color) for color, imgs in p["colors"].items() for img in imgs]
        variant_rows = []
        for color, imgs in p["colors"].items():
            for size in SIZES:
                variant_rows.append({
                    "Option1 Name": p.get("option_name", "Color"),
                    "Option1 Value": color,
                    "Option2 Name": "Size",
                    "Option2 Value": size,
                    "Variant SKU": f"{p['sku']}-{slug(color)}-{size}",
                    "Variant Grams": p["grams"],
                    "Variant Inventory Policy": "deny",
                    "Variant Fulfillment Service": "manual",
                    "Variant Price": p["price"],
                    "Variant Compare At Price": p["compare_at"],
                    "Variant Requires Shipping": "TRUE",
                    "Variant Taxable": "TRUE",
                    "Variant Image": url(imgs[0]),
                    "Variant Weight Unit": "g",
                })
        rows.extend(merge(p, vendor, variant_rows, images, url))

    c = CUSTOM_PRINT
    variant_rows, images = [], []
    for method, (price, compare_at, img) in c["methods"].items():
        images.append((img, method))
        for size in SIZES:
            variant_rows.append({
                "Option1 Name": "Print Method",
                "Option1 Value": method,
                "Option2 Name": "Size",
                "Option2 Value": size,
                "Variant SKU": f"{c['sku']}-{slug(method)}-{size}",
                "Variant Grams": c["grams"],
                "Variant Inventory Policy": "deny",
                "Variant Fulfillment Service": "manual",
                "Variant Price": price,
                "Variant Compare At Price": compare_at,
                "Variant Requires Shipping": "TRUE",
                "Variant Taxable": "TRUE",
                "Variant Image": url(img),
                "Variant Weight Unit": "g",
            })
    rows.extend(merge(c, vendor, variant_rows, images, url))
    return rows


def merge(p, vendor, variant_rows, images, url):
    """Combine variant rows and image rows the way Shopify expects."""
    out = []
    count = max(len(variant_rows), len(images))
    for i in range(count):
        row = {"Handle": p["handle"]}
        if i == 0:
            row.update({
                "Title": p["title"],
                "Body (HTML)": p["body"],
                "Vendor": vendor,
                "Type": p["type"],
                "Tags": p["tags"],
                "Published": "TRUE",
                "SEO Title": p["title"],
                "SEO Description": f"Shop the {p['title']} online. Premium cotton, COD available, easy returns.",
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
    parser.add_argument("--vendor", default="My Store", help="Vendor / brand name")
    parser.add_argument("--out", default=os.path.join(os.path.dirname(__file__), "..", "products.csv"))
    args = parser.parse_args()

    base_url = args.base_url or REPO_RAW.format(branch=args.branch)
    rows = build_rows(base_url, args.vendor)

    with open(args.out, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=HEADERS)
        writer.writeheader()
        writer.writerows(rows)

    products = len({r["Handle"] for r in rows})
    variants = sum(1 for r in rows if r.get("Option1 Value"))
    print(f"Wrote {os.path.normpath(args.out)}: {products} products, {variants} variants")


if __name__ == "__main__":
    main()
