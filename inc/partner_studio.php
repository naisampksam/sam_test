<?php
// Design Studio for partner products (the Looma Apparels website's "Design your Product" studio, assets/studio/):
// real T-shirt photos per colour, print zones and fonts. Each T-shirt of Looma's catalogue is matched to its photo
// set; colours without their own photo use the nearest colour's photo.
declare(strict_types=1);

/** Photo sets of the studio: id => [colour => hex] (photos: assets/studio/tees/{id}-{colour-slug}[-back].jpg). */
const STUDIO_TEES = [
    'oversized-tee-250-gsm-french-terry' => ['Black' => '#131313', 'White' => '#F2EAEA', 'Beige' => '#EBE6D4', 'Navy Blue' => '#1F273A', 'Royal Blue' => '#083D8A',
        'Lavender' => '#684BA2', 'Red' => '#C52E2E', 'Green' => '#24572A', 'Brown' => '#79472D'],
    'acid-wash-oversized-tee-250-gsm' => ['Black' => '#313131', 'Green' => '#24572A', 'Royal Blue' => '#083D8A'],
    'full-sleeve-oversized-tee-250-gsm' => ['Black' => '#131313'],
    'oversized-tee-230-gsm' => ['Black' => '#131313', 'White' => '#F2EAEA'],
    'oversized-tee-190-gsm' => ['Black' => '#131313', 'White' => '#F2EAEA'],
    'regular-fit-tee-190-gsm' => ['Black' => '#000000', 'White' => '#FFFFFF', 'Red' => '#DB0C0C'],
];

/** Print zones on the 600 × 680 photo: [centre x, centre y, width, height, rotation]. */
const STUDIO_ZONES = [
    'oversized-tee-250-gsm-french-terry' => ['front' => [300, 375, 300, 420, 0], 'back' => [300, 357, 300, 450, 0]],
    'acid-wash-oversized-tee-250-gsm' => ['front' => [300, 378, 300, 420, 0], 'back' => [300, 357, 300, 450, 0]],
    'full-sleeve-oversized-tee-250-gsm' => ['front' => [300, 375, 300, 420, 0], 'back' => [300, 357, 300, 450, 0]],
    'oversized-tee-230-gsm' => ['front' => [300, 375, 300, 420, 0], 'back' => [300, 357, 300, 450, 0]],
    'oversized-tee-190-gsm' => ['front' => [300, 375, 300, 420, 0], 'back' => [300, 357, 300, 450, 0]],
    'regular-fit-tee-190-gsm' => ['front' => [300, 372, 290, 415, 0], 'back' => [300, 350, 290, 445, 0]],
];

/** Which photo set fits a catalogue T-shirt (by its name and GSM). */
function studio_tee_id(string $gsm, string $name): string
{
    $n = strtolower($name);
    $g = (int)preg_replace('/\D+/', '', $gsm);
    return match (true) {
        str_contains($n, 'acid') => 'acid-wash-oversized-tee-250-gsm',
        (bool)preg_match('/full\s*-?\s*sleeve/', $n) => 'full-sleeve-oversized-tee-250-gsm',
        str_contains($n, 'regular') => 'regular-fit-tee-190-gsm',
        str_contains($n, 'terry') || $g >= 245 => 'oversized-tee-250-gsm-french-terry',
        $g >= 210 => 'oversized-tee-230-gsm',
        default => 'oversized-tee-190-gsm',
    };
}

function studio_slug(string $s): string
{
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}

/** Studio colour name for a catalogue colour: same name, else the nearest colour by hex, else the first. */
function studio_tee_colour(string $id, string $name, string $hex): string
{
    $set = STUDIO_TEES[$id];
    foreach ($set as $c => $h) {
        if (studio_slug($c) === studio_slug($name)) {
            return $c;
        }
    }
    if (preg_match('/^#?([0-9a-f]{6})$/i', $hex, $m)) {
        $rgb = fn(string $x) => array_map('hexdec', str_split(ltrim($x, '#'), 2));
        [$r, $g, $b] = $rgb($m[1]);
        $best = null;
        $bd = PHP_INT_MAX;
        foreach ($set as $c => $h) {
            [$r2, $g2, $b2] = $rgb($h);
            $d = ($r - $r2) ** 2 + ($g - $g2) ** 2 + ($b - $b2) ** 2;
            if ($d < $bd) {
                $bd = $d;
                $best = $c;
            }
        }
        return (string)$best;
    }
    return (string)array_key_first($set);
}

/** Photos whose colour differs from Looma's real fabric: recoloured to the real one (Brown is a chocolate brown). */
const STUDIO_PHOTO_FIX = ['brown' => '#A4441C'];

/** Colours known by name, for a catalogue colour saved without its hex. */
const STUDIO_NAMED = ['chocolate' => '#A4441C', 'brown' => '#A4441C', 'maroon' => '#5E1A22', 'olive' => '#5D6236', 'bottle green' => '#1F3B2B', 'mustard' => '#D9A21B',
    'sky blue' => '#8EC3E6', 'grey' => '#9A9A9A', 'gray' => '#9A9A9A', 'charcoal' => '#3A3B3D', 'cream' => '#EFE6D2', 'pink' => '#E7A6B8',
    'yellow' => '#E8C33A', 'orange' => '#E2662A', 'purple' => '#5B3A7A', 'navy' => '#1F273A', 'beige' => '#EBE6D4'];

/** Hex of a catalogue colour: its own, else by its name, else ''. */
function studio_hex(string $name, string $hex): string
{
    if (preg_match('/^#?([0-9a-f]{6})$/i', trim($hex), $m)) {
        return '#' . strtoupper($m[1]);
    }
    $n = strtolower(trim($name));
    foreach (STUDIO_NAMED as $k => $h) {
        if ($n === $k || str_contains($n, $k)) {
            return $h;
        }
    }
    return '';
}

/**
 * Configuration for assets/studio/studio.js: every catalogue T-shirt with its colours' photos and print zones.
 * A colour with its own photo uses it; any other colour (e.g. Chocolate) is the lightest photo of that T-shirt,
 * recoloured in the browser to its hex ('tint'), so every colour looks like itself.
 */
function studio_config(): array
{
    $products = [];
    foreach (catalog() as $g) {
        foreach ($g['products'] as $pr) {
            if (!$pr['colors']) {
                continue;
            }
            $id = studio_tee_id($g['gsm'], $pr['name']);
            $set = STUDIO_TEES[$id];
            $lightest = (string)array_reduce(array_keys($set), fn($a, $c) => $a === null || studio_lum($set[$c]) > studio_lum($set[$a]) ? $c : $a);
            $colours = [];
            foreach ($pr['colors'] as $c) {
                $hex = studio_hex($c['name'], (string)$c['hex']);
                $own = null;
                foreach ($set as $sc => $h) {
                    if (studio_slug($sc) === studio_slug($c['name'])) {
                        $own = $sc;
                    }
                }
                $sc = $own ?? ($hex !== '' ? $lightest : studio_tee_colour($id, $c['name'], ''));
                $file = 'assets/studio/tees/' . $id . '-' . studio_slug($sc);
                $to = '';
                if ($own === null) {
                    $to = $hex;
                } elseif ($hex !== '' && studio_dist($hex, $set[$own]) > 48) {
                    $to = $hex; // the catalogue colour is clearly not the photo's: recolour the photo itself
                } elseif (isset(STUDIO_PHOTO_FIX[strtolower($own)])) {
                    $to = STUDIO_PHOTO_FIX[strtolower($own)];
                    $hex = $hex !== '' && studio_dist($hex, $set[$own]) > 48 ? $hex : $to;
                }
                $tint = $to !== '' ? '#tint=' . ltrim($to, '#') : '';
                $colours[] = ['name' => $c['name'], 'hex' => $hex !== '' ? $hex : $set[$sc],
                    'front' => asset($file . '.jpg') . $tint, 'back' => asset($file . '-back.jpg') . $tint];
            }
            $products[] = ['id' => $g['gsm'] . '|' . $pr['name'], 'name' => $pr['name'], 'spec' => $g['gsm'], 'gsm' => $g['gsm'],
                'colours' => $colours, 'display' => $colours[0]['name'], 'zones' => STUDIO_ZONES[$id], 'prices' => [['min' => 1, 'price' => 0]]];
        }
    }
    return ['products' => $products, 'sizes' => [], 'ppi' => 18.2, 'embroidery' => [['min' => 1, 'rate' => 0]],
        'pdfjs' => base_url('assets/studio/pdfjs/'), 'fonts' => STUDIO_FONTS];
}

function studio_dist(string $a, string $b): float
{
    [$r, $g, $bl] = array_map('hexdec', str_split(ltrim($a, '#'), 2));
    [$r2, $g2, $b2] = array_map('hexdec', str_split(ltrim($b, '#'), 2));
    return sqrt(($r - $r2) ** 2 + ($g - $g2) ** 2 + ($bl - $b2) ** 2);
}

function studio_lum(string $hex): float
{
    [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    return 0.299 * $r + 0.587 * $g + 0.114 * $b;
}

/** Fonts for text (self-hosted in assets/studio/fonts, loaded only when used). */
const STUDIO_FONTS = 
    array (
      0 => 
      array (
        'f' => 'Anton',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Bold & display',
      ),
      1 => 
      array (
        'f' => 'Bebas Neue',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Bold & display',
      ),
      2 => 
      array (
        'f' => 'Oswald',
        'w' => 
        array (
          0 => 400,
          1 => 700,
        ),
        'g' => 'Bold & display',
      ),
      3 => 
      array (
        'f' => 'Archivo Black',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Bold & display',
      ),
      4 => 
      array (
        'f' => 'Alfa Slab One',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Bold & display',
      ),
      5 => 
      array (
        'f' => 'Bowlby One',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Bold & display',
      ),
      6 => 
      array (
        'f' => 'Bungee',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Bold & display',
      ),
      7 => 
      array (
        'f' => 'Russo One',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Bold & display',
      ),
      8 => 
      array (
        'f' => 'Staatliches',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Bold & display',
      ),
      9 => 
      array (
        'f' => 'Teko',
        'w' => 
        array (
          0 => 500,
          1 => 700,
        ),
        'g' => 'Bold & display',
      ),
      10 => 
      array (
        'f' => 'Big Shoulders Display',
        'w' => 
        array (
          0 => 700,
          1 => 900,
        ),
        'g' => 'Bold & display',
      ),
      11 => 
      array (
        'f' => 'Rubik Mono One',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Bold & display',
      ),
      12 => 
      array (
        'f' => 'Black Ops One',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Bold & display',
      ),
      13 => 
      array (
        'f' => 'Archivo',
        'w' => 
        array (
          0 => 700,
          1 => 900,
        ),
        'g' => 'Bold & display',
      ),
      14 => 
      array (
        'f' => 'Inter',
        'w' => 
        array (
          0 => 400,
          1 => 600,
        ),
        'g' => 'Clean sans',
      ),
      15 => 
      array (
        'f' => 'Montserrat',
        'w' => 
        array (
          0 => 400,
          1 => 700,
          2 => 900,
        ),
        'g' => 'Clean sans',
      ),
      16 => 
      array (
        'f' => 'Poppins',
        'w' => 
        array (
          0 => 400,
          1 => 700,
          2 => 900,
        ),
        'g' => 'Clean sans',
      ),
      17 => 
      array (
        'f' => 'Kanit',
        'w' => 
        array (
          0 => 400,
          1 => 700,
        ),
        'g' => 'Clean sans',
      ),
      18 => 
      array (
        'f' => 'Josefin Sans',
        'w' => 
        array (
          0 => 400,
          1 => 700,
        ),
        'g' => 'Clean sans',
      ),
      19 => 
      array (
        'f' => 'Raleway',
        'w' => 
        array (
          0 => 400,
          1 => 800,
        ),
        'g' => 'Clean sans',
      ),
      20 => 
      array (
        'f' => 'Playfair Display',
        'w' => 
        array (
          0 => 400,
          1 => 700,
          2 => 900,
        ),
        'g' => 'Serif',
      ),
      21 => 
      array (
        'f' => 'Abril Fatface',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Serif',
      ),
      22 => 
      array (
        'f' => 'Cinzel',
        'w' => 
        array (
          0 => 400,
          1 => 700,
        ),
        'g' => 'Serif',
      ),
      23 => 
      array (
        'f' => 'Roboto Slab',
        'w' => 
        array (
          0 => 400,
          1 => 700,
        ),
        'g' => 'Serif',
      ),
      24 => 
      array (
        'f' => 'DM Serif Display',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Serif',
      ),
      25 => 
      array (
        'f' => 'Merriweather',
        'w' => 
        array (
          0 => 400,
          1 => 900,
        ),
        'g' => 'Serif',
      ),
      26 => 
      array (
        'f' => 'Pacifico',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Script & handwritten',
      ),
      27 => 
      array (
        'f' => 'Dancing Script',
        'w' => 
        array (
          0 => 400,
          1 => 700,
        ),
        'g' => 'Script & handwritten',
      ),
      28 => 
      array (
        'f' => 'Great Vibes',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Script & handwritten',
      ),
      29 => 
      array (
        'f' => 'Lobster',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Script & handwritten',
      ),
      30 => 
      array (
        'f' => 'Satisfy',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Script & handwritten',
      ),
      31 => 
      array (
        'f' => 'Caveat',
        'w' => 
        array (
          0 => 400,
          1 => 700,
        ),
        'g' => 'Script & handwritten',
      ),
      32 => 
      array (
        'f' => 'Allura',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Script & handwritten',
      ),
      33 => 
      array (
        'f' => 'Permanent Marker',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Script & handwritten',
      ),
      34 => 
      array (
        'f' => 'Kaushan Script',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Script & handwritten',
      ),
      35 => 
      array (
        'f' => 'Sacramento',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Script & handwritten',
      ),
      36 => 
      array (
        'f' => 'Yellowtail',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Script & handwritten',
      ),
      37 => 
      array (
        'f' => 'Bangers',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Retro & fun',
      ),
      38 => 
      array (
        'f' => 'Righteous',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Retro & fun',
      ),
      39 => 
      array (
        'f' => 'Press Start 2P',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Retro & fun',
      ),
      40 => 
      array (
        'f' => 'Orbitron',
        'w' => 
        array (
          0 => 400,
          1 => 900,
        ),
        'g' => 'Retro & fun',
      ),
      41 => 
      array (
        'f' => 'Audiowide',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Retro & fun',
      ),
      42 => 
      array (
        'f' => 'Shrikhand',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Retro & fun',
      ),
      43 => 
      array (
        'f' => 'Fredoka',
        'w' => 
        array (
          0 => 400,
          1 => 700,
        ),
        'g' => 'Retro & fun',
      ),
      44 => 
      array (
        'f' => 'Luckiest Guy',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Retro & fun',
      ),
      45 => 
      array (
        'f' => 'Creepster',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Retro & fun',
      ),
      46 => 
      array (
        'f' => 'UnifrakturMaguntia',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Gothic & street',
      ),
      47 => 
      array (
        'f' => 'Pirata One',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Gothic & street',
      ),
      48 => 
      array (
        'f' => 'Metal Mania',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Gothic & street',
      ),
      49 => 
      array (
        'f' => 'Grenze Gotisch',
        'w' => 
        array (
          0 => 400,
          1 => 900,
        ),
        'g' => 'Gothic & street',
      ),
      50 => 
      array (
        'f' => 'Rock Salt',
        'w' => 
        array (
          0 => 400,
        ),
        'g' => 'Gothic & street',
      ),
      51 => 
      array (
        'f' => 'Baloo Chettan 2',
        'w' => 
        array (
          0 => 400,
          1 => 700,
        ),
        'g' => 'Malayalam',
      ),
      52 => 
      array (
        'f' => 'Noto Sans Malayalam',
        'w' => 
        array (
          0 => 400,
          1 => 700,
        ),
        'g' => 'Malayalam',
      ),
    );
