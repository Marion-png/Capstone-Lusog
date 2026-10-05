<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Stores uploaded documents (medical certificates, signed consent forms)
 * encrypted at rest and serves them decrypted. A stolen storage directory
 * yields only ciphertext. Files written before encryption was introduced
 * are served as-is.
 */
class EncryptedFileStorage
{
    public static function store(UploadedFile $file, string $directory): string
    {
        $safeName = time().'_'.preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $path = trim($directory, '/').'/'.$safeName;

        Storage::disk('local')->put($path, Crypt::encryptString($file->get()));

        return $path;
    }

    public static function response(string $path, ?string $downloadName = null, string $disposition = 'attachment'): Response
    {
        $contents = Storage::disk('local')->get($path);

        try {
            $contents = Crypt::decryptString($contents);
        } catch (DecryptException) {
            // Legacy file stored before encryption at rest was introduced.
        }

        $name = $downloadName ?: basename($path);
        $type = self::mimeFromName($name);

        // Every response carries nosniff, so a browser renders a file only as
        // the type this header names. A photo whose name gives nothing away —
        // a camera's "image", a ".jfif" — was still validated as an image on
        // the way in, so it is read off its own bytes rather than handed over
        // as octet-stream, which an <img> will not draw and a tab downloads.
        // Only ever as a raster image: those cannot run as script.
        if ($type === 'application/octet-stream' && $disposition === 'inline') {
            $type = self::rasterImageType($contents) ?? $type;
        }

        return response($contents, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => $disposition.'; filename="'.str_replace('"', '', $name).'"',
        ]);
    }

    /** The image type the bytes declare, if it is one a browser draws and cannot execute. */
    private static function rasterImageType(string $contents): ?string
    {
        if (! class_exists(\finfo::class)) {
            return null;
        }

        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return in_array($sniffed, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'], true)
            ? $sniffed
            : null;
    }

    /**
     * Removes a stored file. Used where retention is deliberately bounded —
     * a scanned attendance photo is deleted once every mark on it has been
     * confirmed. Returns false when the file was already gone.
     */
    public static function delete(string $path): bool
    {
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return false;
        }

        return Storage::disk('local')->delete($path);
    }

    private static function mimeFromName(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            // Accepted by the consultation and learner photo uploads; without
            // these a WebP was sent as octet-stream and drew as a broken image.
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };
    }
}
