<?php
/**
 * Imported .DST files: read the stitches, change them and write a clean DST again.
 *
 *  - "edit" keeps the original stitches: size, rotation, mirror, thread colors, sewing order, leave colors out,
 *    erase or REDO marked areas (only that area is digitized again), stitch length and trim fixes.
 *  - "redo" draws the stitches as a picture and digitizes the whole design again with the app's settings.
 *
 * Coordinates are mm with y pointing down (like the Digitizer). Needs digitizer.php.
 */
class DstEdit
{
    /** per color stop: [['cut' => bool, 'pts' => [[x, y], …]], …] – a "run" is sewn without a jump */
    public array $blocks = [];
    public array $hex = [];          // thread color per color stop (from the file's TC lines, else a default)
    public array $info = [];         // what the original file holds
    public array $shapes = [];       // for the page's "fix an area" feedback & suggestions
    public array $warnings = [];

    private const PALETTE = ['#1a1a1a', '#d32f2f', '#1e40af', '#f5b700', '#15803d', '#7c3aed', '#ea580c', '#0891b2',
        '#db2777', '#78350f', '#64748b', '#84cc16', '#0f766e', '#9f1239', '#c4a35a', '#60a5fa'];
    private const THREAD = 0.45;     // thread width (mm) when the stitches are drawn as a picture

    /** Read a Tajima DST file. */
    public static function read(string $bin): self
    {
        if (strlen($bin) < 515 || (strpos(substr($bin, 0, 512), 'ST:') === false && strncmp($bin, 'LA:', 3) !== 0))
            throw new RuntimeException('This is not a Tajima .DST embroidery file.');
        $e = new self();
        foreach (preg_split('/[\r\n]+/', substr($bin, 0, 512)) as $line)       // optional thread list "TC:#rrggbb,name,code"
            if (preg_match('/^TC:\s*(#[0-9a-f]{6})/i', trim($line), $m)) $e->hex[] = strtolower($m[1]);

        $x = $y = 0; $jumps = 0; $cut = true; $run = null; $block = []; $count = 0; $n = strlen($bin);
        $close = function () use (&$run, &$block) { if ($run && count($run['pts'])) $block[] = $run; $run = null; };
        for ($i = 512; $i + 3 <= $n; $i += 3) {
            $b0 = ord($bin[$i]); $b1 = ord($bin[$i + 1]); $b2 = ord($bin[$i + 2]);
            if (($b2 & 0xF3) === 0xF3) break;                                   // end
            $dx = (($b2 >> 2) & 1) * 81 - (($b2 >> 3) & 1) * 81 + (($b1 >> 2) & 1) * 27 - (($b1 >> 3) & 1) * 27
                + (($b0 >> 2) & 1) * 9 - (($b0 >> 3) & 1) * 9 + ($b1 & 1) * 3 - (($b1 >> 1) & 1) * 3 + ($b0 & 1) - (($b0 >> 1) & 1);
            $dy = (($b2 >> 5) & 1) * 81 - (($b2 >> 4) & 1) * 81 + (($b1 >> 5) & 1) * 27 - (($b1 >> 4) & 1) * 27
                + (($b0 >> 5) & 1) * 9 - (($b0 >> 4) & 1) * 9 + (($b1 >> 7) & 1) * 3 - (($b1 >> 6) & 1) * 3 + (($b0 >> 7) & 1) - (($b0 >> 6) & 1);
            $x += $dx; $y -= $dy;                                               // DST: y up -> y down
            $count++;
            if (($b2 & 0xC3) === 0xC3) {                                        // color change
                $close();
                if ($block) { $e->blocks[] = $block; $block = []; }
                $jumps = 0; $cut = true; continue;
            }
            if ($b2 & 0xC0) { $close(); $jumps++; continue; }                   // jump (or sequin move)
            if (!$run) { $run = ['cut' => $cut || $jumps >= 3, 'pts' => []]; } // 3+ jumps in a row = the machine cuts
            $run['pts'][] = [$x / 10, $y / 10];
            $jumps = 0; $cut = false;
        }
        $close();
        if ($block) $e->blocks[] = $block;
        if (!$e->blocks) throw new RuntimeException('This DST file has no stitches.');
        foreach ($e->blocks as $bi => $_) $e->hex[$bi] = $e->hex[$bi] ?? self::defaultColor($bi);
        $e->hex = array_slice($e->hex, 0, count($e->blocks));
        [$x0, $y0, $x1, $y1] = $e->bbox();
        $st = 0; foreach ($e->blocks as $b) foreach ($b as $r) $st += count($r['pts']);
        $e->info = ['width' => round($x1 - $x0, 1), 'height' => round($y1 - $y0, 1), 'stitches' => $st,
                    'records' => $count, 'colors' => count($e->blocks)];
        return $e;
    }

    private static function defaultColor(int $i): string
    {
        if ($i < count(self::PALETTE)) return self::PALETTE[$i];
        $h = fmod($i * 137.508, 360) / 60; $c = 0.7; $xx = $c * (1 - abs(fmod($h, 2) - 1)); $m = 0.15;   // golden-angle hues
        $rgb = [[$c, $xx, 0], [$xx, $c, 0], [0, $c, $xx], [0, $xx, $c], [$xx, 0, $c], [$c, 0, $xx]][(int)$h % 6];
        return sprintf('#%02x%02x%02x', ...array_map(fn($v) => (int)round(($v + $m) * 255), $rgb));
    }

    public function bbox(): array
    {
        $x0 = $y0 = INF; $x1 = $y1 = -INF;
        foreach ($this->blocks as $b) foreach ($b as $r) foreach ($r['pts'] as [$x, $y]) {
            if ($x < $x0) $x0 = $x; if ($x > $x1) $x1 = $x; if ($y < $y0) $y0 = $y; if ($y > $y1) $y1 = $y;
        }
        return is_finite($x0) ? [$x0, $y0, $x1, $y1] : [0, 0, 1, 1];
    }

    private function map(callable $f): void
    {
        foreach ($this->blocks as &$b) foreach ($b as &$r) foreach ($r['pts'] as &$p) $p = $f($p);
        unset($b, $r, $p);
    }

    /** Keep / reorder color stops: $order lists original stop numbers in the new sewing order. */
    public function pick(array $order): array
    {
        $keep = []; $blocks = []; $hex = [];
        foreach ($order as $i) if (isset($this->blocks[$i]) && !in_array($i, $keep, true)) { $keep[] = $i; $blocks[] = $this->blocks[$i]; $hex[] = $this->hex[$i]; }
        if (!$blocks) throw new RuntimeException('All colors are left out – tick at least one color to sew.');
        $this->blocks = $blocks; $this->hex = $hex;
        return $keep;
    }

    /** Mirror, rotate (degrees, clockwise), then scale to the wanted size. Returns the design's own width : height. */
    public function transform(bool $mirX, bool $mirY, float $rot, float $wMm, float $hMm): float
    {
        [$x0, $y0, $x1, $y1] = $this->bbox(); $cx = ($x0 + $x1) / 2; $cy = ($y0 + $y1) / 2;
        $a = deg2rad($rot); $c = cos($a); $s = sin($a);
        $this->map(function ($p) use ($mirX, $mirY, $cx, $cy, $c, $s) {
            $x = ($mirX ? -1 : 1) * ($p[0] - $cx); $y = ($mirY ? -1 : 1) * ($p[1] - $cy);
            return [$x * $c - $y * $s, $x * $s + $y * $c];
        });
        [$x0, $y0, $x1, $y1] = $this->bbox(); $W = max(0.1, $x1 - $x0); $H = max(0.1, $y1 - $y0);
        $sx = $wMm > 0 ? $wMm / $W : 1.0;
        $sy = $hMm > 0 ? $hMm / $H : $sx;
        if ($wMm <= 0 && $hMm > 0) $sx = $sy;
        $sx = max(0.05, min(20, $sx)); $sy = max(0.05, min(20, $sy));
        $this->map(fn($p) => [($p[0] - $x0) * $sx, ($p[1] - $y0) * $sy]);
        $sc = max($sx, $sy) >= 1 ? max($sx, $sy) : min($sx, $sy);
        if (abs($sc - 1) > 0.2)
            $this->warnings[] = sprintf('The stitches were resized to %d %% of the original. The stitch density does not change with the size, so the '
                . 'design looks %s. For a big size change use “Re-digitize the whole design”.', round($sc * 100), $sc > 1 ? 'thinner with gaps and longer satin stitches' : 'denser and stiffer – risk of thread breaks');
        if (abs($sx - $sy) > 0.02 * max($sx, $sy)) $this->warnings[] = 'Width and height are stretched differently – stitch angles and density change.';
        return $W / $H;
    }

    /** Split stitches longer than $max mm (no long loose loops). */
    public function splitLong(float $max): int
    {
        if ($max <= 0) return 0;
        $n = 0;
        foreach ($this->blocks as &$b) foreach ($b as &$r) {
            $out = [$r['pts'][0]];
            for ($i = 1; $i < count($r['pts']); $i++) {
                $a = end($out); $p = $r['pts'][$i]; $d = hypot($p[0] - $a[0], $p[1] - $a[1]);
                $k = (int)ceil($d / $max);
                for ($j = 1; $j < $k; $j++) { $out[] = [$a[0] + ($p[0] - $a[0]) * $j / $k, $a[1] + ($p[1] - $a[1]) * $j / $k]; $n++; }
                $out[] = $p;
            }
            $r['pts'] = $out;
        }
        unset($b, $r);
        return $n;
    }

    /* ---------- marked areas ---------- */

    private static function inPoly(array $p, array $poly): bool
    {
        $in = false; $n = count($poly);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $poly[$i]; [$xj, $yj] = $poly[$j];
            if (($yi > $p[1]) !== ($yj > $p[1]) && $p[0] < ($xj - $xi) * ($p[1] - $yi) / ($yj - $yi) + $xi) $in = !$in;
        }
        return $in;
    }

    /** Where the stitch a→b crosses the polygon's edge (0..1 along the stitch). */
    private static function crossings(array $a, array $b, array $poly): array
    {
        $t = []; $n = count($poly); $rx = $b[0] - $a[0]; $ry = $b[1] - $a[1];
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $sx = $poly[$i][0] - $poly[$j][0]; $sy = $poly[$i][1] - $poly[$j][1];
            $den = $rx * $sy - $ry * $sx;
            if (abs($den) < 1e-12) continue;
            $qx = $poly[$j][0] - $a[0]; $qy = $poly[$j][1] - $a[1];
            $u = ($qx * $sy - $qy * $sx) / $den; $v = ($qx * $ry - $qy * $rx) / $den;
            if ($u > 1e-9 && $u < 1 - 1e-9 && $v >= 0 && $v <= 1) $t[] = $u;
        }
        sort($t);
        return $t;
    }

    /** Remove every stitch inside the polygon (mm). Cut stitches end exactly at its edge, with lock stitches. Returns mm of thread removed. */
    public function erase(array $poly, float $join = 1.2): float
    {
        $removed = 0.0;
        foreach ($this->blocks as &$b) {
            $out = [];
            foreach ($b as $r) {
                $pts = $r['pts']; $cur = null; $pieces = [];
                if (!self::inPoly($pts[0], $poly)) $cur = ['cut' => $r['cut'], 'pts' => [$pts[0]]];
                for ($i = 1; $i < count($pts); $i++) {
                    $a = $pts[$i - 1]; $p = $pts[$i];
                    $ts = array_merge([0.0], self::crossings($a, $p, $poly), [1.0]);
                    for ($k = 0; $k + 1 < count($ts); $k++) {
                        $t0 = $ts[$k]; $t1 = $ts[$k + 1];
                        $at = fn($t) => [$a[0] + ($p[0] - $a[0]) * $t, $a[1] + ($p[1] - $a[1]) * $t];
                        $len = hypot($p[0] - $a[0], $p[1] - $a[1]) * ($t1 - $t0);
                        if (self::inPoly($at(($t0 + $t1) / 2), $poly)) {
                            $removed += $len;
                            if ($cur) { $cur['tieOut'] = 1; $pieces[] = $cur; $cur = null; }
                        } else {
                            if (!$cur) $cur = ['cut' => true, 'pts' => [$at($t0)], 'tieIn' => 1];
                            $cur['pts'][] = $at($t1);
                        }
                    }
                }
                if ($cur) $pieces[] = $cur;
                array_push($out, ...self::chain($pieces, $join));
            }
            $b = [];
            foreach ($out as $r) if (($r = self::tie($r)) !== null) $b[] = $r;
        }
        unset($b);
        $this->blocks = array_values($this->blocks);
        return $removed;
    }

    /**
     * The pieces left of one stitch path after erasing (e.g. fill rows cut by the area): sew them nearest-first and
     * join pieces that end next to each other along the area's edge, so there is one cut per side, not one per row.
     */
    private static function chain(array $pieces, float $join): array
    {
        if (count($pieces) < 2) return $pieces;
        $res = []; $cur = array_shift($pieces);
        while ($pieces) {
            $e = end($cur['pts']); $best = null; $bd = INF; $rev = false;
            foreach ($pieces as $k => $p) {
                $f = $p['pts'][0]; $l = end($p['pts']);
                if (($d = hypot($f[0] - $e[0], $f[1] - $e[1])) < $bd) { $bd = $d; $best = $k; $rev = false; }
                if (($d = hypot($l[0] - $e[0], $l[1] - $e[1])) < $bd) { $bd = $d; $best = $k; $rev = true; }
            }
            $p = $pieces[$best]; unset($pieces[$best]);
            if ($rev) { $p['pts'] = array_reverse($p['pts']); [$p['tieIn'], $p['tieOut']] = [$p['tieOut'] ?? 0, $p['tieIn'] ?? 0]; }
            if ($bd <= $join) { array_push($cur['pts'], ...$p['pts']); $cur['tieOut'] = $p['tieOut'] ?? 0; }
            else { $res[] = $cur; $p['cut'] = true; $cur = $p; }
        }
        $res[] = $cur;
        return $res;
    }

    /** Lock stitches where a stitch path was cut open; drop leftovers too small to sew. */
    private static function tie(array $r): ?array
    {
        $pts = []; foreach ($r['pts'] as $p) if (!$pts || hypot($p[0] - end($pts)[0], $p[1] - end($pts)[1]) > 0.05) $pts[] = $p;
        $len = 0; for ($i = 1; $i < count($pts); $i++) $len += hypot($pts[$i][0] - $pts[$i - 1][0], $pts[$i][1] - $pts[$i - 1][1]);
        if (count($pts) < 2 || $len < 0.6) return null;
        $lock = function ($a, $b) {             // two tiny back-and-forth stitches at $a, toward $b
            $d = hypot($b[0] - $a[0], $b[1] - $a[1]); $k = min(0.5, $d) / max($d, 1e-9);
            $q = [$a[0] + ($b[0] - $a[0]) * $k, $a[1] + ($b[1] - $a[1]) * $k];
            return [$q, $a, $q, $a];
        };
        if (!empty($r['tieIn']) && hypot($pts[1][0] - $pts[0][0], $pts[1][1] - $pts[0][1]) >= 0.4)
            $pts = array_merge([$pts[0]], $lock($pts[0], $pts[1]), array_slice($pts, 1));
        if (!empty($r['tieOut'])) { $n = count($pts); if (hypot($pts[$n - 2][0] - $pts[$n - 1][0], $pts[$n - 2][1] - $pts[$n - 1][1]) >= 0.4) $pts = array_merge($pts, $lock($pts[$n - 1], $pts[$n - 2])); }
        return ['cut' => $r['cut'], 'pts' => $pts];
    }

    /** Draw the stitches as a picture (thread colors, thread width), area $rect = [x0, y0, x1, y1] mm. Returns a PNG file path. */
    public function render(array $rect, ?array $hex = null): string
    {
        [$x0, $y0, $x1, $y1] = $rect;
        $ppm = min(16, 5000 / max($x1 - $x0, $y1 - $y0));                     // pixels per mm
        $W = max(2, (int)ceil(($x1 - $x0) * $ppm)); $H = max(2, (int)ceil(($y1 - $y0) * $ppm));
        $img = imagecreatetruecolor($W, $H);
        imagealphablending($img, false); imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);
        imagesetthickness($img, max(1, (int)round(self::THREAD * $ppm)));
        $rad = max(1, (int)round(self::THREAD * $ppm));
        foreach ($this->blocks as $bi => $b) {
            $h = ltrim(($hex ?? $this->hex)[$bi], '#');
            $col = imagecolorallocate($img, hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)));
            foreach ($b as $r) {
                $prev = null;
                foreach ($r['pts'] as [$x, $y]) {
                    $q = [(int)round(($x - $x0) * $ppm), (int)round(($y - $y0) * $ppm)];
                    if ($prev) imageline($img, $prev[0], $prev[1], $q[0], $q[1], $col);
                    imagefilledellipse($img, $q[0], $q[1], $rad, $rad, $col);          // round thread ends
                    $prev = $q;
                }
            }
        }
        $f = tempnam(sys_get_temp_dir(), 'dstr');
        imagepng($img, $f); imagedestroy($img);
        return $f;
    }

    /** Distinct thread colors as the Digitizer palette ("#aabbcc,#…"). */
    public function paletteString(): string { return implode(',', array_values(array_unique($this->hex))); }

    /**
     * Redo the marked areas: digitize the design's own picture again, but only inside these areas, erase the old
     * stitches there and put the new ones into the matching color stop. $zones: [['pts' => [[x, y] mm…], 'fix' => […], 'i' => page index]].
     */
    public function redoAreas(array $zones, array $opts, float $W, float $H): void
    {
        $redo = array_values(array_filter($zones, fn($z) => empty($z['fix']['remove'])));
        $patch = [];
        if ($redo) {
            $x0 = $y0 = INF; $x1 = $y1 = -INF;
            foreach ($redo as $z) foreach ($z['pts'] as [$x, $y]) { $x0 = min($x0, $x); $y0 = min($y0, $y); $x1 = max($x1, $x); $y1 = max($y1, $y); }
            $m = 3.0;                                                            // room around the areas
            $x0 = max(-1, $x0 - $m); $y0 = max(-1, $y0 - $m); $x1 = min($W + 1, $x1 + $m); $y1 = min($H + 1, $y1 + $m);
            if ($x1 - $x0 < 11) { $c = ($x0 + $x1) / 2; $x0 = $c - 5.5; $x1 = $c + 5.5; }    // the Digitizer works on 10–400 mm
            if ($y1 - $y0 < 6) { $c = ($y0 + $y1) / 2; $y0 = $c - 3; $y1 = $c + 3; }
            $rw = $x1 - $x0; $rh = $y1 - $y0;
            $nz = array_map(fn($z) => ['pts' => array_map(fn($p) => [($p[0] - $x0) / $rw, ($p[1] - $y0) / $rh], $z['pts']), 'fix' => $z['fix']], $redo);
            $png = $this->render([$x0, $y0, $x1, $y1]);
            try {
                $dz = new Digitizer(array_merge($opts, [
                    'width_mm' => $rw, 'height_mm' => $rh, 'palette' => $this->paletteString(), 'colors' => count(array_unique($this->hex)),
                    'remove_bg' => 0, 'no_crop' => 1, 'only_zones' => 1, 'skip' => '', 'zones' => json_encode($nz),
                ]));
                $dz->load($png);
                $dz->digitize();
            } finally { @unlink($png); }
            foreach ($dz->shapes as $s) {
                if ($s[6] === null) continue;
                $zi = $redo[$s[6]]['i'];
                $patch[$zi] = true;
                $this->shapes[] = [$s[0], round(($x0 + $s[1] * $rw) / $W, 4), round(($y0 + $s[2] * $rh) / $H, 4),
                    round(($x0 + $s[3] * $rw) / $W, 4), round(($y0 + $s[4] * $rh) / $H, 4), $s[5], $zi];
            }
            $newBlocks = $dz->blocks();
        }
        // erase the old stitches – but only where something new was made, or the area was marked "remove"
        $usedLen = [];
        foreach ($zones as $z) {
            if (empty($z['fix']['remove']) && empty($patch[$z['i']])) continue;
            $before = array_map(fn($b) => self::length($b), $this->blocks);
            $gone = $this->erase($z['pts'], empty($z['fix']['remove']) ? 1.5 : 0.8);
            foreach ($this->blocks as $bi => $b) $usedLen[$bi] = ($usedLen[$bi] ?? 0) + $before[$bi] - self::length($b);
            if (!empty($z['fix']['remove']) && $gone > 0) $this->shapes[] = ['removed', 0, 0, 0, 0, 0, $z['i']];
        }
        // the new stitches go to the end of the color stop with the same thread (the one that lost most stitches there)
        foreach ($newBlocks ?? [] as [$rgb, $items]) {
            $h = sprintf('#%02x%02x%02x', ...$rgb); $to = null;
            foreach ($this->hex as $bi => $hx) if ($hx === $h && ($to === null || ($usedLen[$bi] ?? 0) > ($usedLen[$to] ?? 0))) $to = $bi;
            if ($to === null) continue;
            $run = null; $cut = true;                                           // first run of the patch: cut there
            $flush = function () use (&$run, $to) { if ($run && count($run['pts']) > 1) $this->blocks[$to][] = $run; $run = null; };
            foreach ($items as [$x, $y, $t]) {
                if ($t === 'S') {
                    if (!$run) { $run = ['cut' => $cut, 'pts' => []]; $cut = false; }
                    $run['pts'][] = [$x0 + $x, $y0 + $y];
                    continue;
                }
                $flush();
                if ($t === 'T') $cut = true;
            }
            $flush();
        }
    }

    private static function length(array $b): float
    {
        $l = 0; foreach ($b as $r) for ($i = 1; $i < count($r['pts']); $i++) $l += hypot($r['pts'][$i][0] - $r['pts'][$i - 1][0], $r['pts'][$i][1] - $r['pts'][$i - 1][1]);
        return $l;
    }

    /** Stitches for the DST writer: jumps between runs, a cut when the file had one or the jump is long. */
    public function items(float $cutAt): array
    {
        $items = []; $kept = [];
        foreach ($this->blocks as $bi => $b) {
            if (!$b) continue;
            if ($items) $items[] = [null, null, 'C'];
            $kept[] = $bi; $prev = null;
            foreach ($b as $r) {
                $p0 = $r['pts'][0];
                $d = $prev ? hypot($p0[0] - $prev[0], $p0[1] - $prev[1]) : INF;
                $cut = $prev && ($r['cut'] || ($cutAt > 0 && $d > $cutAt) || $d > 24);
                if ($cut) $items[] = [null, null, 'T'];
                if (!$prev || $cut || $d > 0.05) $items[] = [$p0[0], $p0[1], 'J'];
                foreach ($r['pts'] as [$x, $y]) $items[] = [$x, $y, 'S'];
                $prev = end($r['pts']);
            }
        }
        return [$items, $kept];
    }

    /** Rough shape list (bounding boxes per sewn run) for the page's suggestions. */
    public function runShapes(float $W, float $H): array
    {
        $out = [];
        foreach ($this->blocks as $b) foreach ($b as $r) {
            if (count($out) >= 600) break 2;
            $n = count($r['pts']); if ($n < 3) continue;
            $xs = array_column($r['pts'], 0); $ys = array_column($r['pts'], 1);
            $len = 0; $turns = 0;
            for ($i = 1; $i < $n; $i++) {
                $len += hypot($r['pts'][$i][0] - $r['pts'][$i - 1][0], $r['pts'][$i][1] - $r['pts'][$i - 1][1]);
                if ($i > 1) {
                    $ax = $r['pts'][$i - 1][0] - $r['pts'][$i - 2][0]; $ay = $r['pts'][$i - 1][1] - $r['pts'][$i - 2][1];
                    $bx = $r['pts'][$i][0] - $r['pts'][$i - 1][0]; $by = $r['pts'][$i][1] - $r['pts'][$i - 1][1];
                    if ($ax * $bx + $ay * $by < 0) $turns++;
                }
            }
            $avg = $len / ($n - 1);
            $kind = $turns > 0.6 * $n ? ($avg > 2.5 ? 'fill' : 'satin') : 'run';
            $out[] = [$kind, round(min($xs) / $W, 4), round(min($ys) / $H, 4), round(max($xs) / $W, 4), round(max($ys) / $H, 4),
                round(max(max($xs) - min($xs), max($ys) - min($ys)), 1), null];
        }
        return $out;
    }

    /** "Re-digitize the whole design": the stitches drawn as a picture, digitized again with the app's settings. */
    public function redoAll(array $opts, float $W, float $H): Digitizer
    {
        $t = self::THREAD / 2;
        $png = $this->render([-$t, -$t, $W + $t, $H + $t]);
        try {
            $dz = new Digitizer(array_merge($opts, [
                'width_mm' => $W + 2 * $t, 'height_mm' => $H + 2 * $t, 'palette' => $this->paletteString(),
                'colors' => count(array_unique($this->hex)), 'remove_bg' => 0, 'no_crop' => 1, 'only_zones' => 0,
            ]));
            $dz->load($png);
        } finally { @unlink($png); }
        $dz->digitize();
        return $dz;
    }
}
