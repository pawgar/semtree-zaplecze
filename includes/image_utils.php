<?php
/**
 * Image optimization utilities using GD library.
 * Converts images to JPEG, strips metadata, generates random filenames.
 */

function optimizeImage(string $binary, string $filename, bool $keepFilename = false): array {
    // Try to create GD image from binary data
    $img = @imagecreatefromstring($binary);
    if (!$img) {
        // GD can't process - return with random filename only
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'jpg';
        $mimeMap = ['png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
        $outFilename = $keepFilename ? pathinfo($filename, PATHINFO_FILENAME) . '.' . $ext : bin2hex(random_bytes(8)) . '.' . $ext;
        return [
            'data' => $binary,
            'filename' => $outFilename,
            'mime' => $mimeMap[$ext] ?? 'image/jpeg',
        ];
    }

    // Flatten transparency to white background, convert to JPEG
    $width = imagesx($img);
    $height = imagesy($img);
    $canvas = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopy($canvas, $img, 0, 0, 0, 0, $width, $height);
    imagedestroy($img);

    ob_start();
    imagejpeg($canvas, null, 85);
    $jpegData = ob_get_clean();
    imagedestroy($canvas);

    $outFilename = $keepFilename ? pathinfo($filename, PATHINFO_FILENAME) . '.jpg' : bin2hex(random_bytes(8)) . '.jpg';
    return [
        'data' => $jpegData,
        'filename' => $outFilename,
        'mime' => 'image/jpeg',
    ];
}
