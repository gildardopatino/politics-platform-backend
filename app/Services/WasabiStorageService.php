<?php

namespace App\Services;

use App\Models\Tenant;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;

class WasabiStorageService
{
    /**
     * Get S3 Client instance
     */
    public function getS3Client(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region'),
            'endpoint' => config('filesystems.disks.s3.endpoint'),
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
            'use_path_style_endpoint' => config('filesystems.disks.s3.use_path_style_endpoint'),
        ]);
    }

    /**
     * Upload a file to Wasabi and return the signed URL with 7 days expiration
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param string $path Directory path where to store the file
     * @param Tenant|null $tenant Optional tenant to use specific bucket
     * @return array ['key' => string, 'url' => string]
     */
    public function uploadFile($file, string $path = 'landing', ?Tenant $tenant = null): array
    {
        // Generate unique filename
        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $key = $path . '/' . $filename;

        // Check if using S3 or local storage
        $disk = config('filesystems.default');
        
        if ($disk === 's3') {
            // Determine bucket to use
            $bucket = $this->getBucket($tenant);

            // Upload to S3/Wasabi with specific bucket
            $this->putFile($key, file_get_contents($file), $bucket);

            // Generate signed URL with 7 days expiration (maximum allowed)
            $url = $this->getSignedUrl($key, $tenant);
        } else {
            // Use local storage
            Storage::disk('public')->put($key, file_get_contents($file));
            $url = asset('storage/' . $key);
        }

        return [
            'key' => $key,
            'url' => $url,
        ];
    }

    /**
     * Get a signed URL for a file key with 7 days expiration (AWS maximum)
     *
     * @param string $key
     * @param Tenant|null $tenant Optional tenant to use specific bucket
     * @return string
     */
    public function getSignedUrl(string $key, ?Tenant $tenant = null): string
    {
        $client = $this->getS3Client();
        
        // Use tenant bucket if available, otherwise default
        $bucket = $this->getBucket($tenant);

        // Create command for GetObject
        $command = $client->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => $key,
        ]);

        // AWS S3 max expiration is 7 days (604800 seconds)
        // Using 6 days to be safe
        $expirationSeconds = 6 * 24 * 3600; // 6 days = 518400 seconds

        // Create presigned request
        $request = $client->createPresignedRequest($command, "+{$expirationSeconds} seconds");

        return (string) $request->getUri();
    }

    /**
     * Delete a file from Wasabi
     *
     * @param string $key
     * @param Tenant|null $tenant Optional tenant to use specific bucket
     * @return bool
     */
    public function deleteFile(string $key, ?Tenant $tenant = null): bool
    {
        $disk = config('filesystems.default');
        
        if ($disk === 's3') {
            $bucket = $this->getBucket($tenant);
            
            if ($this->fileExists($key, $tenant)) {
                $client = $this->getS3Client();
                
                $client->deleteObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                ]);
                
                return true;
            }
        } else {
            // Use local storage
            if (Storage::disk('public')->exists($key)) {
                Storage::disk('public')->delete($key);
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a file exists in Wasabi
     *
     * @param string $key
     * @param Tenant|null $tenant Optional tenant to use specific bucket
     * @return bool
     */
    public function fileExists(string $key, ?Tenant $tenant = null): bool
    {
        $bucket = $this->getBucket($tenant);
        $client = $this->getS3Client();
        
        return $client->doesObjectExist($bucket, $key);
    }

    /**
     * Get the bucket to use (tenant-specific or default)
     *
     * @param Tenant|null $tenant
     * @return string
     */
    public function getBucket(?Tenant $tenant = null): string
    {
        // If tenant has a specific bucket configured, use it
        if ($tenant && $tenant->s3_bucket) {
            return $tenant->s3_bucket;
        }

        // Otherwise use default from config
        return config('filesystems.disks.s3.bucket');
    }

    /**
     * Put file to S3 with specific bucket
     *
     * @param string $key
     * @param string $contents
     * @param string $bucket
     * @return void
     */
    protected function putFile(string $key, string $contents, string $bucket): void
    {
        $client = $this->getS3Client();
        
        $client->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $contents,
            'ContentType' => $this->getMimeType($key),
        ]);
    }

    /**
     * Get MIME type from file extension
     *
     * @param string $key
     * @return string
     */
    protected function getMimeType(string $key): string
    {
        $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
        ];
        
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
