<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\ApiException;
use App\Core\Config;
use finfo;
use GdImage;
use Throwable;

/**
 * Avatar de «Mi usuario» (§6): JPG/PNG/WebP, máximo 2 MB, MIME real verificado y
 * reprocesado con GD a 512×512. Nunca se guarda el archivo original y el nombre
 * lo genera el servidor, así que no hay extensión ni ruta controlada por el cliente.
 */
final class AvatarStorage
{
    private const SIZE = 512;
    private const RELATIVE_PATTERN = '#^avatars/[a-f0-9]{32}\.(jpg|png|webp)$#';

    public function __construct(
        private readonly Config $config,
        private readonly string $root,
    ) {
    }

    /**
     * @param array<string, mixed> $file Entrada de $_FILES
     * @return string Ruta relativa segura, p. ej. "avatars/3f...a1.png"
     */
    public function store(array $file): string
    {
        $tmp = $file['tmp_name'] ?? null;
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw ApiException::validation(['avatar' => $this->uploadErrorMessage($error)]);
        }

        if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
            throw ApiException::validation(['avatar' => 'No se recibió un archivo válido.']);
        }

        $this->assertSafeClientFilename($file['name'] ?? null);

        $maxBytes = (int) $this->config->get('uploads.avatar_max_bytes', 2097152);

        $size = filesize($tmp);

        if ($size === false || $size === 0) {            throw ApiException::validation(['avatar' => 'El archivo está vacío.']);
        }

        if ($size > $maxBytes) {
            throw ApiException::validation([
                'avatar' => sprintf('El archivo supera el máximo de %d KB.', intdiv($maxBytes, 1024)),
            ]);
        }

        $mime = (static function (string $path): string {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($path);

            return is_string($detected) ? $detected : '';
        })($tmp);

        $allowed = $this->allowedMimes();

        if (!in_array($mime, $allowed, true)) {
            throw ApiException::validation(['avatar' => 'Solo se aceptan imágenes JPG, PNG o WebP.']);
        }

        $info = @getimagesize($tmp);

        if ($info === false) {
            throw ApiException::validation(['avatar' => 'El archivo no es una imagen válida.']);
        }

        $type = (int) ($info[2] ?? 0);
        $source = $this->createImage($tmp, $type);

        if (!$source instanceof GdImage) {
            throw ApiException::validation(['avatar' => 'No se pudo procesar la imagen.']);
        }

        try {
            $canvas = $this->renderSquare($source, $type);
        } catch (Throwable) {
            imagedestroy($source);

            throw ApiException::validation(['avatar' => 'No se pudo procesar la imagen.']);
        }

        imagedestroy($source);

        $extension = $this->extensionFor($type);
        $directory = $this->directory();

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($canvas);

            throw new ApiException(500, 'INTERNAL_ERROR', 'No se pudo guardar el avatar.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $absolute = $directory . DIRECTORY_SEPARATOR . $filename;

        $stored = match ($extension) {
            'jpg' => imagejpeg($canvas, $absolute, 88),
            'png' => imagepng($canvas, $absolute, 6),
            'webp' => imagewebp($canvas, $absolute, 88),
            default => false,
        };

        imagedestroy($canvas);

        if ($stored !== true) {
            throw new ApiException(500, 'INTERNAL_ERROR', 'No se pudo guardar el avatar.');
        }

        @chmod($absolute, 0644);

        return 'avatars/' . $filename;
    }

    /**
     * Ruta absoluta solo si el valor guardado cumple el patrón generado por el
     * servidor; cualquier otra cosa se trata como inexistente (anti path traversal).
     */
    public function absolutePath(mixed $relative): ?string
    {
        if (!is_string($relative) || preg_match(self::RELATIVE_PATTERN, $relative) !== 1) {
            return null;
        }

        $absolute = $this->directory() . DIRECTORY_SEPARATOR . basename($relative);

        return is_file($absolute) ? $absolute : null;
    }

    public function contentType(string $relative): string
    {
        return match (pathinfo($relative, PATHINFO_EXTENSION)) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    public function delete(mixed $relative): void
    {
        $absolute = $this->absolutePath($relative);

        if ($absolute !== null) {
            @unlink($absolute);
        }
    }

    /**
     * @return list<string>
     */
    private function allowedMimes(): array
    {
        $mimes = $this->config->get('uploads.avatar_mimes', ['image/jpeg', 'image/png', 'image/webp']);

        if (!is_array($mimes)) {
            return ['image/jpeg', 'image/png', 'image/webp'];
        }

        $allowed = [];

        foreach ($mimes as $mime) {
            if (is_string($mime) && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $allowed[] = $mime;
            }
        }

        return $allowed === [] ? ['image/jpeg', 'image/png', 'image/webp'] : $allowed;
    }

    private function createImage(string $tmp, int $type): ?GdImage
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG => @imagecreatefrompng($tmp),
            IMAGETYPE_WEBP => @imagecreatefromwebp($tmp),
            default => null,
        };

        return $image instanceof GdImage ? $image : null;
    }

    private function renderSquare(GdImage $source, int $type): GdImage
    {
        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);

        if (!$canvas instanceof GdImage) {
            throw new ApiException(500, 'INTERNAL_ERROR', 'No se pudo procesar la imagen.');
        }

        if ($type === IMAGETYPE_JPEG) {
            // JPEG no tiene alfa: fondo blanco en lugar de negro.
            imagefilledrectangle($canvas, 0, 0, self::SIZE, self::SIZE, (int) imagecolorallocate($canvas, 255, 255, 255));
        } else {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefilledrectangle(
                $canvas,
                0,
                0,
                self::SIZE,
                self::SIZE,
                (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127)
            );
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            throw new ApiException(500, 'INTERNAL_ERROR', 'La imagen no tiene dimensiones válidas.');
        }

        // Recorte tipo "cover": escala al lado menor y centra, sin deformar.
        $scale = max(self::SIZE / $sourceWidth, self::SIZE / $sourceHeight);
        $drawWidth = (int) ceil($sourceWidth * $scale);
        $drawHeight = (int) ceil($sourceHeight * $scale);
        $offsetX = intdiv(self::SIZE - $drawWidth, 2);
        $offsetY = intdiv(self::SIZE - $drawHeight, 2);

        imagecopyresampled(
            $canvas,
            $source,
            $offsetX,
            $offsetY,
            0,
            0,
            $drawWidth,
            $drawHeight,
            $sourceWidth,
            $sourceHeight
        );

        return $canvas;
    }

    /**
     * Aunque el nombre final lo genera el servidor, se rechaza cualquier extensión
     * ejecutable o de documento activo: un `.php`/`.svg` renombrado no entra ni
     * siquiera a reprocesarse.
     */
    private function assertSafeClientFilename(mixed $clientName): void
    {
        if (!is_string($clientName) || $clientName === '') {
            return;
        }

        $extension = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));

        $forbidden = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pl', 'py', 'rb',
            'sh', 'exe', 'bat', 'cmd', 'com', 'js', 'mjs', 'html', 'htm', 'svg', 'svgz',
            'xml', 'htaccess',
        ];

        if ($extension !== '' && in_array($extension, $forbidden, true)) {
            throw ApiException::validation(['avatar' => 'El tipo de archivo no está permitido.']);
        }
    }

    private function extensionFor(int $type): string
    {
        return match ($type) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => 'jpg',
        };
    }

    private function directory(): string
    {
        $storage = (string) $this->config->get('paths.storage', $this->root . DIRECTORY_SEPARATOR . 'storage');

        return rtrim($storage, '\\/') . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'avatars';
    }

    private function uploadErrorMessage(int $error): string
    {        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño máximo permitido.',
            UPLOAD_ERR_PARTIAL => 'La subida se interrumpió. Inténtalo de nuevo.',
            UPLOAD_ERR_NO_FILE => 'Adjunta un archivo de imagen.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'No se pudo recibir el archivo.',
            default => 'No se pudo recibir el archivo.',
        };
    }
}
