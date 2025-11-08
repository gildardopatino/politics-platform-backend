<?php

namespace App\Services;

use App\Models\LandingSocialFeed;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SocialMediaSyncService
{
    protected WasabiStorageService $wasabi;

    public function __construct(WasabiStorageService $wasabi)
    {
        $this->wasabi = $wasabi;
    }

    /**
     * Sync all enabled social networks for a tenant
     */
    public function syncAll(Tenant $tenant): array
    {
        $results = [
            'twitter' => null,
            'facebook' => null,
            'instagram' => null,
            'youtube' => null,
        ];

        if ($tenant->twitter_enabled && $tenant->twitter_bearer_token && $tenant->twitter_user_id) {
            $results['twitter'] = $this->syncTwitter($tenant);
        }

        if ($tenant->facebook_enabled && $tenant->facebook_access_token && $tenant->facebook_page_id) {
            $results['facebook'] = $this->syncFacebook($tenant);
        }

        if ($tenant->instagram_enabled && $tenant->instagram_access_token && $tenant->instagram_user_id) {
            $results['instagram'] = $this->syncInstagram($tenant);
        }

        if ($tenant->youtube_enabled && $tenant->youtube_api_key && $tenant->youtube_channel_id) {
            $results['youtube'] = $this->syncYouTube($tenant);
        }

        // Update last synced timestamp
        $tenant->update(['social_last_synced_at' => now()]);

        return $results;
    }

    /**
     * Sync Twitter/X posts
     */
    public function syncTwitter(Tenant $tenant): array
    {
        try {
            $response = Http::withToken($tenant->twitter_bearer_token)
                ->get("https://api.twitter.com/2/users/{$tenant->twitter_user_id}/tweets", [
                    'max_results' => 10,
                    'tweet.fields' => 'created_at,public_metrics,attachments',
                    'expansions' => 'attachments.media_keys',
                    'media.fields' => 'url,preview_image_url',
                ]);

            if (!$response->successful()) {
                Log::error('Twitter API Error', [
                    'tenant_id' => $tenant->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['synced' => 0, 'errors' => ['API error: ' . $response->status()]];
            }

            $data = $response->json();
            $tweets = $data['data'] ?? [];
            $includes = $data['includes'] ?? [];
            $mediaMap = [];

            // Map media by keys
            if (isset($includes['media'])) {
                foreach ($includes['media'] as $media) {
                    $mediaMap[$media['media_key']] = $media;
                }
            }

            $synced = 0;
            foreach ($tweets as $tweet) {
                $imageUrl = null;

                // Get image if available
                if (isset($tweet['attachments']['media_keys'][0])) {
                    $mediaKey = $tweet['attachments']['media_keys'][0];
                    if (isset($mediaMap[$mediaKey])) {
                        $media = $mediaMap[$mediaKey];
                        $imageUrl = $media['url'] ?? $media['preview_image_url'] ?? null;
                    }
                }

                $this->createOrUpdatePost($tenant, [
                    'external_id' => $tweet['id'],
                    'plataforma' => 'twitter',
                    'usuario' => $tenant->twitter_username ?? '@' . $tenant->twitter_user_id,
                    'contenido' => $tweet['text'],
                    'fecha' => Carbon::parse($tweet['created_at']),
                    'likes' => $tweet['public_metrics']['like_count'] ?? 0,
                    'compartidos' => $tweet['public_metrics']['retweet_count'] ?? 0,
                    'comentarios' => $tweet['public_metrics']['reply_count'] ?? 0,
                    'external_url' => "https://twitter.com/i/web/status/{$tweet['id']}",
                    'image_url' => $imageUrl,
                ]);

                $synced++;
            }

            return ['synced' => $synced, 'errors' => []];
        } catch (\Exception $e) {
            Log::error('Twitter Sync Error', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return ['synced' => 0, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * Sync Facebook posts
     */
    public function syncFacebook(Tenant $tenant): array
    {
        try {
            $response = Http::get("https://graph.facebook.com/v18.0/{$tenant->facebook_page_id}/posts", [
                'access_token' => $tenant->facebook_access_token,
                'fields' => 'id,message,created_time,full_picture,reactions.summary(true),shares,comments.summary(true)',
                'limit' => 10,
            ]);

            if (!$response->successful()) {
                Log::error('Facebook API Error', [
                    'tenant_id' => $tenant->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['synced' => 0, 'errors' => ['API error: ' . $response->status()]];
            }

            $data = $response->json();
            $posts = $data['data'] ?? [];
            $synced = 0;

            foreach ($posts as $post) {
                if (!isset($post['message'])) {
                    continue; // Skip posts without text
                }

                $this->createOrUpdatePost($tenant, [
                    'external_id' => $post['id'],
                    'plataforma' => 'facebook',
                    'usuario' => 'Facebook Page',
                    'contenido' => $post['message'],
                    'fecha' => Carbon::parse($post['created_time']),
                    'likes' => $post['reactions']['summary']['total_count'] ?? 0,
                    'compartidos' => $post['shares']['count'] ?? 0,
                    'comentarios' => $post['comments']['summary']['total_count'] ?? 0,
                    'external_url' => "https://facebook.com/{$post['id']}",
                    'image_url' => $post['full_picture'] ?? null,
                ]);

                $synced++;
            }

            return ['synced' => $synced, 'errors' => []];
        } catch (\Exception $e) {
            Log::error('Facebook Sync Error', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return ['synced' => 0, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * Sync Instagram posts
     */
    public function syncInstagram(Tenant $tenant): array
    {
        try {
            $response = Http::get("https://graph.facebook.com/v18.0/{$tenant->instagram_user_id}/media", [
                'access_token' => $tenant->instagram_access_token,
                'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count',
                'limit' => 10,
            ]);

            if (!$response->successful()) {
                Log::error('Instagram API Error', [
                    'tenant_id' => $tenant->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['synced' => 0, 'errors' => ['API error: ' . $response->status()]];
            }

            $data = $response->json();
            $posts = $data['data'] ?? [];
            $synced = 0;

            foreach ($posts as $post) {
                $imageUrl = $post['media_url'] ?? null;
                if ($post['media_type'] === 'VIDEO') {
                    $imageUrl = $post['thumbnail_url'] ?? null;
                }

                $this->createOrUpdatePost($tenant, [
                    'external_id' => $post['id'],
                    'plataforma' => 'instagram',
                    'usuario' => $tenant->instagram_username ?? 'Instagram',
                    'contenido' => $post['caption'] ?? '',
                    'fecha' => Carbon::parse($post['timestamp']),
                    'likes' => $post['like_count'] ?? 0,
                    'compartidos' => 0, // Instagram API doesn't provide shares
                    'comentarios' => $post['comments_count'] ?? 0,
                    'external_url' => $post['permalink'],
                    'image_url' => $imageUrl,
                ]);

                $synced++;
            }

            return ['synced' => $synced, 'errors' => []];
        } catch (\Exception $e) {
            Log::error('Instagram Sync Error', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return ['synced' => 0, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * Sync YouTube videos
     */
    public function syncYouTube(Tenant $tenant): array
    {
        try {
            $response = Http::get("https://www.googleapis.com/youtube/v3/search", [
                'key' => $tenant->youtube_api_key,
                'channelId' => $tenant->youtube_channel_id,
                'part' => 'snippet',
                'order' => 'date',
                'maxResults' => 10,
                'type' => 'video',
            ]);

            if (!$response->successful()) {
                Log::error('YouTube API Error', [
                    'tenant_id' => $tenant->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['synced' => 0, 'errors' => ['API error: ' . $response->status()]];
            }

            $data = $response->json();
            $videos = $data['items'] ?? [];

            // Get video statistics
            $videoIds = array_column(array_column($videos, 'id'), 'videoId');
            $statsResponse = Http::get("https://www.googleapis.com/youtube/v3/videos", [
                'key' => $tenant->youtube_api_key,
                'id' => implode(',', $videoIds),
                'part' => 'statistics',
            ]);

            $statsMap = [];
            if ($statsResponse->successful()) {
                $statsData = $statsResponse->json();
                foreach ($statsData['items'] ?? [] as $video) {
                    $statsMap[$video['id']] = $video['statistics'];
                }
            }

            $synced = 0;
            foreach ($videos as $video) {
                $videoId = $video['id']['videoId'];
                $snippet = $video['snippet'];
                $stats = $statsMap[$videoId] ?? [];

                $this->createOrUpdatePost($tenant, [
                    'external_id' => $videoId,
                    'plataforma' => 'youtube',
                    'usuario' => $snippet['channelTitle'],
                    'contenido' => $snippet['title'] . "\n\n" . $snippet['description'],
                    'fecha' => Carbon::parse($snippet['publishedAt']),
                    'likes' => $stats['likeCount'] ?? 0,
                    'compartidos' => 0, // YouTube API doesn't provide shares directly
                    'comentarios' => $stats['commentCount'] ?? 0,
                    'external_url' => "https://www.youtube.com/watch?v={$videoId}",
                    'image_url' => $snippet['thumbnails']['high']['url'] ?? null,
                ]);

                $synced++;
            }

            return ['synced' => $synced, 'errors' => []];
        } catch (\Exception $e) {
            Log::error('YouTube Sync Error', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return ['synced' => 0, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * Create or update a social feed post
     */
    protected function createOrUpdatePost(Tenant $tenant, array $data): void
    {
        // Check if post already exists
        $existingPost = LandingSocialFeed::where('tenant_id', $tenant->id)
            ->where('external_id', $data['external_id'])
            ->first();

        $postData = [
            'plataforma' => $data['plataforma'],
            'usuario' => $data['usuario'],
            'contenido' => $data['contenido'],
            'fecha' => $data['fecha'],
            'likes' => $data['likes'],
            'compartidos' => $data['compartidos'],
            'comentarios' => $data['comentarios'],
            'external_url' => $data['external_url'],
            'last_synced_at' => now(),
            'is_synced' => true,
            'is_active' => true,
        ];

        // Download and store image if provided
        if (isset($data['image_url']) && $data['image_url']) {
            try {
                $imageContent = Http::get($data['image_url'])->body();
                $extension = pathinfo(parse_url($data['image_url'], PHP_URL_PATH), PATHINFO_EXTENSION);
                if (empty($extension)) {
                    $extension = 'jpg';
                }
                
                $filename = time() . '_' . uniqid() . '.' . $extension;
                $key = 'landing/social/' . $filename;
                
                $disk = config('filesystems.default');
                if ($disk === 's3') {
                    $bucket = $this->wasabi->getBucket($tenant);
                    $client = $this->wasabi->getS3Client();
                    
                    $client->putObject([
                        'Bucket' => $bucket,
                        'Key' => $key,
                        'Body' => $imageContent,
                        'ContentType' => 'image/' . $extension,
                    ]);
                } else {
                    \Illuminate\Support\Facades\Storage::disk('public')->put($key, $imageContent);
                }
                
                $postData['imagen'] = $key;
            } catch (\Exception $e) {
                Log::warning('Failed to download social media image', [
                    'tenant_id' => $tenant->id,
                    'url' => $data['image_url'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($existingPost) {
            // Update existing post (refresh metrics and content)
            $existingPost->update($postData);
        } else {
            // Create new post
            LandingSocialFeed::create(array_merge($postData, [
                'tenant_id' => $tenant->id,
                'external_id' => $data['external_id'],
            ]));
        }
    }
}
