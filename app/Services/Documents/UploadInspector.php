<?php

namespace App\Services\Documents;

use finfo;
use Throwable;
use ZipArchive;

/**
 * Looks INSIDE an uploaded file before it is stored. Nothing the browser says (file name, extension, Content-Type) is trusted.
 *
 *  - Extension and MIME spoofing: the real type is read from the file content (finfo + magic bytes + image header) and must agree with the
 *    extension the vendor chose. The stored name and the registered MIME come from that detected type, never from the upload.
 *  - Images (jpg, png, webp): the header is read first and the dimensions are capped (decompression bombs), then the picture is decoded and
 *    WRITTEN AGAIN with GD. The new file has only pixels: no EXIF/GPS/XMP/ICC/comments (metadata attacks), no appended or hidden data
 *    (polyglots), and anything a hostile parser trick relied on is gone. If GD cannot decode it the upload is refused.
 *  - PDF: signature at byte 0, a single clean %%EOF tail (no data appended after it), no script / launch / embedded-file / XFA markers (names with
 *    #xx escapes are decoded first) and no PHP tags. Compressed object streams cannot be read without a PDF engine: a DocumentScanner (antivirus)
 *    should still be bound in production.
 *  - docx: a real ZIP package with a size / entry-count / compression-ratio cap (zip bombs), no path tricks, no macros (vbaProject.bin,
 *    macro-enabled content types, customUI), no OLE / ActiveX / embedded objects, no external relationships other than plain hyperlinks, and
 *    no entry that is or looks like an executable, script or nested archive (by name and by first bytes).
 * Nothing is executed or parsed as XML (no XXE); the checks are byte and string tests with hard read limits.
 */
class UploadInspector
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private const MIME_EXTENSION = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /** Entries that must never be inside a docx, by file extension. */
    private const BAD_ENTRY_EXTENSIONS = [
        'exe', 'dll', 'com', 'bat', 'cmd', 'scr', 'msi', 'msp', 'js', 'jse', 'vbs', 'vbe', 'wsf', 'wsh', 'ps1', 'psm1', 'sh', 'bash', 'jar', 'class',
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb', 'hta', 'lnk', 'pif', 'cpl', 'reg',
        'gadget', 'so', 'dylib', 'ocx', 'sys', 'app', 'apk', 'dmg', 'iso', 'img', 'bin', 'ole', 'zip', 'rar', '7z', 'gz', 'tar', 'docm', 'dotm', 'xlsm', 'pptm', 'doc',
    ];

    /** Magic bytes of things that do not belong in a document: PE/DOS, ELF, Mach-O, shell script, Java class, nested zip, OLE container, RAR, 7z, gzip. */
    private const BAD_MAGIC = ["MZ", "\x7fELF", "#!", "\xCA\xFE\xBA\xBE", "\xFE\xED\xFA", "\xCF\xFA\xED\xFE", "PK\x03\x04", "\xD0\xCF\x11\xE0", "Rar!", "7z\xBC\xAF", "\x1f\x8b"];

    /**
     * @param  list<string>  $allowed  extensions the slot accepts (pdf, jpg, jpeg, png, webp, docx)
     * @return array{ok:true,ext:string,mime:string,path:string,temp:bool}|array{ok:false,error:string}
     *                                                                                                  `path` is the file to store; when `temp` is true it is a new file the caller must delete after storing.
     */
    public function inspect(string $path, string $clientName, array $allowed): array
    {
        $claimed = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        if (! in_array($claimed, $allowed, true)) {
            return $this->no('That file type is not allowed.');
        }
        if (! is_file($path) || filesize($path) === 0) {
            return $this->no('That file is empty.');
        }

        $head = (string) file_get_contents($path, false, null, 0, 16);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';

        if (str_starts_with($head, '%PDF-')) {
            $kind = 'pdf';
        } elseif (str_starts_with($head, "\xFF\xD8\xFF")) {
            $kind = 'jpg';
        } elseif (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            $kind = 'png';
        } elseif (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            $kind = 'webp';
        } elseif (str_starts_with($head, "PK\x03\x04")) {
            $kind = 'docx';
        } else {
            return $this->no('That file is not a PDF, an image or a Word document.');
        }

        // The extension must be the one the content proves, and the independent finfo reading must agree (a PDF that finfo calls PHP is refused).
        $sameFamily = $claimed === $kind || ($kind === 'jpg' && $claimed === 'jpeg');
        if (! $sameFamily) {
            return $this->no('The file extension does not match the file content.');
        }
        if ($kind !== 'docx' && (self::MIME_EXTENSION[$mime] ?? null) !== $kind) {
            return $this->no('The file content could not be verified as '.strtoupper($kind).'.');
        }
        if ($kind === 'docx' && ! in_array($mime, [self::DOCX_MIME, 'application/zip', 'application/x-zip-compressed', 'application/octet-stream', 'application/vnd.ms-word.document.macroenabled.12'], true)) {
            return $this->no('The file content could not be verified as a Word document.');
        }

        return match ($kind) {
            'pdf' => $this->pdf($path),
            'docx' => $this->docx($path),
            default => $this->image($path, $kind),
        };
    }

    // ---- images ------------------------------------------------------------------------------------------------------------

    private function image(string $path, string $kind): array
    {
        $info = @getimagesize($path); // reads the header only: no pixel memory is used yet
        $expected = ['jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP][$kind];
        if (! is_array($info) || ($info[2] ?? null) !== $expected) {
            return $this->no('That image could not be read.');
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];
        $maxSide = (int) config('vob.docs_image_max_side', 10000);
        $maxPixels = (int) config('vob.docs_image_max_pixels', 24000000);
        if ($width < 1 || $height < 1 || $width > $maxSide || $height > $maxSide || $width * $height > $maxPixels) {
            return $this->no('That image is too large ('.number_format($maxPixels / 1000000, 0).' megapixels at most). Please reduce its size and upload it again.');
        }

        // GD holds the decoded picture twice (source and output) at about 4 bytes a pixel: make sure the process may use that, and put the limit back.
        $needed = (int) ($width * $height * 4 * 2.5) + 64 * 1024 * 1024;
        $previous = ini_get('memory_limit');
        $limit = $this->bytes((string) $previous);
        if ($limit !== -1 && $limit < $needed) {
            @ini_set('memory_limit', (string) $needed);
        }

        $temp = null;
        $source = null;
        set_error_handler(static function ($no, $message) { throw new \ErrorException($message); });
        try {
            $source = match ($kind) {
                'jpg' => imagecreatefromjpeg($path),
                'png' => imagecreatefrompng($path),
                'webp' => imagecreatefromwebp($path),
            };
            if (! $source) {
                return $this->no('That image could not be read.');
            }
            if ($kind === 'jpg') {
                $source = $this->oriented($source, $path);
            }

            $temp = tempnam(sys_get_temp_dir(), 'vobimg');
            $written = match ($kind) {
                'jpg' => imagejpeg($source, $temp, 90),
                'png' => $this->savePng($source, $temp),
                'webp' => imagewebp($source, $temp, 90),
            };
            if (! $written || ! is_file($temp) || filesize($temp) === 0) {
                throw new \RuntimeException('re-encode failed');
            }
            if (filesize($temp) > (int) config('vob.docs_max_kb') * 1024) {
                @unlink($temp);

                return $this->no('The image is too big once cleaned. Please upload a smaller one (up to '.(config('vob.docs_max_kb') / 1024).' MB).');
            }
        } catch (Throwable) {
            if ($temp) {
                @unlink($temp);
            }

            return $this->no('That image could not be read.');
        } finally {
            restore_error_handler();
            if ($source instanceof \GdImage) {
                imagedestroy($source);
            }
            if ($limit !== -1 && $limit < $needed) {
                @ini_set('memory_limit', (string) $previous);
            }
        }

        return ['ok' => true, 'ext' => $kind, 'mime' => self::mimeOf($kind), 'path' => $temp, 'temp' => true];
    }

    private function savePng(\GdImage $image, string $to): bool
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return imagepng($image, $to, 6);
    }

    /** Apply the camera's EXIF orientation before the EXIF is thrown away, so phone photos do not turn sideways. */
    private function oriented(\GdImage $image, string $path): \GdImage
    {
        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof \GdImage ? $rotated : $image;
    }

    // ---- pdf ---------------------------------------------------------------------------------------------------------------

    private function pdf(string $path): array
    {
        $data = (string) file_get_contents($path);

        $eof = strrpos($data, '%%EOF');
        if ($eof === false) {
            return $this->no('That PDF is incomplete or damaged.');
        }
        $tail = substr($data, $eof + 5);
        if (strlen($tail) > 64 || trim($tail) !== '') {
            return $this->no('That PDF has extra data after its end and cannot be accepted.');
        }

        if (preg_match('/<\?(php|=)|<script\b/i', $data)) {
            return $this->no('That PDF contains script content and cannot be accepted.');
        }

        // Decode #xx name escapes (/J#61vaScript) so a marker cannot hide behind them.
        $names = preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn ($m) => chr(hexdec($m[1])), $data) ?? $data;
        if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFiles?|RichMedia|XFA|SubmitForm|ImportData|GoToE|GoToR)(?![A-Za-z0-9])#', $names)) {
            return $this->no('That PDF contains scripts, embedded files or external actions. Please save it as a plain PDF (for example "Print to PDF") and upload that.');
        }

        return ['ok' => true, 'ext' => 'pdf', 'mime' => 'application/pdf', 'path' => $path, 'temp' => false];
    }

    // ---- docx --------------------------------------------------------------------------------------------------------------

    private function docx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return $this->no('That Word file could not be read.');
        }

        try {
            $count = $zip->numFiles;
            if ($count < 3 || $count > (int) config('vob.docs_docx_max_entries', 1500)) {
                return $this->no('That Word file could not be accepted.');
            }

            $maxTotal = (int) config('vob.docs_docx_max_uncompressed_mb', 60) * 1024 * 1024;
            $total = 0;
            $names = [];
            $contentTypes = '';

            for ($i = 0; $i < $count; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    return $this->no('That Word file could not be read.');
                }
                $name = (string) $stat['name'];
                $size = (int) $stat['size'];
                $packed = max(1, (int) $stat['comp_size']);
                $lower = strtolower($name);

                if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('#^[a-z]:#i', $name)) {
                    return $this->no('That Word file could not be accepted.');
                }
                $total += $size;
                if ($total > $maxTotal || ($size > 1024 * 1024 && $size / $packed > 150)) {
                    return $this->no('That Word file expands to an unreasonable size and cannot be accepted.');
                }
                $names[] = $lower;
                if (str_ends_with($name, '/')) {
                    continue;
                }

                if (preg_match('#(^|/)(vbaproject[^/]*|vbadata\.xml)$#', $lower) || str_contains($lower, 'macros/') || str_starts_with($lower, 'customui/')) {
                    return $this->no('Word files with macros are not allowed.');
                }
                if (str_starts_with($lower, 'word/embeddings/') || str_starts_with($lower, 'word/activex/') || str_contains($lower, 'oleobject')) {
                    return $this->no('Word files with embedded objects are not allowed.');
                }
                // word/printerSettings/printerSettings1.bin is ordinary printer data that Word writes into many files (still checked by its first bytes below)
                $printerData = (bool) preg_match('#^word/printersettings/[^/]+\.bin$#', $lower);
                if (! $printerData && in_array(pathinfo($lower, PATHINFO_EXTENSION), self::BAD_ENTRY_EXTENSIONS, true)) {
                    return $this->no('That Word file contains a file type that is not allowed inside a document.');
                }

                $stream = $zip->getStream($name);
                if (! is_resource($stream)) {
                    return $this->no('That Word file could not be read.');
                }
                $first = (string) fread($stream, 8);
                $isXml = str_ends_with($lower, '.xml') || str_ends_with($lower, '.rels');
                $body = $isXml ? $first.(string) stream_get_contents($stream, 2 * 1024 * 1024) : '';
                fclose($stream);

                foreach (self::BAD_MAGIC as $magic) {
                    if (str_starts_with($first, $magic)) {
                        return $this->no('That Word file contains an executable or archive and cannot be accepted.');
                    }
                }

                if ($name === '[Content_Types].xml') {
                    $contentTypes = $body;
                }
                if (str_ends_with($lower, '.rels') && $this->badRelationships($body)) {
                    return $this->no('That Word file links to external or embedded content and cannot be accepted.');
                }
            }

            foreach (['[content_types].xml', '_rels/.rels', 'word/document.xml'] as $required) {
                if (! in_array($required, $names, true)) {
                    return $this->no('That is not a valid Word (.docx) file.');
                }
            }
            if (! str_contains($contentTypes, 'wordprocessingml.document.main+xml')) {
                return $this->no('That is not a valid Word (.docx) file.');
            }
            if (preg_match('/macroEnabled|vbaProject|vnd\.ms-office\.(vbaProject|activeX)|oleObject/i', $contentTypes)) {
                return $this->no('Word files with macros are not allowed.');
            }
        } finally {
            $zip->close();
        }

        return ['ok' => true, 'ext' => 'docx', 'mime' => self::DOCX_MIME, 'path' => $path, 'temp' => false];
    }

    /**
     * Relationships of a part. Blocked by their TYPE (last segment, so ordinary parts such as .../package/2006/relationships/metadata/core-properties,
     * which every Word file has, are fine): macro projects, OLE objects, ActiveX, embedded packages, frames, sub-documents, external workbooks.
     * An external TARGET is allowed only for a plain hyperlink, or for the attached template when it is a local path (Word writes
     * file:///C:/.../Normal.dotm into many documents); a template or anything else on http(s), ftp or a network host (UNC) is refused.
     */
    private function badRelationships(string $xml): bool
    {
        if (! preg_match_all('/<Relationship\b[^>]*>/i', $xml, $all)) {
            return false;
        }
        foreach ($all[0] as $tag) {
            $type = preg_match('/\bType="([^"]*)"/i', $tag, $m) ? strtolower(basename($m[1])) : '';
            $target = preg_match('/\bTarget="([^"]*)"/i', $tag, $m) ? trim(html_entity_decode($m[1])) : '';
            $external = (bool) preg_match('/\bTargetMode="External"/i', $tag);

            if (preg_match('/^(vbaproject\w*|oleobject|activex\w*|package|frame|subdocument|externallink\w*|macro\w*)$/', $type)) {
                return true;
            }
            if ($external && $type !== 'hyperlink') {
                $local = $type === 'attachedtemplate' && ! preg_match('#^(https?:|ftp:|\\\\|//|file://[^/])#i', $target);
                if (! $local) {
                    return true;
                }
            }
        }

        return false;
    }

    // ---- helpers -----------------------------------------------------------------------------------------------------------

    public static function mimeOf(string $ext): string
    {
        return ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'docx' => self::DOCX_MIME][$ext];
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** @return array{ok:false,error:string} */
    private function no(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
