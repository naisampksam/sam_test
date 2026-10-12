<?php
/**
 * Simple image -> embroidery (Tajima .DST) auto-digitizer.
 *
 * Pipeline: load image -> scale to real size (mm) -> reduce to N thread colors
 * -> clean up speckles -> for every color: underlay + tatami/satin fill
 * -> order stitches, add lock stitches & trims -> encode as DST.
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

    public static function defaults(): array
    {
        return [
            'width_mm'      => 100,   // final design width
            'colors'        => 6,     // number of thread colors (auto palette)
            'palette'       => '',    // optional fixed thread colors "#ff0000,#000000"
            'remove_bg'     => 1,     // drop the background (corner) color
            'cleanup'       => 2,     // speckle cleanup passes
            'density'       => 0.40,  // fill row spacing (mm)
            'stitch_len'    => 3.5,   // max tatami stitch length (mm)
            'satin_max'     => 7.0,   // runs shorter than this are sewn as one satin stitch
            'angle'         => 45,    // fill angle (deg)
            'underlay'      => 1,     // underlay on/off
            'underlay_gap'  => 2.0,   // underlay row spacing (mm)
            'inset'         => 0.4,   // underlay inset from edges (mm)
            'pull_comp'     => 0.2,   // pull compensation added to each end of a row (mm)
            'min_run'       => 0.6,   // ignore areas thinner than this (mm)
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
        $o['width_mm']   = max(10, min(400, (float)$o['width_mm']));
        $o['colors']     = max(1, min(30, (int)$o['colors']));
        $o['density']    = max(0.15, min(3, (float)$o['density']));
        $o['stitch_len'] = max(1, min(12, (float)$o['stitch_len']));
        $o['satin_max']  = max(0, min(12, (float)$o['satin_max']));
        $o['underlay_gap'] = max(0.5, min(6, (float)$o['underlay_gap']));
        $o['coverage']   = max(50, min(300, (float)$o['coverage']));
        $o['layers']     = max(1, min(3, (int)$o['layers']));
        $o['overlap']    = max(0, min(2, (float)$o['overlap']));
        // effective row spacing: higher coverage -> rows closer together
        $o['spacing']    = max(0.12, $o['density'] * 100 / $o['coverage']);
        $this->o = $o;
    }

    /* ------------------------------------------------------------------ */
    /*  1. Image loading & color reduction                                 */
    /* ------------------------------------------------------------------ */

    public function load(string $file): void
    {
        $data = file_get_contents($file);
        $src = @imagecreatefromstring($data);
        if (!$src) throw new RuntimeException('Could not read image. Use PNG, JPG, GIF, WEBP or BMP.');

        $sw = imagesx($src); $sh = imagesy($src);
        // grid resolution: half the row spacing, but cap grid size for speed
        $this->px = max($this->o['spacing'] / 2, $this->o['width_mm'] / 1200);
        $this->w = max(2, (int)round($this->o['width_mm'] / $this->px));
        $this->h = max(2, (int)round($this->w * $sh / $sw));

        $img = imagecreatetruecolor($this->w, $this->h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagecopyresampled($img, $src, 0, 0, 0, 0, $this->w, $this->h, $sw, $sh);
        imagedestroy($src);

        // read pixels; alpha (0 opaque .. 127 transparent)
        $pix = [];
        for ($y = 0; $y < $this->h; $y++) {
            for ($x = 0; $x < $this->w; $x++) {
                $c = imagecolorat($img, $x, $y);
                $a = ($c >> 24) & 0x7F;
                $pix[] = $a > 63 ? null : [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
            }
        }
        imagedestroy($img);

        $this->colors = $this->buildPalette($pix);
        $this->assign($pix);
        if (trim($this->o['palette']) === '') $this->mergeTinyColors();
        for ($i = 0; $i < (int)$this->o['cleanup']; $i++) $this->majorityFilter();
        if ($this->o['remove_bg']) $this->removeBackground();
    }

    private function buildPalette(array $pix): array
    {
        // fixed palette from the user (their actual thread colors)
        if (trim($this->o['palette']) !== '') {
            $pal = [];
            foreach (preg_split('/[\s,;]+/', $this->o['palette']) as $hex) {
                $hex = ltrim(trim($hex), '#');
                if (preg_match('/^[0-9a-f]{6}$/i', $hex)) {
                    $pal[] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
                }
            }
            if ($pal) return $pal;
        }

        // k-means on a sample of opaque pixels
        $sample = [];
        $opaque = array_values(array_filter($pix));
        if (!$opaque) throw new RuntimeException('Image is fully transparent.');
        $step = max(1, intdiv(count($opaque), 6000));
        for ($i = 0; $i < count($opaque); $i += $step) $sample[] = $opaque[$i];

        $k = min($this->o['colors'], count($sample));
        // k-means++ style init: farthest point
        $cent = [$sample[0]];
        while (count($cent) < $k) {
            $best = null; $bestD = -1;
            foreach ($sample as $p) {
                $d = PHP_INT_MAX;
                foreach ($cent as $c) $d = min($d, self::dist2($p, $c));
                if ($d > $bestD) { $bestD = $d; $best = $p; }
            }
            if ($bestD <= 0) break;
            $cent[] = $best;
        }
        for ($it = 0; $it < 12; $it++) {
            $sum = array_fill(0, count($cent), [0, 0, 0, 0]);
            foreach ($sample as $p) {
                $ci = self::nearest($p, $cent);
                $sum[$ci][0] += $p[0]; $sum[$ci][1] += $p[1]; $sum[$ci][2] += $p[2]; $sum[$ci][3]++;
            }
            foreach ($sum as $i => $s) {
                if ($s[3]) $cent[$i] = [intdiv($s[0], $s[3]), intdiv($s[1], $s[3]), intdiv($s[2], $s[3])];
            }
        }
        return $cent;
    }

    private function assign(array $pix): void
    {
        $lab = '';
        $cache = [];
        foreach ($pix as $p) {
            if ($p === null) { $lab .= "\xFF"; continue; }
            $key = ($p[0] >> 2) << 12 | ($p[1] >> 2) << 6 | ($p[2] >> 2);
            if (!isset($cache[$key])) $cache[$key] = chr(self::nearest($p, $this->colors));
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
        $bigPal = array_intersect_key($this->colors, $big);
        $map = [];
        foreach ($cnt as $ci => $n) {
            if (isset($big[$ci])) continue;
            $best = null; $bd = PHP_INT_MAX;
            foreach ($bigPal as $bi => $c) { $d = self::dist2($this->colors[$ci], $c); if ($d < $bd) { $bd = $d; $best = $bi; } }
            $map[chr($ci)] = chr($best);
        }
        if ($map) $this->labels = strtr($this->labels, $map);
    }

    /** 3x3 majority vote: removes single-pixel noise and jagged edges. */
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
                arsort($cnt);
                $top = array_key_first($cnt);
                if ($cnt[$top] >= 5) $dst[$y * $w + $x] = (string)$top;
            }
        }
        $this->labels = $dst;
    }

    /** Treat the most common border color as background (not stitched). */
    private function removeBackground(): void
    {
        $w = $this->w; $h = $this->h; $cnt = [];
        for ($x = 0; $x < $w; $x++) { foreach ([$x, ($h - 1) * $w + $x] as $i) { $v = $this->labels[$i]; $cnt[$v] = ($cnt[$v] ?? 0) + 1; } }
        for ($y = 0; $y < $h; $y++) { foreach ([$y * $w, $y * $w + $w - 1] as $i) { $v = $this->labels[$i]; $cnt[$v] = ($cnt[$v] ?? 0) + 1; } }
        arsort($cnt);
        $bg = (string)array_key_first($cnt);
        if ($bg === "\xFF") return; // already transparent
        $this->labels = str_replace($bg, "\xFF", $this->labels);
    }

    /* ------------------------------------------------------------------ */
    /*  2. Stitch generation                                               */
    /* ------------------------------------------------------------------ */

    public function digitize(): void
    {
        // sew big areas first (background-ish areas under details)
        $areas = count_chars($this->labels, 1);
        unset($areas[255]);
        arsort($areas);

        $first = true;
        $this->order = [];
        foreach (array_keys($areas) as $ci) {
            $mask = $this->maskFor($ci);
            $grown = $this->growIntoNeighbours($mask, (int)round($this->o['overlap'] / $this->px));
            $blocks = [];
            if ($this->o['underlay']) {
                $inner = $this->erode($mask, (int)round($this->o['inset'] / $this->px));
                $blocks = array_merge($blocks, $this->nearestOrder($this->fill($inner, $this->o['angle'] + 90, $this->o['underlay_gap'], 4.0, 0, 0)));
            }
            // top fill; extra layers are crossed at an angle for maximum coverage
            for ($l = 0; $l < $this->o['layers']; $l++) {
                $blocks = array_merge($blocks, $this->nearestOrder($this->fill($grown, $this->o['angle'] + $l * 180 / $this->o['layers'],
                    $this->o['spacing'], $this->o['stitch_len'], $this->o['satin_max'], $this->o['pull_comp'])));
            }
            if (!$blocks) continue;

            if (!$first) $this->stitches[] = [null, null, 'C'];
            $first = false;
            $this->order[] = $ci;
            foreach ($blocks as $b) $this->sewBlock($b);
            $this->tieOff();
        }
        if (!$this->order) throw new RuntimeException('Nothing to stitch – try fewer colors or turn off background removal.');
    }

    public array $order = [];

    /** Sew the closest block next (either direction) - fewer jumps and trims. */
    private function nearestOrder(array $blocks): array
    {
        $out = [];
        $pos = $this->pos ?? ($blocks[0][0] ?? [0, 0]);
        while ($blocks) {
            $best = null; $bd = INF; $rev = false;
            foreach ($blocks as $k => $b) {
                $d0 = hypot($b[0][0] - $pos[0], $b[0][1] - $pos[1]);
                $e = end($b);
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

    private function maskFor(int $ci): string
    {
        $c = chr($ci);
        $m = '';
        $len = strlen($this->labels);
        for ($i = 0; $i < $len; $i++) $m .= $this->labels[$i] === $c ? "\1" : "\0";
        return $m;
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

    private function erode(string $m, int $r): string
    {
        $w = $this->w; $h = $this->h;
        for ($n = 0; $n < $r; $n++) {
            $out = $m;
            for ($y = 0; $y < $h; $y++)
                for ($x = 0; $x < $w; $x++) {
                    $i = $y * $w + $x;
                    if ($m[$i] === "\1" && ($x == 0 || $y == 0 || $x == $w - 1 || $y == $h - 1
                        || $m[$i - 1] === "\0" || $m[$i + 1] === "\0" || $m[$i - $w] === "\0" || $m[$i + $w] === "\0"))
                        $out[$i] = "\0";
                }
            $m = $out;
        }
        return $m;
    }

    /**
     * Scan-line fill at an angle. Returns blocks: each block is a list of
     * stitch points (mm) that can be sewn continuously.
     */
    private function fill(string $mask, float $angleDeg, float $spacing, float $len, float $satinMax, float $pull): array
    {
        $a = deg2rad($angleDeg);
        $ux = cos($a); $uy = sin($a);     // along the row
        $nx = -$uy;    $ny = $ux;         // across rows
        $W = $this->w * $this->px; $H = $this->h * $this->px;
        $corners = [[0, 0], [$W, 0], [0, $H], [$W, $H]];
        $tMin = $sMin = INF; $tMax = $sMax = -INF;
        foreach ($corners as [$cx, $cy]) {
            $t = $cx * $nx + $cy * $ny; $s = $cx * $ux + $cy * $uy;
            $tMin = min($tMin, $t); $tMax = max($tMax, $t);
            $sMin = min($sMin, $s); $sMax = max($sMax, $s);
        }
        $step = $this->px / 2;
        $minRun = $this->o['min_run'];

        // collect runs per row
        $rows = [];
        for ($t = $tMin + $spacing / 2, $r = 0; $t < $tMax; $t += $spacing, $r++) {
            $runs = []; $in = false; $start = 0;
            for ($s = $sMin; $s <= $sMax + $step; $s += $step) {
                $x = $ux * $s + $nx * $t; $y = $uy * $s + $ny * $t;
                $gx = (int)floor($x / $this->px); $gy = (int)floor($y / $this->px);
                $inside = $gx >= 0 && $gy >= 0 && $gx < $this->w && $gy < $this->h
                    && $mask[$gy * $this->w + $gx] === "\1";
                if ($inside && !$in) { $in = true; $start = $s; }
                elseif (!$inside && $in) {
                    $in = false;
                    if ($s - $start >= $minRun) $runs[] = [$start - $pull, $s - $step + $pull];
                }
            }
            $rows[$r] = ['t' => $t, 'runs' => $runs];
        }

        // chain runs row-to-row into continuous blocks (zig-zag)
        $used = [];
        $blocks = [];
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
                    $pts = $this->rowStitches($s0, $s1, $len, $satinMax, $cr, $dir);
                    foreach ($pts as $s) $block[] = [$ux * $s + $nx * $t, $uy * $s + $ny * $t];
                    $endS = $dir > 0 ? $s1 : $s0;
                    // next row: overlapping run whose start is near our end
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
                // drop specks (fewer than 3 rows): they only add trims & birdnests
                if (count($block) >= 6) $blocks[] = $block;
            }
        }
        return $blocks;
    }

    /** Points along one row; brick offset so needle holes don't line up. */
    private function rowStitches(float $s0, float $s1, float $len, float $satinMax, int $row, int $dir): array
    {
        $L = $s1 - $s0;
        if ($L <= max($satinMax, $len)) {
            $pts = [$s0, $s1];                 // satin-style single stitch
        } else {
            $pts = [$s0];
            $off = ($row % 3) * $len / 3;      // tatami brick pattern
            for ($s = $s0 + $len - $off; $s < $s1 - $len * 0.3; $s += $len) if ($s > $s0 + 0.3) $pts[] = $s;
            $pts[] = $s1;
        }
        return $dir > 0 ? $pts : array_reverse($pts);
    }

    private ?array $pos = null; // current needle position (mm)

    private function sewBlock(array $pts): void
    {
        if (!$pts) return;
        [$x, $y] = $pts[0];
        if ($this->pos !== null) {
            $d = hypot($x - $this->pos[0], $y - $this->pos[1]);
            if ($d > $this->o['trim_at']) {
                $this->tieOff();
                $this->stitches[] = [null, null, 'T'];
            }
            if ($d > 0.05) $this->stitches[] = [$x, $y, 'J'];
        } else {
            $this->stitches[] = [$x, $y, 'J'];
        }
        $needTie = $this->pos === null || end($this->stitches)[2] !== 'S';
        $this->pos = [$x, $y];
        $this->stitches[] = [$x, $y, 'S'];
        if ($needTie && $this->o['lock'] && isset($pts[1])) $this->tieIn($pts[1]);
        for ($i = 1; $i < count($pts); $i++) {
            $this->stitches[] = [$pts[$i][0], $pts[$i][1], 'S'];
            $this->pos = $pts[$i];
        }
    }

    private function tieIn(array $toward): void
    {
        [$x, $y] = $this->pos;
        $d = hypot($toward[0] - $x, $toward[1] - $y) ?: 1;
        $dx = ($toward[0] - $x) / $d * 0.5; $dy = ($toward[1] - $y) / $d * 0.5;
        foreach ([[1, 1], [0, 0], [1, 1], [0, 0]] as [$m]) $this->stitches[] = [$x + $dx * $m, $y + $dy * $m, 'S'];
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

        $body = '';
        $count = 0; $colorChanges = 0;
        $cur = [0, 0];
        $bx = [0, 0]; $by = [0, 0];
        // every record updates the needle position and the design bounds
        $emit = function (int $dx, int $dy, string $type) use (&$body, &$count, &$cur, &$bx, &$by) {
            $body .= self::encode($dx, $dy, $type); $count++;
            $cur[0] += $dx; $cur[1] += $dy;
            $bx = [min($bx[0], $cur[0]), max($bx[1], $cur[0])];
            $by = [min($by[0], $cur[1]), max($by[1], $cur[1])];
        };
        $move = function (int $tx, int $ty, string $type) use (&$cur, $emit) {
            // split moves larger than the DST limit of 121 units (12.1 mm)
            while ($cur[0] !== $tx || $cur[1] !== $ty) {
                $emit(max(-121, min(121, $tx - $cur[0])), max(-121, min(121, $ty - $cur[1])), $type);
            }
        };

        $minSt = max(0, (float)$this->o['min_stitch']) * 10; $prevT = '';
        $trims = 0;
        foreach ($this->stitches as [$x, $y, $t]) {
            if ($t === 'C') { $body .= self::encode(0, 0, 'C'); $count++; $colorChanges++; continue; }
            if ($t === 'T') { $trims++; $emit(2, 2, 'J'); $emit(-4, -4, 'J'); $emit(2, 2, 'J'); continue; } // Tajima trim = 3 jumps
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
        ];
        $this->stats['warnings'] = $this->risks();
        return $h . $body;
    }

    public array $stats = [];

    /** Settings that commonly cause thread breaks, birdnests or puckering. */
    private function risks(): array
    {
        $o = $this->o; $w = [];
        if ($o['spacing'] < 0.3) $w[] = sprintf('Very dense (%.2f mm row spacing). Below ~0.3 mm the needle hits its own stitches and the thread may break or the fabric can tear. Lower Coverage.', $o['spacing']);
        if ($o['layers'] > 1) $w[] = sprintf('%d fill layers: full coverage but stiff and heavy. Sew slower (about 600 spm), use a sharp 75/11 needle and firm backing.', $o['layers']);
        if ($o['stitch_len'] < 2) $w[] = 'Fill stitch length under 2 mm: lots of short stitches. Use 3–4 mm.';
        if ($o['satin_max'] > 8) $w[] = 'Satin wider than 8 mm: long loose stitches can snag. Keep it at 7 mm or less.';
        if ($o['min_stitch'] < 0.3) $w[] = 'Minimum stitch below 0.3 mm: tiny stitches are a common cause of thread breaks.';
        $st = $this->stats;
        if ($st['trims'] > 20 && $st['trims'] * 150 > $st['stitches'])
            $w[] = "{$st['trims']} trims - the design is very fragmented (looks like a photo or noisy image). Use a clean logo, fewer colors, more cleanup passes or a bigger 'Skip areas thinner than'.";
        if ($st['width'] > 400 || $st['height'] > 400) $w[] = 'Design is larger than most hoops (400 mm).';
        if (!$o['lock']) $w[] = 'Lock stitches are off: thread may pull out after trims.';
        return $w;
    }

    /** Encode one DST 3-byte record (balanced ternary). */
    private static function encode(int $x, int $y, string $type): string
    {
        $b0 = $b1 = 0; $b2 = 0x03;
        if ($type === 'J') $b2 |= 0x80;
        if ($type === 'C') return "\x00\x00\xC3";
        if ($type === 'E') return "\x00\x00\xF3";

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
                $svg .= sprintf('<path d="%s" fill="none" stroke="rgb(%d,%d,%d)" stroke-width="0.25" stroke-linecap="round"/>', $path, ...$c);
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

    private function hex(int $ci): string
    {
        return sprintf('#%02x%02x%02x', ...$this->colors[$ci]);
    }

    private static function dist2(array $a, array $b): int
    {
        // weighted RGB distance (closer to human perception)
        $dr = $a[0] - $b[0]; $dg = $a[1] - $b[1]; $db = $a[2] - $b[2];
        return 2 * $dr * $dr + 4 * $dg * $dg + 3 * $db * $db;
    }

    private static function nearest(array $p, array $pal): int
    {
        $best = 0; $bd = PHP_INT_MAX;
        foreach ($pal as $i => $c) { $d = self::dist2($p, $c); if ($d < $bd) { $bd = $d; $best = $i; } }
        return $best;
    }
}
