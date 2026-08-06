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

        return response($contents, 200, [
            'Content-Type' => self::mimeFromName($name),
            'Content-Disposition' => $disposition.'; filename="'.str_replace('"', '', $name).'"',
        ]);
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
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };
    }
}
