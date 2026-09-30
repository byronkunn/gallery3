<?php

namespace App\Support;

use App\Models\PostMedia;
use Illuminate\Support\Facades\Storage;

/**
 * Storage housekeeping for uploaded artwork and video files.
 */
class MediaMaintenance
{
    /**
     * Directories that hold user uploads on the public disk.
     *
     * @var array<int, string>
     */
    public const UPLOAD_DIRECTORIES = ['posts', 'videos'];

    /**
     * Files on the public disk that no post_media row points at.
     *
     * @return array<int, string>
     */
    public static function orphanedFiles(): array
    {
        $disk = Storage::disk('public');

        $referenced = PostMedia::query()
            ->pluck('url')
            ->map(fn (?string $url): ?string => self::relativePath($url))
            ->filter()
            ->flip();

        $orphans = [];
        foreach (self::UPLOAD_DIRECTORIES as $directory) {
            if (! $disk->exists($directory)) {
                continue;
            }

            foreach ($disk->allFiles($directory) as $file) {
                if (! $referenced->has($file)) {
                    $orphans[] = $file;
                }
            }
        }

        sort($orphans);

        return $orphans;
    }

    /**
     * Total size of uploaded media, in bytes, grouped per directory.
     *
     * @return array{files: int, bytes: int, by_directory: array<string, int>}
     */
    public static function storageUsage(): array
    {
        $disk = Storage::disk('public');
        $byDirectory = [];
        $files = 0;
        $bytes = 0;

        foreach (self::UPLOAD_DIRECTORIES as $directory) {
            $directoryBytes = 0;
            if (! $disk->exists($directory)) {
                $byDirectory[$directory] = 0;

                continue;
            }

            foreach ($disk->allFiles($directory) as $file) {
                $directoryBytes += $disk->size($file);
                $files++;
            }

            $byDirectory[$directory] = $directoryBytes;
            $bytes += $directoryBytes;
        }

        return ['files' => $files, 'bytes' => $bytes, 'by_directory' => $byDirectory];
    }

    /**
     * Rebuild a thumbnail for a stored upload. Returns false when the source
     * file is remote or the image extension is unavailable.
     */
    public static function regenerateThumbnail(PostMedia $media): bool
    {
        $relativePath = self::relativePath($media->url);
        if (! $relativePath) {
            return false;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($relativePath) || ! function_exists('imagecreatefromstring')) {
            return false;
        }

        $image = @imagecreatefromstring((string) $disk->get($relativePath));
        if (! $image) {
            return false;
        }

        $sourceWidth = max(1, imagesx($image));
        $sourceHeight = max(1, imagesy($image));
        $targetWidth = min(480, $sourceWidth);
        $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));

        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        $extension = self::thumbnailExtension($relativePath);
        $thumbnailRelativePath = self::thumbnailPath($relativePath, $extension);

        ob_start();
        match ($extension) {
            'png' => imagepng($thumbnail),
            'webp' => imagewebp($thumbnail),
            'gif' => imagegif($thumbnail),
            default => imagejpeg($thumbnail, null, 85),
        };
        $binary = (string) ob_get_clean();

        imagedestroy($image);
        imagedestroy($thumbnail);

        if ($binary === '') {
            return false;
        }

        $disk->put($thumbnailRelativePath, $binary);
        $media->update(['thumbnail_url' => $disk->url($thumbnailRelativePath)]);

        return true;
    }

    private static function thumbnailPath(string $relativePath, string $extension): string
    {
        $directory = pathinfo($relativePath, PATHINFO_DIRNAME);
        $filename = pathinfo($relativePath, PATHINFO_FILENAME);

        return $directory.'/thumbnails/'.$filename.'.'.$extension;
    }

    private static function thumbnailExtension(string $relativePath): string
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'png',
            'webp' => function_exists('imagewebp') ? 'webp' : 'jpg',
            'gif' => function_exists('imagegif') ? 'gif' : 'jpg',
            default => 'jpg',
        };
    }

    /**
     * Turn a stored media URL into a path relative to the public disk.
     */
    public static function relativePath(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';

        if (! str_starts_with($path, '/storage/')) {
            return null;
        }

        return ltrim(substr($path, strlen('/storage/')), '/') ?: null;
    }

    /**
     * Human readable byte count for the admin console.
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $power > 1 ? 2 : 0).' '.$units[$power];
    }
}
