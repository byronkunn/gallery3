<?php

use App\Models\Pool;
use App\Models\PoolChapter;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Tag;
use App\Support\SpamControls;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public string $uploadMode = 'single'; // 'single' or 'batch'

    public string $mediaType = 'image'; // 'image' or 'video'

    public string $title = '';

    public string $description = '';

    public string $sourceUrl = '';

    public bool $isNsfw = false;

    public string $taggingMode = 'whole'; // 'whole' or 'per_image'

    public string $tagInput = '';

    public ?int $targetPoolId = null;

    // Batch Upload Fields
    public string $batchUrlsInput = '';

    public array $batchItems = [];

    public string $bulkTagInput = '';

    public array $imageUploads = [];

    public mixed $videoUpload = null;

    public array $images = [''];

    public string $videoUrl = '';

    public string $videoThumbnailUrl = '/sfw/image/sample_fd6cf1c5b5dda7658bf8b050ebb8240f.jpg';

    public function loadSfwVideoPreset(string $path)
    {
        $this->mediaType = 'video';
        $this->videoUrl = $path;
        $this->videoThumbnailUrl = '/sfw/image/sample_fd6cf1c5b5dda7658bf8b050ebb8240f.jpg';
        $this->dispatch('notify', 'Loaded video asset from /sfw/video folder!');
    }

    public function setUploadMode(string $mode)
    {
        $this->uploadMode = $mode;
        if ($mode === 'batch' && empty($this->batchItems)) {
            $this->loadPresetBatch();
        }
    }

    public function loadPresetBatch()
    {
        $presetUrls = [
            'https://images.unsplash.com/photo-1579783900882-c0d3dad7b119?auto=format&fit=crop&w=1400&q=85',
            'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=1400&q=85',
            'https://images.unsplash.com/photo-1578632767115-351597cf2477?auto=format&fit=crop&w=1400&q=85',
            'https://images.unsplash.com/photo-1563089145-599997674d42?auto=format&fit=crop&w=1400&q=85',
        ];

        $this->batchItems = [];
        foreach ($presetUrls as $idx => $url) {
            $this->batchItems[] = [
                'url' => $url,
                'title' => 'Batch Artwork #'.($idx + 1),
                'is_nsfw' => false,
                'tags_input' => 'digital_art, illustration, character_design, high_resolution',
            ];
        }
        $this->batchUrlsInput = implode("\n", $presetUrls);
    }

    public function parseBatchUrls()
    {
        $lines = array_map('trim', explode("\n", $this->batchUrlsInput));
        $lines = array_filter($lines);
        $this->batchItems = [];
        foreach ($lines as $idx => $url) {
            $this->batchItems[] = [
                'url' => $url,
                'title' => 'Artwork #'.($idx + 1),
                'is_nsfw' => false,
                'tags_input' => $this->detectAutoTags($url, $idx),
            ];
        }
        $this->dispatch('notify', 'Parsed '.count($this->batchItems).' batch items with URL-based tag suggestions.');
    }

    public function detectAutoTags(string $url, int $index): string
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        $suggestions = [];
        foreach ([
            'landscape|scenery|mountain|forest|city' => ['scenery', 'landscape'],
            'portrait|character|girl|boy|person' => ['character_design', 'portrait'],
            'concept|environment|world' => ['concept_art', 'environment_design'],
            'anime|manga' => ['anime', 'manga_style'],
        ] as $pattern => $tags) {
            if (preg_match('/'.$pattern.'/i', $path)) {
                $suggestions = array_merge($suggestions, $tags);
            }
        }

        return implode(', ', array_unique($suggestions ?: ['digital_art', 'illustration']));
    }

    public function autoDetectTagsForBatch()
    {
        foreach ($this->batchItems as $idx => &$item) {
            $item['tags_input'] = $this->detectAutoTags($item['url'], $idx);
        }
        $this->dispatch('notify', 'Tag suggestions refreshed from the image URLs. Review them before publishing.');
    }

    public function applyBulkRating(bool $nsfw)
    {
        foreach ($this->batchItems as &$item) {
            $item['is_nsfw'] = $nsfw;
        }
        $this->dispatch('notify', 'Applied rating to all batch items!');
    }

    public function applyBulkTag()
    {
        if (empty(trim($this->bulkTagInput))) {
            return;
        }
        $tagToApply = strtolower(trim(str_replace('#', '', $this->bulkTagInput)));
        $tagToApply = str_replace(' ', '_', $tagToApply);

        foreach ($this->batchItems as &$item) {
            $existing = array_map('trim', explode(',', $item['tags_input']));
            if (! in_array($tagToApply, $existing)) {
                $existing[] = $tagToApply;
                $item['tags_input'] = implode(', ', array_filter($existing));
            }
        }
        $this->bulkTagInput = '';
        $this->dispatch('notify', "Added #{$tagToApply} to all batch items!");
    }

    public function removeBatchItem(int $index)
    {
        unset($this->batchItems[$index]);
        $this->batchItems = array_values($this->batchItems);
    }

    public function submitBatchPosts()
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in to upload batch posts');

            return;
        }

        if (empty($this->batchItems)) {
            $this->dispatch('notify', 'No batch items to upload');

            return;
        }

        $user = Auth::user();
        $createdCount = 0;

        $validBatchItems = collect($this->batchItems)->filter(fn (array $item): bool => filled(trim($item['url'] ?? '')));
        if ($validBatchItems->isEmpty()) {
            $this->dispatch('notify', 'Add at least one valid URL before uploading.');

            return;
        }
        SpamControls::enforce('posts', 12, 3600, json_encode($validBatchItems->all()), $validBatchItems->count());

        foreach ($validBatchItems as $item) {
            $url = trim($item['url']);
            if (empty($url)) {
                continue;
            }

            $isVid = (bool) preg_match('/\.(mp4|webm|mov)(\?.*)?$/i', $url) || str_contains($url, '/video/');
            $post = Post::create([
                'user_id' => $user->id,
                'title' => $item['title'] ?: ($isVid ? 'Video Post' : 'Batch Artwork'),
                'description' => 'Uploaded via Batch Multi-Post Uploader',
                'media_type' => $isVid ? 'video' : 'image',
                'media_count' => 1,
                'views_count' => 0,
                'likes_count' => 0,
                'is_nsfw' => $item['is_nsfw'] ?? false,
            ]);

            PostMedia::create([
                'post_id' => $post->id,
                'order' => 1,
                'url' => $url,
                'thumbnail_url' => $url,
                'width' => 1400,
                'height' => 1000,
                'aspect_ratio' => 1.4,
            ]);

            $parsedTags = array_map('trim', explode(',', $item['tags_input']));
            $parsedTags[] = $user->username;

            foreach (array_unique($parsedTags) as $tName) {
                $cleanName = strtolower(trim(str_replace('#', '', $tName)));
                $cleanName = str_replace(' ', '_', $cleanName);
                if (empty($cleanName)) {
                    continue;
                }

                $tag = Tag::firstOrCreate(
                    ['name' => $cleanName],
                    ['slug' => Str::slug($cleanName), 'type' => 'general']
                );
                $post->tags()->syncWithoutDetaching([$tag->id]);
                $tag->increment('posts_count');
            }
            $createdCount++;
        }

        $this->dispatch('notify', "Successfully published {$createdCount} batch posts!");

        return redirect()->route('gallery');
    }

    public function addImageSlot()
    {
        if (count($this->images) >= 40) {
            $this->dispatch('notify', 'Maximum 40 images allowed per regular post');

            return;
        }
        $this->images[] = '';
    }

    public function removeImage(int $index)
    {
        if (count($this->images) <= 1) {
            return;
        }
        unset($this->images[$index]);
        $this->images = array_values($this->images);
    }

    public function removeImageUpload(int $index): void
    {
        unset($this->imageUploads[$index]);
        $this->imageUploads = array_values($this->imageUploads);
    }

    private function isAllowedMediaUrl(mixed $value): bool
    {
        if (blank($value)) {
            return true;
        }
        if (! is_string($value)) {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true);
        }

        return preg_match('#^/(?:sfw/(?:image|video)|storage/(?:posts|videos))/[A-Za-z0-9._/-]+$#', $value) === 1;
    }

    public function addTag(string $tagName)
    {
        $tags = array_map('trim', explode(',', $this->tagInput));
        $tags = array_filter($tags);
        if (! in_array($tagName, $tags)) {
            $tags[] = $tagName;
            $this->tagInput = implode(', ', $tags);
        }
    }

    public function submitPost()
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in to upload');

            return;
        }

        $this->validate([
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'sourceUrl' => 'nullable|url|max:2048',
            'images' => ['array', 'max:40'],
            'images.*' => ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $this->isAllowedMediaUrl($value)) {
                    $fail('Use an HTTP(S) image URL or a supported local media path.');
                }
            }],
            'imageUploads' => ['array', 'max:40'],
            'imageUploads.*' => ['image', 'mimes:jpg,jpeg,png,gif,webp', 'max:12288'],
            'videoUpload' => ['nullable', 'file', 'mimes:mp4,webm,mov', 'max:12288'],
            'videoUrl' => ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $this->isAllowedMediaUrl($value)) {
                    $fail('Use an HTTP(S) video URL or a supported local media path.');
                }
            }],
        ]);
        if ($this->mediaType === 'image' && collect($this->images)->filter(fn ($url) => filled(trim((string) $url)))->isEmpty() && $this->imageUploads === []) {
            $this->addError('imageUploads', 'Choose at least one image file or provide an image URL.');
            return;
        }
        if ($this->mediaType === 'video' && ! $this->videoUpload && blank($this->videoUrl)) {
            $this->addError('videoUpload', 'Choose a video file or provide a video URL.');
            return;
        }
        if ($this->mediaType === 'image' && count($this->imageUploads) + count(array_filter($this->images, fn ($url) => filled(trim((string) $url)))) > 40) {
            $this->addError('imageUploads', 'A post can contain up to 40 images.');
            return;
        }

        SpamControls::enforce('posts', 12, 3600, $this->title.' '.$this->description.' '.implode(' ', $this->images));

        $user = Auth::user();

        // 1. Create Post
        $post = Post::create([
            'user_id' => $user->id,
            'title' => $this->title,
            'description' => $this->description,
            'media_type' => $this->mediaType,
            'media_count' => $this->mediaType === 'image' ? count($this->imageUploads) + count(array_filter($this->images, fn ($url) => filled(trim((string) $url)))) : 1,
            'views_count' => 0,
            'likes_count' => 0,
            'is_nsfw' => $this->isNsfw,
            'source_url' => $this->sourceUrl,
        ]);

        // 2. Attach Media
        if ($this->mediaType === 'image') {
            $mediaItems = [];
            foreach ($this->imageUploads as $upload) {
                $path = $upload->storePublicly('posts', 'public');
                [$width, $height] = getimagesize($upload->getRealPath()) ?: [1400, 1000];
                $mediaItems[] = [
                    'url' => '/storage/'.$path,
                    'width' => $width,
                    'height' => $height,
                    'aspect_ratio' => $height > 0 ? $width / $height : 1.4,
                ];
            }
            foreach ($this->images as $url) {
                if (filled(trim((string) $url))) {
                    $mediaItems[] = ['url' => trim($url), 'width' => 1400, 'height' => 1000, 'aspect_ratio' => 1.4];
                }
            }

            foreach ($mediaItems as $idx => $item) {
                PostMedia::create([
                    'post_id' => $post->id,
                    'order' => $idx + 1,
                    'url' => $item['url'],
                    'thumbnail_url' => $item['url'],
                    'width' => $item['width'],
                    'height' => $item['height'],
                    'aspect_ratio' => $item['aspect_ratio'],
                ]);
            }
        } else {
            $videoPath = $this->videoUpload?->storePublicly('videos', 'public');
            $videoUrl = $videoPath ? '/storage/'.$videoPath : $this->videoUrl;
            PostMedia::create([
                'post_id' => $post->id,
                'order' => 1,
                'url' => $videoUrl,
                'thumbnail_url' => $this->videoThumbnailUrl ?: $videoUrl,
                'width' => 1280,
                'height' => 720,
                'aspect_ratio' => 1.77,
                'duration' => 20,
            ]);
        }

        // 3. Attach Tags
        $parsedTags = array_map('trim', explode(',', $this->tagInput));
        $parsedTags = array_filter($parsedTags);

        // Always add author tag automatically
        $parsedTags[] = $user->username;

        foreach (array_unique($parsedTags) as $tName) {
            $cleanName = strtolower(trim(str_replace('#', '', $tName)));
            $cleanName = str_replace(' ', '_', $cleanName);
            if (empty($cleanName)) {
                continue;
            }

            $tag = Tag::firstOrCreate(
                ['name' => $cleanName],
                ['slug' => Str::slug($cleanName), 'type' => 'general']
            );
            $post->tags()->syncWithoutDetaching([$tag->id]);
            $tag->increment('posts_count');
        }

        // 4. Attach to Pool if specified
        if ($this->targetPoolId) {
            $pool = Pool::find($this->targetPoolId);
            if ($pool && (! $pool->is_locked || $pool->user_id === $user->id)) {
                $chNum = $pool->chapters()->count() + 1;
                PoolChapter::create([
                    'pool_id' => $pool->id,
                    'post_id' => $post->id,
                    'chapter_number' => $chNum,
                    'title' => $this->title,
                    'order' => $chNum,
                ]);
                $pool->increment('chapters_count');
            }
        }

        $this->dispatch('notify', 'Artwork published successfully!');

        return redirect()->route('post.detail', $post->id);
    }

    public function render()
    {
        $user = Auth::user();
        $availablePools = $user ? Pool::where('is_locked', false)->orWhere('user_id', $user->id)->get() : collect();
        $suggestedTags = Tag::orderBy('posts_count', 'desc')->take(16)->get();

        return view('components.⚡upload-view', [
            'currentUser' => $user,
            'availablePools' => $availablePools,
            'suggestedTags' => $suggestedTags,
        ]);
    }
};
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-black tracking-tight text-[var(--text-main)]">Upload Artwork / Media</h1>
        <p class="text-xs text-[var(--text-dim)]">Share up to 40 images or an MP4, WebM, or MOV video file up to 12 MB. You can also use direct media URLs.</p>
    </div>

    @if(!Auth::check())
        <div class="p-8 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-center space-y-3">
            <h3 class="font-bold text-lg">Please Log In to Publish</h3>
            <p class="text-xs text-[var(--text-dim)]">You must be authenticated to upload artwork or manga chapters.</p>
            <a href="{{ route('login') }}" class="inline-block px-6 py-2.5 rounded-2xl accent-bg text-white font-bold text-sm shadow">
                Log In
            </a>
        </div>
    @else
        <!-- Upload Mode Switcher (Single Post vs Batch Multi-Post Uploader) -->
        <div class="flex items-center gap-2 p-1.5 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] mb-6 shadow-sm">
            <button type="button" 
                    wire:click="setUploadMode('single')" 
                    class="flex-1 py-2.5 px-4 rounded-xl text-xs font-bold transition flex items-center justify-center gap-2 {{ $uploadMode === 'single' ? 'accent-bg text-white shadow' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <span>Single Post / Gallery Upload</span>
            </button>
            <button type="button" 
                    wire:click="setUploadMode('batch')" 
                    class="flex-1 py-2.5 px-4 rounded-xl text-xs font-bold transition flex items-center justify-center gap-2 {{ $uploadMode === 'batch' ? 'accent-bg text-white shadow' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
                <span>Batch Multi-Post Uploader (Tag Suggestions & Bulk Edit)</span>
            </button>
        </div>

        @if($uploadMode === 'single')
            <form wire:submit.prevent="submitPost" class="space-y-6">
            <!-- Media Type Selector (Spec: either images or video — not mixed) -->
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4">
                <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Select Media Format</label>
                <div class="grid grid-cols-2 gap-4">
                    <button type="button" 
                            wire:click="$set('mediaType', 'image')" 
                            class="p-4 rounded-2xl border text-center transition {{ $mediaType === 'image' ? 'accent-border bg-[var(--accent-glow)] font-bold text-[var(--text-main)]' : 'border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)] text-[var(--text-muted)]' }}">
                        <svg class="w-7 h-7 mx-auto mb-2 {{ $mediaType === 'image' ? 'accent-text' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        <div class="text-sm font-bold">Image Set</div>
                        <div class="text-[11px] opacity-70 mt-0.5">Up to 40 high-res images / pages</div>
                    </button>

                    <button type="button" 
                            wire:click="$set('mediaType', 'video')" 
                            class="p-4 rounded-2xl border text-center transition {{ $mediaType === 'video' ? 'accent-border bg-[var(--accent-glow)] font-bold text-[var(--text-main)]' : 'border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)] text-[var(--text-muted)]' }}">
                        <svg class="w-7 h-7 mx-auto mb-2 {{ $mediaType === 'video' ? 'accent-text' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                        <div class="text-sm font-bold">Video Post</div>
                        <div class="text-[11px] opacity-70 mt-0.5">5 min / 500 MB max, autoplay on hover</div>
                    </button>
                </div>
            </div>

            <!-- Media Items Input -->
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4">
                @if($mediaType === 'image')
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Images ({{ count($imageUploads) + count(array_filter($images, fn ($url) => filled(trim((string) $url)))) }} / 40)</label>
                        <button type="button" wire:click="addImageSlot" class="px-3 py-1.5 rounded-xl accent-bg text-white font-bold text-xs shadow hover:opacity-90">
                            + Add Image URL
                        </button>
                    </div>

                    <label class="block rounded-2xl border border-dashed border-[var(--border-medium)] p-4 text-sm font-semibold">
                        <span>Select image files (JPG, PNG, GIF, WebP; up to 12 MB each)</span>
                        <input type="file" wire:model="imageUploads" accept="image/jpeg,image/png,image/gif,image/webp" multiple class="mt-2 block w-full text-xs">
                    </label>
                    @error('imageUploads') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                    @error('imageUploads.*') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        @foreach($imageUploads as $uploadIndex => $imageUpload)
                            <div wire:key="image-upload-{{ $uploadIndex }}" class="relative overflow-hidden rounded-xl border border-[var(--border-subtle)]">
                                @if(Str::startsWith($imageUpload->getMimeType(), 'image/'))
                                    <img src="{{ $imageUpload->temporaryUrl() }}" class="aspect-square w-full object-cover">
                                @else
                                    <div class="flex aspect-square items-center justify-center p-3 text-center text-xs text-rose-400">Unsupported file type</div>
                                @endif
                                <button type="button" wire:click="removeImageUpload({{ $uploadIndex }})" class="absolute right-2 top-2 rounded-lg bg-black/70 px-2 py-1 text-xs font-bold text-white">Remove</button>
                            </div>
                        @endforeach
                    </div>

                    <div class="space-y-3">
                        @foreach($images as $idx => $imgUrl)
                            <div class="flex items-center gap-3 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)]">
                                <span class="font-mono text-xs text-[var(--text-dim)] w-6 text-center font-bold">{{ $idx + 1 }}</span>
                                <div class="w-12 h-12 rounded-xl bg-neutral-900 overflow-hidden shrink-0">
                                    @if(!empty($imgUrl))
                                        <img src="{{ $imgUrl }}" class="w-full h-full object-cover">
                                    @else
                                        <div class="w-full h-full flex items-center justify-center text-[var(--text-dim)] text-xs">Empty</div>
                                    @endif
                                </div>
                                <input type="url" 
                                       wire:model.live.debounce.300ms="images.{{ $idx }}" 
                                       placeholder="Paste Image URL (https://...)" 
                                       class="flex-1 bg-transparent border-0 text-sm outline-none text-[var(--text-main)] placeholder-[var(--text-dim)]">
                                
                                @if(count($images) > 1)
                                    <button type="button" wire:click="removeImage({{ $idx }})" class="p-2 text-rose-400 hover:text-rose-200">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="space-y-4">
                        <div>
                            <label class="block rounded-2xl border border-dashed border-[var(--border-medium)] p-4 text-sm font-semibold">
                                <span>Choose video file (MP4, WebM, MOV; up to 12 MB)</span>
                                <input type="file" wire:model="videoUpload" accept="video/mp4,video/webm,video/quicktime" class="mt-2 block w-full text-xs">
                            </label>
                            @error('videoUpload') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                            @if($videoUpload)
                                <p class="mt-2 text-xs text-emerald-400">Selected: {{ $videoUpload->getClientOriginalName() }}</p>
                            @endif
                        </div>
                        <div>
                            <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Or use a video URL (.mp4 / webm)</label>
                            <input type="url" wire:model="videoUrl" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Video Poster / Thumbnail URL</label>
                            <input type="url" wire:model="videoThumbnailUrl" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                        </div>
                    </div>
                @endif
            </div>

            <!-- Post Details -->
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4">
                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Post Title</label>
                    <input type="text" wire:model="title" placeholder="Give your artwork a title..." class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)] font-bold">
                    @error('title') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Description / Artist Notes</label>
                    <textarea wire:model="description" rows="3" placeholder="Notes, lore, tools used, canvas size, brush pack..." class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]"></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Source Link (Optional)</label>
                        <input type="url" wire:model="sourceUrl" placeholder="https://artstation.com/..." class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                    </div>

                    <div>
                        <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Publish into Manga Pool (Optional)</label>
                        <select wire:model="targetPoolId" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                            <option value="">-- Standalone Gallery Post --</option>
                            @foreach($availablePools as $pl)
                                <option value="{{ $pl->id }}">{{ $pl->title }} ({{ $pl->chapters_count }} Chapters)</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="pt-2">
                    <label class="flex items-center gap-2 text-sm text-[var(--text-main)] cursor-pointer">
                        <input type="checkbox" wire:model="isNsfw" class="w-4 h-4 rounded accent-bg">
                        <span class="font-bold">Mark as NSFW / Mature Content</span>
                    </label>
                </div>
            </div>

            <!-- Danbooru Tags Input & Suggestions -->
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4">
                <div class="flex items-center justify-between">
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Danbooru Tags (comma-separated)</label>
                    
                    <!-- Tagging Mode: Spec: uploader chooses one tag set for whole post or tags per image -->
                    <div class="flex items-center gap-2 text-xs">
                        <button type="button" wire:click="$set('taggingMode', 'whole')" class="px-2.5 py-1 rounded-xl {{ $taggingMode === 'whole' ? 'accent-bg text-white font-bold' : 'text-[var(--text-dim)]' }}">Single Tag Set</button>
                        <button type="button" wire:click="$set('taggingMode', 'per_image')" class="px-2.5 py-1 rounded-xl {{ $taggingMode === 'per_image' ? 'accent-bg text-white font-bold' : 'text-[var(--text-dim)]' }}">Per-Image Tags</button>
                    </div>
                </div>

                <input type="text" 
                       wire:model="tagInput"
                       placeholder="e.g. scenery, night_sky, frieren, highres, concept_art..." 
                       class="w-full p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)] font-mono">

                <!-- Suggested Danbooru Tags -->
                <div>
                    <div class="text-[11px] font-bold text-[var(--text-dim)] uppercase tracking-wider mb-2">Click to Add Trending Tags</div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($suggestedTags as $stag)
                            <button type="button" wire:click="addTag('{{ $stag->name }}')" class="px-2.5 py-1 rounded-xl text-xs font-medium border {{ $stag->getTypeBadgeClasses() }}">
                                + #{{ $stag->name }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-4">
                <a href="{{ route('gallery') }}" class="px-6 py-3 rounded-2xl text-sm font-bold hover:bg-[var(--bg-surface-elevated)] transition">Cancel</a>
                <button type="submit" class="px-8 py-3 rounded-2xl accent-bg text-white font-bold text-sm shadow-xl hover:opacity-90 transition">
                    Publish Artwork
                </button>
            </div>
        </form>
        @else
            <!-- Batch Multi-Post Uploader Mode -->
            <div class="space-y-6">
                <!-- Batch URLs Input Box -->
                <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="font-bold text-base text-[var(--text-main)]">1. Multi-File Drag & Drop / URL Batch Input</h3>
                            <p class="text-xs text-[var(--text-dim)]">Paste one image URL per line to create multiple separate gallery posts at once.</p>
                        </div>
                        <button type="button" wire:click="loadPresetBatch" class="px-3 py-1.5 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-xs font-bold hover:bg-[var(--bg-surface)] transition">
                            Load Demo Batch Presets
                        </button>
                    </div>

                    <textarea wire:model="batchUrlsInput" rows="4" placeholder="https://images.unsplash.com/photo-1...&#10;https://images.unsplash.com/photo-2...&#10;https://images.unsplash.com/photo-3..." class="w-full p-3.5 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none font-mono focus:border-[var(--accent-primary)]"></textarea>

                    <div class="flex items-center justify-between">
                        <button type="button" wire:click="parseBatchUrls" class="px-5 py-2.5 rounded-2xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                            </svg>
                            <span>Parse Image URLs into Batch Items</span>
                        </button>
                        <span class="text-xs text-[var(--text-dim)] font-mono">{{ count($batchItems) }} items queued</span>
                    </div>
                </div>

                @if(count($batchItems) > 0)
                    <!-- Bulk Editor Bar -->
                    <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-medium)] space-y-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <h3 class="font-bold text-base text-[var(--text-main)] flex items-center gap-2">
                                <span>2. Bulk Editor Tools</span>
                                <span class="px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-400 text-xs border border-indigo-500/30 font-bold">URL-Based Suggestions</span>
                            </h3>

                            <button type="button" wire:click="autoDetectTagsForBatch" class="px-3.5 py-1.5 rounded-xl bg-purple-500/15 text-purple-400 border border-purple-500/30 text-xs font-bold hover:bg-purple-500/25 transition flex items-center gap-1.5">
                                ⚡ Refresh Tag Suggestions
                            </button>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-1">
                            <!-- Bulk Rating Buttons -->
                            <div class="flex items-center gap-3">
                                <span class="text-xs font-bold text-[var(--text-dim)] uppercase">Bulk Rating:</span>
                                <button type="button" wire:click="applyBulkRating(false)" class="px-3 py-1.5 rounded-xl bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 text-xs font-bold hover:bg-emerald-500/25 transition">
                                    Set All Safe
                                </button>
                                <button type="button" wire:click="applyBulkRating(true)" class="px-3 py-1.5 rounded-xl bg-rose-500/15 text-rose-400 border border-rose-500/30 text-xs font-bold hover:bg-rose-500/25 transition">
                                    Set All NSFW
                                </button>
                            </div>

                            <!-- Bulk Add Tag -->
                            <div class="flex items-center gap-2">
                                <input type="text" wire:model="bulkTagInput" placeholder="Add tag to all (e.g. concept_art)..." class="flex-1 p-2 px-3 rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs font-mono outline-none">
                                <button type="button" wire:click="applyBulkTag" class="px-3 py-2 rounded-xl accent-bg text-white text-xs font-bold shadow hover:opacity-90">
                                    Apply to All
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Batch Items Preview Cards -->
                    <div class="space-y-4">
                        <h3 class="font-bold text-sm text-[var(--text-dim)] uppercase tracking-wider">3. Review & Customize Individual Posts</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach($batchItems as $bIdx => $bItem)
                                <div class="p-4 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] flex gap-4 relative group">
                                    <div class="w-24 h-32 rounded-2xl bg-neutral-900 overflow-hidden shrink-0 shadow border border-[var(--border-subtle)]">
                                        <img src="{{ $bItem['url'] }}" class="w-full h-full object-cover">
                                    </div>

                                    <div class="flex-1 min-w-0 space-y-2">
                                        <div class="flex items-center justify-between gap-2">
                                            <input type="text" wire:model="batchItems.{{ $bIdx }}.title" placeholder="Title..." class="w-full p-2 rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs font-bold outline-none">
                                            <button type="button" wire:click="removeBatchItem({{ $bIdx }})" class="p-1 text-rose-400 hover:text-rose-200 shrink-0" title="Remove Item">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                            </button>
                                        </div>

                                        <div>
                                            <label class="text-[10px] font-bold text-[var(--text-dim)] uppercase">Tags</label>
                                            <input type="text" wire:model="batchItems.{{ $bIdx }}.tags_input" class="w-full p-2 rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-[11px] font-mono outline-none">
                                        </div>

                                        <div class="flex items-center justify-between pt-1">
                                            <label class="flex items-center gap-1.5 text-xs text-[var(--text-dim)] cursor-pointer">
                                                <input type="checkbox" wire:model="batchItems.{{ $bIdx }}.is_nsfw" class="w-3.5 h-3.5 rounded accent-bg">
                                                <span class="font-bold text-[11px]">NSFW</span>
                                            </label>

                                            <span class="text-[10px] font-mono text-[var(--text-dim)]">Item #{{ $bIdx + 1 }}</span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Submit All Batch Posts Button -->
                    <div class="flex justify-end pt-4">
                        <button type="button" wire:click="submitBatchPosts" class="px-8 py-3.5 rounded-2xl accent-bg text-white font-extrabold text-sm shadow-2xl hover:opacity-90 transition flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Publish All {{ count($batchItems) }} Batch Posts Now</span>
                        </button>
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
