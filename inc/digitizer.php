<?php
/**
 * Image -> embroidery (Tajima .DST) auto-digitizer, object based.
 *
 * Pipeline:
 *   load image -> scale to real size (mm) -> reduce to N thread colors (Lab)
 *   -> clean up speckles -> split every color into separate shapes (objects)
 *   -> per object pick the stitch type a digitizer would use:
 *        thin line   -> triple (bean) running stitch along its centre line
 *        narrow shape-> satin column that turns with the shape (+ centre/zigzag underlay)
 *        wide area   -> tatami fill (+ edge-run and tatami underlay)
 *   -> order objects nearest-first, travel inside shapes, lock stitches, trims
 *   -> remove too-short stitches -> encode as DST.
 *
 * All internal coordinates are in millimetres; DST stores 0.1 mm units.
 */

class Digitizer
{
    private array $o;          // options
    private int $w, $h;        // working grid size (pixels)
    private float $px;         // mm per grid pixel
    private string $labels;    // one byte per pixel: color index, 255 = background
    private array $colors = []; // [[r,g,b], ...]
    private array $stitches = []; // [x, y, type] type: S=stitch, J=jump, T=trim, C=color change
    private ?array $pos = null;  // current needle position (mm)
    public array $order = [];    // color indexes in sewing order
    public array $stats = [];
    private array $objects = ['fill' => 0, 'satin' => 0, 'run' => 0, 'tiny' => 0];

    public static function defaults(): array
    {
        return [
            'width_mm'      => 100,   // final design width
            'height_mm'     => 0,     // 0 = keep proportions; otherwise stretch to exactly this height
            'colors'        => 6,     // number of thread colors (auto palette)
            'palette'       => '',    // optional fixed thread colors "#ff0000,#000000"
            'remove_bg'     => 1,     // drop the background (corner) color
            'cleanup'       => 2,     // speckle cleanup passes
            // defaults measured from professional DST files (thread per area, satin & fill geometry)
            'density'       => 0.25,  // fill row spacing (mm)
            'satin_density' => 0.40,  // satin: needle spacing along each edge (0.2 mm per stitch)
            'stitch_len'    => 4.2,   // max tatami stitch length (mm) - pro files: 4.17-4.19
            'satin_max'     => 7.0,   // shapes up to this wide become satin columns (mm)
            'satin_split'   => 5.0,   // satin stitches longer than this are split (staggered) – no loose loops
            'auto_angle'    => 1,     // fill angle follows each shape (rows run along its length)
            'run_max'       => 1.0,   // lines thinner than this become running stitch (mm)
            'run_len'       => 1.6,   // running stitch length (mm)
            'run_triple'    => 1,     // bold triple (bean) running stitch
            'angle'         => 45,    // fill angle (deg)
            'underlay'      => 1,     // underlay on/off
            'underlay_gap'  => 2.0,   // tatami underlay row spacing (mm)
            'inset'         => 0.4,   // underlay inset from edges (mm)
            'pull_comp'     => 0.2,   // pull compensation added to each side (mm)
            'min_run'       => 0.6,   // ignore fill fragments thinner than this (mm)
            'detail'        => 2.0,   // smallest shape kept (mm²); smaller blobs merge into neighbours, lines are kept
            'coverage'      => 100,   // stitch volume %: 150 = 1.5x more rows (denser)
            'layers'        => 1,     // fill layers: 2 = second cross-hatched fill on top
            'overlap'       => 0.3,   // colors overlap each other by this much (mm) - no gaps
            'min_stitch'    => 0.3,   // drop stitches shorter than this (mm) - prevents thread breaks
            'trim_at'       => 2.0,   // trim when a jump is longer than this (mm)
            'lock'          => 1,     // tie-in / tie-off stitches
            'details_last'  => 1,     // fills first, then details/outlines on top (colors may repeat)
            'zones'         => '',    // marked areas with their own fixes (JSON from the page, see zoneOptions())
            'skip'          => '',    // colors to leave out, e.g. "#ffffff,#a0a0a0" (matched to the nearest design color)
            'label'         => 'DESIGN',
            'no_crop'       => 0,     // use the whole picture (no cutting of empty margins) – for re-digitizing DST files
            'only_zones'    => 0,     // stitch only what is inside the marked areas (redo part of an imported DST)
        ];
    }

    public function __construct(array $opts)
    {
        $o = array_merge(self::defaults(), $opts);
        $o['width_mm']     = max(10, min(400, (float)$o['width_mm']));
        $o['height_mm']    = (float)$o['height_mm'] > 0 ? max(5, min(400, (float)$o['height_mm'])) : 0;
        $o['colors']       = max(1, min(30, (int)$o['colors']));
        $o['density']      = max(0.15, min(3, (float)$o['density']));
        $o['stitch_len']   = max(1, min(12, (float)$o['stitch_len']));
        $o['satin_max']    = max(0, min(12, (float)$o['satin_max']));
        $o['satin_split']  = max(2.5, min(7, (float)$o['satin_split']));
        $o['run_max']      = max(0, min(3, (float)$o['run_max']));
        $o['run_len']      = max(1, min(5, (float)$o['run_len']));
        $o['underlay_gap'] = max(0.5, min(6, (float)$o['underlay_gap']));
        $o['coverage']     = max(50, min(300, (float)$o['coverage']));
        $o['layers']       = max(1, min(3, (int)$o['layers']));
        $o['overlap']      = max(0, min(2, (float)$o['overlap']));
        foreach (['angle', 'inset', 'pull_comp', 'min_run', 'min_stitch', 'trim_at'] as $k) $o[$k] = (float)$o[$k];
        $this->o = self::derive($o);
        $this->zones = self::parseZones((string)$o['zones']);
    }

    /** Values that follow from the settings: effective fill row spacing and satin step. */
    private static function derive(array $o): array
    {
        $o['spacing']    = max(0.12, $o['density'] * 100 / $o['coverage']);       // higher coverage -> denser rows
        $o['satin_step'] = max(0.1, (float)$o['satin_density'] / 2 * 100 / $o['coverage']);
        return $o;
    }

    /* ---------- marked areas ("fix this area") ---------- */

    private array $zones = [];        // [['pts' => [[x 0..1, y 0..1], …], 'fix' => [...]], …]
    private array $zoneMasks = [];    // per zone: one byte per grid pixel
    private string $detailMask = '';  // pixels in areas marked "small details lost"
    public array $shapes = [];        // what was made, for the page's suggestions
    public float $aspect = 1.0;       // width : height of the artwork's content

    public static function parseZones(string $json): array
    {
        $z = json_decode($json, true);
        if (!is_array($z)) return [];
        $out = [];
        foreach (array_slice($z, 0, 12) as $zone) {
            $pts = [];
            foreach (array_slice((array)($zone['pts'] ?? []), 0, 400) as $p)
                if (is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1]))
                    $pts[] = [max(-0.1, min(1.1, (float)$p[0])), max(-0.1, min(1.1, (float)$p[1]))];
            if (count($pts) < 3) continue;
            $fix = [];
            foreach (['messy', 'details', 'thick', 'gaps', 'rough', 'remove'] as $k) if (!empty($zone['fix'][$k])) $fix[$k] = 1;
            if (isset($zone['fix']['dir']) && is_numeric($zone['fix']['dir'])) $fix['dir'] = fmod((float)$zone['fix']['dir'], 180);
            if (in_array($zone['fix']['type'] ?? '', ['fill', 'satin', 'straight', 'run'], true)) $fix['type'] = $zone['fix']['type'];
            $out[] = ['pts' => $pts, 'fix' => $fix];
        }
        return $out;
    }

    /** Settings for shapes inside a marked area: the user's complaint turned into stitch settings. */
    private function zoneOptions(array $fix): array
    {
        $o = $this->o;
        if (!empty($fix['messy']))   { $o['compact_any'] = 1; $o['satin_split'] = min($o['satin_split'], 4.0); }
        if (!empty($fix['details'])) { $o['detail'] = 0.3; $o['min_run'] = 0.3; $o['run_max'] = min($o['run_max'], 0.6); $o['min_stitch'] = min($o['min_stitch'], 0.25); }
        if (!empty($fix['thick']))   { $o['coverage'] *= 0.85; $o['pull_comp'] = max(0, $o['pull_comp'] - 0.1); $o['underlay_gap'] *= 1.4; }
        if (!empty($fix['gaps']))    { $o['coverage'] *= 1.15; $o['pull_comp'] += 0.15; $o['overlap'] += 0.2; }
        if (!empty($fix['rough']))   { $o['edge_smooth'] = 2; $o['satin_split'] = min($o['satin_split'], 4.0); }
        if (isset($fix['dir']))      { $o['force_dir'] = $fix['dir']; $o['auto_angle'] = 0; $o['angle'] = $fix['dir']; }
        if (isset($fix['type']))     $o['force_type'] = $fix['type'];
        return self::derive($o);
    }

    private function buildZoneMasks(): void
    {
        $this->zoneMasks = []; $this->detailMask = '';
        foreach ($this->zones as $zi => $z) {
            $img = imagecreate($this->w, $this->h);
            imagecolorallocate($img, 0, 0, 0); $on = imagecolorallocate($img, 255, 255, 255);
            $flat = [];
            foreach ($z['pts'] as [$x, $y]) { $flat[] = (int)round($x * $this->w); $flat[] = (int)round($y * $this->h); }
            imagefilledpolygon($img, $flat, $on);
            $m = '';
            for ($y = 0; $y < $this->h; $y++) for ($x = 0; $x < $this->w; $x++) $m .= imagecolorat($img, $x, $y) === $on ? "\1" : "\0";
            imagedestroy($img);
            $this->zoneMasks[$zi] = $m;
            if (!empty($z['fix']['details'])) $this->detailMask = $this->detailMask === '' ? $m : ($this->detailMask | $m);
        }
    }

    /**
     * If a marked area covers only part of a shape (between 2 % and 90 %), cut the shape at the area's edge:
     * returns the pieces (inside pieces carry the area, with a small overlap so no gap shows), else null.
     */
    private function cutByZone(array $r): ?array
    {
        $done = $r['zdone'] ?? [];
        $w = $r['w']; $h = $r['h']; $W = $this->w; $H = $this->h; $len = $w * $h;
        $total = substr_count($r['m'], "\1");
        foreach ($this->zoneMasks as $zi => $zm) {
            if (in_array($zi, $done, true)) continue;
            // the area's mask in the shape's own frame
            $local = '';
            for ($y = 0; $y < $h; $y++) {
                $gy = $r['y0'] + $y;
                if ($gy < 0 || $gy >= $H) { $local .= str_repeat("\0", $w); continue; }
                $gx0 = $r['x0']; $row = '';
                if ($gx0 < 0) { $row = str_repeat("\0", -$gx0); $gx0 = 0; }
                $row .= substr($zm, $gy * $W + $gx0, max(0, min($W, $r['x0'] + $w) - $gx0));
                $local .= str_pad($row, $w, "\0");
            }
            $in = $r['m'] & $local;
            $n = substr_count($in, "\1");
            if ($n === 0 || $n >= 0.9 * $total || $n * $this->px ** 2 < 0.3) continue;   // nothing, (almost) all, or a speck
            if ($n < 0.02 * $total) continue;
            $grow = max(1, (int)round(0.25 / $this->px));                             // overlap at the cut: no gap
            $inside = self::morph($in, $w, $grow, true) & $r['m'];
            $outside = $r['m'] & ~$in; $outside &= str_repeat("\1", $len);
            $pieces = [];
            foreach ($this->components($inside, $w, $h, $r['x0'], $r['y0']) as $p) { $p['zone'] = $zi; $p['zdone'] = array_merge($done, [$zi]); $pieces[] = $p; }
            foreach ($this->components($outside, $w, $h, $r['x0'], $r['y0']) as $p) { $p['zdone'] = array_merge($done, [$zi]); $pieces[] = $p; }
            return $pieces;
        }
        return null;
    }

    /** The marked area that covers most of a shape (at least 40 %), or null. */
    private function zoneOf(array $r): ?int
    {
        if (!$this->zoneMasks) return null;
        $w = $r['w']; $W = $this->w; $H = $this->h; $n = 0; $hit = [];
        for ($i = strpos($r['m'], "\1"); $i !== false; $i = strpos($r['m'], "\1", $i + 1)) {
            if (($n++ & 3) !== 0) continue;                       // every 4th pixel is plenty
            $gx = $r['x0'] + $i % $w; $gy = $r['y0'] + intdiv($i, $w);
            if ($gx < 0 || $gy < 0 || $gx >= $W || $gy >= $H) continue;
            foreach ($this->zoneMasks as $zi => $zm) if ($zm[$gy * $W + $gx] === "\1") $hit[$zi] = ($hit[$zi] ?? 0) + 1;
        }
        if (!$hit) return null;
        arsort($hit); $zi = array_key_first($hit);
        return $hit[$zi] * 4 >= 0.4 * $n ? $zi : null;
    }

    /* ------------------------------------------------------------------ */
    /*  1. Image loading & color reduction                                 */
    /* ------------------------------------------------------------------ */

    public function load(string $file): void
    {
        $src = @imagecreatefromstring(file_get_contents($file));
        if (!$src) throw new RuntimeException('Could not read image. Use PNG, JPG, GIF, WEBP or BMP.');

        if (!$this->o['no_crop']) $src = $this->cropToContent($src);   // width = width of the design itself, not of empty margins
        $sw = imagesx($src); $sh = imagesy($src);
        $this->aspect = $sw / max(1, $sh);              // the design's own proportions (width : height)
        // grid: fine enough for 0.1-0.2 mm edges, capped for speed
        // work grid ("zoom"): 0.08 mm per pixel for small designs, never coarser than 0.2 mm
        $this->px = min(0.2, max($this->o['width_mm'] / 1500, 0.08));
        $this->w = max(2, (int)round($this->o['width_mm'] / $this->px));
        $this->h = max(2, (int)round($this->o['height_mm'] > 0 ? $this->o['height_mm'] / $this->px : $this->w * $sh / $sw));

        $img = imagecreatetruecolor($this->w, $this->h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagecopyresampled($img, $src, 0, 0, 0, 0, $this->w, $this->h, $sw, $sh);
        imagedestroy($src);

        $this->buildZoneMasks();
        $pix = [];
        for ($y = 0; $y < $this->h; $y++) {
            for ($x = 0; $x < $this->w; $x++) {
                $c = imagecolorat($img, $x, $y);
                $pix[] = (($c >> 24) & 0x7F) > 100 ? null : [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
            }
        }
        imagedestroy($img);

        $this->colors = $this->buildPalette($pix);
        $this->assign($pix);
        if (trim($this->o['palette']) === '') $this->mergeTinyColors();
        for ($i = 0; $i < (int)$this->o['cleanup']; $i++) $this->majorityFilter();
        if ($this->o['remove_bg']) $this->removeBackground();
        $this->skipColors();
        $this->removeSlivers();
    }

    /**
     * Professional cleanup: tiny blobs (< 1 mm²) and hair-thin slivers squeezed between two
     * colors (blurry edges) are given to the surrounding color. Thin lines on background stay.
     */
    private function removeSlivers(): void
    {
        $w = $this->w; $h = $this->h; $L = $this->labels; $n = $w * $h;
        $seen = str_repeat("\0", $n);
        $minArea = max(0.3, (float)$this->o['detail']) / ($this->px * $this->px);
        $tinyArea = 0.3 / ($this->px * $this->px);
        $labs = array_map([self::class, 'lab'], $this->colors);
        for ($i = 0; $i < $n; $i++) {
            if ($seen[$i] === "\1" || $L[$i] === "\xFF") continue;
            $c = $L[$i]; $q = [$i]; $seen[$i] = "\1"; $head = 0; $perim = 0; $around = [];
            while ($head < count($q)) {
                $p = $q[$head++]; $x = $p % $w; $y = intdiv($p, $w);
                foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
                    $xx = $x + $dx; $yy = $y + $dy;
                    if ($xx < 0 || $yy < 0 || $xx >= $w || $yy >= $h) { $perim++; $around["\xFF"] = ($around["\xFF"] ?? 0) + 1; continue; }
                    $j = $yy * $w + $xx;
                    if ($L[$j] !== $c) { $perim++; $around[$L[$j]] = ($around[$L[$j]] ?? 0) + 1; }
                }
                // grow over all 8 neighbours so diagonal thin lines stay one piece
                for ($dy = -1; $dy <= 1; $dy++) for ($dx = -1; $dx <= 1; $dx++) {
                    $xx = $x + $dx; $yy = $y + $dy;
                    if ($xx < 0 || $yy < 0 || $xx >= $w || $yy >= $h) continue;
                    $j = $yy * $w + $xx;
                    if ($L[$j] === $c && $seen[$j] === "\0") { $seen[$j] = "\1"; $q[] = $j; }
                }
            }
            $area = count($q);
            if ($this->detailMask !== '' && $this->detailMask[$i] === "\1" && $area >= 0.1 / ($this->px * $this->px)) continue;   // marked "details lost": keep
            if ($area < $minArea && $area >= $tinyArea) {   // small: keep only if it is a line (long & thin)
                $xs = array_map(fn($p) => $p % $w, $q); $ys = array_map(fn($p) => intdiv($p, $w), $q);
                $len = hypot(max($xs) - min($xs), max($ys) - min($ys)) * $this->px;
                $mw = 2 * $area / max(1, $perim) * $this->px;
                if ($len >= 2.5 && $mw <= 1.2) continue;
            }
            $meanW = 2 * $area / max(1, $perim) * $this->px;
            $bgShare = ($around["\xFF"] ?? 0) / max(1, $perim);
            $colored = $around; unset($colored["\xFF"]);
            arsort($colored);
            if ($area >= $minArea) {
                // a thin strip is only a blur artifact if its color is a MIX of the two colors beside it;
                // a dark vein or outline between areas is real detail and is kept
                if (!($meanW < 0.5 && $bgShare < 0.3) || count($colored) < 2) continue;
                [$A, $B] = array_slice(array_keys($colored), 0, 2);
                if (self::segDist($labs[ord($c)], $labs[ord((string)$A)], $labs[ord((string)$B)]) > 12) continue;
            }
            if (!$colored) { foreach ($q as $p) $this->labels[$p] = "\xFF"; continue; }
            $to = (string)array_key_first($colored);
            foreach ($q as $p) $this->labels[$p] = $to;
        }
    }

    /** Cut away empty margins (transparent, or plain background color when background removal is on). */
    private function cropToContent($src)
    {
        $w = imagesx($src); $h = imagesy($src);
        $step = max(1, (int)floor(max($w, $h) / 1000));
        $px = fn($x, $y) => imagecolorat($src, $x, $y);
        $isTrue = imageistruecolor($src);
        $rgba = function ($c) use ($src, $isTrue) {
            if ($isTrue) return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255, ($c >> 24) & 127];
            $i = imagecolorsforindex($src, $c); return [$i['red'], $i['green'], $i['blue'], $i['alpha']];
        };
        $bg = null;
        if ($this->o['remove_bg']) {                     // background = color shared by most corners
            $cs = [$rgba($px(0, 0)), $rgba($px($w - 1, 0)), $rgba($px(0, $h - 1)), $rgba($px($w - 1, $h - 1))];
            foreach ($cs as $a) {
                $n = 0; foreach ($cs as $b) if (abs($a[0] - $b[0]) + abs($a[1] - $b[1]) + abs($a[2] - $b[2]) < 30) $n++;
                if ($n >= 3 && $a[3] < 64) { $bg = $a; break; }
            }
        }
        $x0 = $w; $y0 = $h; $x1 = -1; $y1 = -1;
        for ($y = 0; $y < $h; $y += $step) for ($x = 0; $x < $w; $x += $step) {
            $c = $rgba($px($x, $y));
            if ($c[3] > 100) continue;                                                  // transparent
            if ($bg && abs($c[0] - $bg[0]) + abs($c[1] - $bg[1]) + abs($c[2] - $bg[2]) < 40) continue;   // background
            if ($x < $x0) $x0 = $x; if ($x > $x1) $x1 = $x; if ($y < $y0) $y0 = $y; if ($y > $y1) $y1 = $y;
        }
        if ($x1 < 0) return $src;                                                       // nothing found: keep as is
        $m = 2 * $step;
        $x0 = max(0, $x0 - $m); $y0 = max(0, $y0 - $m); $x1 = min($w - 1, $x1 + $m); $y1 = min($h - 1, $y1 + $m);
        if ($x0 === 0 && $y0 === 0 && $x1 === $w - 1 && $y1 === $h - 1) return $src;
        $out = imagecreatetruecolor($x1 - $x0 + 1, $y1 - $y0 + 1);
        imagealphablending($out, false); imagesavealpha($out, true);
        imagecopy($out, $src, 0, 0, $x0, $y0, $x1 - $x0 + 1, $y1 - $y0 + 1);
        imagedestroy($src);
        return $out;
    }

    private function buildPalette(array $pix): array
    {
        // fixed palette from the user (their actual thread colors)
        if (trim($this->o['palette']) !== '') {
            $pal = [];
            foreach (preg_split('/[\s,;]+/', $this->o['palette']) as $hex) {
                $hex = ltrim(trim($hex), '#');
                if (preg_match('/^[0-9a-f]{6}$/i', $hex))
                    $pal[] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
            }
            if ($pal) return $pal;
        }

        // k-means in Lab space (matches how the eye groups colors)
        $opaque = array_values(array_filter($pix));
        if (!$opaque) throw new RuntimeException('Image is fully transparent.');
        $step = max(1, intdiv(count($opaque), 6000));
        $sample = [];
        for ($i = 0; $i < count($opaque); $i += $step) $sample[] = [$opaque[$i], self::lab($opaque[$i])];

        $k = min($this->o['colors'], count($sample));
        $cent = [$sample[0][1]];
        while (count($cent) < $k) {          // farthest-point init
            $best = null; $bestD = -1;
            foreach ($sample as [, $l]) {
                $d = INF;
                foreach ($cent as $c) $d = min($d, self::dl($l, $c));
                if ($d > $bestD) { $bestD = $d; $best = $l; }
            }
            if ($bestD <= 1) break;
            $cent[] = $best;
        }
        $rgb = [];
        for ($it = 0; $it < 12; $it++) {
            $sum = array_fill(0, count($cent), [0, 0, 0, 0, 0, 0, 0]);
            foreach ($sample as [$p, $l]) {
                $ci = self::nearestLab($l, $cent);
                for ($j = 0; $j < 3; $j++) { $sum[$ci][$j] += $l[$j]; $sum[$ci][$j + 3] += $p[$j]; }
                $sum[$ci][6]++;
            }
            foreach ($sum as $i => $s) if ($s[6]) {
                $cent[$i] = [$s[0] / $s[6], $s[1] / $s[6], $s[2] / $s[6]];
                $rgb[$i] = [(int)round($s[3] / $s[6]), (int)round($s[4] / $s[6]), (int)round($s[5] / $s[6])];
            }
        }
        ksort($rgb);
        return array_values($rgb);
    }

    private function assign(array $pix): void
    {
        $labs = array_map([self::class, 'lab'], $this->colors);
        $lab = ''; $cache = [];
        foreach ($pix as $p) {
            if ($p === null) { $lab .= "\xFF"; continue; }
            $key = ($p[0] >> 2) << 12 | ($p[1] >> 2) << 6 | ($p[2] >> 2);
            if (!isset($cache[$key])) $cache[$key] = chr(self::nearestLab(self::lab($p), $labs));
            $lab .= $cache[$key];
        }
        $this->labels = $lab;
    }

    /** Colors covering < 2% of the design are usually blurry edges - fold them into the nearest big color. */
    private function mergeTinyColors(): void
    {
        $cnt = count_chars($this->labels, 1); unset($cnt[255]);
        $total = array_sum($cnt) ?: 1;
        $big = array_filter($cnt, fn($n) => $n / $total >= 0.02);
        if (!$big) return;
        $map = [];
        foreach ($cnt as $ci => $n) {
            if (isset($big[$ci])) continue;
            $best = null; $bd = INF;
            foreach ($big as $bi => $_) {
                $d = self::dl(self::lab($this->colors[$ci]), self::lab($this->colors[$bi]));
                if ($d < $bd) { $bd = $d; $best = $bi; }
            }
            $map[chr($ci)] = chr($best);
        }
        if ($map) $this->labels = strtr($this->labels, $map);
    }

    /** Speck cleanup: a pixel with at most one same-colored neighbour takes the majority color. */
    private function majorityFilter(): void
    {
        $w = $this->w; $h = $this->h; $src = $this->labels; $dst = $src;
        for ($y = 1; $y < $h - 1; $y++) {
            for ($x = 1; $x < $w - 1; $x++) {
                $cnt = [];
                for ($dy = -1; $dy <= 1; $dy++)
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $v = $src[($y + $dy) * $w + $x + $dx];
                        $cnt[$v] = ($cnt[$v] ?? 0) + 1;
                    }
                $me = $src[$y * $w + $x];
                if ($cnt[$me] > 2) continue;           // part of a line or area: keep (thin lines survive)
                if ($this->detailMask !== '' && $this->detailMask[$y * $w + $x] === "\1") continue;   // marked: keep every detail
                arsort($cnt);
                $dst[$y * $w + $x] = (string)array_key_first($cnt);
            }
        }
        $this->labels = $dst;
    }

    /** Leave out colors the user un-ticked (they become background: not stitched). */
    private function skipColors(): void
    {
        $map = [];
        foreach (preg_split('/[\s,;]+/', (string)$this->o['skip']) as $hex) {
            $hex = ltrim(trim($hex), '#');
            if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) continue;
            $lab = self::lab([hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))]);
            foreach ($this->colors as $ci => $c)
                if (self::dl($lab, self::lab($c)) < 9) $map[chr($ci)] = "\xFF";   // ΔE < 3: same color
        }
        if ($map) $this->labels = strtr($this->labels, $map);
    }

    /** Treat the most common border color as background (not stitched). */
    private function removeBackground(): void
    {
        $w = $this->w; $h = $this->h; $cnt = [];
        for ($x = 0; $x < $w; $x++) foreach ([$x, ($h - 1) * $w + $x] as $i) { $v = $this->labels[$i]; $cnt[$v] = ($cnt[$v] ?? 0) + 1; }
        for ($y = 0; $y < $h; $y++) foreach ([$y * $w, $y * $w + $w - 1] as $i) { $v = $this->labels[$i]; $cnt[$v] = ($cnt[$v] ?? 0) + 1; }
        arsort($cnt);
        $bg = (string)array_key_first($cnt);
        if ($bg === "\xFF") return;
        $this->labels = str_replace($bg, "\xFF", $this->labels);
    }

    /* ------------------------------------------------------------------ */
    /*  2. Objects: split each color into shapes and digitize each shape   */
    /* ------------------------------------------------------------------ */

    public function digitize(): void
    {
        // big areas first so details are sewn on top
        $areas = count_chars($this->labels, 1);
        unset($areas[255]);
        arsort($areas);

        // plan every shape of every color first
        $plans = [];
        $grow = (int)round($this->o['overlap'] / $this->px);
        foreach (array_keys($areas) as $ci) {
            $regs = $this->components($this->closeMask($this->maskFor($ci)));
            $base = $this->o;
            for ($ri = 0; $ri < count($regs); $ri++) {
                $reg = $regs[$ri];
                $this->o = $base;
                // a marked area that covers only part of a shape: cut the shape there, the part inside gets the fixes
                if ($this->zoneMasks && ($cut = $this->cutByZone($reg)) !== null) {
                    foreach ($cut as $piece) $regs[] = $piece;
                    continue;
                }
                $zi = array_key_exists('zone', $reg) ? $reg['zone'] : $this->zoneOf($reg);
                if ($zi === null && $this->o['only_zones']) continue;
                if ($zi !== null) {
                    if (!empty($this->zones[$zi]['fix']['remove'])) {             // "remove this part"
                        $this->shapes[] = ['removed', 0, 0, 0, 0, 0, $zi];
                        continue;
                    }
                    $this->o = $this->zoneOptions($this->zones[$zi]['fix']);
                }
                // shapes that only touch at a point (e.g. two diamond dots) are sewn separately
                if (empty($reg['necked']) && max($reg['w'], $reg['h']) * $this->px <= 25 && $reg['area'] * $this->px ** 2 <= 200 && count($parts = $this->splitNecks($reg)) > 1) {
                    foreach ($parts as $pp) { $pp['necked'] = 1; $pp += array_intersect_key($reg, ['zone' => 1, 'zdone' => 1]); $regs[] = $pp; }
                    continue;
                }
                $plan = $this->planObject($reg);
                if (!$plan || !$plan['blocks']) continue;
                if ($plan['kind'] === 'fill' && empty($reg['split'])) {
                    [$body, $thin] = $this->splitThin($reg);
                    if ($thin) {                       // thin lines attached to a filled body: plan them separately
                        $this->objects['fill']--;
                        foreach ($thin as $t) { $t['split'] = 1; $t += array_intersect_key($reg, ['zone' => 1, 'zdone' => 1]); $regs[] = $t; }
                        $body['split'] = 1; $reg = $body;
                        $plan = $this->planObject($reg, 'fill');
                        if (!$plan || !$plan['blocks']) continue;
                    }
                }
                // like a pro: fills reach under their neighbours (no gaps); outlines/satins keep their true width
                if ($plan['kind'] === 'fill' && $grow > 0 && ($g = $this->planObject($this->growRegion($reg, $grow), 'fill'))) {
                    $plan = $g; $this->objects['fill']--;    // counted once
                }
                $plans[$ci][$plan['kind'] === 'fill' ? 'base' : 'detail'][] = $plan;
                if (count($this->shapes) < 600)
                    $this->shapes[] = [$plan['kind'], round(max(0, $reg['x0']) / $this->w, 4), round(max(0, $reg['y0']) / $this->h, 4),
                        round(min($this->w, $reg['x0'] + $reg['w']) / $this->w, 4), round(min($this->h, $reg['y0'] + $reg['h']) / $this->h, 4),
                        round(max($reg['w'], $reg['h']) * $this->px, 1), $zi];
            }
            $this->o = $base;
        }

        // sewing sequence like a professional: all fills (largest color first), then details and
        // outlines on top - light details first, dark outlines last. Colors are revisited when needed.
        $stages = [];
        foreach (array_keys($areas) as $ci) if (!empty($plans[$ci]['base'])) $stages[] = [$ci, 'base'];
        $det = array_keys(array_filter($plans, fn($p) => !empty($p['detail'])));
        usort($det, fn($a, $b) => self::lab($this->colors[$b])[0] <=> self::lab($this->colors[$a])[0]);
        foreach ($det as $ci) $stages[] = [$ci, 'detail'];
        if (!$this->o['details_last']) {   // one stop per color: details right after that color's fills
            $stages = [];
            foreach (array_keys($areas) as $ci) foreach (['base', 'detail'] as $k) if (!empty($plans[$ci][$k])) $stages[] = [$ci, $k];
        }

        foreach ($stages as $si => [$ci, $kind]) {
            // travel stitches may hide under anything still to be sewn
            $this->buildTravelGrid(array_unique(array_column(array_slice($stages, $si), 0)));
            if ($this->order && end($this->order) !== $ci) {
                $this->tieOff(); $this->stitches[] = [null, null, 'T']; $this->stitches[] = [null, null, 'C'];
            }
            if (end($this->order) !== $ci) $this->order[] = $ci;

            // sew the nearest object next
            $todo = $plans[$ci][$kind];
            while ($todo) {
                $here = $this->pos ?? [0, 0];
                $best = null; $bd = INF; $rev = false;
                foreach ($todo as $k => $p) {
                    $s = $p['blocks'][0][0];
                    $d = hypot($s[0] - $here[0], $s[1] - $here[1]);
                    if ($d < $bd) { $bd = $d; $best = $k; $rev = false; }
                    if ($p['reversible']) {
                        $lb = end($p['blocks']); $e = end($lb);
                        $d = hypot($e[0] - $here[0], $e[1] - $here[1]);
                        if ($d < $bd) { $bd = $d; $best = $k; $rev = true; }
                    }
                }
                $p = $todo[$best]; unset($todo[$best]);
                $blocks = $rev ? array_map('array_reverse', array_reverse($p['blocks'])) : $p['blocks'];
                foreach ($blocks as $b) $this->sewBlock($b, $p['reg']);
            }
        }
        $this->tieOff();
        if (!$this->order && !$this->o['only_zones']) throw new RuntimeException('Nothing to stitch – try fewer colors or turn off background removal.');
    }

    /** Decide how a single shape is stitched and build its stitch blocks. */
    private function planObject(array $reg, ?string $force = null): ?array
    {
        $px = $this->px;
        $areaMm = $reg['area'] * $px * $px;
        if ($areaMm < max(0.25, $this->o['min_run'] ** 2)) return null;           // speck
        $meanW = 2 * $reg['area'] / max(1, $reg['perim']) * $px;                    // ≈ average width (mm)
        if (max($reg['w'], $reg['h']) * $px < 4.5 && $meanW < 1.2) $this->objects['tiny']++;   // e.g. very small text
        $ft = $this->o['force_type'] ?? null;
        if ($ft === 'fill') $force = 'fill';
        if ($ft === 'run') {                                    // "sew as a line": outline running stitch
            $edge = $this->contour($reg);
            if (count($edge) > 2) { $this->objects['run']++; return ['kind' => 'run', 'reg' => $reg, 'reversible' => false, 'blocks' => [$this->runBlock($edge)]]; }
        }

        // small compact shapes (dots, diamonds, short marks, tiny rings): straight satin in one direction,
        // like a digitizer does – a curved satin along a 2–4 mm centre line only makes star-bursts
        if ($force !== 'fill' && $ft !== 'satin' && ($meanW > $this->o['run_max'] || $ft === 'straight')) {
            [$ang, $L, $W] = $this->regionShape($reg);
            $narrow = min($W, 2.5 * $meanW) <= $this->o['satin_max'];
            // stitch length is the stroke thickness (a small ring is crossed on both sides of its hole)
            if (($narrow && (($L <= 2.2 * $W && max($L, $W) <= 12) || $L <= 7))
                || ($narrow && !empty($this->o['compact_any']) && max($L, $W) <= 25)
                || ($narrow && isset($this->o['force_dir']))
                || $ft === 'straight') {
                $sdir = isset($this->o['force_dir']) ? $this->o['force_dir'] : $ang + 90;   // stitch direction
                $blocks = [];
                if ($this->o['underlay'] && $W >= 2.0) {
                    $inner = $this->erodeRegion($reg, (int)round(max($this->o['inset'], 0.4) / $px));
                    $blocks = $this->nearestOrder($this->fill($inner, $ang, max(0.8, $W / 2.5), 2.0, 0, 0));
                }
                $sat = $this->nearestOrder($this->fill($reg, $sdir, $this->o['satin_step'], $this->o['satin_split'], $this->o['satin_split'], $this->o['pull_comp']), $blocks ? end($blocks[count($blocks) - 1]) : null);
                if ($sat) {
                    $this->objects['satin']++;
                    return ['kind' => 'satin', 'reg' => $reg, 'reversible' => false, 'blocks' => array_merge($blocks, $sat)];
                }
            }
        }

        if ($force !== 'fill' && $meanW <= $this->o['satin_max'] * 1.1) {
            $dt = $this->distance($reg);
            $sk = $this->skeleton($reg);
            $segs = $this->segments($reg, $sk, $dt);
            if ($segs) {
                $widths = [];
                foreach ($segs as $s) foreach ($s['w'] as $wd) $widths[] = $wd;
                sort($widths);
                $w90 = $widths[(int)floor(count($widths) * 0.9)] ?? 0;
                $len = array_sum(array_map(fn($s) => self::pathLen($s['pts']), $segs));
                if ($w90 <= $this->o['run_max'] && $len > 0.5) {
                    $this->objects['run']++;
                    return ['kind' => 'run', 'reg' => $reg, 'reversible' => true, 'blocks' => $this->nearestOrder(array_map(fn($s) => $this->runBlock($s['pts']), $segs))];
                }
                // satin only for stroke-like shapes: centre lines x width ≈ area, and not a web of many pieces
                $medW = $widths[intdiv(count($widths), 2)];
                $strokeRatio = $len * $medW / max(0.01, $areaMm);
                $stroke = count($segs) <= max(6, $areaMm / 15) && $strokeRatio > 0.55 && $strokeRatio < 1.8;
                if ($w90 <= $this->o['satin_max'] && $len > 1.0 && $stroke) {
                    $this->objects['satin']++;
                    $blocks = [];
                    foreach ($segs as $s) { $b = $this->satinBlock($reg, $s, $dt); if ($b) $blocks[] = $b; }
                    if ($blocks) return ['kind' => 'satin', 'reg' => $reg, 'reversible' => true, 'blocks' => $this->nearestOrder($blocks)];
                }
            }
        }

        // tatami fill
        $this->objects['fill']++;
        $blocks = [];
        if ($this->o['underlay']) {
            $inner = $this->erodeRegion($reg, (int)round($this->o['inset'] / $px));
            if ($areaMm > 12) {
                $edge = $this->contour($inner);
                if (count($edge) > 3) $blocks[] = $edge;
            }
            $edgeEnd = $blocks ? end($blocks[0]) : null;
            $blocks = array_merge($blocks, $this->nearestOrder(
                $this->fill($inner, ($this->o['auto_angle'] ? $this->axisAngle($reg) : $this->o['angle']) + 90, $this->o['underlay_gap'], 4.0, 0, 0), $edgeEnd));
        }
        $ang = $this->o['auto_angle'] ? $this->axisAngle($reg) : $this->o['angle'];
        for ($l = 0; $l < $this->o['layers']; $l++) {
            $last = $blocks ? end($blocks) : null;
            $blocks = array_merge($blocks, $this->nearestOrder(
                $this->fill($reg, $ang + $l * 180 / $this->o['layers'], $this->o['spacing'],
                    $this->o['stitch_len'], min($this->o['satin_max'], $this->o['satin_split']), $this->o['pull_comp']), $last ? end($last) : null));
        }
        return ['kind' => 'fill', 'reg' => $reg, 'reversible' => false, 'blocks' => array_values(array_filter($blocks))];
    }

    /** Long-axis direction (deg) and the shape's length and width along / across it (mm). */
    private function regionShape(array $r): array
    {
        $ang = $this->axisAngleRaw($r);
        $a = deg2rad($ang); $ux = cos($a); $uy = sin($a); $w = $r['w'];
        $l0 = $c0 = INF; $l1 = $c1 = -INF;
        for ($i = strpos($r['m'], "\1"); $i !== false; $i = strpos($r['m'], "\1", $i + 1)) {
            $x = $i % $w; $y = intdiv($i, $w);
            $l = $x * $ux + $y * $uy; $c = -$x * $uy + $y * $ux;
            if ($l < $l0) $l0 = $l; if ($l > $l1) $l1 = $l; if ($c < $c0) $c0 = $c; if ($c > $c1) $c1 = $c;
        }
        return [$ang, ($l1 - $l0 + 1) * $this->px, ($c1 - $c0 + 1) * $this->px];
    }

    /** Long axis of a shape (deg), always – also for round shapes. */
    private function axisAngleRaw(array $r): float
    {
        $w = $r['w']; $n = 0; $sx = $sy = $sxx = $syy = $sxy = 0;
        for ($i = strpos($r['m'], "\1"); $i !== false; $i = strpos($r['m'], "\1", $i + 1)) {
            $x = $i % $w; $y = intdiv($i, $w);
            $n++; $sx += $x; $sy += $y; $sxx += $x * $x; $syy += $y * $y; $sxy += $x * $y;
        }
        if ($n < 3) return 0.0;
        $cxx = $sxx / $n - ($sx / $n) ** 2; $cyy = $syy / $n - ($sy / $n) ** 2; $cxy = $sxy / $n - ($sx / $n) * ($sy / $n);
        return rad2deg(0.5 * atan2(2 * $cxy, $cxx - $cyy));
    }

    /** Direction of a shape's long axis (deg). Fill rows run along it: longer rows, fewer turns, clean sheen. */
    private function axisAngle(array $r): float
    {
        $w = $r['w']; $n = 0; $sx = $sy = $sxx = $syy = $sxy = 0;
        for ($i = strpos($r['m'], "\1"); $i !== false; $i = strpos($r['m'], "\1", $i + 1)) {
            $x = $i % $w; $y = intdiv($i, $w);
            $n++; $sx += $x; $sy += $y; $sxx += $x * $x; $syy += $y * $y; $sxy += $x * $y;
        }
        if ($n < 3) return $this->o['angle'];
        $cxx = $sxx / $n - ($sx / $n) ** 2; $cyy = $syy / $n - ($sy / $n) ** 2; $cxy = $sxy / $n - ($sx / $n) * ($sy / $n);
        if (abs($cxx - $cyy) < 0.15 * ($cxx + $cyy) && abs($cxy) < 0.1 * ($cxx + $cyy)) return $this->o['angle']; // roundish: default
        return rad2deg(0.5 * atan2(2 * $cxy, $cxx - $cyy));
    }

    /* ---------- masks & regions ---------- */

    private function maskFor(int $ci): string
    {
        $c = chr($ci);
        return strtr($this->labels, [$c => "\1"] + array_fill_keys(array_map('chr', range(0, 255)), "\0"));
    }

    /** Grow a color's mask into neighbouring colored pixels (not background) so colors overlap. */
    private function growIntoNeighbours(string $m, int $r): string
    {
        // fast dilation with whole-string bitwise OR of shifted copies (\0/\1 bytes), limited to colored pixels
        $w = $this->w; $n = strlen($m);
        $colored = strtr($this->labels, ["\xFF" => "\0"] + array_fill_keys(array_map('chr', range(0, 254)), "\1"));
        for ($k = 0; $k < $r; $k++) {
            $d = $m | ("\0" . substr($m, 0, $n - 1)) | (substr($m, 1) . "\0")
                    | (str_repeat("\0", $w) . substr($m, 0, $n - $w)) | (substr($m, $w) . str_repeat("\0", $w));
            $m = $d & $colored;
        }
        return $m;
    }

    /** Dilate (or erode) a \0/\1 mask of width $w, $k times, 8-neighbour, using whole-string bit operations. */
    private static function morph(string $m, int $w, int $k, bool $dilate): string
    {
        $n = strlen($m);
        $sh = fn(string $s, int $o) => $o > 0 ? str_repeat("\0", $o) . substr($s, 0, $n - $o) : substr($s, -$o) . str_repeat("\0", -$o);
        $offs = [1, -1, $w, -$w, $w + 1, $w - 1, -$w + 1, -$w - 1];
        for ($i = 0; $i < $k; $i++) {
            $r = $m;
            foreach ($offs as $o) $r = $dilate ? ($r | $sh($m, $o)) : ($r & $sh($m, $o));
            $m = $r;
        }
        return $m;
    }

    /**
     * Split a shape into its thick body and the thin lines attached to it (like an outline touching a
     * letter): the body is filled, the thin parts get running stitch / satin. Returns [body, [thin regions]].
     */
    private function splitThin(array $r): array
    {
        $k = max(1, (int)round($this->o['run_max'] * 0.75 / $this->px));
        $body = self::morph(self::morph($r['m'], $r['w'], $k, false), $r['w'], $k + 1, true) & $r['m'];   // opening
        $thin = $r['m'] & ~$body;
        $thin = $thin & str_repeat("\1", strlen($thin));                                          // keep \0/\1 bytes
        $parts = [];
        foreach ($this->components($thin, $r['w'], $r['h'], $r['x0'], $r['y0']) as $p)
            if ($p['area'] * $this->px ** 2 >= 0.5 && max($p['w'], $p['h']) * $this->px >= 3) $parts[] = $p;
        if (!$parts) return [$r, []];
        $b = $r; $b['m'] = $body; $b['area'] = substr_count($body, "\1");
        return [$b, $parts];
    }

    /**
     * Split a small shape where two parts only touch at a narrow pinch point (e.g. two diamond dots
     * meeting at a corner). Only splits when the neck is much thinner (< 30 %) than the parts.
     */
    private function splitNecks(array $r): array
    {
        $minA = 0.6 / ($this->px ** 2);
        $kMax = max(1, (int)ceil(1.6 / $this->px));      // necks up to ~3 mm, judged against the part size below
        for ($k = 1; $k <= $kMax; $k++) {
            $core = self::morph($r['m'], $r['w'], $k, false);
            $cores = array_values(array_filter($this->components($core, $r['w'], $r['h'], 0, 0), fn($c) => $c['area'] >= $minA));
            if (count($cores) < 2) {
                if (strpos($core, "\1") === false) return [$r];   // eroded away without splitting
                continue;
            }
            $parts = []; $taken = str_repeat("\0", strlen($r['m']));
            foreach ($cores as $c) {
                $m = str_repeat("\0", strlen($r['m']));
                for ($y = 0; $y < $c['h']; $y++) for ($x = 0; $x < $c['w']; $x++)
                    if ($c['m'][$y * $c['w'] + $x] === "\1") $m[($c['y0'] + $y) * $r['w'] + $c['x0'] + $x] = "\1";
                $m = self::morph($m, $r['w'], $k + 1, true) & $r['m'] & ~$taken;
                $m &= str_repeat("\1", strlen($m));
                $taken |= $m;
                foreach ($this->components($m, $r['w'], $r['h'], $r['x0'], $r['y0']) as $p) $parts[] = $p;
            }
            $neck = 2 * $k * $this->px;
            foreach ($parts as $p) { [, , $pw] = $this->regionShape($p); if ($neck > 0.3 * $pw) return [$r]; }
            return $parts;
        }
        return [$r];
    }

    /** Morphological close (dilate then erode, 8-neighbour): joins dotted/broken thin lines without making shapes fatter. */
    private function closeMask(string $m): string
    {
        $w = $this->w; $n = strlen($m);
        $sh = function (string $s, int $o) use ($n): string {      // shifted copy: result[i] = s[i - o]
            return $o > 0 ? str_repeat("\0", $o) . substr($s, 0, $n - $o) : substr($s, -$o) . str_repeat("\0", -$o);
        };
        $offs = [1, -1, $w, -$w, $w + 1, $w - 1, -$w + 1, -$w - 1];
        $d = $m; foreach ($offs as $o) $d |= $sh($m, $o);
        $e = $d; foreach ($offs as $o) $e &= $sh($d, $o);
        return $e | $m;
    }

    /** Grow one shape by n pixels into neighbouring colored pixels (never into the background). */
    private function growRegion(array $r, int $n): array
    {
        $W = $this->w; $H = $this->h;
        $x0 = max(0, $r['x0'] - $n); $y0 = max(0, $r['y0'] - $n);
        $x1 = min($W, $r['x0'] + $r['w'] + $n); $y1 = min($H, $r['y0'] + $r['h'] + $n);
        $w = $x1 - $x0 + 2; $h = $y1 - $y0 + 2;               // keep a 1 px empty border
        $m = str_repeat("\0", $w * $h); $col = $m;
        for ($y = 0; $y < $r['h']; $y++) for ($x = 0; $x < $r['w']; $x++)
            if ($r['m'][$y * $r['w'] + $x] === "\1") $m[($r['y0'] + $y - $y0 + 1) * $w + ($r['x0'] + $x - $x0 + 1)] = "\1";
        for ($y = 1; $y < $h - 1; $y++) for ($x = 1; $x < $w - 1; $x++) {
            $gx = $x0 + $x - 1; $gy = $y0 + $y - 1;
            if ($gx < $W && $gy < $H && $this->labels[$gy * $W + $gx] !== "\xFF") $col[$y * $w + $x] = "\1";
        }
        $len = $w * $h;
        for ($k = 0; $k < $n; $k++)
            $m = ($m | ("\0" . substr($m, 0, $len - 1)) | (substr($m, 1) . "\0")
                    | (str_repeat("\0", $w) . substr($m, 0, $len - $w)) | (substr($m, $w) . str_repeat("\0", $w))) & $col;
        return ['x0' => $x0 - 1, 'y0' => $y0 - 1, 'w' => $w, 'h' => $h, 'm' => $m,
                'area' => substr_count($m, "\1"), 'perim' => $r['perim']];
    }

    /** Connected shapes (8-connected) with a local mask padded by 1 pixel. */
    private function components(string $mask, ?int $w = null, ?int $h = null, int $ox = 0, int $oy = 0): array
    {
        $w ??= $this->w; $h ??= $this->h; $n = $w * $h;
        $seen = $mask; $regions = [];
        for ($i = strpos($seen, "\1"); $i !== false; $i = strpos($seen, "\1", $i + 1)) {
            $q = [$i]; $seen[$i] = "\0"; $head = 0;
            $minx = $maxx = $i % $w; $miny = $maxy = intdiv($i, $w); $perim = 0;
            while ($head < count($q)) {
                $p = $q[$head++]; $x = $p % $w; $y = intdiv($p, $w);
                if ($x < $minx) $minx = $x; if ($x > $maxx) $maxx = $x;
                if ($y < $miny) $miny = $y; if ($y > $maxy) $maxy = $y;
                foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
                    $xx = $x + $dx; $yy = $y + $dy;
                    if ($xx < 0 || $yy < 0 || $xx >= $w || $yy >= $h || $mask[$yy * $w + $xx] === "\0") $perim++;
                }
                for ($dy = -1; $dy <= 1; $dy++) for ($dx = -1; $dx <= 1; $dx++) {
                    $xx = $x + $dx; $yy = $y + $dy;
                    if ($xx < 0 || $yy < 0 || $xx >= $w || $yy >= $h) continue;
                    $j = $yy * $w + $xx;
                    if ($seen[$j] === "\1") { $seen[$j] = "\0"; $q[] = $j; }
                }
            }
            $rw = $maxx - $minx + 3; $rh = $maxy - $miny + 3;
            $m = str_repeat("\0", $rw * $rh);
            foreach ($q as $p) $m[(intdiv($p, $w) - $miny + 1) * $rw + ($p % $w) - $minx + 1] = "\1";
            $regions[] = ['x0' => $ox + $minx - 1, 'y0' => $oy + $miny - 1, 'w' => $rw, 'h' => $rh, 'm' => $m, 'area' => count($q), 'perim' => $perim];
        }
        return $regions;
    }

    private function inside(array $r, float $x, float $y): bool
    {
        $gx = (int)floor($x / $this->px) - $r['x0']; $gy = (int)floor($y / $this->px) - $r['y0'];
        return $gx >= 0 && $gy >= 0 && $gx < $r['w'] && $gy < $r['h'] && $r['m'][$gy * $r['w'] + $gx] === "\1";
    }

    private function erodeRegion(array $r, int $n): array
    {
        $w = $r['w']; $m = $r['m'];
        for ($k = 0; $k < $n; $k++) {
            $out = $m;
            for ($i = $w; $i < strlen($m) - $w; $i++)
                if ($m[$i] === "\1" && ($m[$i - 1] === "\0" || $m[$i + 1] === "\0" || $m[$i - $w] === "\0" || $m[$i + $w] === "\0"))
                    $out[$i] = "\0";
            $m = $out;
        }
        $r['m'] = $m;
        return $r;
    }

    /** Chamfer distance to the edge (in pixels) for every pixel of the shape. */
    private function distance(array $r): array
    {
        $w = $r['w']; $h = $r['h']; $m = $r['m']; $n = $w * $h;
        $d = [];
        for ($i = 0; $i < $n; $i++) $d[$i] = $m[$i] === "\1" ? 1 << 20 : 0;
        for ($y = 1; $y < $h - 1; $y++) for ($x = 1; $x < $w - 1; $x++) {
            $i = $y * $w + $x; if (!$d[$i]) continue;
            $d[$i] = min($d[$i], $d[$i - 1] + 3, $d[$i - $w] + 3, $d[$i - $w - 1] + 4, $d[$i - $w + 1] + 4);
        }
        for ($y = $h - 2; $y > 0; $y--) for ($x = $w - 2; $x > 0; $x--) {
            $i = $y * $w + $x; if (!$d[$i]) continue;
            $d[$i] = min($d[$i], $d[$i + 1] + 3, $d[$i + $w] + 3, $d[$i + $w + 1] + 4, $d[$i + $w - 1] + 4);
        }
        return $d;
    }

    /** Zhang-Suen thinning -> 1 pixel wide centre line, then remove staircase corners. */
    private function skeleton(array $r): string
    {
        $w = $r['w']; $h = $r['h']; $m = $r['m'];
        $nb = fn($m, $i) => [$m[$i - $w] === "\1", $m[$i - $w + 1] === "\1", $m[$i + 1] === "\1", $m[$i + $w + 1] === "\1",
                             $m[$i + $w] === "\1", $m[$i + $w - 1] === "\1", $m[$i - 1] === "\1", $m[$i - $w - 1] === "\1"];
        do {
            $changed = false;
            for ($step = 0; $step < 2; $step++) {
                $del = [];
                for ($y = 1; $y < $h - 1; $y++) for ($x = 1; $x < $w - 1; $x++) {
                    $i = $y * $w + $x;
                    if ($m[$i] !== "\1") continue;
                    $p = $nb($m, $i); // P2..P9 = N NE E SE S SW W NW
                    $b = array_sum($p);
                    if ($b < 2 || $b > 6) continue;
                    $a = 0; for ($k = 0; $k < 8; $k++) if (!$p[$k] && $p[($k + 1) % 8]) $a++;
                    if ($a !== 1) continue;
                    if ($step === 0 ? ($p[0] && $p[2] && $p[4]) || ($p[2] && $p[4] && $p[6])
                                    : ($p[0] && $p[2] && $p[6]) || ($p[0] && $p[4] && $p[6])) continue;
                    $del[] = $i;
                }
                foreach ($del as $i) $m[$i] = "\0";
                if ($del) $changed = true;
            }
        } while ($changed);

        // remove staircase corner pixels (keeps lines 8-connected, 1 pixel thin)
        for ($y = 1; $y < $h - 1; $y++) for ($x = 1; $x < $w - 1; $x++) {
            $i = $y * $w + $x;
            if ($m[$i] !== "\1") continue;
            $p = $nb($m, $i);
            $corner = ($p[0] && $p[2]) || ($p[2] && $p[4]) || ($p[4] && $p[6]) || ($p[6] && $p[0]);
            if (!$corner) continue;
            if (self::neighbourGroups($p) === 1) $m[$i] = "\0";
        }
        return $m;
    }

    /** Number of 8-connected groups among the 8 neighbours (P2..P9 = N NE E SE S SW W NW). */
    private static function neighbourGroups(array $p): int
    {
        static $pos = [[0, -1], [1, -1], [1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1]];
        $set = array_keys(array_filter($p));
        $seen = []; $groups = 0;
        foreach ($set as $s) {
            if (isset($seen[$s])) continue;
            $groups++; $stack = [$s]; $seen[$s] = true;
            while ($stack) {
                $a = array_pop($stack);
                foreach ($set as $b) if (!isset($seen[$b]) && abs($pos[$a][0] - $pos[$b][0]) <= 1 && abs($pos[$a][1] - $pos[$b][1]) <= 1) { $seen[$b] = true; $stack[] = $b; }
            }
        }
        return $groups;
    }

    /** Trace the centre line into polylines (mm) with the shape width along them. */
    private function segments(array $r, string $sk, array $dt): array
    {
        $w = $r['w']; $px = $this->px;
        $offs = [-$w - 1, -$w, -$w + 1, -1, 1, $w - 1, $w, $w + 1];
        $nbrs = function ($i) use ($sk, $offs) { $o = []; foreach ($offs as $d) if ($sk[$i + $d] === "\1") $o[] = $i + $d; return $o; };
        $deg = [];
        for ($i = strpos($sk, "\1"); $i !== false; $i = strpos($sk, "\1", $i + 1)) $deg[$i] = count($nbrs($i));
        if (!$deg) return [];

        $usedEdge = []; $paths = [];
        $walk = function ($a, $b) use (&$usedEdge, $nbrs, &$deg) {
            $path = [$a, $b]; $usedEdge["$a-$b"] = $usedEdge["$b-$a"] = true;
            $prev = $a; $cur = $b;
            while ($deg[$cur] === 2) {
                $next = null;
                foreach ($nbrs($cur) as $c) if ($c !== $prev && !isset($usedEdge["$cur-$c"])) { $next = $c; break; }
                if ($next === null) break;
                $usedEdge["$cur-$next"] = $usedEdge["$next-$cur"] = true;
                $path[] = $next; $prev = $cur; $cur = $next;
                if ($cur === $path[0]) break;
            }
            return $path;
        };
        foreach ($deg as $i => $dg) if ($dg !== 2)
            foreach ($nbrs($i) as $j) if (!isset($usedEdge["$i-$j"])) $paths[] = $walk($i, $j);
        foreach ($deg as $i => $dg) if ($dg === 2) {           // closed loops (rings, O shapes)
            $nb = $nbrs($i);
            if (!isset($usedEdge["$i-{$nb[0]}"])) $paths[] = $walk($i, $nb[0]);
        }
        if (!$paths && $deg) $paths[] = [array_key_first($deg)];

        $segs = [];
        foreach ($paths as $p) {
            $endA = count($p) > 1 && $deg[$p[0]] === 1;
            $endB = count($p) > 1 && $deg[end($p)] === 1;
            $jA = $deg[$p[0]] >= 3; $jB = $deg[end($p)] >= 3;
            $wd = array_map(fn($i) => max($px, (2 * $dt[$i] / 3 - 1) * $px), $p);
            $len = count($p) * $px;
            if ($jA && $jB && count($p) <= 3) continue;                      // junction cluster
            if (($endA xor $endB) && ($jA || $jB) && $len < max($wd) * 1.2) continue; // spur
            $pts = array_map(fn($i) => [($r['x0'] + $i % $w + 0.5) * $px, ($r['y0'] + intdiv($i, $w) + 0.5) * $px], $p);
            $pts = self::smooth($pts, 3);
            // extend free ends to the tip of the shape
            if ($endA) array_unshift($pts, $this->tip($r, $pts[0], $pts[min(3, count($pts) - 1)]));
            if ($endB) $pts[] = $this->tip($r, end($pts), $pts[max(0, count($pts) - 4)]);
            $segs[] = ['pts' => $pts, 'w' => $wd];
        }
        return $segs;
    }

    private function tip(array $r, array $p, array $from): array
    {
        $dx = $p[0] - $from[0]; $dy = $p[1] - $from[1]; $l = hypot($dx, $dy);
        if ($l < 1e-6) return $p;
        $dx /= $l; $dy /= $l; $t = 0; $st = $this->px / 2;
        while ($t < $this->o['satin_max'] && $this->inside($r, $p[0] + $dx * ($t + $st), $p[1] + $dy * ($t + $st))) $t += $st;
        $t = max(0, $t - $this->px * 0.5);
        return [$p[0] + $dx * $t, $p[1] + $dy * $t];
    }

    /** Outer edge of a shape as a closed running-stitch loop (edge-run underlay). */
    private function contour(array $r): array
    {
        $w = $r['w']; $m = $r['m'];
        $start = strpos($m, "\1");
        if ($start === false) return [];
        $dirs = [[1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1], [0, -1], [1, -1]];
        $idx = []; foreach ($dirs as $k => [$dx, $dy]) $idx["$dx,$dy"] = $k;
        $cur = $start; $b = 4; $first = null; $pts = []; $guard = 4 * $r['area'] + 16;
        while ($guard-- > 0) {
            $found = null;
            for ($k = 1; $k <= 8; $k++) {
                $d = ($b + $k) % 8;
                $nx = $cur + $dirs[$d][0] + $dirs[$d][1] * $w;
                if ($m[$nx] === "\1") { $found = $d; break; }
            }
            if ($found === null) break;
            if ($cur === $start && $first !== null && $found === $first) break;
            if ($first === null) $first = $found;
            $pts[] = [($r['x0'] + $cur % $w + 0.5) * $this->px, ($r['y0'] + intdiv($cur, $w) + 0.5) * $this->px];
            $pd = ($found + 7) % 8;
            $bp = $cur + $dirs[$pd][0] + $dirs[$pd][1] * $w;
            $cur = $cur + $dirs[$found][0] + $dirs[$found][1] * $w;
            $diff = $bp - $cur; $bx = (($diff % $w) + $w + 1) % $w - 1; $by = intdiv($diff - $bx, $w);
            $b = $idx["$bx,$by"] ?? 4;
        }
        if (count($pts) < 4) return [];
        $pts = self::smooth($pts, 2);
        $pts[] = $pts[0];
        return self::resample($pts, 2.5);
    }

    /* ---------- stitch types ---------- */

    /** Thin line: running stitch along the centre line (triple = bold bean stitch). */
    private function runBlock(array $pts): array
    {
        $pts = self::resample($pts, $this->o['run_len']);
        if (!$this->o['run_triple']) return $pts;
        $out = [$pts[0]];
        for ($i = 1; $i < count($pts); $i++) { $out[] = $pts[$i]; $out[] = $pts[$i - 1]; $out[] = $pts[$i]; }
        return $out;
    }

    /** Satin column that follows the shape: stitches go across it, perpendicular to the centre line. */
    private function satinBlock(array $r, array $seg, array $dt): array
    {
        $o = $this->o;
        // smooth centre line (removes pixel stair-steps) -> clean, straight satin edges
        $path = self::smooth(self::resample($seg['pts'], 0.3), 6);
        $path = self::resample($path, $o['satin_step']);
        $n = count($path);
        if ($n < 2) return [];
        $k = max(1, (int)ceil(1.0 / $o['satin_step']));
        $rows = []; $maxW = 0;
        // typical half-width of this column; junction blobs must not widen it (no fans at joins)
        $hws = array_map(fn($p) => $this->halfWidth($r, $dt, $p), $path); sort($hws);
        $typ = $hws[intdiv(count($hws), 2)];
        // stitch direction: the narrowest crossing within ±60° of the centre-line normal
        // (stitches stay square to the edges and corners get a clean miter instead of a fan)
        $angs = [];
        for ($i = 0; $i < $n; $i++) {
            $a = $path[max(0, $i - $k)]; $b = $path[min($n - 1, $i + $k)];
            $base = atan2($b[0] - $a[0], -($b[1] - $a[1]));          // normal of the tangent
            $c = $path[$i]; $best = $base; $bw = INF;
            foreach ([0, -10, 10, -20, 20, -30, 30, -40, 40, -50, 50, -60, 60] as $off) {
                $t = $base + deg2rad($off); $ux = cos($t); $uy = sin($t);
                $wd = $this->ray($r, $c, $ux, $uy) + $this->ray($r, $c, -$ux, -$uy) + abs($off) * 0.002;
                if ($wd < $bw) { $bw = $wd; $best = $t; }
            }
            $angs[] = $best;
        }
        for ($i = 0; $i < $n; $i++) {
            $sx = $sy = 0;
            for ($j = max(0, $i - 1); $j <= min($n - 1, $i + 1); $j++) { $sx += cos($angs[$j]); $sy += sin($angs[$j]); }
            $nx = $sx; $ny = $sy; $l = hypot($nx, $ny) ?: 1; $nx /= $l; $ny /= $l;
            $c = $path[$i];
            // never wider than the shape is at this point: stops spikes into joining strokes
            $lim = min($this->halfWidth($r, $dt, $c) * 1.3, $typ * 1.35) + $this->px;
            $rows[] = [$c, $nx, $ny, min($lim, $this->ray($r, $c, $nx, $ny)), min($lim, $this->ray($r, $c, -$nx, -$ny))];
        }
        // smooth the column edges (median of 5) so satin edges are clean, not jagged
        foreach ([3, 4] as $side) {
            $v = array_column($rows, $side);
            for ($i = 0; $i < $n; $i++) {
                $win = array_slice($v, max(0, $i - 2), 5); sort($win);
                $rows[$i][$side] = $win[intdiv(count($win), 2)];
            }
        }
        foreach ([3, 4] as $side) {                    // then average: straight, crisp satin edges
            $v = array_column($rows, $side);
            for ($i = 0; $i < $n; $i++) {
                $hw = empty($this->o['edge_smooth']) ? 3 : 6;   // "rough edges" marked: smooth harder
                $win = array_slice($v, max(0, $i - $hw), 2 * $hw + 1);
                $rows[$i][$side] = array_sum($win) / count($win);
            }
        }
        foreach ($rows as $row) $maxW = max($maxW, $row[3] + $row[4]);
        $pc = $o['pull_comp'];
        $at = fn($row, $d) => [$row[0][0] + $row[1] * $d, $row[0][1] + $row[2] * $d];

        $block = [];
        // underlay: centre run forward, zigzag back, then satin forward
        $forward = true;
        if ($o['underlay'] && $maxW >= 1.2) {
            foreach (self::resample($path, 2.0) as $p) $block[] = $p;
            $forward = false;
            if ($maxW >= 3.0) {
                $step = max(1, (int)round(2.0 / $o['satin_step'])); $side = 1;
                for ($i = $n - 1; $i >= 0; $i -= $step) {
                    $row = $rows[$i];
                    $d = $side > 0 ? max(0, $row[3] - $o['inset']) : -max(0, $row[4] - $o['inset']);
                    $block[] = $at($row, $d); $side = -$side;
                }
                $block[] = $path[0];
                $forward = true;
            }
        }
        $seq = $forward ? $rows : array_reverse($rows);
        $side = 1;
        foreach ($seq as $j => $row) {
            $l = $at($row, $row[3] + $pc); $rt = $at($row, -$row[4] - $pc);
            [$from, $to] = $side > 0 ? [$rt, $l] : [$l, $rt];
            $width = hypot($to[0] - $from[0], $to[1] - $from[1]);
            if ($j === 0) $block[] = $from;
            // split satin: long stitches get staggered needle points so they don't snag (like a digitizer's auto-split)
            $parts = (int)ceil($width / $o['satin_split']);
            for ($s = 1; $s < $parts; $s++) {
                $f = ($s - ($j % 2 ? 0.35 : 0.65) + 0.5) / $parts;
                $block[] = [$from[0] + ($to[0] - $from[0]) * $f, $from[1] + ($to[1] - $from[1]) * $f];
            }
            $block[] = $to;
            $side = -$side;
        }
        return $block;
    }

    /** Half the shape width (mm) at a point, from the distance map. */
    private function halfWidth(array $r, array $dt, array $c): float
    {
        $gx = (int)floor($c[0] / $this->px) - $r['x0']; $gy = (int)floor($c[1] / $this->px) - $r['y0'];
        $best = 0;
        for ($dy = -1; $dy <= 1; $dy++) for ($dx = -1; $dx <= 1; $dx++) {
            $i = ($gy + $dy) * $r['w'] + $gx + $dx;
            if (isset($dt[$i])) $best = max($best, $dt[$i]);
        }
        return $best / 3 * $this->px;
    }

    private function ray(array $r, array $c, float $dx, float $dy): float
    {
        $st = $this->px / 2; $t = 0; $max = $this->o['satin_max'];
        if (!$this->inside($r, $c[0], $c[1])) return 0;
        while ($t < $max && $this->inside($r, $c[0] + $dx * ($t + $st), $c[1] + $dy * ($t + $st))) $t += $st;
        return $t + $st / 2;
    }

    /**
     * Tatami fill: scan lines at an angle, chained row-to-row into continuous blocks.
     */
    private function fill(array $reg, float $angleDeg, float $spacing, float $len, float $satinMax, float $pull): array
    {
        $a = deg2rad($angleDeg);
        $ux = cos($a); $uy = sin($a);     // along the row
        $nx = -$uy;    $ny = $ux;         // across rows
        $X0 = $reg['x0'] * $this->px; $Y0 = $reg['y0'] * $this->px;
        $X1 = $X0 + $reg['w'] * $this->px; $Y1 = $Y0 + $reg['h'] * $this->px;
        $tMin = $sMin = INF; $tMax = $sMax = -INF;
        foreach ([[$X0, $Y0], [$X1, $Y0], [$X0, $Y1], [$X1, $Y1]] as [$cx, $cy]) {
            $t = $cx * $nx + $cy * $ny; $s = $cx * $ux + $cy * $uy;
            $tMin = min($tMin, $t); $tMax = max($tMax, $t);
            $sMin = min($sMin, $s); $sMax = max($sMax, $s);
        }
        $step = $this->px / 2;
        $minRun = $this->o['min_run'];

        $rows = [];
        // align rows to a global grid so neighbouring objects line up
        $t0 = ceil($tMin / $spacing) * $spacing + $spacing / 2;
        for ($t = $t0, $r = 0; $t < $tMax; $t += $spacing, $r++) {
            $runs = []; $in = false; $start = 0;
            for ($s = $sMin; $s <= $sMax + $step; $s += $step) {
                $inside = $this->inside($reg, $ux * $s + $nx * $t, $uy * $s + $ny * $t);
                if ($inside && !$in) { $in = true; $start = $s; }
                elseif (!$inside && $in) {
                    $in = false;
                    if ($s - $start >= $minRun) $runs[] = [$start - $pull, $s - $step + $pull];
                }
            }
            $rows[$r] = ['t' => $t, 'runs' => $runs, 'i' => (int)round($t / $spacing)];
        }

        $used = []; $blocks = [];
        $maxLink = max($spacing * 3, 1.0);
        foreach ($rows as $r => $row) {
            foreach ($row['runs'] as $k => $run) {
                if (isset($used["$r:$k"])) continue;
                $block = [];
                $cr = $r; $ck = $k; $dir = 1;
                while (true) {
                    $used["$cr:$ck"] = true;
                    [$s0, $s1] = $rows[$cr]['runs'][$ck];
                    $t = $rows[$cr]['t'];
                    foreach ($this->rowStitches($s0, $s1, $len, $satinMax, $rows[$cr]['i'], $dir) as $s)
                        $block[] = [$ux * $s + $nx * $t, $uy * $s + $ny * $t];
                    $endS = $dir > 0 ? $s1 : $s0;
                    $nr = $cr + 1; $best = null; $bestD = INF;
                    if (isset($rows[$nr])) {
                        foreach ($rows[$nr]['runs'] as $nk => [$a0, $a1]) {
                            if (isset($used["$nr:$nk"])) continue;
                            if ($a1 < $s0 - $maxLink || $a0 > $s1 + $maxLink) continue;
                            $d = abs(($dir > 0 ? $a1 : $a0) - $endS);
                            if ($d < $bestD) { $bestD = $d; $best = $nk; }
                        }
                    }
                    if ($best === null || $bestD > $maxLink) break; // never drag long stitches across the design
                    $cr = $nr; $ck = $best; $dir = -$dir;
                }
                if (count($block) >= 6) $blocks[] = $block;   // drop specks: only trims & birdnests
            }
        }
        return $blocks;
    }

    /** Points along one row; brick offset (aligned to a global grid) so needle holes don't line up. */
    private function rowStitches(float $s0, float $s1, float $len, float $satinMax, int $row, int $dir): array
    {
        if ($s1 - $s0 <= max($satinMax, $len)) {
            $pts = [$s0, $s1];
        } else {
            $pts = [$s0];
            $off = (($row % 3 + 3) % 3) * $len / 3;
            for ($s = floor(($s0 - $off) / $len) * $len + $off + $len; $s < $s1 - $len * 0.3; $s += $len)
                if ($s > $s0 + 0.3 * $len) $pts[] = $s;
            $pts[] = $s1;
        }
        return $dir > 0 ? $pts : array_reverse($pts);
    }

    /** Sew the closest block next (either direction) - fewer jumps and trims. */
    private function nearestOrder(array $blocks, ?array $pos = null): array
    {
        $blocks = array_values(array_filter($blocks));
        $out = [];
        $pos ??= $blocks[0][0] ?? [0, 0];
        while ($blocks) {
            $best = null; $bd = INF; $rev = false;
            foreach ($blocks as $k => $b) {
                $e = end($b);
                $d0 = hypot($b[0][0] - $pos[0], $b[0][1] - $pos[1]);
                $d1 = hypot($e[0] - $pos[0], $e[1] - $pos[1]);
                if ($d0 < $bd) { $bd = $d0; $best = $k; $rev = false; }
                if ($d1 < $bd) { $bd = $d1; $best = $k; $rev = true; }
            }
            $b = $blocks[$best]; unset($blocks[$best]);
            if ($rev) $b = array_reverse($b);
            $out[] = $b;
            $pos = end($b);
        }
        return $out;
    }

    /* ---------- sewing ---------- */

    private function sewBlock(array $pts, array $reg): void
    {
        if (!$pts) return;
        [$x, $y] = $pts[0];
        $needTie = true;
        if ($this->pos !== null) {
            $d = hypot($x - $this->pos[0], $y - $this->pos[1]);
            if ($d <= 0.05) {
                $needTie = false;
            } elseif ($d < 15 && $this->lineInside($reg, $this->pos, [$x, $y])) {
                // travel inside the shape with short stitches
                $nSt = (int)ceil($d / 2.5);
                for ($i = 1; $i <= $nSt; $i++)
                    $this->stitches[] = [$this->pos[0] + ($x - $this->pos[0]) * $i / $nSt, $this->pos[1] + ($y - $this->pos[1]) * $i / $nSt, 'S'];
                $needTie = false;
            } elseif (!$this->o['only_zones'] && ($route = $this->route($this->pos, [$x, $y])) !== null) {
                // like a pro: run to the next shape through this color or areas sewn later (hidden) - no trim
                foreach ($route as $p) $this->stitches[] = [$p[0], $p[1], 'S'];
                $needTie = false;
            } else {
                $afterColor = end($this->stitches)[2] === 'C';   // already trimmed before the color change
                if ($d > $this->o['trim_at'] && !$afterColor) { $this->tieOff(); $this->stitches[] = [null, null, 'T']; }
                $this->stitches[] = [$x, $y, 'J'];
            }
        } else {
            $this->stitches[] = [$x, $y, 'J'];
        }
        $this->pos = [$x, $y];
        $this->stitches[] = [$x, $y, 'S'];
        if ($needTie && $this->o['lock'] && isset($pts[1])) $this->tieIn($pts[1]);
        for ($i = 1; $i < count($pts); $i++) {
            // long-stitch safety: nothing longer than 7 mm (loose loops snag and break)
            $d = hypot($pts[$i][0] - $this->pos[0], $pts[$i][1] - $this->pos[1]);
            $parts = (int)ceil($d / 7.0);
            for ($k = 1; $k < $parts; $k++)
                $this->stitches[] = [$this->pos[0] + ($pts[$i][0] - $this->pos[0]) * $k / $parts, $this->pos[1] + ($pts[$i][1] - $this->pos[1]) * $k / $parts, 'S'];
            $this->stitches[] = [$pts[$i][0], $pts[$i][1], 'S'];
            $this->pos = $pts[$i];
        }
    }

    // travel grid: cells where a running stitch is hidden or blends in (this color + colors sewn later)
    private string $travel = '';
    private int $tw = 0, $th = 0;
    private float $tc = 0.5;

    private function buildTravelGrid(array $colorsAllowed): void
    {
        $map = array_fill_keys(array_map('chr', range(0, 255)), "\0");
        foreach ($colorsAllowed as $c) $map[chr($c)] = "\1";
        $full = strtr($this->labels, $map);
        $this->tc = max(0.5, $this->px * 2);
        $this->tw = (int)ceil($this->w * $this->px / $this->tc); $this->th = (int)ceil($this->h * $this->px / $this->tc);
        $g = str_repeat("\0", $this->tw * $this->th);
        $f = $this->tc / $this->px;
        for ($cy = 0; $cy < $this->th; $cy++) for ($cx = 0; $cx < $this->tw; $cx++) {
            // a cell is usable only if all of it is usable (keeps travel stitches away from visible edges)
            $ok = true;
            foreach ([[0.1, 0.1], [0.9, 0.1], [0.1, 0.9], [0.9, 0.9], [0.5, 0.5]] as [$ox, $oy]) {
                $gx = (int)(($cx + $ox) * $f); $gy = (int)(($cy + $oy) * $f);
                if ($gx >= $this->w || $gy >= $this->h || $full[$gy * $this->w + $gx] !== "\1") { $ok = false; break; }
            }
            if ($ok) $g[$cy * $this->tw + $cx] = "\1";
        }
        $this->travel = $g;
    }

    /** Shortest hidden path (running stitches up to 2.5 mm) between two points, or null. */
    private function route(array $a, array $b): ?array
    {
        if ($this->travel === '') return null;
        $tw = $this->tw; $th = $this->th; $tc = $this->tc;
        $cell = function ($p) use ($tw, $th, $tc) {
            $cx = (int)floor($p[0] / $tc); $cy = (int)floor($p[1] / $tc);
            return ($cx < 0 || $cy < 0 || $cx >= $tw || $cy >= $th) ? -1 : $cy * $tw + $cx;
        };
        // start/end usually sit on a shape edge: snap to the nearest usable cell within 1 mm
        $snap = function ($p) use ($cell, $tc) {
            $best = -1; $bd = INF;
            for ($dy = -1.0; $dy <= 1.0001; $dy += $tc) for ($dx = -1.0; $dx <= 1.0001; $dx += $tc) {
                $c = $cell([$p[0] + $dx, $p[1] + $dy]);
                if ($c >= 0 && $this->travel[$c] === "\1" && hypot($dx, $dy) < $bd) { $bd = hypot($dx, $dy); $best = $c; }
            }
            return $best;
        };
        $s = $snap($a); $e = $snap($b);
        if ($s < 0 || $e < 0) return null;
        $maxLen = min(40, 2.5 * hypot($b[0] - $a[0], $b[1] - $a[1]) + 5);
        $prev = [$s => -1]; $q = [$s]; $h = 0; $found = $s === $e;
        $nb = [-1, 1, -$tw, $tw, -$tw - 1, -$tw + 1, $tw - 1, $tw + 1];
        $limit = (int)(($maxLen / $tc) ** 2 * 3.2) + 50;
        while (!$found && $h < count($q)) {
            $c = $q[$h++]; $x = $c % $tw;
            foreach ($nb as $o) {
                $n = $c + $o;
                if ($n < 0 || $n >= $tw * $th || isset($prev[$n]) || $this->travel[$n] !== "\1") continue;
                if (abs($n % $tw - $x) > 1) continue;
                $prev[$n] = $c; $q[] = $n;
                if ($n === $e) { $found = true; break; }
            }
            if (count($q) > $limit) return null;   // farther than allowed
        }
        if (!$found) return null;
        $cells = [];
        for ($c = $e; $c !== -1; $c = $prev[$c]) $cells[] = [($c % $tw + 0.5) * $tc, (intdiv($c, $tw) + 0.5) * $tc];
        $pts = array_merge([$a], array_reverse($cells), [$b]);
        if (self::pathLen($pts) > $maxLen) return null;
        // straighten: longest straight hop (max 2.5 mm) that stays on usable cells
        $out = []; $i = 0; $n = count($pts);
        while ($i < $n - 1) {
            $j = $i + 1;
            while ($j + 1 < $n && hypot($pts[$j + 1][0] - $pts[$i][0], $pts[$j + 1][1] - $pts[$i][1]) <= 2.5 && $this->travelLine($pts[$i], $pts[$j + 1])) $j++;
            $out[] = $pts[$j]; $i = $j;
        }
        return $out;
    }

    private function travelLine(array $a, array $b): bool
    {
        $d = hypot($b[0] - $a[0], $b[1] - $a[1]); $n = max(1, (int)ceil($d / ($this->tc / 2)));
        for ($i = 1; $i < $n; $i++) {
            $cx = (int)floor(($a[0] + ($b[0] - $a[0]) * $i / $n) / $this->tc); $cy = (int)floor(($a[1] + ($b[1] - $a[1]) * $i / $n) / $this->tc);
            if ($cx < 0 || $cy < 0 || $cx >= $this->tw || $cy >= $this->th || $this->travel[$cy * $this->tw + $cx] !== "\1") return false;
        }
        return true;
    }

    private function lineInside(array $reg, array $a, array $b): bool
    {
        $d = hypot($b[0] - $a[0], $b[1] - $a[1]);
        $n = max(1, (int)ceil($d / ($this->px / 2)));
        for ($i = 0; $i <= $n; $i++)
            if (!$this->inside($reg, $a[0] + ($b[0] - $a[0]) * $i / $n, $a[1] + ($b[1] - $a[1]) * $i / $n)) return false;
        return true;
    }

    private function tieIn(array $toward): void
    {
        [$x, $y] = $this->pos;
        $d = hypot($toward[0] - $x, $toward[1] - $y) ?: 1;
        $dx = ($toward[0] - $x) / $d * 0.4; $dy = ($toward[1] - $y) / $d * 0.4;
        foreach ([1, 0, 1, 0] as $m) $this->stitches[] = [$x + $dx * $m, $y + $dy * $m, 'S'];
    }

    private function tieOff(): void
    {
        if (!$this->o['lock'] || $this->pos === null) return;
        $last = end($this->stitches);
        if (!$last || $last[2] !== 'S') return;
        [$x, $y] = $this->pos;
        foreach ([[0.4, 0], [0, 0], [0, 0.4], [0, 0]] as [$dx, $dy]) $this->stitches[] = [$x + $dx, $y + $dy, 'S'];
    }

    /* ------------------------------------------------------------------ */
    /*  3. DST encoding                                                    */
    /* ------------------------------------------------------------------ */

    public function toDst(): string
    {
        // centre design on (0,0), convert to 0.1mm units, Y up
        $minX = $minY = INF; $maxX = $maxY = -INF;
        foreach ($this->stitches as [$x, $y]) {
            if ($x === null) continue;
            $minX = min($minX, $x); $maxX = max($maxX, $x);
            $minY = min($minY, $y); $maxY = max($maxY, $y);
        }
        $cx = ($minX + $maxX) / 2; $cy = ($minY + $maxY) / 2;

        $body = ''; $count = 0; $colorChanges = 0; $trims = 0;
        $cur = [0, 0]; $bx = [0, 0]; $by = [0, 0];
        // every record updates the needle position and the design bounds
        $emit = function (int $dx, int $dy, string $type) use (&$body, &$count, &$cur, &$bx, &$by) {
            $body .= self::encode($dx, $dy, $type); $count++;
            $cur[0] += $dx; $cur[1] += $dy;
            $bx = [min($bx[0], $cur[0]), max($bx[1], $cur[0])];
            $by = [min($by[0], $cur[1]), max($by[1], $cur[1])];
        };
        $move = function (int $tx, int $ty) use (&$cur, $emit) {
            // stitches: split anything above the DST limit of 121 units (12.1 mm)
            while ($cur[0] !== $tx || $cur[1] !== $ty)
                $emit(max(-121, min(121, $tx - $cur[0])), max(-121, min(121, $ty - $cur[1])), 'S');
        };
        // Moving without sewing, like Wilcom writes it: jumps of at most 7 mm. A CUT is 3 or more jumps in a row
        // (machines set to "trim after 3 jumps" cut the thread there); a short move without a cut is 1–2 jumps.
        $jumpTo = function (int $tx, int $ty, bool $cut) use (&$cur, $emit) {
            $d = hypot($tx - $cur[0], $ty - $cur[1]);
            $n = max($cut ? 3 : 1, (int)ceil($d / 70));
            if (!$cut && $n > 2) $n = 2;                       // never looks like a cut by accident
            $sx = $cur[0]; $sy = $cur[1];
            for ($k = 1; $k <= $n; $k++) {
                $nx = (int)round($sx + ($tx - $sx) * $k / $n); $ny = (int)round($sy + ($ty - $sy) * $k / $n);
                $emit($nx - $cur[0], $ny - $cur[1], 'J');
            }
        };

        $minSt = max(0, $this->o['min_stitch']) * 10; $prevT = '';
        $cutNext = true;                                       // start: from the centre to the first stitch
        foreach ($this->stitches as [$x, $y, $t]) {
            if ($t === 'C') { $body .= self::encode(0, 0, 'C'); $count++; $colorChanges++; $prevT = 'C'; $cutNext = true; continue; }
            if ($t === 'T') { $cutNext = true; $prevT = 'T'; continue; }   // the cut is written with the next move
            $tx = (int)round(($x - $cx) * 10);
            $ty = (int)round(($cy - $y) * 10);
            if ($t === 'J') {
                if ($cutNext) { if ($colorChanges > 0 || $count > 0 || $cur !== [0, 0]) $trims++; }
                $jumpTo($tx, $ty, $cutNext); $cutNext = false; $prevT = 'J'; continue;
            }
            if ($cutNext && ($tx !== $cur[0] || $ty !== $cur[1])) { $jumpTo($tx, $ty, true); $trims++; }
            $cutNext = false;
            // skip too-short stitches: tiny needle moves shred and break the thread
            if ($prevT === 'S' && hypot($tx - $cur[0], $ty - $cur[1]) < $minSt) continue;
            $prevT = 'S';
            $move($tx, $ty);
        }
        // finish like a professional file: lock (already sewn), cut, and return to the start point (centre)
        if ($cur !== [0, 0]) { $jumpTo(0, 0, true); $trims++; }
        $body .= self::encode(0, 0, 'E'); $count++;

        $label = substr(preg_replace('/[^A-Za-z0-9 _-]/', '', $this->o['label']) ?: 'DESIGN', 0, 16);
        $h  = sprintf("LA:%-16s\r", $label);
        $h .= sprintf("ST:%7d\r", $count);
        $h .= sprintf("CO:%3d\r", $colorChanges);
        $h .= sprintf("+X:%5d\r-X:%5d\r+Y:%5d\r-Y:%5d\r", $bx[1], -$bx[0], $by[1], -$by[0]);
        // AX/AY = where the needle ends, relative to the start point (0 = back at the centre)
        $h .= sprintf("AX:%s%5d\rAY:%s%5d\rMX:+%5d\rMY:+%5d\rPD:******\r", $cur[0] < 0 ? '-' : '+', abs($cur[0]), $cur[1] < 0 ? '-' : '+', abs($cur[1]), 0, 0);
        $h .= "\x1A";
        $h = str_pad($h, 512, ' ');

        $this->stats = [
            'stitches' => $count,
            'colors'   => $colorChanges + 1,
            'width'    => ($bx[1] - $bx[0]) / 10,
            'height'   => ($by[1] - $by[0]) / 10,
            'trims'    => $trims,
            'objects'  => $this->objects,
            'aspect'   => round($this->aspect, 5),
        ];
        $this->stats['warnings'] = $this->risks();
        return $h . $body;
    }

    /** Settings that commonly cause thread breaks, birdnests or puckering. */
    private function risks(): array
    {
        $o = $this->o; $w = []; $st = $this->stats;
        if ($o['spacing'] < 0.18) $w[] = sprintf('Very dense (%.2f mm row spacing) - denser than professional commercial designs (≈0.2–0.25 mm). The thread may break or the fabric can tear. Lower Coverage.', $o['spacing']);
        if ($o['layers'] > 1) $w[] = sprintf('%d fill layers: full coverage but stiff and heavy. Sew slower (about 600 spm), use a sharp 75/11 needle and firm backing.', $o['layers']);
        if ($o['stitch_len'] < 2) $w[] = 'Fill stitch length under 2 mm: lots of short stitches. Use 3–4 mm.';
        if ($o['satin_split'] > 6) $w[] = 'Satin stitches up to ' . $o['satin_split'] . ' mm long: on T-shirts and knits long stitches can snag. 4.5–5 mm is safer.';
        if ($o['min_stitch'] < 0.3) $w[] = 'Minimum stitch below 0.3 mm: tiny stitches are a common cause of thread breaks.';
        if ($st['trims'] > 20 && $st['trims'] * 60 > $st['stitches'])
            $w[] = "{$st['trims']} trims - the design is very fragmented (looks like a photo or noisy image). Use a clean logo, fewer colors, more cleanup passes or a bigger 'Skip areas thinner than'.";
        if ($st['width'] > 400 || $st['height'] > 400) $w[] = 'Design is larger than most hoops (400 mm).';
        $ob = $st['objects'];
        if ($ob['tiny'] >= 3) $w[] = 'Many hair-thin details were found. Text smaller than about 5 mm tall cannot be sewn cleanly - make the design bigger or the small text larger/bolder.';
        if (!$o['lock']) $w[] = 'Lock stitches are off: thread may pull out after trims.';
        return $w;
    }

    /** Encode one DST 3-byte record (balanced ternary). */
    private static function encode(int $x, int $y, string $type): string
    {
        if ($type === 'C') return "\x00\x00\xC3";
        if ($type === 'E') return "\x00\x00\xF3";
        $b0 = $b1 = 0; $b2 = 0x03;
        if ($type === 'J') $b2 |= 0x80;
        if ($x > 40)  { $b2 |= 0x04; $x -= 81; } if ($x < -40) { $b2 |= 0x08; $x += 81; }
        if ($x > 13)  { $b1 |= 0x04; $x -= 27; } if ($x < -13) { $b1 |= 0x08; $x += 27; }
        if ($x > 4)   { $b0 |= 0x04; $x -= 9; }  if ($x < -4)  { $b0 |= 0x08; $x += 9; }
        if ($x > 1)   { $b1 |= 0x01; $x -= 3; }  if ($x < -1)  { $b1 |= 0x02; $x += 3; }
        if ($x > 0)   { $b0 |= 0x01; $x -= 1; }  if ($x < 0)   { $b0 |= 0x02; $x += 1; }
        if ($y > 40)  { $b2 |= 0x20; $y -= 81; } if ($y < -40) { $b2 |= 0x10; $y += 81; }
        if ($y > 13)  { $b1 |= 0x20; $y -= 27; } if ($y < -13) { $b1 |= 0x10; $y += 27; }
        if ($y > 4)   { $b0 |= 0x20; $y -= 9; }  if ($y < -4)  { $b0 |= 0x10; $y += 9; }
        if ($y > 1)   { $b1 |= 0x80; $y -= 3; }  if ($y < -1)  { $b1 |= 0x40; $y += 3; }
        if ($y > 0)   { $b0 |= 0x80; $y -= 1; }  if ($y < 0)   { $b0 |= 0x40; $y += 1; }
        return chr($b0) . chr($b1) . chr($b2);
    }

    /* ------------------------------------------------------------------ */
    /*  4. Preview & color sheet                                           */
    /* ------------------------------------------------------------------ */

    public function previewSvg(): string
    {
        $W = $this->w * $this->px; $H = $this->h * $this->px;
        $svg = sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="-1 -1 %.1f %.1f" style="width:100%%;height:auto;background:#fff">', $W + 2, $H + 2);
        $ci = 0; $path = ''; $prev = null;
        $flush = function () use (&$svg, &$path, &$ci) {
            if ($path !== '') {
                $c = $this->colors[$this->order[$ci]];
                $svg .= sprintf('<path data-hex="#%02x%02x%02x" d="%s" fill="none" stroke="rgb(%d,%d,%d)" stroke-width="0.4" stroke-linecap="round" stroke-linejoin="round"/>', ...[...$c, $path, ...$c]);
            }
            $path = '';
        };
        foreach ($this->stitches as [$x, $y, $t]) {
            if ($t === 'C') { $flush(); $ci++; $prev = null; continue; }
            if ($t !== 'S') { $prev = null; continue; }
            $path .= ($prev === null ? 'M' : 'L') . round($x, 2) . ' ' . round($y, 2);
            $prev = true;
        }
        $flush();
        // start / end point for the machine: the design centre (the needle starts here and returns here)
        $minX = $minY = INF; $maxX = $maxY = -INF; $firstS = null;
        foreach ($this->stitches as [$x, $y, $t]) {
            if ($x === null) continue;
            $minX = min($minX, $x); $maxX = max($maxX, $x); $minY = min($minY, $y); $maxY = max($maxY, $y);
            if ($t === 'S' && !$firstS) $firstS = [$x, $y];
        }
        if ($firstS) {
            $cx = ($minX + $maxX) / 2; $cy = ($minY + $maxY) / 2; $r = max(0.8, ($maxX - $minX) / 90);
            $svg .= sprintf('<g class="emb-mark" stroke-width="%.2f"><circle cx="%.2f" cy="%.2f" r="%.2f" fill="none" stroke="#16a34a"/>'
                . '<path d="M%.2f %.2fh%.2fM%.2f %.2fv%.2f" stroke="#16a34a"/><title>Start &amp; end point (design centre)</title></g>',
                $r / 4, $cx, $cy, $r, $cx - $r * 1.6, $cy, $r * 3.2, $cx, $cy - $r * 1.6, $r * 3.2);
            $svg .= sprintf('<g class="emb-mark"><circle cx="%.2f" cy="%.2f" r="%.2f" fill="#16a34a"/><title>First stitch</title></g>', $firstS[0], $firstS[1], $r * 0.55);
        }
        return $svg . '</svg>';
    }

    /**
     * Use ready-made stitches (an imported DST) instead of digitizing a picture, so the same writer,
     * preview and thread list are used. $items: [x mm, y mm (down), 'S'|'J'|'T'|'C'], frame 0..$wMm × 0..$hMm.
     */
    public function useStitches(array $items, array $rgbs, float $wMm, float $hMm, float $aspect): void
    {
        $this->stitches = $items;
        $this->colors = $rgbs;
        $this->order = array_keys($rgbs);
        $this->px = 0.05;
        $this->w = max(2, (int)round($wMm / $this->px));
        $this->h = max(2, (int)round($hMm / $this->px));
        $this->aspect = $aspect;
    }

    /** The stitches per color stop: [[r,g,b], [[x, y, type], …]] – used to merge a redone area into a DST. */
    public function blocks(): array
    {
        if (!$this->order) return [];
        $out = []; $i = 0; $cur = [];
        foreach ($this->stitches as $s) {
            if ($s[2] === 'C') { $out[] = [$this->colors[$this->order[$i]], $cur]; $cur = []; $i++; continue; }
            $cur[] = $s;
        }
        $out[] = [$this->colors[$this->order[$i]], $cur];
        return $out;
    }

    /** Thread stops in sewing order: [hex, stitchCount]. */
    public function colorSheet(): array
    {
        $out = []; $i = 0; $n = 0;
        foreach ($this->stitches as [, , $t]) {
            if ($t === 'C') { $out[] = [$this->hex($this->order[$i]), $n]; $i++; $n = 0; }
            elseif ($t === 'S') $n++;
        }
        $out[] = [$this->hex($this->order[$i]), $n];
        return $out;
    }

    /* ---------- helpers ---------- */

    private function hex(int $ci): string { return sprintf('#%02x%02x%02x', ...$this->colors[$ci]); }

    private static function lab(array $c): array
    {
        $f = function ($v) { $v /= 255; return $v > 0.04045 ? (($v + 0.055) / 1.055) ** 2.4 : $v / 12.92; };
        [$r, $g, $b] = [$f($c[0]), $f($c[1]), $f($c[2])];
        $x = ($r * 0.4124 + $g * 0.3576 + $b * 0.1805) / 0.95047;
        $y =  $r * 0.2126 + $g * 0.7152 + $b * 0.0722;
        $z = ($r * 0.0193 + $g * 0.1192 + $b * 0.9505) / 1.08883;
        $h = fn($t) => $t > 0.008856 ? $t ** (1 / 3) : 7.787 * $t + 16 / 116;
        return [116 * $h($y) - 16, 500 * ($h($x) - $h($y)), 200 * ($h($y) - $h($z))];
    }

    /** Lab distance from color c to the blend line between a and b. */
    private static function segDist(array $c, array $a, array $b): float
    {
        $ab = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]]; $l2 = $ab[0] ** 2 + $ab[1] ** 2 + $ab[2] ** 2;
        $t = $l2 ? max(0, min(1, (($c[0] - $a[0]) * $ab[0] + ($c[1] - $a[1]) * $ab[1] + ($c[2] - $a[2]) * $ab[2]) / $l2)) : 0;
        return sqrt(self::dl($c, [$a[0] + $ab[0] * $t, $a[1] + $ab[1] * $t, $a[2] + $ab[2] * $t]));
    }

    private static function dl(array $a, array $b): float
    {
        return ($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2;
    }

    private static function nearestLab(array $l, array $pal): int
    {
        $best = 0; $bd = INF;
        foreach ($pal as $i => $c) { $d = self::dl($l, $c); if ($d < $bd) { $bd = $d; $best = $i; } }
        return $best;
    }

    private static function smooth(array $pts, int $iter): array
    {
        $n = count($pts);
        if ($n < 3) return $pts;
        $closed = hypot($pts[0][0] - $pts[$n - 1][0], $pts[0][1] - $pts[$n - 1][1]) < 1e-6;
        for ($k = 0; $k < $iter; $k++) {
            $o = $pts;
            for ($i = 1; $i < $n - 1; $i++)
                $o[$i] = [($pts[$i - 1][0] + 2 * $pts[$i][0] + $pts[$i + 1][0]) / 4, ($pts[$i - 1][1] + 2 * $pts[$i][1] + $pts[$i + 1][1]) / 4];
            if ($closed) $o[$n - 1] = $o[0];
            $pts = $o;
        }
        return $pts;
    }

    private static function pathLen(array $pts): float
    {
        $l = 0;
        for ($i = 1; $i < count($pts); $i++) $l += hypot($pts[$i][0] - $pts[$i - 1][0], $pts[$i][1] - $pts[$i - 1][1]);
        return $l;
    }

    /** Points at equal distance along a polyline (always keeps both ends). */
    private static function resample(array $pts, float $step): array
    {
        if (count($pts) < 2) return $pts;
        $out = [$pts[0]]; $acc = 0;
        $total = self::pathLen($pts);
        $n = max(1, (int)round($total / $step)); $step = $total / $n;
        $next = $step;
        for ($i = 1; $i < count($pts); $i++) {
            [$ax, $ay] = $pts[$i - 1]; [$bx, $by] = $pts[$i];
            $seg = hypot($bx - $ax, $by - $ay);
            while ($seg > 0 && $acc + $seg >= $next - 1e-9 && count($out) < $n) {
                $f = ($next - $acc) / $seg;
                $out[] = [$ax + ($bx - $ax) * $f, $ay + ($by - $ay) * $f];
                $next += $step;
            }
            $acc += $seg;
        }
        $out[] = end($pts);
        return $out;
    }
}
