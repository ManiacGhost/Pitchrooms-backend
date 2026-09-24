<?php

declare(strict_types=1);

namespace PitchRooms\Controllers;

use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Core\HttpException;
use PitchRooms\Core\Paths;
use PitchRooms\Core\Request;
use PitchRooms\Core\Response;
use PitchRooms\Services\ActivityLog;
use PitchRooms\Support\Id;
use PitchRooms\Support\Str;

/**
 * Uploads live outside the webroot and are served through this controller,
 * never linked to directly. Signup used to stash base64 data URLs in
 * localStorage — resumes, decks and verification documents now land here.
 */
final class FileController
{
    private const ALLOWED = [
        'application/pdf'  => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'video/mp4'  => 'mp4',
    ];

    /** POST /files — multipart upload. */
    public function store(Request $request): Response
    {
        $file = $request->file('file') ?? $request->file('upload');
        if ($file === null || !isset($file['tmp_name'])) {
            throw HttpException::badRequest('No file was uploaded.', 'NO_FILE');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw HttpException::badRequest($this->uploadError((int) $file['error']), 'UPLOAD_FAILED');
        }

        $maxBytes = Env::int('UPLOAD_MAX_BYTES', 15728640);
        if ((int) $file['size'] > $maxBytes) {
            throw HttpException::badRequest(
                sprintf('That file is larger than the %d MB limit.', (int) round($maxBytes / 1048576)),
                'FILE_TOO_LARGE'
            );
        }

        // Trust the file's own bytes, not the browser's Content-Type header.
        $mime = $this->detectMime((string) $file['tmp_name']);
        if (!isset(self::ALLOWED[$mime])) {
            throw HttpException::badRequest(
                'That file type is not allowed. Use PDF, Word, PowerPoint, an image, or MP4.',
                'BAD_FILE_TYPE'
            );
        }

        $id = Id::make('file');
        $extension = self::ALLOWED[$mime];
        $relative = gmdate('Y/m') . '/' . $id . '.' . $extension;
        $absolute = Paths::uploads($relative);

        Paths::ensureDir(dirname($absolute));

        if (!@move_uploaded_file((string) $file['tmp_name'], $absolute)
            && !@rename((string) $file['tmp_name'], $absolute)) {
            throw HttpException::server('Could not store the uploaded file.', 'STORE_FAILED');
        }
        @chmod($absolute, 0644);

        Database::insert('files', [
            'id'           => $id,
            'owner_id'     => $request->userId(),
            'kind'         => $request->string('kind') ?: 'document',
            'name'         => mb_substr((string) ($file['name'] ?? 'upload.' . $extension), 0, 255),
            'mime_type'    => $mime,
            'size_bytes'   => (int) $file['size'],
            'storage_path' => $relative,
            'checksum'     => hash_file('sha256', $absolute) ?: null,
            'visibility'   => $request->string('visibility') === 'public' ? 'public' : 'private',
            'created_at'   => Str::dbDate(),
            'deleted_at'   => null,
        ]);

        ActivityLog::record((string) $request->userId(), 'file', $id, 'uploaded', ['kind' => $request->string('kind')], $request);

        return Response::created([
            'fileId'    => $id,
            'id'        => $id,
            'name'      => $file['name'] ?? null,
            'mimeType'  => $mime,
            'sizeBytes' => (int) $file['size'],
            'url'       => Env::url('/files/' . $id),
        ]);
    }

    /** GET /files/{id} — streams the file to anyone allowed to see it. */
    public function show(Request $request, array $params): Response
    {
        $file = Database::first(
            'SELECT * FROM files WHERE id = :id AND deleted_at IS NULL',
            ['id' => (string) $params['id']]
        );

        if ($file === null) {
            throw HttpException::notFound('File not found.');
        }

        $this->assertReadable($file, $request);

        $absolute = Paths::uploads((string) $file['storage_path']);
        if (!is_file($absolute)) {
            throw HttpException::notFound('That file is no longer stored.');
        }

        $inline = in_array((string) $file['mime_type'], ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'], true)
            && $request->string('download') === '';

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $file['mime_type']);
            header('Content-Length: ' . (string) filesize($absolute));
            header('X-Content-Type-Options: nosniff');
            header(sprintf(
                'Content-Disposition: %s; filename="%s"',
                $inline ? 'inline' : 'attachment',
                addslashes((string) $file['name'])
            ));
            header('Cache-Control: private, max-age=' . Env::int('FILE_URL_TTL', 900));

            $origin = $request->header('origin');
            if ($origin !== null) {
                header('Access-Control-Allow-Origin: ' . rtrim($origin, '/'));
                header('Access-Control-Allow-Credentials: true');
            }
        }

        readfile($absolute);
        exit;
    }

    /** GET /files/{id}/meta */
    public function meta(Request $request, array $params): Response
    {
        $file = Database::first(
            'SELECT * FROM files WHERE id = :id AND deleted_at IS NULL',
            ['id' => (string) $params['id']]
        );

        if ($file === null) {
            throw HttpException::notFound('File not found.');
        }
        $this->assertReadable($file, $request);

        return Response::json([
            'id'        => $file['id'],
            'name'      => $file['name'],
            'kind'      => $file['kind'],
            'mimeType'  => $file['mime_type'],
            'sizeBytes' => (int) $file['size_bytes'],
            'url'       => Env::url('/files/' . $file['id']),
            'createdAt' => Str::toIso($file['created_at']),
        ]);
    }

    /** DELETE /files/{id} */
    public function destroy(Request $request, array $params): Response
    {
        $file = Database::first('SELECT * FROM files WHERE id = :id', ['id' => (string) $params['id']]);
        if ($file === null) {
            throw HttpException::notFound('File not found.');
        }
        if ($file['owner_id'] !== $request->userId() && !$request->isAdmin()) {
            throw HttpException::forbidden('That file is not yours.');
        }

        // Soft delete: proposals and messages may still reference it.
        Database::update('files', ['deleted_at' => Str::dbDate()], 'id = :id', ['id' => $file['id']]);

        return Response::json(['deleted' => true]);
    }

    // ---------------------------------------------------------------- helpers

    private function assertReadable(array $file, Request $request): void
    {
        if ($file['visibility'] === 'public' || $request->isAdmin()) {
            return;
        }

        $userId = $request->userId();
        if ($userId === null) {
            throw HttpException::unauthenticated('Sign in to open this file.');
        }
        if ($file['owner_id'] === $userId) {
            return;
        }

        $fileId = (string) $file['id'];

        // A counterparty can read what was shared with them: a proposal's CV
        // or deck, a brief's attachment, or a message attachment.
        $shared = (int) Database::scalar(
            'SELECT
               (SELECT COUNT(*) FROM proposals p
                 WHERE (p.resume_file_id = :f1 OR p.deck_file_id = :f2)
                   AND (p.buyer_id = :u1 OR p.seller_id = :u2))
             + (SELECT COUNT(*) FROM opportunity_files of2
                 JOIN opportunities o ON o.id = of2.opportunity_id
                 WHERE of2.file_id = :f3
                   AND (o.owner_id = :u3
                        OR EXISTS (SELECT 1 FROM proposals p2 WHERE p2.opportunity_id = o.id AND p2.seller_id = :u4)))
             + (SELECT COUNT(*) FROM message_attachments ma
                 JOIN messages m ON m.id = ma.message_id
                 WHERE ma.file_id = :f4 AND (m.from_id = :u5 OR m.to_id = :u6))',
            [
                'f1' => $fileId, 'f2' => $fileId, 'f3' => $fileId, 'f4' => $fileId,
                'u1' => $userId, 'u2' => $userId, 'u3' => $userId,
                'u4' => $userId, 'u5' => $userId, 'u6' => $userId,
            ]
        );

        if ($shared === 0) {
            throw HttpException::forbidden('You do not have access to this file.');
        }
    }

    private function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return (string) (mime_content_type($path) ?: 'application/octet-stream');
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is too large for the server limit.',
            UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not write the file.',
            default              => 'The upload failed.',
        };
    }
}
