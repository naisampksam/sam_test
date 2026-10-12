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
            'colors'        => 6,     // number of thread colors (auto palette)
            'palette'       => '',    // optional fixed thread colors "#ff0000,#000000"
            'remove_bg'     => 1,     // drop the background (corner) color
            'cleanup'       => 2,     // speckle cleanup passes
            'density'       => 0.40,  // fill / satin row spacing (mm)
            'stitch_len'    => 3.5,   // max tatami stitch length (mm)
            'satin_max'     => 7.0,   // shapes up to this wide become satin columns (mm)
            'run_max'       => 1.0,   // lines thinner than this become running stitch (mm)
            'run_len'       => 2.0,   // running stitch length (mm)
            'run_triple'    => 1,     // bold triple (bean) running stitch
            'angle'         => 45,    // fill angle (deg)
            'underlay'      => 1,     // underlay on/off
            'underlay_gap'  => 2.0,   // tatami underlay row spacing (mm)
            'inset'         => 0.4,   // underlay inset from edges (mm)
            'pull_comp'     => 0.2,   // pull compensation added to each side (mm)
            'min_run'       => 0.6,   // ignore fill fragments thinner than this (mm)
            'coverage'      => 100,   // stitch volume %: 150 = 1.5x more rows (denser)
            'layers'        => 1,     // fill layers: 2 = second cross-hatched fill on top
            'overlap'       => 0.3,   // colors overlap each other by this much (mm) - no gaps
            'min_stitch'    => 0.3,   // drop stitches shorter than this (mm) - prevents thread breaks
            'trim_at'       => 3.0,   // trim when a jump is longer than this (mm)
            'lock'          => 1,     // tie-in / tie-off stitches
            'label'         => 'DESIGN',
        ];
    }

    public function __construct(array $opts)
    {
        $o = array_merge(self::defaults(), $opts);
        $o['width_mm']     = max(10, min(400, (float)$o['width_mm']));
        $o['colors']       = max(1, min(30, (int)$o['colors']));
        $o['density']      = max(0.15, min(3, (float)$o['density']));
        $o['stitch_len']   = max(1, min(12, (float)$o['stitch_len']));
        $o['satin_max']    = max(0, min(12, (float)$o['satin_max']));
        $o['run_max']      = max(0, min(3, (float)$o['run_max']));
        $o['run_len']      = max(1, min(5, (float)$o['run_len']));
        $o['underlay_gap'] = max(0.5, min(6, (float)$o['underlay_gap']));
        $o['coverage']     = max(50, min(300, (float)$o['coverage']));
        $o['layers']       = max(1, min(3, (int)$o['layers']));
        $o['overlap']      = max(0, min(2, (float)$o['overlap']));
        foreach (['angle', 'inset', 'pull_comp', 'min_run', 'min_stitch', 'trim_at'] as $k) $o[$k] = (float)$o[$k];
        // effective row spacing: higher coverage -> rows closer together
        $o['spacing']      = max(0.12, $o['density'] * 100 / $o['coverage']);
        $this->o = $o;
    }

    /* ------------------------------------------------------------------ */
    /*  1. Image loading & color reduction                                 */
    /* ------------------------------------------------------------------ */

    public function load(string $file): void
    {
        $src = @imagecreatefromstring(file_get_contents($file));
        if (!$src) throw new RuntimeException('Could not read image. Use PNG, JPG, GIF, WEBP or BMP.');

        $sw = imagesx($src); $sh = imagesy($src);
        // grid: fine enough for 0.1-0.2 mm edges, capped for speed
        $this->px = max($this->o['width_mm'] / 1500, min(0.2, $this->o['spacing'] / 2));
        $this->w = max(2, (int)round($this->o['width_mm'] / $this->px));
        $this->h = max(2, (int)round($this->w * $sh / $sw));

        $img = imagecreatetruecolor($this->w, $this->h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagecopyresampled($img, $src, 0, 0, 0, 0, $this->w, $this->h, $sw, $sh);
        imagedestroy($src);

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
        $minArea = 1.0 / ($this->px * $this->px);
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
            $meanW = 2 * $area / max(1, $perim) * $this->px;
            $bgShare = ($around["\xFF"] ?? 0) / max(1, $perim);
            if ($area >= $minArea && !($meanW < 0.5 && $bgShare < 0.3)) continue;
            $colored = $around; unset($colored["\xFF"]);
            if (!$colored) { if ($area < $minArea) foreach ($q as $p) $this->labels[$p] = "\xFF"; continue; }
            arsort($colored);
            $to = (string)array_key_first($colored);
            foreach ($q as $p) $this->labels[$p] = $to;
        }
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
                arsort($cnt);
                $dst[$y * $w + $x] = (string)array_key_first($cnt);
            }
        }
        $this->labels = $dst;
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

        $first = true;
        foreach (array_keys($areas) as $ci) {
            $mask = $this->maskFor($ci);
            $mask = $this->growIntoNeighbours($mask, (int)round($this->o['overlap'] / $this->px));

            $plans = [];
            foreach ($this->components($mask) as $reg) {
                $plan = $this->planObject($reg);
                if ($plan && $plan['blocks']) $plans[] = $plan;
            }
            if (!$plans) continue;

            if (!$first) { $this->tieOff(); $this->stitches[] = [null, null, 'T']; $this->stitches[] = [null, null, 'C']; }
            $first = false;
            $this->order[] = $ci;

            // sew the nearest object next
            while ($plans) {
                $here = $this->pos ?? [0, 0];
                $best = null; $bd = INF; $rev = false;
                foreach ($plans as $k => $p) {
                    $s = $p['blocks'][0][0];
                    $d = hypot($s[0] - $here[0], $s[1] - $here[1]);
                    if ($d < $bd) { $bd = $d; $best = $k; $rev = false; }
                    if ($p['reversible']) {
                        $lb = end($p['blocks']); $e = end($lb);
                        $d = hypot($e[0] - $here[0], $e[1] - $here[1]);
                        if ($d < $bd) { $bd = $d; $best = $k; $rev = true; }
                    }
                }
                $p = $plans[$best]; unset($plans[$best]);
                $blocks = $rev ? array_map('array_reverse', array_reverse($p['blocks'])) : $p['blocks'];
                foreach ($blocks as $b) $this->sewBlock($b, $p['reg']);
            }
        }
        $this->tieOff();
        if (!$this->order) throw new RuntimeException('Nothing to stitch – try fewer colors or turn off background removal.');
    }

    /** Decide how a single shape is stitched and build its stitch blocks. */
    private function planObject(array $reg): ?array
    {
        $px = $this->px;
        $areaMm = $reg['area'] * $px * $px;
        if ($areaMm < max(0.25, $this->o['min_run'] ** 2)) return null;           // speck
        $meanW = 2 * $reg['area'] / max(1, $reg['perim']) * $px;                    // ≈ average width (mm)
        if (max($reg['w'], $reg['h']) * $px < 4.5 && $meanW < 1.2) $this->objects['tiny']++;   // e.g. very small text

        if ($meanW <= $this->o['satin_max'] * 1.1) {
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
                    return ['reg' => $reg, 'reversible' => true, 'blocks' => $this->nearestOrder(array_map(fn($s) => $this->runBlock($s['pts']), $segs))];
                }
                if ($w90 <= $this->o['satin_max'] && $len > 1.0) {
                    $this->objects['satin']++;
                    $blocks = [];
                    foreach ($segs as $s) { $b = $this->satinBlock($reg, $s, $dt); if ($b) $blocks[] = $b; }
                    if ($blocks) return ['reg' => $reg, 'reversible' => true, 'blocks' => $this->nearestOrder($blocks)];
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
                $this->fill($inner, $this->o['angle'] + 90, $this->o['underlay_gap'], 4.0, 0, 0), $edgeEnd));
        }
        for ($l = 0; $l < $this->o['layers']; $l++) {
            $last = $blocks ? end($blocks) : null;
            $blocks = array_merge($blocks, $this->nearestOrder(
                $this->fill($reg, $this->o['angle'] + $l * 180 / $this->o['layers'], $this->o['spacing'],
                    $this->o['stitch_len'], $this->o['satin_max'], $this->o['pull_comp']), $last ? end($last) : null));
        }
        return ['reg' => $reg, 'reversible' => false, 'blocks' => array_values(array_filter($blocks))];
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
        $w = $this->w; $h = $this->h;
        for ($n = 0; $n < $r; $n++) {
            $out = $m;
            for ($y = 1; $y < $h - 1; $y++)
                for ($x = 1; $x < $w - 1; $x++) {
                    $i = $y * $w + $x;
                    if ($m[$i] === "\0" && $this->labels[$i] !== "\xFF"
                        && ($m[$i - 1] === "\1" || $m[$i + 1] === "\1" || $m[$i - $w] === "\1" || $m[$i + $w] === "\1"))
                        $out[$i] = "\1";
                }
            $m = $out;
        }
        return $m;
    }

    /** Connected shapes (8-connected) with a local mask padded by 1 pixel. */
    private function components(string $mask): array
    {
        $w = $this->w; $h = $this->h; $n = $w * $h;
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
            $regions[] = ['x0' => $minx - 1, 'y0' => $miny - 1, 'w' => $rw, 'h' => $rh, 'm' => $m, 'area' => count($q), 'perim' => $perim];
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
        $path = self::resample($path, $o['spacing']);
        $n = count($path);
        if ($n < 2) return [];
        $k = max(1, (int)ceil(1.0 / $o['spacing']));
        $rows = []; $maxW = 0;
        // typical half-width of this column; junction blobs must not widen it (no fans at joins)
        $hws = array_map(fn($p) => $this->halfWidth($r, $dt, $p), $path); sort($hws);
        $typ = $hws[intdiv(count($hws), 2)];
        for ($i = 0; $i < $n; $i++) {
            $a = $path[max(0, $i - $k)]; $b = $path[min($n - 1, $i + $k)];
            $tx = $b[0] - $a[0]; $ty = $b[1] - $a[1]; $tl = hypot($tx, $ty) ?: 1;
            $nx = -$ty / $tl; $ny = $tx / $tl;
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
                $step = max(1, (int)round(2.0 / $o['spacing'])); $side = 1;
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
            // split satin: stitches wider than 7 mm get staggered needle points so they don't snag
            $parts = (int)ceil($width / 7.0);
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
                // travel inside the shape with short stitches (hidden under / blends with the fill)
                $nSt = (int)ceil($d / 2.5);
                for ($i = 1; $i <= $nSt; $i++)
                    $this->stitches[] = [$this->pos[0] + ($x - $this->pos[0]) * $i / $nSt, $this->pos[1] + ($y - $this->pos[1]) * $i / $nSt, 'S'];
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
        $dx = ($toward[0] - $x) / $d * 0.5; $dy = ($toward[1] - $y) / $d * 0.5;
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
        $move = function (int $tx, int $ty, string $type) use (&$cur, $emit) {
            // split moves larger than the DST limit of 121 units (12.1 mm)
            while ($cur[0] !== $tx || $cur[1] !== $ty)
                $emit(max(-121, min(121, $tx - $cur[0])), max(-121, min(121, $ty - $cur[1])), $type);
        };

        $minSt = max(0, $this->o['min_stitch']) * 10; $prevT = '';
        foreach ($this->stitches as [$x, $y, $t]) {
            if ($t === 'C') { $body .= self::encode(0, 0, 'C'); $count++; $colorChanges++; $prevT = 'C'; continue; }
            if ($t === 'T') { $trims++; $emit(2, 2, 'J'); $emit(-4, -4, 'J'); $emit(2, 2, 'J'); $prevT = 'T'; continue; } // Tajima trim = 3 jumps
            $tx = (int)round(($x - $cx) * 10);
            $ty = (int)round(($cy - $y) * 10);
            // skip too-short stitches: tiny needle moves shred and break the thread
            if ($t === 'S' && $prevT === 'S' && hypot($tx - $cur[0], $ty - $cur[1]) < $minSt) continue;
            $prevT = $t;
            $move($tx, $ty, $t === 'J' ? 'J' : 'S');
        }
        $body .= self::encode(0, 0, 'E'); $count++;

        $label = substr(preg_replace('/[^A-Za-z0-9 _-]/', '', $this->o['label']) ?: 'DESIGN', 0, 16);
        $h  = sprintf("LA:%-16s\r", $label);
        $h .= sprintf("ST:%7d\r", $count);
        $h .= sprintf("CO:%3d\r", $colorChanges);
        $h .= sprintf("+X:%5d\r-X:%5d\r+Y:%5d\r-Y:%5d\r", $bx[1], -$bx[0], $by[1], -$by[0]);
        $h .= sprintf("AX:+%5d\rAY:+%5d\rMX:+%5d\rMY:+%5d\rPD:******\r", 0, 0, 0, 0);
        $h .= "\x1A";
        $h = str_pad($h, 512, ' ');

        $this->stats = [
            'stitches' => $count,
            'colors'   => $colorChanges + 1,
            'width'    => ($bx[1] - $bx[0]) / 10,
            'height'   => ($by[1] - $by[0]) / 10,
            'trims'    => $trims,
            'objects'  => $this->objects,
        ];
        $this->stats['warnings'] = $this->risks();
        return $h . $body;
    }

    /** Settings that commonly cause thread breaks, birdnests or puckering. */
    private function risks(): array
    {
        $o = $this->o; $w = []; $st = $this->stats;
        if ($o['spacing'] < 0.3) $w[] = sprintf('Very dense (%.2f mm row spacing). Below ~0.3 mm the needle hits its own stitches and the thread may break or the fabric can tear. Lower Coverage.', $o['spacing']);
        if ($o['layers'] > 1) $w[] = sprintf('%d fill layers: full coverage but stiff and heavy. Sew slower (about 600 spm), use a sharp 75/11 needle and firm backing.', $o['layers']);
        if ($o['stitch_len'] < 2) $w[] = 'Fill stitch length under 2 mm: lots of short stitches. Use 3–4 mm.';
        if ($o['satin_max'] > 8) $w[] = 'Satin wider than 8 mm: long satins are split automatically, but 6–7 mm looks best.';
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
        $svg = sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %.1f %.1f" style="width:100%%;height:auto;background:#fff">', $W, $H);
        $ci = 0; $path = ''; $prev = null;
        $flush = function () use (&$svg, &$path, &$ci) {
            if ($path !== '') {
                $c = $this->colors[$this->order[$ci]];
                $svg .= sprintf('<path d="%s" fill="none" stroke="rgb(%d,%d,%d)" stroke-width="0.4" stroke-linecap="round" stroke-linejoin="round"/>', $path, ...$c);
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
        return $svg . '</svg>';
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
