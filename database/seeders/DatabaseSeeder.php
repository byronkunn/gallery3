<?php

namespace Database\Seeders;

use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Comment;
use App\Models\Community;
use App\Models\CommunityChannel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Pool;
use App\Models\PoolChapter;
use App\Models\PoolHistory;
use App\Models\PoolProgress;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Users
        $me = User::create([
            'name' => 'Kira Yukishiro',
            'username' => 'kira_art',
            'email' => 'kira@booru.art',
            'password' => Hash::make('password'),
            'avatar_url' => '/sfw/avatar/0738955ca929985f39921b4876053170.png',
            'banner_url' => '/sfw/banner-image/sample_0a026d6456f6806f67a9d67f856e8862.jpg',
            'bio' => 'Lead Illustrator & Concept Artist. Exploring celestial aesthetics, neon nights, and melancholic fantasy realms. 🎨 Commissions: OPEN',
            'website' => 'https://kirayuki.art',
            'is_artist' => true,
            'is_admin' => true,
            'commission_status' => 'Open',
            'theme_mode' => 'dark',
            'theme_palette' => 'violet',
            'blur_nsfw' => true,
            'hide_nsfw' => false,
            'font_size' => 'md',
            'reduced_motion' => false,
        ]);

        $ren = User::create([
            'name' => 'Ren Amamiya',
            'username' => 'phantom_ren',
            'email' => 'ren@booru.art',
            'password' => Hash::make('password'),
            'avatar_url' => '/sfw/avatar/sample_07acb23be6e4bea15d091117693849b2.jpg',
            'banner_url' => '/sfw/banner-image/sample_1186719173244242bce471e4476a266e.jpg',
            'bio' => 'Manga author & character draft master. Working on "Chronicles of the Astral Blade".',
            'website' => 'https://astralblade.manga',
            'is_artist' => true,
            'commission_status' => 'Waitlist',
            'theme_mode' => 'dark',
            'theme_palette' => 'crimson',
        ]);

        $aoi = User::create([
            'name' => 'Aoi Sorano',
            'username' => 'aoi_clouds',
            'email' => 'aoi@booru.art',
            'password' => Hash::make('password'),
            'avatar_url' => '/sfw/avatar/sample_1cc3b7361bda35f6bf0f78d8cb47640f.jpg',
            'banner_url' => '/sfw/banner-image/sample_1ebf418432111a9530b3287dc96b9410.jpg',
            'bio' => 'Cyberpunk world-builder & environmental designer. Lover of synthetic sunsets and rainy alleyways.',
            'website' => 'https://aoisorano.studio',
            'is_artist' => true,
            'commission_status' => 'Closed',
            'theme_mode' => 'oled',
            'theme_palette' => 'cyan',
        ]);

        $maya = User::create([
            'name' => 'Maya Lin',
            'username' => 'mayadraws',
            'email' => 'maya@booru.art',
            'password' => Hash::make('password'),
            'avatar_url' => '/sfw/avatar/sample_412993eb4b402e04f3f15b03b23bf071.jpg',
            'banner_url' => '/sfw/banner-image/sample_25eb2815b78288a5b6b97431e6086882.jpg',
            'bio' => 'Anime splash illustrator. Fan of fantasy, magical girls, and expressive character poses.',
            'website' => 'https://twitter.com/mayadraws',
            'is_artist' => true,
            'commission_status' => 'Open',
            'theme_mode' => 'light',
            'theme_palette' => 'sakura',
        ]);

        // Follows
        $me->following()->attach([$ren->id, $aoi->id, $maya->id]);
        $ren->following()->attach([$me->id, $aoi->id]);
        $aoi->following()->attach([$me->id]);
        $maya->following()->attach([$me->id, $ren->id]);

        // 2. Tags with Booru Categories
        $tagsData = [
            // Artists
            ['name' => 'kira_art', 'type' => 'artist'],
            ['name' => 'phantom_ren', 'type' => 'artist'],
            ['name' => 'aoi_clouds', 'type' => 'artist'],
            ['name' => 'mayadraws', 'type' => 'artist'],

            // Characters
            ['name' => 'frieren', 'type' => 'character'],
            ['name' => 'fern', 'type' => 'character'],
            ['name' => 'makima', 'type' => 'character'],
            ['name' => 'asuka_langley', 'type' => 'character'],
            ['name' => 'cyber_samurai', 'type' => 'character'],
            ['name' => 'astral_valkyrie', 'type' => 'character'],

            // Series / Copyright
            ['name' => 'frieren_beyond_journeys_end', 'type' => 'series'],
            ['name' => 'chainsaw_man', 'type' => 'series'],
            ['name' => 'neon_genesis_evangelion', 'type' => 'series'],
            ['name' => 'original', 'type' => 'series'],
            ['name' => 'cyberpunk_2077', 'type' => 'series'],

            // General
            ['name' => 'scenery', 'type' => 'general'],
            ['name' => 'night_sky', 'type' => 'general'],
            ['name' => 'glowing_eyes', 'type' => 'general'],
            ['name' => 'rain', 'type' => 'general'],
            ['name' => 'cyberpunk_city', 'type' => 'general'],
            ['name' => 'cherry_blossoms', 'type' => 'general'],
            ['name' => 'sword', 'type' => 'general'],
            ['name' => 'portrait', 'type' => 'general'],
            ['name' => 'wide_angle', 'type' => 'general'],
            ['name' => 'lighting', 'type' => 'general'],
            ['name' => 'clouds', 'type' => 'general'],
            ['name' => 'floating_petals', 'type' => 'general'],

            // Meta
            ['name' => 'highres', 'type' => 'meta'],
            ['name' => 'multiple_views', 'type' => 'meta'],
            ['name' => 'concept_art', 'type' => 'meta'],
            ['name' => 'comic', 'type' => 'meta'],
        ];

        $tags = [];
        foreach ($tagsData as $t) {
            $tag = Tag::create([
                'name' => $t['name'],
                'slug' => Str::slug($t['name']),
                'type' => $t['type'],
                'posts_count' => 0,
            ]);
            $tags[$t['name']] = $tag;
        }

        // Followed tags for $me
        $me->followedTags()->attach([
            $tags['scenery']->id,
            $tags['cyberpunk_city']->id,
            $tags['concept_art']->id,
            $tags['kira_art']->id,
        ]);

        // 3. Posts with Media
        $postsData = [
            // Post 1: Multi-image post by Kira (3 images)
            [
                'user' => $me,
                'title' => 'Starlight Citadel — The High Spires',
                'description' => 'A set of 3 landscape studies visualizing the Citadel of Aeris at dusk, midnight, and dawn.',
                'media_type' => 'image',
                'views_count' => 1420,
                'likes_count' => 389,
                'is_nsfw' => false,
                'source_url' => 'https://artstation.com/artwork/starlight-citadel',
                'tags' => ['kira_art', 'original', 'scenery', 'night_sky', 'lighting', 'highres', 'multiple_views'],
                'media' => [
                    [
                        'url' => '/sfw/image/sample_fd6cf1c5b5dda7658bf8b050ebb8240f.jpg',
                        'width' => 1600,
                        'height' => 1000,
                        'aspect_ratio' => 1.6,
                    ],
                    [
                        'url' => '/sfw/image/sample_9a25245cb71b342de0c410501ff3be51.jpg',
                        'width' => 1600,
                        'height' => 1100,
                        'aspect_ratio' => 1.45,
                    ],
                    [
                        'url' => '/sfw/image/sample_86d50f2706661b4cb3e4d2558232d4f8.jpg',
                        'width' => 1600,
                        'height' => 1200,
                        'aspect_ratio' => 1.33,
                    ],
                ],
            ],
            // Post 2: Neo Tokyo Cyberpunk by Aoi (2 images)
            [
                'user' => $aoi,
                'title' => 'Neon Rainfall // District 09',
                'description' => 'Heavy downpour reflection in the lower sectors. Character study + environment overview.',
                'media_type' => 'image',
                'views_count' => 2890,
                'likes_count' => 612,
                'is_nsfw' => false,
                'source_url' => 'https://pixiv.net/artworks/9823145',
                'tags' => ['aoi_clouds', 'cyberpunk_2077', 'cyber_samurai', 'cyberpunk_city', 'rain', 'lighting', 'highres'],
                'media' => [
                    [
                        'url' => '/sfw/image/santiago-betancur-cell-v02.jpg',
                        'width' => 1600,
                        'height' => 1067,
                        'aspect_ratio' => 1.5,
                    ],
                    [
                        'url' => '/sfw/image/sample_64512bd8d97e03ba8cb219e8f59d2c43.jpg',
                        'width' => 1600,
                        'height' => 900,
                        'aspect_ratio' => 1.77,
                    ],
                ],
            ],
            // Post 3: Frieren & Fern illustration by Maya (Single image)
            [
                'user' => $maya,
                'title' => 'Beyond Journey\'s End — Sunset Spellcasting',
                'description' => 'Fern practicing offensive defensive magic while Frieren searches for rare blue flowers in the meadow.',
                'media_type' => 'image',
                'views_count' => 4510,
                'likes_count' => 1240,
                'is_nsfw' => false,
                'source_url' => 'https://twitter.com/mayadraws/status/1765489',
                'tags' => ['mayadraws', 'frieren', 'fern', 'frieren_beyond_journeys_end', 'scenery', 'clouds', 'portrait', 'highres'],
                'media' => [
                    [
                        'url' => '/sfw/image/john-staub-marvel-loki-western-color-jstaub.jpg',
                        'width' => 1600,
                        'height' => 1067,
                        'aspect_ratio' => 1.5,
                    ],
                ],
            ],
            // Post 4: Video post by Kira (Autoplay muted on desktop hover)
            [
                'user' => $me,
                'title' => 'Astral Particle Animation Breakdown',
                'description' => 'Real-time looping shader showcase for the Astral Blade awakening sequence.',
                'media_type' => 'video',
                'views_count' => 1930,
                'likes_count' => 418,
                'is_nsfw' => false,
                'source_url' => 'https://youtube.com/watch?v=astral-fx',
                'tags' => ['kira_art', 'original', 'concept_art', 'lighting'],
                'media' => [
                    [
                        'url' => '/sfw/video/1c8313c25672bc727f4985fcd7b488a3.mp4',
                        'thumbnail_url' => '/sfw/image/sample_3a1cef0a2f73e70cd3d2165a88ab9d78.jpg',
                        'width' => 1280,
                        'height' => 720,
                        'aspect_ratio' => 1.77,
                        'duration' => 15,
                    ],
                ],
            ],
            // Post 5: Makima portrait by Ren (Single image)
            [
                'user' => $ren,
                'title' => 'Control — Golden Glow',
                'description' => 'Intense eye expression study in acrylic and ink.',
                'media_type' => 'image',
                'views_count' => 3810,
                'likes_count' => 915,
                'is_nsfw' => false,
                'source_url' => 'https://artstation.com/artwork/makima-control',
                'tags' => ['phantom_ren', 'makima', 'chainsaw_man', 'glowing_eyes', 'portrait', 'highres'],
                'media' => [
                    [
                        'url' => '/sfw/image/sample_e2b524fcf4cd0c803d0d940ff342a84c.jpg',
                        'width' => 1200,
                        'height' => 1500,
                        'aspect_ratio' => 0.8,
                    ],
                ],
            ],
            // Post 6: Cherry Blossoms Samurai by Maya (4 images)
            [
                'user' => $maya,
                'title' => 'The Wandering Ronin & Floating Petals',
                'description' => 'Character sheet and battle frames under the blooming sakura garden.',
                'media_type' => 'image',
                'views_count' => 2100,
                'likes_count' => 540,
                'is_nsfw' => false,
                'source_url' => null,
                'tags' => ['mayadraws', 'original', 'sword', 'cherry_blossoms', 'floating_petals', 'multiple_views', 'highres'],
                'media' => [
                    [
                        'url' => '/sfw/image/sample_3a88abbc0412737c013a94573ad4b70b.jpg',
                        'width' => 1600,
                        'height' => 1067,
                        'aspect_ratio' => 1.5,
                    ],
                    [
                        'url' => '/sfw/image/sample_29416423e2bad115abcd148625416902.jpg',
                        'width' => 1600,
                        'height' => 1067,
                        'aspect_ratio' => 1.5,
                    ],
                    [
                        'url' => '/sfw/image/sample_d297c6b40f5880a0e3afada84f5f149e.jpg',
                        'width' => 1600,
                        'height' => 1067,
                        'aspect_ratio' => 1.5,
                    ],
                    [
                        'url' => '/sfw/image/sample_55236e8ec0f656eccb9e796bb39e77fb.jpg',
                        'width' => 1600,
                        'height' => 1200,
                        'aspect_ratio' => 1.33,
                    ],
                ],
            ],
            // Post 7: Chapter 1 of Manga Pool by Ren (Chapter post with 4 pages)
            [
                'user' => $ren,
                'title' => 'Chronicles of the Astral Blade — Chapter 1: The Broken Seal',
                'description' => 'Chapter 1 complete release. American LTR reading direction. 4 high-res story pages.',
                'media_type' => 'image',
                'views_count' => 5200,
                'likes_count' => 1430,
                'is_nsfw' => false,
                'source_url' => null,
                'tags' => ['phantom_ren', 'original', 'astral_valkyrie', 'sword', 'comic', 'highres'],
                'media' => [
                    [
                        'url' => '/sfw/image/pavel-yankovich-01.jpg',
                        'width' => 1400,
                        'height' => 1980,
                        'aspect_ratio' => 0.7,
                    ],
                    [
                        'url' => '/sfw/image/sample_4c9fc7b8bd126c0947d9a9846087527f.jpg',
                        'width' => 1400,
                        'height' => 1980,
                        'aspect_ratio' => 0.7,
                    ],
                    [
                        'url' => '/sfw/image/sample_37ae46bfbb6ab04233f8b225aeee56db.jpg',
                        'width' => 1400,
                        'height' => 1980,
                        'aspect_ratio' => 0.7,
                    ],
                    [
                        'url' => '/sfw/image/i-m-potatoes-yana-gaisina-photo-2026-09-11-12-38-56.jpg',
                        'width' => 1400,
                        'height' => 1980,
                        'aspect_ratio' => 0.7,
                    ],
                ],
            ],
            // Post 8: Chapter 2 of Manga Pool by Ren
            [
                'user' => $ren,
                'title' => 'Chronicles of the Astral Blade — Chapter 2: Whispers in the Ruins',
                'description' => 'Chapter 2. Entering the sunken hollows of the celestial forge.',
                'media_type' => 'image',
                'views_count' => 3120,
                'likes_count' => 880,
                'is_nsfw' => false,
                'source_url' => null,
                'tags' => ['phantom_ren', 'original', 'astral_valkyrie', 'comic', 'highres'],
                'media' => [
                    [
                        'url' => '/sfw/image/sample_dc069b59e1c652a8c4bbe98970ea865e.jpg',
                        'width' => 1400,
                        'height' => 1980,
                        'aspect_ratio' => 0.7,
                    ],
                    [
                        'url' => '/sfw/image/pavel-yankovich-08.jpg',
                        'width' => 1400,
                        'height' => 1980,
                        'aspect_ratio' => 0.7,
                    ],
                    [
                        'url' => '/sfw/image/c78dc13197ac708d3b6726cff49785eb.jpg',
                        'width' => 1400,
                        'height' => 1980,
                        'aspect_ratio' => 0.7,
                    ],
                ],
            ],
            // Post 9: NSFW Post demo (to demonstrate blur/filter toggle!)
            [
                'user' => $aoi,
                'title' => 'Cyberpunk Underworld — Midnight VIP Lounge',
                'description' => 'Sensual atmosphere study. Tagged as NSFW for mature lighting and suggestive attire.',
                'media_type' => 'image',
                'views_count' => 3340,
                'likes_count' => 775,
                'is_nsfw' => true,
                'source_url' => null,
                'tags' => ['aoi_clouds', 'cyberpunk_city', 'portrait', 'lighting'],
                'media' => [
                    [
                        'url' => '/sfw/image/vadim-marchenkov-legendary-final-preview.jpg',
                        'width' => 1600,
                        'height' => 1067,
                        'aspect_ratio' => 1.5,
                    ],
                ],
            ],
            // Post 10: Additional Post by Kira
            [
                'user' => $me,
                'title' => 'Celestial Observatory & Horizon Lines',
                'description' => 'High atmosphere rendering of planetary alignment.',
                'media_type' => 'image',
                'views_count' => 1890,
                'likes_count' => 512,
                'is_nsfw' => false,
                'source_url' => null,
                'tags' => ['kira_art', 'scenery', 'night_sky', 'lighting'],
                'media' => [
                    [
                        'url' => '/sfw/image/901ffd6b7e896544a6de89b314828787.jpg',
                        'width' => 1600,
                        'height' => 1067,
                        'aspect_ratio' => 1.5,
                    ],
                ],
            ],
            // Post 11: Additional Post by Maya
            [
                'user' => $maya,
                'title' => 'Fantasy Maiden & Magical Crest',
                'description' => 'Character splash illustration with gold leaf accents.',
                'media_type' => 'image',
                'views_count' => 2940,
                'likes_count' => 845,
                'is_nsfw' => false,
                'source_url' => null,
                'tags' => ['mayadraws', 'portrait', 'highres', 'glowing_eyes'],
                'media' => [
                    [
                        'url' => '/sfw/image/sample_ce912cfa8723b039152de9dcf6570667.jpg',
                        'width' => 1400,
                        'height' => 1800,
                        'aspect_ratio' => 0.77,
                    ],
                ],
            ],
            // Post 12: Video Post by Ren
            [
                'user' => $ren,
                'title' => 'Action Choreography Animation Cut',
                'description' => 'Keyframe motion testing for Astral Blade combat sequence.',
                'media_type' => 'video',
                'views_count' => 2410,
                'likes_count' => 690,
                'is_nsfw' => false,
                'source_url' => null,
                'tags' => ['phantom_ren', 'original', 'sword'],
                'media' => [
                    [
                        'url' => '/sfw/video/heman-work-orig.mp4',
                        'thumbnail_url' => '/sfw/image/sample_85ea7778a8ef27adc53a8af6101d19ac.jpg',
                        'width' => 1280,
                        'height' => 720,
                        'aspect_ratio' => 1.77,
                        'duration' => 20,
                    ],
                ],
            ],
        ];

        $createdPosts = [];
        foreach ($postsData as $pData) {
            $post = Post::create([
                'user_id' => $pData['user']->id,
                'title' => $pData['title'],
                'description' => $pData['description'],
                'media_type' => $pData['media_type'],
                'media_count' => count($pData['media']),
                'views_count' => $pData['views_count'],
                'likes_count' => $pData['likes_count'],
                'is_nsfw' => $pData['is_nsfw'],
                'source_url' => $pData['source_url'],
            ]);

            foreach ($pData['media'] as $idx => $m) {
                PostMedia::create([
                    'post_id' => $post->id,
                    'order' => $idx + 1,
                    'url' => $m['url'],
                    'thumbnail_url' => $m['thumbnail_url'] ?? $m['url'],
                    'width' => $m['width'],
                    'height' => $m['height'],
                    'aspect_ratio' => $m['aspect_ratio'],
                    'duration' => $m['duration'] ?? null,
                ]);
            }

            foreach ($pData['tags'] as $tagName) {
                if (isset($tags[$tagName])) {
                    $post->tags()->attach($tags[$tagName]->id);
                    $tags[$tagName]->increment('posts_count');
                }
            }

            $createdPosts[] = $post;
        }

        // 4. Public Likes
        $me->likedPosts()->attach([$createdPosts[1]->id, $createdPosts[2]->id, $createdPosts[4]->id, $createdPosts[6]->id]);
        $ren->likedPosts()->attach([$createdPosts[0]->id, $createdPosts[1]->id]);
        $aoi->likedPosts()->attach([$createdPosts[0]->id, $createdPosts[2]->id]);
        $maya->likedPosts()->attach([$createdPosts[0]->id, $createdPosts[4]->id]);

        // 5. Comments
        Comment::create([
            'post_id' => $createdPosts[0]->id,
            'user_id' => $ren->id,
            'content' => 'The color palette in slide 2 is breathtaking. Masterful lighting work!',
        ]);
        Comment::create([
            'post_id' => $createdPosts[0]->id,
            'user_id' => $aoi->id,
            'content' => 'Those dusk gradients are top tier. Would love to collaborate on a world-building piece.',
        ]);

        // 6. Collections (User-ordered, drag to reorder, public/private)
        $publicCollection = Collection::create([
            'user_id' => $me->id,
            'title' => 'Neon Nights & Cyber Architecture',
            'description' => 'A curated visual sequence of futuristic cityscapes, reflective rain, and synthwave moods.',
            'is_private' => false,
            'cover_url' => $createdPosts[1]->primaryMedia->url,
            'items_count' => 3,
            'followers_count' => 142,
        ]);

        CollectionItem::create(['collection_id' => $publicCollection->id, 'post_id' => $createdPosts[1]->id, 'order' => 1]);
        CollectionItem::create(['collection_id' => $publicCollection->id, 'post_id' => $createdPosts[3]->id, 'order' => 2]);
        CollectionItem::create(['collection_id' => $publicCollection->id, 'post_id' => $createdPosts[0]->id, 'order' => 3]);

        $ren->followingCollections()->attach($publicCollection->id);
        $aoi->followingCollections()->attach($publicCollection->id);

        $privateCollection = Collection::create([
            'user_id' => $me->id,
            'title' => 'Private Lighting Reference Vault',
            'description' => 'Personal moodboards and anatomical reference angles for upcoming client works.',
            'is_private' => true,
            'cover_url' => $createdPosts[2]->primaryMedia->url,
            'items_count' => 2,
            'followers_count' => 0,
        ]);

        CollectionItem::create(['collection_id' => $privateCollection->id, 'post_id' => $createdPosts[2]->id, 'order' => 1]);
        CollectionItem::create(['collection_id' => $privateCollection->id, 'post_id' => $createdPosts[4]->id, 'order' => 2]);

        // 7. Pools (Series & Manga)
        $pool = Pool::create([
            'user_id' => $ren->id,
            'title' => 'Chronicles of the Astral Blade',
            'description' => 'An original fantasy saga exploring fallen celestial deities and ancient runic weapons. American LTR reading mode.',
            'cover_url' => $createdPosts[6]->primaryMedia->url,
            'is_locked' => false,
            'chapters_count' => 2,
            'followers_count' => 328,
        ]);

        $ch1 = PoolChapter::create([
            'pool_id' => $pool->id,
            'post_id' => $createdPosts[6]->id,
            'chapter_number' => 1.0,
            'title' => 'Chapter 1: The Broken Seal',
            'order' => 1,
        ]);

        $ch2 = PoolChapter::create([
            'pool_id' => $pool->id,
            'post_id' => $createdPosts[7]->id,
            'chapter_number' => 2.0,
            'title' => 'Chapter 2: Whispers in the Ruins',
            'order' => 2,
        ]);

        // Follow pool
        $me->followingPools()->attach($pool->id);

        // Pool Progress for $me ("Continue reading" state)
        PoolProgress::create([
            'user_id' => $me->id,
            'pool_id' => $pool->id,
            'last_chapter_id' => $ch2->id,
            'last_page' => 2,
        ]);

        // Pool history
        PoolHistory::create([
            'pool_id' => $pool->id,
            'user_id' => $ren->id,
            'action' => 'created_pool',
            'details' => 'Initialized series "Chronicles of the Astral Blade"',
            'created_at' => now()->subDays(10),
        ]);
        PoolHistory::create([
            'pool_id' => $pool->id,
            'user_id' => $ren->id,
            'action' => 'added_chapter',
            'details' => 'Added Chapter 1: The Broken Seal (6 pages)',
            'created_at' => now()->subDays(8),
        ]);
        PoolHistory::create([
            'pool_id' => $pool->id,
            'user_id' => $ren->id,
            'action' => 'added_chapter',
            'details' => 'Added Chapter 2: Whispers in the Ruins (3 pages)',
            'created_at' => now()->subDays(2),
        ]);

        // 8. Notifications
        Notification::create([
            'user_id' => $me->id,
            'actor_id' => $ren->id,
            'type' => 'pool_chapter',
            'notifiable_type' => Pool::class,
            'notifiable_id' => $pool->id,
            'message' => 'added a new chapter "Chapter 2: Whispers in the Ruins" to Chronicles of the Astral Blade',
            'read_at' => null,
            'created_at' => now()->subHours(2),
        ]);

        Notification::create([
            'user_id' => $me->id,
            'actor_id' => $aoi->id,
            'type' => 'like',
            'notifiable_type' => Post::class,
            'notifiable_id' => $createdPosts[0]->id,
            'message' => 'and 3 others liked your post "Starlight Citadel — The High Spires"',
            'read_at' => null,
            'created_at' => now()->subHours(5),
        ]);

        Notification::create([
            'user_id' => $me->id,
            'actor_id' => $maya->id,
            'type' => 'follow',
            'notifiable_type' => User::class,
            'notifiable_id' => $me->id,
            'message' => 'started following you',
            'read_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);

        Notification::create([
            'user_id' => $me->id,
            'actor_id' => $ren->id,
            'type' => 'collection_update',
            'notifiable_type' => Collection::class,
            'notifiable_id' => $publicCollection->id,
            'message' => 'followed your collection "Neon Nights & Cyber Architecture"',
            'read_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
        ]);

        // 9. Conversations & Telegram-style 1:1 Chat
        $conv1 = Conversation::create([
            'user_one_id' => $me->id,
            'user_two_id' => $aoi->id,
            'last_message_at' => now()->subMinutes(12),
        ]);

        $m1 = Message::create([
            'conversation_id' => $conv1->id,
            'sender_id' => $aoi->id,
            'text' => 'Hey Kira! Did you see the new high-altitude lighting pass I rendered?',
            'is_read' => true,
            'read_at' => now()->subMinutes(30),
            'created_at' => now()->subMinutes(40),
        ]);

        $m2 = Message::create([
            'conversation_id' => $conv1->id,
            'sender_id' => $aoi->id,
            'text' => 'Check out the reflections on this one:',
            'shared_post_id' => $createdPosts[1]->id,
            'is_read' => true,
            'read_at' => now()->subMinutes(25),
            'created_at' => now()->subMinutes(35),
        ]);

        $m3 = Message::create([
            'conversation_id' => $conv1->id,
            'sender_id' => $me->id,
            'text' => 'Woah, District 09 looks incredible! The wet asphalt highlights are insane.',
            'reply_to_id' => $m2->id,
            'reactions' => ['🔥' => [$aoi->id, $me->id], '❤️' => [$aoi->id]],
            'is_read' => true,
            'read_at' => now()->subMinutes(15),
            'created_at' => now()->subMinutes(20),
        ]);

        $m4 = Message::create([
            'conversation_id' => $conv1->id,
            'sender_id' => $aoi->id,
            'text' => 'Thanks! Let me know when you want to review the chapter 3 backgrounds together 🚀',
            'reactions' => ['👍' => [$me->id]],
            'is_read' => false,
            'created_at' => now()->subMinutes(12),
        ]);

        // Conversation 2 with Ren
        $conv2 = Conversation::create([
            'user_one_id' => $me->id,
            'user_two_id' => $ren->id,
            'last_message_at' => now()->subHours(3),
        ]);

        Message::create([
            'conversation_id' => $conv2->id,
            'sender_id' => $ren->id,
            'text' => 'Chapter 2 is live! Let me know if the reading pacing flows well for you in the lightbox reader.',
            'shared_post_id' => $createdPosts[7]->id,
            'is_read' => true,
            'read_at' => now()->subHours(2),
            'created_at' => now()->subHours(3),
        ]);

        // 10. Lounge Communities & Spaces (Discord/Telegram Hybrid with SFW Media)
        $c1 = Community::create([
            'owner_id' => $me->id,
            'name' => 'Original Art Studio',
            'slug' => 'original-art-studio',
            'description' => 'A dedicated sanctuary for digital painters, concept artists, and visual storytellers. Share WIPs, receive constructive critique, and participate in weekly challenges.',
            'icon_url' => '/sfw/avatar/0738955ca929985f39921b4876053170.png',
            'banner_url' => '/sfw/banner-image/sample_0a026d6456f6806f67a9d67f856e8862.jpg',
            'visibility' => 'public',
            'topics' => ['illustration', 'concept-art', 'digital-painting'],
            'onboarding_questions' => ['What art medium or software do you primarily use?'],
            'rules' => "1. Keep feedback constructive and respectful.\n2. Always credit references and stock assets.\n3. Keep posts SFW.",
            'member_count' => 4,
        ]);

        DB::table('community_members')->insert([
            ['community_id' => $c1->id, 'user_id' => $me->id, 'status' => 'active', 'joined_at' => now()->subDays(30), 'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)],
            ['community_id' => $c1->id, 'user_id' => $ren->id, 'status' => 'active', 'joined_at' => now()->subDays(25), 'created_at' => now()->subDays(25), 'updated_at' => now()->subDays(25)],
            ['community_id' => $c1->id, 'user_id' => $aoi->id, 'status' => 'active', 'joined_at' => now()->subDays(20), 'created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20)],
            ['community_id' => $c1->id, 'user_id' => $maya->id, 'status' => 'active', 'joined_at' => now()->subDays(15), 'created_at' => now()->subDays(15), 'updated_at' => now()->subDays(15)],
        ]);

        $chGen = CommunityChannel::create([
            'community_id' => $c1->id,
            'name' => 'general',
            'slug' => 'general',
            'type' => 'text',
            'description' => 'General chat for community members.',
            'position' => 0,
        ]);

        $chForum = CommunityChannel::create([
            'community_id' => $c1->id,
            'name' => 'show-and-tell',
            'slug' => 'show-and-tell',
            'type' => 'forum',
            'description' => 'Post work-in-progress, finished art, and tutorials.',
            'position' => 1,
        ]);

        $chEvents = CommunityChannel::create([
            'community_id' => $c1->id,
            'name' => 'events',
            'slug' => 'events',
            'type' => 'events',
            'description' => 'Community art sprints and live sessions.',
            'position' => 2,
        ]);

        DB::table('community_messages')->insert([
            [
                'community_channel_id' => $chGen->id,
                'user_id' => $ren->id,
                'body' => 'Welcome everyone to the Original Art Studio lounge! Feel free to share your latest WIPs in #show-and-tell.',
                'attachment_url' => '/sfw/image/b77813157c9f6e1ee90ac783fadf868a.png',
                'attachment_type' => 'image',
                'created_at' => now()->subHours(5),
                'updated_at' => now()->subHours(5),
            ],
            [
                'community_channel_id' => $chGen->id,
                'user_id' => $aoi->id,
                'body' => 'Super excited to be here! Working on a new atmospheric lighting study today.',
                'attachment_url' => '/sfw/image/c3f2b27eee02ca1545026e818caa9873.jpg',
                'attachment_type' => 'image',
                'created_at' => now()->subHours(3),
                'updated_at' => now()->subHours(3),
            ],
            [
                'community_channel_id' => $chGen->id,
                'user_id' => $me->id,
                'body' => 'That ambient occlusion on the background pillars looks crisp @aoi_clouds!',
                'attachment_url' => '/sfw/image/john-staub-marvel-loki-western-color-jstaub.jpg',
                'attachment_type' => 'image',
                'created_at' => now()->subMinutes(45),
                'updated_at' => now()->subMinutes(45),
            ],
        ]);

        DB::table('community_forum_tags')->insert([
            ['community_id' => $c1->id, 'name' => 'WIP', 'slug' => 'wip', 'created_at' => now(), 'updated_at' => now()],
            ['community_id' => $c1->id, 'name' => 'Tutorial', 'slug' => 'tutorial', 'created_at' => now(), 'updated_at' => now()],
            ['community_id' => $c1->id, 'name' => 'Feedback', 'slug' => 'feedback', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $post1Id = DB::table('community_forum_posts')->insertGetId([
            'community_channel_id' => $chForum->id,
            'user_id' => $maya->id,
            'title' => 'Color Harmony and Secondary Light Sources Guide',
            'body' => 'Here is a quick visual breakdown of how warm bounce light affects cool shadow edges in outdoor environments.',
            'status' => 'open',
            'created_at' => now()->subHours(4),
            'updated_at' => now()->subHours(4),
        ]);

        DB::table('community_forum_replies')->insert([
            [
                'community_forum_post_id' => $post1Id,
                'user_id' => $me->id,
                'body' => 'Extremely helpful breakdown! The sub-surface scattering note on rim highlights is super clean.',
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],
        ]);

        DB::table('community_events')->insert([
            'community_channel_id' => $chEvents->id,
            'user_id' => $me->id,
            'title' => 'Weekend 2-Hour Speed Painting Challenge',
            'description' => 'Topic: "Ancient Sunken Citadel". Join us in general text chat with your 2-hour art progress!',
            'starts_at' => now()->addDays(2)->setHour(18)->setMinute(0),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
