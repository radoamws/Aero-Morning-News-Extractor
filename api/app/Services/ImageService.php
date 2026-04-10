<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\BmpEncoder;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;

class ImageService
{
    private ImageManager $imageManager;
    private int $maxWidth;
    private int $maxHeight;
    private int $maxSize;
    private string $backgroundColor;
    private string $format;
    private int $quality;

    public function __construct()
    {
        $this->imageManager = new ImageManager(new Driver());
        $this->maxWidth = (int) env('IMAGE_WIDTH', 700);
        $this->maxHeight = (int) env('IMAGE_HEIGHT', 400);
        $this->maxSize = (int) env('IMAGE_MAX_SIZE', 1000000);
        $this->backgroundColor = env('IMAGE_BACKGROUND_COLOR', '#005A8C');
        $this->format = env('IMAGE_FORMAT', 'jpg');
        $this->quality = (int) env('IMAGE_QUALITY', 85);
    }

    /**
     * Download and process image from URL
     */
    public function downloadAndOptimizeImage(string $imageUrl, string $newsTitle): ?string
    {
        try {
            $imageContent = @file_get_contents($imageUrl);
            
            if ($imageContent === false) {
                Log::warning("Failed to download image: $imageUrl");
                return null;
            }

            $tempPath = storage_path('app/temp/' . uniqid() . '.tmp');
            @mkdir(dirname($tempPath), 0755, true);
            file_put_contents($tempPath, $imageContent);

            $result = $this->processImage($tempPath, $newsTitle);
            @unlink($tempPath);

            return $result;
        } catch (\Exception $e) {
            Log::error("Error downloading/optimizing image: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Process local image attachment
     */
    public function processAttachmentImage(string $filePath, string $newsTitle): ?string
    {
        try {
            if (!file_exists($filePath)) {
                Log::warning("Image file not found: $filePath");
                return null;
            }

            return $this->processImage($filePath, $newsTitle);
        } catch (\Exception $e) {
            Log::error("Error processing attachment image: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Main image processing logic
     */
    private function processImage(string $filePath, string $newsTitle): ?string
    {
        try {
            $image = $this->imageManager->read($filePath);
            
            // Get original dimensions
            $origWidth = $image->width();
            $origHeight = $image->height();

            if ($origWidth <= 0 || $origHeight <= 0) {
                throw new \RuntimeException('Invalid image dimensions');
            }
            
            // Create background canvas
            $canvas = $this->imageManager->create($this->maxWidth, $this->maxHeight);
            $color = trim($this->backgroundColor);
            if ($color !== '' && $color[0] !== '#') {
                $color = '#' . $color;
            }
            $canvas->fill($color !== '' ? $color : '#005A8C');

            // Calculate scaling to fit image in canvas
            $scale = min(
                $this->maxWidth / $origWidth,
                $this->maxHeight / $origHeight
            );

            // Do not upscale small images.
            $scale = min(1, $scale);

            $newWidth = (int) ($origWidth * $scale);
            $newHeight = (int) ($origHeight * $scale);

            // Resize image
            $image->scale($newWidth, $newHeight);

            // Calculate position to center image
            $x = (int) (($this->maxWidth - $newWidth) / 2);
            $y = (int) (($this->maxHeight - $newHeight) / 2);

            // Place image on canvas
            $canvas->place($image, 'top-left', $x, $y);

            // Generate unique filename
            $slug = Str::slug($newsTitle);
            $slug = substr($slug, 0, 100);
            $filename = "{$slug}-" . uniqid() . ".{$this->format}";
            
            $storagePath = storage_path("app/public/images/{$filename}");
            @mkdir(dirname($storagePath), 0755, true);

            // Save image (Intervention Image v3 encodes first)
            $encoded = $this->encodeForStorage($canvas, $this->format, $this->quality);
            $encoded->save($storagePath);

            // Check file size
            $fileSize = filesize($storagePath);
            if ($fileSize > $this->maxSize) {
                Log::warning("Image size exceeds limit: $fileSize > {$this->maxSize}");
                
                // Reduce quality and try again
                $encoded = $this->encodeForStorage($canvas, $this->format, 60);
                $encoded->save($storagePath);
                $fileSize = filesize($storagePath);
                
                if ($fileSize > $this->maxSize) {
                    @unlink($storagePath);
                    return null;
                }
            }

            return "/storage/images/{$filename}";
        } catch (\Exception $e) {
            Log::error("Error in image processing: " . $e->getMessage());
            return $this->storeOriginalImage($filePath, $newsTitle);
        }
    }

    private function encodeForStorage(ImageInterface $image, string $format, int $quality): EncodedImageInterface
    {
        $ext = strtolower(trim($format));
        $ext = ltrim($ext, '.');

        return match ($ext) {
            'jpg', 'jpeg' => $image->encode(new JpegEncoder($quality)),
            'webp' => $image->encode(new WebpEncoder($quality)),
            'png' => $image->encode(new PngEncoder()),
            'gif' => $image->encode(new GifEncoder()),
            'bmp' => $image->encode(new BmpEncoder()),
            'avif' => $image->encode(new AvifEncoder($quality)),
            default => $image->encode(new JpegEncoder($quality)),
        };
    }

    private function storeOriginalImage(string $filePath, string $newsTitle): ?string
    {
        try {
            if (!file_exists($filePath)) {
                return null;
            }

            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if ($extension === '' || $extension === 'bin' || $extension === 'tmp') {
                $mimeType = mime_content_type($filePath) ?: '';
                $extension = match ($mimeType) {
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                    default => 'jpg',
                };
            }

            $slug = Str::slug($newsTitle);
            $slug = substr($slug, 0, 100);
            $filename = "{$slug}-" . uniqid() . ".{$extension}";
            $storagePath = storage_path("app/public/images/{$filename}");
            @mkdir(dirname($storagePath), 0755, true);

            if (!@copy($filePath, $storagePath)) {
                return null;
            }

            return "/storage/images/{$filename}";
        } catch (\Exception $e) {
            Log::error("Error storing original image: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate image URL
     */
    public function isValidImageUrl(string $url): bool
    {
        $validExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        return in_array($extension, $validExtensions) && filter_var($url, FILTER_VALIDATE_URL);
    }
}
