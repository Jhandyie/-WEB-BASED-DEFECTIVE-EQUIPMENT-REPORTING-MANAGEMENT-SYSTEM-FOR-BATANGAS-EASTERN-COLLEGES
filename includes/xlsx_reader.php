<?php
/**
 * includes/xlsx_reader.php — dependency-free .xlsx reader (no ZipArchive).
 * Unzips the workbook (store + deflate) and returns all cell text values
 * across every worksheet — enough to scan for inventory property numbers.
 *
 * becXlsxSheets() keeps row/column coordinates, which the PMO inventory
 * workbook needs: its Building/Area/Room columns only make sense positionally.
 */

if (!function_exists('becXlsxUnzip')) {
    /** Read a ZIP into [entryName => uncompressed bytes] via the central directory. */
    function becXlsxUnzip(string $path): array {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 22) return [];
        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false) return [];
        $cdCount  = unpack('v', substr($data, $eocd + 10, 2))[1];
        $cdOffset = unpack('V', substr($data, $eocd + 16, 4))[1];
        $out = [];
        $p = $cdOffset;
        for ($i = 0; $i < $cdCount; $i++) {
            if (substr($data, $p, 4) !== "PK\x01\x02") break;
            $method     = unpack('v', substr($data, $p + 10, 2))[1];
            $compSize   = unpack('V', substr($data, $p + 20, 4))[1];
            $nameLen    = unpack('v', substr($data, $p + 28, 2))[1];
            $extraLen   = unpack('v', substr($data, $p + 30, 2))[1];
            $commentLen = unpack('v', substr($data, $p + 32, 2))[1];
            $lho        = unpack('V', substr($data, $p + 42, 4))[1];
            $name       = substr($data, $p + 46, $nameLen);
            $lNameLen  = unpack('v', substr($data, $lho + 26, 2))[1];
            $lExtraLen = unpack('v', substr($data, $lho + 28, 2))[1];
            $start     = $lho + 30 + $lNameLen + $lExtraLen;
            $comp      = substr($data, $start, $compSize);
            if ($method === 0) {
                $out[$name] = $comp;
            } elseif ($method === 8) {
                $inf = @gzinflate($comp);
                if ($inf !== false) $out[$name] = $inf;
            }
            $p += 46 + $nameLen + $extraLen + $commentLen;
        }
        return $out;
    }
}

if (!function_exists('becXlsxAllText')) {
    /** All cell text values across every worksheet (shared, inline, and literal). */
    function becXlsxAllText(string $path): array {
        $files = becXlsxUnzip($path);
        if (!$files) return [];

        $dec = static fn($s) => html_entity_decode((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // shared strings table
        $shared = [];
        if (isset($files['xl/sharedStrings.xml'])
            && preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $files['xl/sharedStrings.xml'], $m)) {
            foreach ($m[1] as $si) {
                if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm)) {
                    $shared[] = $dec(implode('', $tm[1]));
                } else {
                    $shared[] = '';
                }
            }
        }

        $texts = [];
        foreach ($files as $name => $xml) {
            if (!preg_match('#^xl/worksheets/sheet[^/]+\.xml$#', $name)) continue;
            if (!preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/s', $xml, $cm, PREG_SET_ORDER)) continue;
            foreach ($cm as $c) {
                $attr = $c[1];
                $inner = $c[2];
                if (strpos($attr, 't="inlineStr"') !== false) {
                    if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $inner, $tm)) {
                        $texts[] = $dec(implode('', $tm[1]));
                    }
                } elseif (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) {
                    if (strpos($attr, 't="s"') !== false) {
                        $idx = (int)$vm[1];
                        if (isset($shared[$idx])) $texts[] = $shared[$idx];
                    } else {
                        $texts[] = $dec($vm[1]);
                    }
                }
            }
        }
        return $texts;
    }
}

if (!function_exists('becXlsxSheets')) {
    /**
     * Every worksheet as a grid, keyed by the sheet's display name:
     *   [sheetName => [rowNumber => [colIndex(0=A) => text]]]
     * Blank cells are simply absent; rows and columns keep their real indexes.
     */
    function becXlsxSheets(string $path): array {
        $files = becXlsxUnzip($path);
        if (!$files) return [];

        $dec = static fn($s) => html_entity_decode((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // shared strings table
        $shared = [];
        if (isset($files['xl/sharedStrings.xml'])
            && preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $files['xl/sharedStrings.xml'], $m)) {
            foreach ($m[1] as $si) {
                if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm)) {
                    $shared[] = $dec(implode('', $tm[1]));
                } else {
                    $shared[] = '';
                }
            }
        }

        // rId => worksheet part, then sheet display name => worksheet part
        $relTarget = [];
        if (isset($files['xl/_rels/workbook.xml.rels'])
            && preg_match_all('/<Relationship\b[^>]*>/', $files['xl/_rels/workbook.xml.rels'], $rm)) {
            foreach ($rm[0] as $rel) {
                if (preg_match('/Id="([^"]+)"/', $rel, $a) && preg_match('/Target="([^"]+)"/', $rel, $b)) {
                    $t = ltrim($dec($b[1]), '/');
                    if (strpos($t, 'xl/') !== 0) $t = 'xl/' . $t;
                    $relTarget[$a[1]] = $t;
                }
            }
        }
        $sheetPart = [];
        if (isset($files['xl/workbook.xml'])
            && preg_match_all('/<sheet\b[^>]*>/', $files['xl/workbook.xml'], $sm)) {
            foreach ($sm[0] as $i => $sheet) {
                if (!preg_match('/name="([^"]*)"/', $sheet, $a)) continue;
                $name = $dec($a[1]);
                $part = null;
                if (preg_match('/r:id="([^"]+)"/', $sheet, $b) && isset($relTarget[$b[1]])) {
                    $part = $relTarget[$b[1]];
                }
                if ($part === null) $part = 'xl/worksheets/sheet' . ($i + 1) . '.xml';
                $sheetPart[$name] = $part;
            }
        }

        $out = [];
        foreach ($sheetPart as $name => $part) {
            $out[$name] = [];
            if (!isset($files[$part])) continue;
            // Self-closing cells (`<c r="H16" s="37"/>`) are blank. They must be matched
            // by their own branch — a single greedy pattern runs past the `/` and steals
            // the *next* cell's value, silently shifting every column after a gap.
            if (!preg_match_all('#<c\b([^>]*?)/>|<c\b([^>]*?)>(.*?)</c>#s', $files[$part], $cm, PREG_SET_ORDER)) continue;
            foreach ($cm as $c) {
                if (count($c) < 4) continue; // self-closing = blank cell
                $attr  = $c[2];
                $inner = $c[3];
                if (!preg_match('/r="([A-Z]+)(\d+)"/', $attr, $rm2)) continue;
                // column letters -> 0-based index (A=0, B=1, ... AA=26)
                $col = 0;
                foreach (str_split($rm2[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
                $col--;
                $row = (int)$rm2[2];

                $val = null;
                if (strpos($attr, 't="inlineStr"') !== false) {
                    if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $inner, $tm)) {
                        $val = $dec(implode('', $tm[1]));
                    }
                } elseif (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) {
                    if (strpos($attr, 't="s"') !== false) {
                        $idx = (int)$vm[1];
                        $val = $shared[$idx] ?? null;
                    } else {
                        $val = $dec($vm[1]);
                    }
                }
                if ($val === null) continue;
                $val = trim(preg_replace('/\s+/u', ' ', $val));
                if ($val === '') continue;
                $out[$name][$row][$col] = $val;
            }
            ksort($out[$name]);
        }
        return $out;
    }
}
