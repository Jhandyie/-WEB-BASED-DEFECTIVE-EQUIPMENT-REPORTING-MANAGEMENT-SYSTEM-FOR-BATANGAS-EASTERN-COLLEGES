<?php
/**
 * includes/report_media.php — resolve the photos and videos attached to a defect report.
 *
 * A reporter can attach several photos and short videos (student_dashboard.php posts
 * `photos[]` and `videos[]`), and they arrive by more than one route depending on how
 * old the report is: a single `photo_url`/`photo_path` column, a JSON array in
 * `defect_photos` / `defect_videos`, or simply files on disk named after the report.
 * These two helpers collapse all of that into a de-duplicated list.
 *
 * Extracted from admin_defect_reports.php so the assignment screen can show the same
 * evidence. Dispatching a technician to a fault nobody has looked at is the whole
 * problem this system exists to fix.
 */
require_once __DIR__ . '/../config/database.php';

if (!function_exists('becMediaIsImage')) {
    function becMediaIsImage(string $path): bool {
        return (bool) preg_match('~\.(jpe?g|png|webp|gif|bmp)(\?|$)~i', $path);
    }
    function becMediaIsVideo(string $path): bool {
        return (bool) preg_match('~\.(mp4|webm|mov|m4v|ogv)(\?|$)~i', $path);
    }
}

if (!function_exists('photoListFromRow')) {
    function photoListFromRow($row) {
        $photos = [];
        if (!empty($row['photo_url']))  { $photos[] = (string)$row['photo_url']; }
        if (!empty($row['photo_path'])) { $photos[] = (string)$row['photo_path']; }
        if (!empty($row['defect_photos'])) {
            $raw = $row['defect_photos'];
            if (is_string($raw)) {
                $dec = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($dec)) {
                    foreach ($dec as $p) { $photos[] = (string)$p; }
                } else {
                    $photos[] = $raw;
                }
            }
        }
        // Uses the shared, once-per-request directory index (see becReportPhotoFiles).
        // That index matches EVERY upload whose name starts with the report id, so it
        // also returns the videos (BEC-…_v1.mp4) — those were coming back in the photo
        // list and rendering as a broken <img>. Only the scan is filtered by
        // extension; the explicit photo columns are trusted as given, since a stored
        // path may legitimately have no extension.
        foreach (becReportPhotoFiles((string)($row['report_id'] ?? '')) as $match) {
            if (becMediaIsImage($match)) { $photos[] = $match; }
        }
        $out = [];
        foreach ($photos as $p) {
            $p = str_replace('\\', '/', trim((string)$p));
            if ($p === '') { continue; }
            if (!in_array($p, $out, true)) { $out[] = $p; }
        }
        return $out;
    }
}

if (!function_exists('videoListFromRow')) {
    function videoListFromRow($row) {
        $vids = [];
        if (!empty($row['defect_videos'])) {
            $raw = $row['defect_videos'];
            if (is_string($raw)) {
                $dec = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($dec)) {
                    foreach ($dec as $v) { $vids[] = (string)$v; }
                } elseif (trim($raw) !== '') {
                    $vids[] = $raw;
                }
            }
        }
        // Symmetry with photoListFromRow: a video sitting on disk with no row in
        // defect_videos was invisible everywhere, even though the same directory
        // index already knew about it.
        foreach (becReportPhotoFiles((string)($row['report_id'] ?? '')) as $match) {
            if (becMediaIsVideo($match)) { $vids[] = $match; }
        }
        $out = [];
        foreach ($vids as $v) {
            $v = str_replace('\\', '/', trim((string)$v));
            if ($v !== '' && !in_array($v, $out, true)) { $out[] = $v; }
        }
        return $out;
    }
}
