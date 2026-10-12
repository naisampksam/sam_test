# Samurai Drop 01

Twelve Japanese-streetwear designs for oversized tees, each planned around professional print placements (full back, center back, front, chest embroidery, sleeve, nape). All Japanese text is real and checked for meaning, set from real fonts and converted to vector outlines.

Open `gallery/index.html` to preview each design on the brand's blank tees, switch shirt colours and copy the Etsy listing text.

| Design | Kanji | Meaning | Chest | Best shirt colours |
|---|---|---|---|---|
| **Ronin** | 浪人 (rōnin) | A masterless samurai: a warrior who wanders alone after losing his lord. | 浪人 + 侍 seal | White, Black, Beige |
| **The Duel** | 決闘 (kettō) | A duel: a fight decided between two people. | 決闘 + 武 seal | Black, White, Bottle green |
| **Bushido** | 武士道 (bushidō) | The way of the warrior: the samurai code of conduct. | 武士道 + 誠 seal | White, Black, Navy |
| **Ittō Ryōdan** | 一刀両断 (ittō ryōdan) | To cut something in two with a single stroke. Figuratively: decisive action, no hesitation. | 一刀両断 + 斬 seal | White, Black, Chocolate |
| **Samurai** | 侍 (samurai) | Samurai. From the old verb saburau (侍う): "to serve". | 侍 + 武 seal | White, Black, Beige |
| **Chiru Sakura** | 散る桜 (chiru sakura) | 散る桜 / 残る桜も / 散る桜. "The cherry blossoms that fall, and those that remain, are falling blossoms too." A verse attributed to the monk Ryōkan. | 散る桜 + 桜 seal | Black, Chocolate, White |
| **Torii** | 鳥居 (torii) | The gate of a Shinto shrine: the line between the everyday world and the sacred one. | 鳥居 + 道 seal | White, Black, Beige |
| **Hitori Tabi** | 一人旅 (hitori tabi) | Traveling alone: a journey taken by yourself. | 一人旅 + 旅 seal | White, Beige, Black |
| **Fudōshin** | 不動心 (fudōshin) | The unmoving mind: calm that does not shake under pressure. A core idea in Japanese martial arts. | 不動心 + 心 seal | Black, Navy, White |
| **Ryū** | 龍 (ryū) | Dragon. In Japan, a symbol of power, wisdom and water. | 龍 + 武 seal | Black, White, Chocolate |
| **Gekka** | 月下 (gekka) | Beneath the moon. | Front print instead of embroidery | Black, White, Bottle green |
| **Ichigo Ichie** | 一期一会 (ichigo ichie) | One time, one meeting: treasure every encounter, because it will never happen the same way again. | 一期一会 + 会 seal | Black, White, Navy |

## Files

| Folder | What | Use |
|---|---|---|
| `dtf/<design>-light.svg` / `-dark.svg` | **Final DTF back prints as vectors**: flat paths, 2 inks, no effects, 14×21 in. Specks under 0.6 mm removed | Send to the DTF printer. Opens in CorelDRAW, Illustrator, Inkscape and RIPs |
| `dtf/<design>-chest-<mode>.svg` | Final DTF chest print as vectors, true size | DTF chest print if you skip embroidery |
| `dtf/<design>-sleeve-<mode>.svg` / `-nape-` | Sleeve and nape prints as vectors, true size | Small DTF transfers (see placement chart) |
| `print/<design>-light.png` / `-dark.png` | Back print, 4200×6300 px transparent (14×21 in @ 300 dpi) | Send to DTF printer. **light** = black ink for white/beige/lavender tees; **dark** = off-white ink for black/navy/green/chocolate tees |
| `print/<design>-embroidery-<mode>.png` | Flat embroidery art, 2.2 in wide @ 300 dpi | Reference for the digitizer |
| `svg/<design>-embroidery-<mode>.svg` | Vector embroidery art, text outlined | **Send this to the digitizer** for DST/PES |
| `print/<design>-chest-<mode>.png` | Textured chest art | DTF chest print if you skip embroidery |
| `svg/*.svg` | Editable sources (brush texture made with SVG filters, which only browsers/Inkscape show) | Edit, then rebuild `dtf/` |
| `mockups/<design>-<colour>-{back,front,closeup}.jpg` | Mockups on your blank tee photos | Etsy/Instagram listing photos |
| `gallery/` | Interactive preview page | Review and pick designs |
| `src/` | Generator scripts | Edit text and colours, then rebuild |

## Print placements

| Placement | Max size | Position | Used by |
|---|---|---|---|
| Full back (collar to hem) | Up to 12 × 24 in | 1–1.5 in below the back collar, centred | Hitori Tabi, Fudōshin, Ichigo Ichie |
| Center back | 12 × 16 in to 14 × 21 in | 2–3 in below the back collar, centred | Ronin, Duel, Bushido, Ittō Ryōdan, Samurai, Chiru Sakura, Torii, Ryū |
| Full front | Up to 12 × 12 in | 3 in below the front collar, centred | Gekka |
| Left chest | 2.2–4 in wide | 7–9 in below HPS, centre ~4 in left of centre front | All designs with embroidery |
| Sleeve | 1.5 × 3–4.5 in, vertical | Outer sleeve, centred, ~1 in above the hem | Hitori Tabi, Fudōshin, Gekka, Ichigo Ichie |
| Nape | 1.7–2.4 × 1.1 in | 0.5–1 in below the back collar, centred | Torii, Ryū, Gekka |

Per design: **Ronin**: Center back 14×21 in, Left chest embroidery 2.2×3.3 in; **The Duel**: Center back 14×21 in, Left chest embroidery 2.2×3.3 in; **Bushido**: Center back 14×21 in, Left chest embroidery 2.2×4.3 in; **Ittō Ryōdan**: Center back 14×21 in, Left chest embroidery 2.2×4.3 in; **Samurai**: Center back 14×21 in, Left chest embroidery 2.2×2.6 in; **Chiru Sakura**: Center back 14×21 in, Left chest embroidery 2.2×4.3 in; **Torii**: Center back 12×16 in, Left chest embroidery 2.2×3.3 in, Nape print 2.4×1.1 in; **Hitori Tabi**: Full back, collar to hem 12×24 in, Left chest embroidery 2.2×4.3 in, Sleeve print 1.5×4.1 in; **Fudōshin**: Full back, collar to hem 12×24 in, Left chest embroidery 2.2×4.3 in, Sleeve print 1.5×4.1 in; **Ryū**: Center back 12×18 in, Left chest embroidery 2.2×2.6 in, Nape print 1.7×1.1 in; **Gekka**: Full front 12×12 in, Sleeve print 1.5×3 in, Nape print 2.4×1.1 in; **Ichigo Ichie**: Full back, collar to hem 12×24 in, Left chest embroidery 2.2×4.3 in, Sleeve print 1.5×4.4 in.

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

### Torii · 鳥居

**Title:** Torii Gate Samurai Oversized Tee, Red Sun Japanese Back Print, Ronin Shrine Streetwear Shirt, Embroidered Kanji Gift

**Tags:** torii gate shirt, samurai shirt, japanese streetwear, red sun shirt, ronin t shirt, kanji shirt, oversized tee, shrine gate tee, japan travel gift, japanese art tee, minimalist japan, martial arts gift, japan lover gift

**Description:**

```
Where the path begins. A lone ronin waits beneath a torii gate, set against a huge hand-brushed red sun.

• Heavyweight oversized fit, drop shoulders
• Center-back DTF print (12 × 16 in) + small 鳥居 nape print under the collar
• Embroidered 鳥居 on the left chest
• Real Japanese, checked for meaning: 鳥居 = shrine gate
```

### Hitori Tabi · 一人旅

**Title:** Hitori Tabi Samurai Oversized Tee, Full Length Back Print, Red Sun Mountain Japanese Streetwear, Embroidered Kanji Shirt

**Tags:** samurai shirt, japanese streetwear, mountain red sun, full back print, oversized tee, kanji shirt, solo travel shirt, wanderer t shirt, japanese art tee, minimalist tee, hiking gift, ronin t shirt, japan lover gift

**Description:**

```
The long road alone. A collar-to-hem back print: ink mountains under a red sun, and far below, a lone swordsman in red walking toward them.

• Heavyweight oversized fit, drop shoulders
• Full-length DTF back print (12 × 24 in), collar to hem
• 一人旅 kanji on the sleeve + embroidered on the left chest
• Real Japanese, checked for meaning: 一人旅 = traveling alone
```

### Fudōshin · 不動心

**Title:** Fudoshin Samurai Oversized Tee, Red Beam Full Back Print, Minimalist Japanese Streetwear, Martial Arts Mindset Shirt

**Tags:** samurai shirt, fudoshin shirt, japanese streetwear, full back print, oversized tee, kanji shirt, martial arts gift, minimalist tee, red line shirt, mindset shirt, karate gift, stoic shirt, black samurai tee

**Description:**

```
Stay still. Strike once. A red beam runs from the collar down onto a lone samurai, outlined only by a red rim of light.

• Heavyweight oversized fit, drop shoulders
• Full-length DTF back print (12 × 24 in), starting at the collar
• 不動心 kanji on the sleeve + embroidered on the left chest
• Real Japanese, checked for meaning: 不動心 = the unmoving mind
```

### Ryū · 龍

**Title:** Dragon Samurai Oversized Tee, Ryu Red Sun Back Print, Ronin Reflection Japanese Streetwear, Embroidered Dragon Kanji

**Tags:** dragon samurai tee, ryu shirt, japanese dragon, samurai shirt, red sun shirt, japanese streetwear, oversized tee, kanji shirt, ronin t shirt, anime aesthetic, dragon gift, japanese art tee, japan lover gift

**Description:**

```
The dragon within. A ronin stands on still water while a dragon coils through the red sun behind him, cut out so the shirt shows through.

• Heavyweight oversized fit, drop shoulders
• Center-back DTF print (12 × 18 in) + small 龍 nape print under the collar
• Embroidered 龍 on the left chest
• Real Japanese, checked for meaning: 龍 = dragon
```

### Gekka · 月下

**Title:** Samurai Under the Moon Oversized Tee, Gekka Front Print, Japanese Kanji Sleeve Print, Minimalist Samurai Streetwear Shirt

**Tags:** samurai moon shirt, samurai shirt, japanese streetwear, front print tee, oversized tee, kanji sleeve print, red moon shirt, samurai silhouette, japanese art tee, minimalist tee, martial arts gift, anime aesthetic, japan lover gift

**Description:**

```
Beneath the moon. A samurai raises his blade on a grassy hill under a huge moon: a front print with kanji on the sleeve and under the back collar.

• Heavyweight oversized fit, drop shoulders
• Front DTF print (12 × 12 in)
• 月下 sleeve print + nape print under the back collar
• Real Japanese, checked for meaning: 月下 = beneath the moon
```

### Ichigo Ichie · 一期一会

**Title:** Ichigo Ichie Samurai Oversized Tee, Red Kanji Banner Full Back Print, Japanese Proverb Streetwear, Embroidered Kanji

**Tags:** ichigo ichie shirt, samurai shirt, japanese proverb, kanji shirt, full back print, japanese streetwear, oversized tee, red kanji shirt, ronin t shirt, meaningful tee, japanese art tee, martial arts gift, japan lover gift

**Description:**

```
One life, one chance. A red brush banner hangs from the collar with 一期一会 cut out of it, above a ronin resting on a red katana.

• Heavyweight oversized fit, drop shoulders
• Full-length DTF back print (12 × 24 in), banner starting at the collar
• 一期一会 sleeve print + embroidered on the left chest
• Real Japanese, checked for meaning: 一期一会 = one time, one meeting
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
node render.mjs             # every piece to ../print at 300 dpi of its real print size
python3 vectorize.py        # flat DTF vector SVGs in ../dtf (needs potrace, Pillow, numpy)
python3 mockups.py          # placement mockups + gallery assets (Pillow, numpy)
node gallery.mjs            # gallery page + this README
```

Rendering uses Playwright's Chromium. Kanji come from Yuji Boku / Yuji Syuku and Latin text from Bebas Neue (all SIL Open Font License, subset in `src/fonts`). To add a new character, re-download the font subset with it included; `build.mjs` outlines whatever the font contains.
