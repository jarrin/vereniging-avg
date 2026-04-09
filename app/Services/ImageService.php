<?php

namespace App\Services;

class ImageService
{
    /**
     * Resize an image to a maximum width and height while maintaining aspect ratio
     * @param string $sourcePath Path to the source image
     * @param string $targetPath Path to save the resized image
     * @param int $maxWidth Maximum width
     * @param int $maxHeight Maximum height
     * @return bool Success
     */
    public static function resizeImage(string $sourcePath, string $targetPath, int $maxWidth = 200, int $maxHeight = 200): bool
    {
        // Get image info
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return false;
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];
        $mime = $imageInfo['mime'];

        // Calculate new dimensions
        $aspectRatio = $width / $height;
        if ($width > $height) {
            $newWidth = min($width, $maxWidth);
            $newHeight = $newWidth / $aspectRatio;
            if ($newHeight > $maxHeight) {
                $newHeight = $maxHeight;
                $newWidth = $newHeight * $aspectRatio;
            }
        } else {
            $newHeight = min($height, $maxHeight);
            $newWidth = $newHeight * $aspectRatio;
            if ($newWidth > $maxWidth) {
                $newWidth = $maxWidth;
                $newHeight = $newWidth / $aspectRatio;
            }
        }

        // Create image resource based on type
        switch ($mime) {
            case 'image/jpeg':
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $sourceImage = imagecreatefrompng($sourcePath);
                break;
            case 'image/gif':
                $sourceImage = imagecreatefromgif($sourcePath);
                break;
            default:
                return false;
        }

        if (!$sourceImage) {
            return false;
        }

        // Create new image
        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG
        if ($mime === 'image/png') {
            imagealphablending($resizedImage, false);
            imagesavealpha($resizedImage, true);
            $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
            imagefill($resizedImage, 0, 0, $transparent);
        }

        // Resize
        imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // Save based on type
        $success = false;
        switch ($mime) {
            case 'image/jpeg':
                $success = imagejpeg($resizedImage, $targetPath, 90);
                break;
            case 'image/png':
                $success = imagepng($resizedImage, $targetPath, 9);
                break;
            case 'image/gif':
                $success = imagegif($resizedImage, $targetPath);
                break;
        }

        // Clean up
        imagedestroy($sourceImage);
        imagedestroy($resizedImage);

        return $success;
    }
}