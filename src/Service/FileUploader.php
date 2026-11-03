<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FileUploader
{
    private const MAX_SIZE = 5 * 1024 * 1024; // 5MB
    private const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function __construct(
        #[Autowire(param: '%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Stores an image file under public/uploads/<subdir> and returns the generated filename.
     *
     * @throws \RuntimeException when the file is not a valid image upload
     */
    public function uploadImage(UploadedFile $file, string $subdir = 'avatars'): string
    {
        if (!$file->isValid()) {
            throw new \RuntimeException('Please choose a valid image to upload.');
        }

        if (($file->getSize() ?? 0) > self::MAX_SIZE) {
            throw new \RuntimeException('Image is too large. Maximum size is 5MB.');
        }

        $mime = $file->getMimeType() ?? '';
        if (!isset(self::ALLOWED_MIMES[$mime])) {
            throw new \RuntimeException(sprintf('%s is not an accepted image type. Accepted: JPG, PNG, GIF, WEBP.', $file->getClientOriginalName()));
        }

        $directory = $this->projectDir.'/public/uploads/'.$subdir;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the upload directory.');
        }

        $filename = bin2hex(random_bytes(16)).'.'.self::ALLOWED_MIMES[$mime];

        try {
            $file->move($directory, $filename);
        } catch (FileException) {
            throw new \RuntimeException('Upload failed: could not save the image.');
        }

        return $filename;
    }

    public function exists(string $subdir, ?string $filename): bool
    {
        if (!$filename) {
            return false;
        }

        return is_file($this->projectDir.'/public/uploads/'.$subdir.'/'.$filename);
    }
}
