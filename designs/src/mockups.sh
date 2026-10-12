#!/usr/bin/env bash
# Builds mockups on the blank tee photos in the repo root, plus web assets for the gallery page.
# Needs ImageMagick (convert/composite). Run after render.mjs.
#   designs/mockups/<design>-<colour>-back.jpg     back print on the tee
#   designs/mockups/<design>-<colour>-front.jpg    chest embroidery on the tee
#   designs/mockups/<design>-<colour>-closeup.jpg  embroidery close-up with thread texture
set -euo pipefail
cd "$(dirname "$0")/../.."
ROOT=$PWD
PRINT=designs/print
OUT=designs/mockups
WEB=designs/gallery/assets
mkdir -p "$OUT" "$WEB"
TMP=$(mktemp -d)

DESIGNS="ronin duel bushido ittoryodan samurai sakura"
# colour | back blank | front blank | ink mode
COLOURS="white|white back.PNG|whicte front.PNG|light
beige|beige back.PNG|beige front.PNG|light
lavender|lavender back.PNG|lavender front.PNG|light
black|back back.PNG|black front.PNG|dark
bottle-green|bottle green back .PNG|bottle green front.PNG|dark
chocolate|chocolate back.PNG|chocolate front.PNG|dark
navy|navy back.PNG|royal blue front .PNG|dark"

# Placement on the 1024x1536 blanks (~23.7 px per inch of shirt).
BACK_W=332; BACK_X=346; BACK_Y=318      # 14x21 in back print
CHEST_W=52; CHEST_X=593; CHEST_Y=440     # 2.2 in wide left-chest piece
CLOSE_X=520; CLOSE_Y=400; CLOSE=180; ZOOM=4

thread() { # $1 in.png $2 out.png : diagonal satin texture + slight raised shadow
  local w h; w=$(identify -format '%w' "$1"); h=$(identify -format '%h' "$1")
  convert -size "${w}x${h}" tile:pattern:left45 -blur 0x0.7 -level 0%,100% +level 82%,100% "$TMP/tex.png"
  convert "$1" \( "$TMP/tex.png" \) -compose Multiply -composite \( "$1" -alpha extract \) -compose CopyOpacity -composite "$TMP/t.png"
  convert "$TMP/t.png" \( +clone -background black -shadow 75x2+2+3 \) +swap -background none -layers merge +repage "$2"
}

while IFS='|' read -r colour back front mode; do
  convert "$back" -resize 600x900 -quality 80 "$WEB/blank-$colour-back.jpg"
  convert "$front" -resize 600x900 -quality 80 "$WEB/blank-$colour-front.jpg"
  for d in $DESIGNS; do
    convert "$PRINT/$d-$mode.png" -resize ${BACK_W}x "$TMP/b.png"
    composite -geometry +$BACK_X+$BACK_Y "$TMP/b.png" "$back" -quality 86 "$OUT/$d-$colour-back.jpg"

    convert "$PRINT/$d-embroidery-$mode.png" -resize ${CHEST_W}x "$TMP/c.png"
    composite -geometry +$CHEST_X+$CHEST_Y "$TMP/c.png" "$front" -quality 86 "$OUT/$d-$colour-front.jpg"

    # close-up: crop the chest area, enlarge it and lay the textured embroidery on top
    convert "$front" -crop ${CLOSE}x${CLOSE}+$CLOSE_X+$CLOSE_Y +repage -resize $((CLOSE * ZOOM))x "$TMP/crop.png"
    convert "$PRINT/$d-embroidery-$mode.png" -resize $((CHEST_W * ZOOM))x "$TMP/ce.png"
    thread "$TMP/ce.png" "$TMP/ct.png"
    composite -geometry +$(((CHEST_X - CLOSE_X) * ZOOM))+$(((CHEST_Y - CLOSE_Y) * ZOOM)) "$TMP/ct.png" "$TMP/crop.png" -quality 86 "$OUT/$d-$colour-closeup.jpg"
  done
done <<< "$COLOURS"

# Overlay art for the gallery (placed over the blanks with CSS).
for d in $DESIGNS; do
  for mode in light dark; do
    convert "$PRINT/$d-$mode.png" -resize 600x "$WEB/$d-$mode.png"
    convert "$PRINT/$d-embroidery-$mode.png" -resize 200x "$TMP/e.png"
    thread "$TMP/e.png" "$WEB/$d-embroidery-$mode.png"
  done
done
rm -r "$TMP"
echo "mockups: $(ls "$OUT" | wc -l) files; gallery assets: $(ls "$WEB" | wc -l) files"
