<?php
/**
 * Digital signature helpers for printable documents.
 * --------------------------------------------------------------------------
 * The system renders every "PDF" as an HTML page printed via the browser, so a
 * signature is simply a transparent PNG <img> dropped above the existing
 * signature line. Two sources are supported (Hybrid model):
 *
 *   - School-level (institutional) signers, stored in school_settings:
 *       headmaster / accountant / hr / registrar  (image + name + title)
 *   - Per-staff signers, stored on teacher_profiles.signature_image
 *       (e.g. the actual class teacher on a report card).
 *
 * Everything is gated by the master toggle school_settings.signatures_enabled,
 * so a school can switch back to blank lines for wet signatures at any time.
 *
 * Multi-tenant safe: settings live in each tenant's own DB and the columns are
 * self-healed via ensureSignatureColumns() / ensureTeacherProfileColumns().
 */

require_once __DIR__ . '/settings_helper.php';

if (!function_exists('normalizeSignatureImage')) {
    /**
     * Normalize an uploaded signature so it prints at a consistent size no
     * matter what dimensions the source file had.
     *
     * Two things vary wildly between uploads and both make signatures look
     * "too small" / "too big" on documents:
     *   1. The raw pixel size of the image (templates only set a max-height,
     *      so a small source never fills it and a large one gets clamped).
     *   2. Empty/transparent padding around the actual ink.
     *
     * We trim the surrounding empty margin to the real ink bounds, then scale
     * to a canonical height (capping width for very long signatures) and write
     * a transparent PNG. After this, the existing max-height in the templates
     * clamps every signature to the same printed height.
     *
     * @return bool true on success (PNG written to $destPath), false on failure
     *              (caller should keep the original file).
     */
    function normalizeSignatureImage($srcPath, $destPath, $targetHeight = 160, $maxWidth = 520) {
        if (!function_exists('imagecreatetruecolor') || !is_file($srcPath)) return false;
        $info = @getimagesize($srcPath);
        if ($info === false) return false;

        switch ($info[2]) {
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($srcPath);  $hasAlpha = true;  break;
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($srcPath); $hasAlpha = false; break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($srcPath);  $hasAlpha = true;  break;
            default: return false;
        }
        if (!$src) return false;

        $w = imagesx($src);
        $h = imagesy($src);

        // Find the bounding box of the actual signature ink. "Ink" = an opaque
        // pixel (alpha images) or a non-near-white pixel (flat JPGs).
        $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($src, $x, $y);
                $a = ($rgba >> 24) & 0x7F;          // 0 = opaque, 127 = transparent
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                $isInk = $hasAlpha
                    ? ($a < 100)
                    : (($r * 0.299 + $g * 0.587 + $b * 0.114) < 240);
                if ($isInk) {
                    if ($x < $minX) $minX = $x;
                    if ($x > $maxX) $maxX = $x;
                    if ($y < $minY) $minY = $y;
                    if ($y > $maxY) $maxY = $y;
                }
            }
        }
        // Blank/undetectable image: fall back to the full canvas.
        if ($maxX < 0) { $minX = 0; $minY = 0; $maxX = $w - 1; $maxY = $h - 1; }

        // Breathing room around the ink (~4% of the longest side).
        $pad  = (int)round(max($maxX - $minX, $maxY - $minY) * 0.04);
        $minX = max(0, $minX - $pad);
        $minY = max(0, $minY - $pad);
        $maxX = min($w - 1, $maxX + $pad);
        $maxY = min($h - 1, $maxY + $pad);
        $cropW = $maxX - $minX + 1;
        $cropH = $maxY - $minY + 1;

        // Scale to the canonical height; cap width for very long signatures.
        $newH = $targetHeight;
        $newW = (int)round($cropW * ($newH / $cropH));
        if ($newW > $maxWidth) {
            $newW = $maxWidth;
            $newH = (int)round($cropH * ($newW / $cropW));
        }
        $newW = max(1, $newW);
        $newH = max(1, $newH);

        $dst = imagecreatetruecolor($newW, $newH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        imagecopyresampled($dst, $src, 0, 0, $minX, $minY, $newW, $newH, $cropW, $cropH);

        $ok = @imagepng($dst, $destPath);
        imagedestroy($src);
        imagedestroy($dst);
        return (bool)$ok;
    }
}

if (!function_exists('signaturesEnabled')) {
    /** Master switch: are embedded signatures turned on for this school? */
    function signaturesEnabled() {
        return (string)getSchoolSetting('signatures_enabled', '0') === '1';
    }
}

if (!function_exists('signatureBasePath')) {
    /** Web base path for /uploads (mirrors getSchoolLogo()'s resolution). */
    function signatureBasePath() {
        $base_path = '/school_ms';
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $doc_root = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
            $root_dir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
            if ($doc_root && $root_dir && strpos($root_dir, $doc_root) === 0) {
                $base_path = substr($root_dir, strlen($doc_root));
            }
        }
        $base_path = '/' . trim($base_path, '/');
        return ($base_path === '/') ? '' : $base_path;
    }
}

if (!function_exists('signatureFileUrl')) {
    /**
     * Resolve an uploaded signature filename to a web URL, or '' if missing.
     * Served through serve_image.php because direct access to /uploads is
     * denied by uploads/.htaccess.
     */
    function signatureFileUrl($filename) {
        $filename = trim((string)$filename);
        if ($filename === '') return '';
        $path = __DIR__ . '/../uploads/signatures/' . $filename;
        if (!file_exists($path)) return '';
        return signatureBasePath() . '/serve_image.php?path=signatures/' . rawurlencode($filename);
    }
}

if (!function_exists('getSchoolSignature')) {
    /**
     * Institutional signer details.
     * @param string $slot one of: headmaster, accountant, hr, registrar
     * @return array{url:string,name:string,title:string}
     */
    function getSchoolSignature($slot) {
        $slot = preg_replace('/[^a-z]/', '', strtolower($slot));
        return [
            'url'   => signatureFileUrl(getSchoolSetting('signature_' . $slot, '')),
            'name'  => (string)getSchoolSetting('signature_' . $slot . '_name', ''),
            'title' => (string)getSchoolSetting('signature_' . $slot . '_title', ''),
        ];
    }
}

if (!function_exists('getStaffSignatureUrl')) {
    /** Per-staff signature image URL for a given user id, or '' if none. */
    function getStaffSignatureUrl($db, $user_id) {
        $user_id = (int)$user_id;
        if (!$user_id) return '';
        try {
            $stmt = $db->prepare("SELECT signature_image FROM teacher_profiles WHERE user_id = :id");
            $stmt->execute([':id' => $user_id]);
            return signatureFileUrl($stmt->fetchColumn() ?: '');
        } catch (PDOException $e) {
            return '';
        }
    }
}

if (!function_exists('signatureImg')) {
    /**
     * Print-safe <img> for a signature, or '' when signatures are disabled or
     * the URL is empty (so templates transparently fall back to a blank line).
     */
    function signatureImg($url, $maxHeight = 50) {
        if (!signaturesEnabled() || trim((string)$url) === '') return '';
        return '<img src="' . htmlspecialchars($url) . '" alt="Signature" '
             . 'style="max-height:' . (int)$maxHeight . 'px;max-width:170px;object-fit:contain;'
             . 'display:block;margin:0 auto;-webkit-print-color-adjust:exact;print-color-adjust:exact;">';
    }
}

if (!function_exists('signatureBlock')) {
    /**
     * A self-contained signatory block: signature image (if any) sitting on a
     * ruled line, with the printed name and title beneath. Useful for report
     * footers that don't already have a signature area.
     *
     * @param array $sig ['url'=>, 'name'=>, 'title'=>] (e.g. from getSchoolSignature)
     * @param string $fallbackTitle used when no title is configured
     */
    function signatureBlock(array $sig, $fallbackTitle = '') {
        $img   = signatureImg($sig['url'] ?? '');
        $name  = htmlspecialchars($sig['name'] ?? '');
        $title = htmlspecialchars($sig['title'] ?: $fallbackTitle);
        // ~50px reserved above the line so signed and unsigned blocks align.
        $imgArea = '<div style="height:50px;display:flex;align-items:flex-end;justify-content:center;">' . $img . '</div>';
        $html  = '<div style="text-align:center;display:inline-block;min-width:180px;">';
        $html .= $imgArea;
        $html .= '<div style="border-top:1px solid #333;margin-top:2px;padding-top:4px;font-size:11px;font-weight:600;color:#333;">'
               . ($name !== '' ? $name : '&nbsp;') . '</div>';
        if ($title !== '') {
            $html .= '<div style="font-size:9px;color:#777;">' . $title . '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('signatureForTitle')) {
    /**
     * Map a free-text signatory label (e.g. "Headmaster/Headmistress",
     * "Prepared By (Accountant)", "Registrar") to the matching institutional
     * signature image, or '' when there is no school-level signer for it (so
     * roles like "Class Teacher", "Librarian", "Bursar's clerk" stay blank for
     * manual signing). Deputies/assistants always sign manually.
     */
    function signatureForTitle($title, $maxHeight = 40) {
        $t = strtolower((string)$title);
        if (preg_match('/deputy|assistant/', $t)) return '';
        $slot = null;
        if (preg_match('/headmaster|headmistress|principal|head teacher/', $t))      $slot = 'headmaster';
        elseif (preg_match('/accountant|cashier|finance/', $t))                      $slot = 'accountant';
        elseif (preg_match('/registrar/', $t))                                       $slot = 'registrar';
        elseif (preg_match('/\bhr\b|human resource/', $t))                           $slot = 'hr';
        if ($slot === null) return '';
        return signatureImg(getSchoolSignature($slot)['url'], $maxHeight);
    }
}

if (!function_exists('signatureRow')) {
    /**
     * Render a complete, self-contained row of signatory blocks (inline styled,
     * so it needs no page CSS). Each title gets its institutional signature
     * image above the ruled line when one is configured & enabled. Drop-in
     * replacement for the static signature blocks on report print pages:
     *     <?php echo signatureRow(['Class Teacher','Head of Department','Headmaster/Headmistress']); ?>
     */
    function signatureRow(array $titles) {
        $cols = max(1, count($titles));
        $h  = '<div style="display:grid;grid-template-columns:repeat(' . $cols . ',1fr);gap:30px;margin-top:28px;margin-bottom:12px;">';
        foreach ($titles as $title) {
            $img = signatureForTitle($title, 40);
            $h .= '<div style="text-align:center;">'
                . '<div style="height:42px;display:flex;align-items:flex-end;justify-content:center;">' . $img . '</div>'
                . '<div style="border-top:1.5px solid #374151;margin-top:2px;padding-top:4px;font-size:10px;font-weight:700;color:#1e3a5f;">'
                . htmlspecialchars($title) . '</div>'
                . '</div>';
        }
        $h .= '</div>';
        return $h;
    }
}
