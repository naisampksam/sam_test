#!/usr/bin/env python3
"""Printable HAIKUFIT thank-you card (A6, two sides) with a QR code to Dojo Run.

Writes print/thank-you-card/card.html. Open it in a browser and print (A6, no margins),
or run tools/render_card.mjs to get card.pdf, card-front.png and card-back.png.

Needs the `qrcode` package:  pip install qrcode
"""
import base64
import io
import pathlib

import qrcode
import qrcode.image.svg

ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT = ROOT / 'print' / 'thank-you-card'
URL = 'https://haikufit.in/pages/dojo-run?utm_source=thank_you_card&utm_medium=print&utm_campaign=dojo_run'
FONT = pathlib.Path.home() / '.local/share/fonts/Oswald[wght].ttf'


def qr_svg(data):
    qr = qrcode.QRCode(error_correction=qrcode.constants.ERROR_CORRECT_M, border=0, box_size=10)
    qr.add_data(data)
    qr.make(fit=True)
    buf = io.BytesIO()
    qr.make_image(image_factory=qrcode.image.svg.SvgPathImage).save(buf)
    svg = buf.getvalue().decode()
    return svg[svg.index('<svg'):]


def data_uri(path, mime):
    return f'data:{mime};base64,{base64.b64encode(path.read_bytes()).decode()}'


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    logo = data_uri(ROOT / 'theme/assets/logo.png', 'image/png')
    font = f"src: url('{data_uri(FONT, 'font/ttf')}');" if FONT.exists() else "src: local('Oswald');"
    html = f"""<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>HAIKUFIT thank-you card</title>
<style>
@font-face {{ font-family: 'Oswald'; {font} font-weight: 200 700; }}
@page {{ size: 105mm 148mm; margin: 0; }}
* {{ box-sizing: border-box; margin: 0; }}
body {{ background: #ccc; font-family: 'Oswald', 'Arial Narrow', sans-serif; color: #0E0E0E; }}
.card {{ width: 105mm; height: 148mm; margin: 0 auto 8mm; background: #F3EFE6; position: relative; overflow: hidden;
  page-break-after: always; break-after: page; padding: 11mm 9mm 9mm; display: flex; flex-direction: column; align-items: center; text-align: center; }}
@media print {{ body {{ background: none; }} .card {{ margin: 0; }} }}
.frame {{ position: absolute; inset: 4mm; border: 0.5mm solid #0E0E0E; pointer-events: none; }}
.frame::after {{ content: ''; position: absolute; inset: 1.2mm; border: 0.25mm solid #C8102E; }}
.logo {{ width: 52mm; }}
.rule {{ width: 16mm; height: 0.7mm; background: #C8102E; margin: 5mm auto; border-radius: 1mm; }}
h1 {{ font-size: 25pt; font-weight: 700; letter-spacing: 0.04em; line-height: 1.05; text-transform: uppercase; }}
h1 em {{ font-style: normal; color: #C8102E; }}
.haiku {{ margin-top: 7mm; font-size: 12.5pt; font-weight: 300; line-height: 1.55; letter-spacing: 0.02em; }}
.note {{ margin-top: auto; font-size: 8.6pt; font-weight: 400; line-height: 1.5; letter-spacing: 0.02em; max-width: 74mm; }}
.sign {{ margin-top: 3mm; font-size: 9pt; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; }}
.front {{ justify-content: center; }}
.front .logo {{ position: absolute; top: 13mm; left: 50%; transform: translateX(-50%); }}
.front .note {{ margin-top: 9mm; }}
.dots {{ margin-top: 7mm; letter-spacing: 3mm; color: #C8102E; font-size: 9pt; }}
.kicker {{ font-size: 8pt; font-weight: 600; letter-spacing: 0.3em; color: #C8102E; text-transform: uppercase; }}
.back h1 {{ font-size: 21pt; margin-top: 2mm; }}
.lead {{ margin-top: 2.5mm; font-size: 10pt; font-weight: 400; line-height: 1.4; max-width: 78mm; }}
.lead b {{ color: #C8102E; font-weight: 700; }}
.qr {{ margin-top: 5mm; padding: 3mm; background: #fff; border: 0.5mm solid #0E0E0E; border-radius: 1.5mm; box-shadow: 1.2mm 1.2mm 0 #C8102E; }}
.qr svg {{ width: 33mm; height: 33mm; display: block; }}
.qr svg path {{ fill: #0E0E0E; }}
.url {{ margin-top: 3.2mm; font-size: 8pt; letter-spacing: 0.06em; }}
.steps {{ list-style: none; padding: 0; margin-top: 4mm; display: grid; grid-template-columns: repeat(3, 1fr); gap: 2mm; width: 100%; }}
.steps li {{ font-size: 7.6pt; line-height: 1.3; font-weight: 400; }}
.steps b {{ display: block; margin: 0 auto 1mm; width: 5.6mm; height: 5.6mm; line-height: 5.6mm; border-radius: 50%; background: #0E0E0E; color: #F3EFE6; font-size: 7.5pt; }}
.bonus {{ margin-top: auto; width: 100%; padding: 2.6mm 3mm; border: 0.35mm dashed #C8102E; border-radius: 1.5mm; font-size: 7.8pt; line-height: 1.4; }}
.bonus strong {{ color: #C8102E; letter-spacing: .06em; text-transform: uppercase; }}
.small {{ margin-top: 2.4mm; font-size: 6.2pt; opacity: .7; letter-spacing: .02em; }}
</style></head><body>

<section class="card front">
  <div class="frame"></div>
  <img class="logo" src="{logo}" alt="HAIKUFIT">
  <div class="rule"></div>
  <h1>Arigatō,<br><em>warrior.</em></h1>
  <p class="haiku">Cotton meets the skin,<br>the blade rests, the spirit wakes.<br>Wear the warrior's way.</p>
  <p class="dots">• • •</p>
  <p class="note">Thank you for choosing HAIKUFIT. Your tee was printed just for you. Wear it with pride, and tag us when you do.</p>
  <p class="sign">Team HAIKUFIT</p>
</section>

<section class="card back">
  <div class="frame"></div>
  <p class="kicker">Dojo Run</p>
  <h1>Your next battle<br><em>awaits</em></h1>
  <p class="lead">Scan, play our samurai game and win up to <b>₹150 off</b> your next order.</p>
  <div class="qr">{qr_svg(URL)}</div>
  <p class="url">haikufit.in/pages/dojo-run</p>
  <ol class="steps">
    <li><b>1</b>Scan &amp; fight. Play as often as you like.</li>
    <li><b>2</b>Sign in to claim your code.</li>
    <li><b>3</b>Use it within 24 hours on orders of ₹999+.</li>
  </ol>
  <p class="bonus"><strong>Review bonus:</strong> loved your tee? Review it on its product page (a photo makes it legendary) and unlock one more reward this month.</p>
  <p class="small">One reward used every 30 days. One reward per order. Full rules: haikufit.in/pages/offers-rewards</p>
</section>
</body></html>
"""
    (OUT / 'card.html').write_text(html, encoding='utf-8')
    print('wrote', OUT / 'card.html')


if __name__ == '__main__':
    main()
