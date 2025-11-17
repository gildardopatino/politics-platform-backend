<?php

namespace App\Services;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class VotingPlaceImageService
{
    protected ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Generate voting place image with voter data
     * 
     * @param string $cedula Document number
     * @param array $data Voting place data
     * @return string URL of generated image
     */
    public function generateVotingPlaceImage(string $cedula, array $data): string
    {
        try {
            // Load base image
            $templatePath = public_path('images/boceto1.jpg');
            
            if (!file_exists($templatePath)) {
                throw new \Exception("Template image not found: {$templatePath}");
            }

            $img = $this->manager->read($templatePath);

            // Font path (you may need to adjust this)
            $fontFile = public_path('fonts/RobotoCondensed-VariableFont_wght.ttf');
            
            // Check if custom font exists, otherwise use default
            $useCustomFont = file_exists($fontFile);
            
            if (!$useCustomFont) {
                Log::warning('Custom font not found, using system default', ['font_path' => $fontFile]);
            }

            // Add text to image - Adjusted positions for boceta1.png
            // These positions are estimates and may need fine-tuning based on actual image
            
            // C.C (Cédula) - Top left area
            $this->addText($img, "{$cedula}", 610, 320, 40, '#000000', $fontFile, $useCustomFont);
            
            // Departamento - Below cedula
            $this->addText($img, "{$data['departamento']}", 170, 425, 50, '#000000', $fontFile, $useCustomFont);
            
            // Ciudad/Municipio - Below departamento
            $this->addText($img, "{$data['ciudad']}", 170, 560, 50, '#000000', $fontFile, $useCustomFont);

            // Direccion Votacion - Below Municpio
            $this->addText($img, "{$data['direccion']}", 90, 695, 36, '#000000', $fontFile, $useCustomFont);
            
            // Puesto de votación - Below ciudad
            $this->addText($img, "{$data['puesto']}", 560, 460, 26, '#000000', $fontFile, $useCustomFont);
            
            // Mesa - Larger and highlighted
            $this->addText($img, "{$data['mesa']}", 680, 580, 50, '#000000', $fontFile, $useCustomFont, 'bold');

            // Save generated image
            $directory = 'images/votaciones';
            if (!file_exists(public_path($directory))) {
                mkdir(public_path($directory), 0755, true);
            }

            $filename = "{$cedula}.jpg"; // Changed to JPG for smaller size
            $savePath = public_path("{$directory}/{$filename}");
            
            // Save as JPEG with compression to reduce file size
            $img->toJpeg(85)->save($savePath); // 85% quality

            // Generate URL
            $imageUrl = url("{$directory}/{$filename}");

            Log::info('Voting place image generated successfully', [
                'cedula' => $cedula,
                'path' => $savePath,
                'url' => $imageUrl,
                'file_size' => file_exists($savePath) ? filesize($savePath) : 0,
            ]);

            return $imageUrl;

        } catch (\Exception $e) {
            Log::error('Failed to generate voting place image', [
                'cedula' => $cedula,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Add text to image
     * 
     * @param mixed $img Image object
     * @param string $text Text to add
     * @param int $x X position
     * @param int $y Y position
     * @param int $size Font size
     * @param string $color Hex color
     * @param string $fontFile Font file path
     * @param bool $useCustomFont Whether to use custom font
     * @param string $weight Font weight
     */
    protected function addText(
        $img, 
        string $text, 
        int $x, 
        int $y, 
        int $size, 
        string $color = '#000000',
        ?string $fontFile = null,
        bool $useCustomFont = false,
        string $weight = 'normal'
    ): void {
        try {
            if ($useCustomFont && $fontFile && file_exists($fontFile)) {
                // Use custom TTF font
                $img->text($text, $x, $y, function ($font) use ($size, $color, $fontFile) {
                    $font->file($fontFile);
                    $font->size($size);
                    $font->color($color);
                    $font->align('left');
                    $font->valign('top');
                });
            } else {
                // Use GD's built-in font (fallback)
                $img->text($text, $x, $y, function ($font) use ($size, $color) {
                    $font->size($size);
                    $font->color($color);
                    $font->align('left');
                    $font->valign('top');
                });
            }
        } catch (\Exception $e) {
            Log::warning('Failed to add text to image', [
                'text' => $text,
                'error' => $e->getMessage(),
            ]);
            // Continue even if text fails to add
        }
    }

    /**
     * Generate voting place image and send via WhatsApp
     * 
     * @param string $phone Phone number
     * @param string $cedula Document number
     * @param array $votingData Voting place data
     * @param int $tenantId Tenant ID
     * @return bool Success status
     */
    public function sendVotingPlaceImageWhatsApp(
        string $phone, 
        string $cedula, 
        array $votingData, 
        int $tenantId
    ): bool {
        try {
            // Generate image
            $imageUrl = $this->generateVotingPlaceImage($cedula, $votingData);

            // Get local path
            $localPath = str_replace(url(''), public_path(''), $imageUrl);
            
            // Convert image to base64 for Evolution API
            if (file_exists($localPath)) {
                $imageData = file_get_contents($localPath);
                $base64Image = base64_encode($imageData); // Just base64, no data URI prefix
                
                $fileSizeKB = strlen($imageData) / 1024;
                Log::info('Image prepared for sending', [
                    'cedula' => $cedula,
                    'file_size_kb' => round($fileSizeKB, 2),
                ]);
            } else {
                throw new \Exception("Generated image not found at {$localPath}");
            }

            // Send via WhatsApp
            $whatsappService = app(WhatsAppNotificationService::class);
            
            $caption = "📍 *Información de Votación*\n\n";
            $caption .= "Cédula: {$cedula}\n";
            $caption .= "Departamento: {$votingData['departamento']}\n";
            $caption .= "Ciudad: {$votingData['ciudad']}\n";
            $caption .= "Puesto: {$votingData['puesto']}\n";
            $caption .= "Mesa: {$votingData['mesa']}";

            $success = $whatsappService->sendImage(
                $phone,
                $base64Image, // Send as pure base64
                $tenantId,
                $caption,
                "puesto_votacion_{$cedula}.jpg" // Changed to JPG
            );

            if ($success) {
                Log::info('Voting place image sent via WhatsApp', [
                    'cedula' => $cedula,
                    'phone' => $phone,
                    'tenant_id' => $tenantId,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Failed to send voting place image via WhatsApp', [
                'cedula' => $cedula,
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
