# Samurai Drop 01

Six Japanese-streetwear designs for oversized tees: a large sumi-e **back print** (DTF) plus a matching **left-chest embroidery**. All Japanese text is real and checked for meaning, set from real fonts and converted to vector outlines.

Open `gallery/index.html` to preview each design on the brand's blank tees, switch shirt colours and copy the Etsy listing text.

| Design | Kanji | Meaning | Chest | Best shirt colours |
|---|---|---|---|---|
| **Ronin** | 浪人 (rōnin) | A masterless samurai: a warrior who wanders alone after losing his lord. | 浪人 + 侍 seal | White, Black, Beige |
| **The Duel** | 決闘 (kettō) | A duel: a fight decided between two people. | 決闘 + 武 seal | Black, White, Bottle green |
| **Bushido** | 武士道 (bushidō) | The way of the warrior: the samurai code of conduct. | 武士道 + 誠 seal | White, Black, Navy |
| **Ittō Ryōdan** | 一刀両断 (ittō ryōdan) | To cut something in two with a single stroke. Figuratively: decisive action, no hesitation. | 一刀両断 + 斬 seal | White, Black, Chocolate |
| **Samurai** | 侍 (samurai) | Samurai. From the old verb saburau (侍う): "to serve". | 侍 + 武 seal | White, Black, Beige |
| **Chiru Sakura** | 散る桜 (chiru sakura) | 散る桜 / 残る桜も / 散る桜. "The cherry blossoms that fall, and those that remain, are falling blossoms too." A verse attributed to the monk Ryōkan. | 散る桜 + 桜 seal | Black, Chocolate, White |

## Files

| Folder | What | Use |
|---|---|---|
| `dtf/<design>-light.svg` / `-dark.svg` | **Final DTF back prints as vectors**: flat paths, 2 inks, no effects, 14×21 in. Specks under 0.6 mm removed | Send to the DTF printer. Opens in CorelDRAW, Illustrator, Inkscape and RIPs |
| `dtf/<design>-chest-<mode>.svg` | Final DTF chest print as vectors, true size | DTF chest print if you skip embroidery |
| `print/<design>-light.png` / `-dark.png` | Back print, 4200×6300 px transparent (14×21 in @ 300 dpi) | Send to DTF printer. **light** = black ink for white/beige/lavender tees; **dark** = off-white ink for black/navy/green/chocolate tees |
| `print/<design>-embroidery-<mode>.png` | Flat embroidery art, 2.2 in wide @ 300 dpi | Reference for the digitizer |
| `svg/<design>-embroidery-<mode>.svg` | Vector embroidery art, text outlined | **Send this to the digitizer** for DST/PES |
| `print/<design>-chest-<mode>.png` | Textured chest art | DTF chest print if you skip embroidery |
| `svg/*.svg` | Editable sources (brush texture made with SVG filters, which only browsers/Inkscape show) | Edit, then rebuild `dtf/` |
| `mockups/<design>-<colour>-{back,front,closeup}.jpg` | Mockups on your blank tee photos | Etsy/Instagram listing photos |
| `gallery/` | Interactive preview page | Review and pick designs |
| `src/` | Generator scripts | Edit text and colours, then rebuild |

## Production specs

- **Back print:** DTF. Send dtf/<design>-<light|dark>.svg: flat vector paths, 2 solid inks, no effects or semi-transparency, sized 14 × 21 in. A 4200 × 6300 px PNG is also in print/. Artwork fills about 12 in wide. Place ~3 in below the back collar.
- **DTF clean-up:** No grain speckle, every splatter dot at least ~1 mm, and anything under 0.6 mm removed in tracing, so nothing tiny peels off the film.
- **Light vs dark:** Every design has a -light file (black ink, for white/beige/lavender) and a -dark file (off-white ink, for black/navy/green/chocolate). Red stays the same: close to Pantone 485 C.
- **Front embroidery:** Left chest, 2.2 in wide, 3.3–4.3 in tall. Send the *-embroidery-*.svg (all text is already outlined) to your digitizer for DST/PES. Expect roughly 6–10k stitches.
- **Thread colours:** Light tees: black + vermilion red + light grey (tone-on-tone katana). Dark tees: off-white + vermilion red + charcoal (tone-on-tone katana).
- **Small details:** Seal characters are knocked out (shirt shows through). Keep them at least 1 mm stroke. If your digitizer struggles, stitch the seal as a solid red square.
- **DTF alternative:** No embroidery budget for a run? The *-chest-*.png files are textured DTF versions of the same chest art.

## Etsy listings

### Ronin · 浪人

**Title:** Ronin Samurai Oversized Tee, Japanese Streetwear Back Print, Red Sun Kanji Shirt, Embroidered Chest, Bushido Gift

**Tags:** samurai shirt, ronin t shirt, japanese streetwear, kanji shirt, oversized tee, red sun shirt, bushido shirt, japanese art tee, samurai gift, anime aesthetic, martial arts gift, japan lover gift, sumi e ink art

**Description:**

```
Walk your own path. Our Ronin tee shows a lone masterless samurai against a hand-brushed red sun, finished with the kanji 浪人 (rōnin) and a red hanko seal.

• Heavyweight oversized fit, drop shoulders
• Large DTF back print: soft, stretchy, wash-resistant
• Embroidered 浪人 kanji on the left chest
• Real Japanese, checked for meaning: 浪人 = "masterless samurai / wanderer"

Size up for an extra-relaxed look. Wash inside out, cold.
```

### The Duel · 決闘

**Title:** Samurai Duel Oversized T-Shirt, Japanese Katana Fight Back Print, Kanji Streetwear Tee, Embroidered, Martial Arts Gift

**Tags:** samurai duel shirt, katana t shirt, japanese streetwear, kanji shirt, oversized tee, samurai shirt, sword fight tee, japanese art tee, kendo gift, martial arts gift, ink art shirt, cherry blossom tee, warrior shirt

**Description:**

```
Only one walks away. Two samurai face each other across a stroke of red ink in this sumi-e style back print, with 決闘 (kettō, "duel") brushed between them.

• Heavyweight oversized fit, drop shoulders
• Large DTF back print in ink black/off-white and vermilion red
• Embroidered 決闘 kanji on the left chest
• Real Japanese, checked for meaning

A gift for kendo, karate and BJJ people, and for anyone who loves samurai cinema.
```

### Bushido · 武士道

**Title:** Bushido Seven Virtues Oversized Tee, Samurai Code Katana Back Print, Japanese Kanji Streetwear, Embroidered, Discipline Gift

**Tags:** bushido shirt, seven virtues tee, samurai code shirt, katana shirt, japanese streetwear, kanji shirt, oversized tee, martial arts gift, karate gift, bjj gift, stoic shirt, discipline shirt, japan lover gift

**Description:**

```
Live by the code. A katana cuts down a red brush stripe, flanked by 武士道 (bushidō) and the seven virtues of the samurai:

義 Righteousness · 勇 Courage · 仁 Benevolence · 礼 Respect · 誠 Honesty · 名誉 Honor · 忠義 Loyalty

• Heavyweight oversized fit, drop shoulders
• Large DTF back print
• Embroidered 武士道 kanji on the left chest
• Real Japanese, checked for meaning
```

### Ittō Ryōdan · 一刀両断

**Title:** Itto Ryodan Japanese Proverb Oversized Tee, Enso Circle Katana Slash Back Print, Minimalist Kanji Streetwear, Embroidered

**Tags:** kanji shirt, enso circle shirt, zen shirt, japanese streetwear, minimalist tee, samurai shirt, katana shirt, oversized tee, japanese proverb, stoic shirt, martial arts gift, ink brush art, japan lover gift

**Description:**

```
One stroke. No second chance. 一刀両断 (ittō ryōdan) is a Japanese idiom: to cut something in two with a single stroke, meaning to act decisively.

A red katana slash splits a hand-brushed Zen ensō circle in this minimalist back print.

• Heavyweight oversized fit, drop shoulders
• Large DTF back print
• Embroidered 一刀両断 on the left chest
• Real Japanese, checked for meaning
```

### Samurai · 侍

**Title:** Samurai Kanji Oversized T-Shirt, Giant Red 侍 Brush Back Print, Japanese Warrior Streetwear Tee, Embroidered, Samurai Gift

**Tags:** samurai shirt, kanji samurai tee, japanese streetwear, oversized tee, samurai silhouette, red kanji shirt, katana shirt, japanese art tee, warrior shirt, martial arts gift, anime aesthetic, bushido shirt, japan lover gift

**Description:**

```
Those who serve. A samurai stands before a giant red brush-written 侍 (samurai), a character that comes from the old verb "to serve".

• Heavyweight oversized fit, drop shoulders
• Large DTF back print
• Embroidered 侍 on the left chest
• Real Japanese, checked for meaning
```

### Chiru Sakura · 散る桜

**Title:** Cherry Blossom Katana Oversized Tee, Japanese Haiku Sakura Back Print, Ryokan Poem Kanji Streetwear, Embroidered Gift

**Tags:** cherry blossom tee, sakura shirt, katana shirt, japanese poem shirt, haiku shirt, japanese streetwear, oversized tee, samurai shirt, kanji shirt, zen shirt, japanese art tee, mono no aware, japan lover gift

**Description:**

```
The blossoms that remain will also fall. A katana rests beneath a sakura branch, with petals sliced as they drift past the blade. The back carries the verse 散る桜 残る桜も 散る桜, attributed to the monk Ryōkan.

• Heavyweight oversized fit, drop shoulders
• Large DTF back print
• Embroidered 散る桜 on the left chest
• Real Japanese, checked for meaning
```


## Next drop: AI art prompts

Generate the art without text, then add real kanji with the build kit. Image models invent fake characters, so never let them write the Japanese.

**Hannya mask**: Demon mask from Noh theatre. Huge with anime and tattoo fans.

```
Hannya demon mask, Japanese sumi-e ink painting, bold black dry-brush strokes, vermilion red accents on horns and lips, ink splatter, high contrast, isolated on pure white background, t-shirt back print design, centered composition --no text, letters, kanji, watermark, frame --ar 2:3 --style raw
```

**Ronin in the rain**: Moody, cinematic. Pairs with the Ronin drop.

```
lone ronin samurai in a straw kasa hat walking through heavy rain, full-body silhouette, Japanese sumi-e black ink, one tall vertical vermilion red brush stroke behind him, ink splatter and rain streaks, minimalist, isolated on pure white background, streetwear back print --no text, letters, kanji, watermark --ar 2:3 --style raw
```

**Tiger vs dragon**: Classic ryūko (竜虎) rivalry: strength vs wisdom.

```
tiger and dragon circling each other in combat, Japanese ink wash painting, sumi-e, energetic black brush strokes, large red sun disc behind them, dramatic negative space, isolated on pure white background, t-shirt back print --no text, letters, kanji, watermark --ar 2:3 --style raw
```

**Crow and red moon**: Dark, minimal. Strong on black tees in the off-white ink version.

```
black crow perched on the hilt of a katana planted in the ground, huge red full moon behind, Japanese sumi-e brush painting, dry brush texture, falling red maple leaves, minimal composition, isolated on pure white background, t-shirt print design --no text, letters, kanji, watermark --ar 2:3 --style raw
```

**Kitsune samurai**: Fox-masked warrior mid-draw (iaidō). Crossover appeal with anime fans.

```
samurai wearing a white kitsune fox mask drawing his katana in an iaido stance, motion blur ink slash, sumi-e black ink with vermilion red mask markings, ink splatter, dynamic diagonal composition, isolated on pure white background, streetwear back print --no text, letters, kanji, watermark --ar 2:3 --style raw
```

**Koi and blade**: Two koi circling a katana. Softer; good for lavender and beige tees.

```
two koi fish swimming in a circle around a vertical katana, one red koi and one black koi, Japanese ink painting, flowing brush strokes, water ripples drawn in ink, isolated on pure white background, t-shirt print design --no text, letters, kanji, watermark --ar 2:3 --style raw
```


## Rebuilding

```bash
cd designs/src
npm install                 # opentype.js (text -> outlines)
node build.mjs              # writes ../svg
node render.mjs 3.5 ../print light.svg && node render.mjs 3.5 ../print dark.svg   # back prints, 14x21 in
node render.mjs 3 ../print chest && node render.mjs 3 ../print embroidery         # chest pieces at true size
python3 vectorize.py        # flat DTF vector SVGs in ../dtf (needs potrace, Pillow, numpy)
./mockups.sh                # mockups + gallery assets (needs ImageMagick)
node gallery.mjs            # gallery page + this README
```

Rendering uses Playwright's Chromium. Kanji come from Yuji Boku / Yuji Syuku and Latin text from Bebas Neue (all SIL Open Font License, subset in `src/fonts`). To add a new character, re-download the font subset with it included; `build.mjs` outlines whatever the font contains.
