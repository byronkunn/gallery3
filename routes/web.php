<?php

use App\Http\Controllers\AppealController;
use App\Http\Controllers\AuthController;
use App\Models\Collection;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\Pool;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.store');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register.store');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::get('/appeals', [AppealController::class, 'index'])->name('appeals.index');
Route::post('/appeals', [AppealController::class, 'store'])->middleware('throttle:3,60')->name('appeals.store');

// 1. Gallery Feed (Home, Explore, Search)
Route::get('/', function () {
    return view('pages.gallery');
})->name('gallery');

Route::get('/gallery', function () {
    return view('pages.gallery');
});

Route::get('/lounge', function () {
    return view('pages.lounge');
})->name('lounge.explore');

Route::get('/lounge/invite/{code}', function (string $code) {
    abort_unless(Auth::check(), 401);
    $communityId = DB::transaction(function () use ($code) {
        $invite = DB::table('community_invites')->where('code', $code)->lockForUpdate()->first();
        abort_unless($invite && (! $invite->expires_at || now()->lt($invite->expires_at)) && (! $invite->max_uses || $invite->used_count < $invite->max_uses), 404);
        $community = Community::findOrFail($invite->community_id);
        $member = DB::table('community_members')->where('community_id', $community->id)->where('user_id', Auth::id())->first();
        abort_if($member?->status === 'banned', 403);
        if (! $member || $member->status !== 'active') {
            DB::table('community_members')->updateOrInsert(
                ['community_id' => $community->id, 'user_id' => Auth::id()],
                ['status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]
            );
            $community->increment('member_count');
        }
        DB::table('community_invites')->where('id', $invite->id)->increment('used_count');

        return $community->id;
    });
    $community = Community::findOrFail($communityId);

    return redirect()->route('lounge.community', $community->slug);
})->middleware('auth')->name('lounge.invite');

Route::get('/lounge/{slug}/{channel?}', function (string $slug, ?string $channel = null) {
    return view('pages.community', ['slug' => $slug, 'channel' => $channel]);
})->name('lounge.community');

// 2. Post Media View
Route::get('/post/{id}', function ($id) {
    $post = Post::findOrFail($id);

    return view('pages.post', [
        'id' => (int) $id,
        'title' => ($post->title ?? 'Post #'.$post->id).' — Booru Art Gallery',
    ]);
})->name('post.detail');

// 3. User Profile
Route::get('/profile/{username}', function ($username) {
    $user = User::where('username', $username)->firstOrFail();

    return view('pages.profile', [
        'username' => $username,
    ]);
})->name('profile');

// 4. Messaging (Twitter-look, Telegram-feel 1:1 chat)
Route::get('/messages/{conversationId?}', function ($conversationId = null) {
    return view('pages.messages', [
        'conversationId' => $conversationId ? (int) $conversationId : null,
    ]);
})->name('messages');

// 5. Notifications
Route::get('/notifications', function () {
    return view('pages.notifications');
})->name('notifications');

// 6. Settings
Route::get('/settings', function () {
    return view('pages.settings');
})->name('settings');

// 7. Upload
Route::get('/upload', function () {
    return view('pages.upload');
})->name('upload');

// 8. Pools (Series & Manga)
Route::get('/pools', function () {
    return view('pages.pools-index');
})->name('pools.index');

Route::get('/pools/{id}', function ($id) {
    $pool = Pool::findOrFail($id);

    return view('pages.pool-detail', [
        'id' => (int) $id,
        'title' => $pool->title.' — Manga & Series Pool',
    ]);
})->name('pools.detail');

// 9. Collections
Route::get('/collections/{id}', function ($id) {
    $coll = Collection::findOrFail($id);

    return view('pages.collection-detail', [
        'id' => (int) $id,
        'title' => $coll->title.' — Collection',
    ]);
})->name('collection.detail');

// Demo account switching is available only in local and test environments.
if (app()->environment(['local', 'testing'])) {
    $switchDemoUser = function (string $id) {
        if ($id === 'guest') {
            Auth::logout();
            session()->put('is_guest', true);
        } else {
            $user = User::findOrFail($id);
            Auth::login($user);
            session()->forget('is_guest');
        }

        return redirect()->back();
    };
    Route::post('/switch-user/{id}', $switchDemoUser)->name('switch-user');
    if (app()->environment('testing')) {
        Route::get('/switch-user/{id}', $switchDemoUser);
    }
}

// 11. Admin Console
Route::get('/admin', function () {
    if (! Auth::check() || ! Auth::user()->isAdmin()) {
        return redirect()->route('gallery')->with('error', 'Unauthorized access.');
    }

    return view('pages.admin');
})->name('admin');

// 12. Admin Message Exporter & Media Exporter
Route::get('/admin/export-chat/{conversationId}', function ($conversationId) {
    if (! Auth::check() || ! Auth::user()->isAdmin()) {
        abort(403, 'Unauthorized access');
    }
    $conversation = Conversation::with(['userOne', 'userTwo', 'messages.sender', 'messages.sharedPost'])->findOrFail($conversationId);

    $u1 = $conversation->userOne->username;
    $u2 = $conversation->userTwo->username;

    $exportData = [
        'conversation_id' => $conversation->id,
        'user_one' => ['id' => $conversation->userOne->id, 'name' => $conversation->userOne->name, 'username' => $u1],
        'user_two' => ['id' => $conversation->userTwo->id, 'name' => $conversation->userTwo->name, 'username' => $u2],
        'exported_at' => now()->toIso8601String(),
        'messages_count' => $conversation->messages->count(),
        'messages' => $conversation->messages->map(function ($m) {
            return [
                'id' => $m->id,
                'sender' => $m->sender->username,
                'sender_name' => $m->sender->name,
                'timestamp' => $m->created_at->toIso8601String(),
                'text' => $m->text,
                'shared_post_id' => $m->shared_post_id,
                'shared_post_title' => $m->sharedPost->title ?? null,
            ];
        }),
    ];

    $filename = "chat_log_conv_{$conversation->id}_{$u1}_vs_{$u2}.json";

    return response()->streamDownload(function () use ($exportData) {
        echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }, $filename, ['Content-Type' => 'application/json']);
})->name('admin.export-chat');

Route::get('/admin/export-chat-media/{conversationId}', function ($conversationId) {
    if (! Auth::check() || ! Auth::user()->isAdmin()) {
        abort(403, 'Unauthorized access');
    }
    $conversation = Conversation::with(['messages.sharedPost.media'])->findOrFail($conversationId);

    $mediaList = [];
    foreach ($conversation->messages as $m) {
        if ($m->sharedPost && $m->sharedPost->media) {
            foreach ($m->sharedPost->media as $med) {
                $mediaList[] = [
                    'message_id' => $m->id,
                    'post_id' => $m->sharedPost->id,
                    'post_title' => $m->sharedPost->title,
                    'media_url' => asset($med->url),
                    'thumbnail_url' => asset($med->thumbnail_url ?? $med->url),
                ];
            }
        }
    }

    $exportData = [
        'conversation_id' => $conversation->id,
        'exported_at' => now()->toIso8601String(),
        'attached_media_count' => count($mediaList),
        'media_attachments' => $mediaList,
    ];

    $filename = "chat_media_conv_{$conversation->id}.json";

    return response()->streamDownload(function () use ($exportData) {
        echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }, $filename, ['Content-Type' => 'application/json']);
})->name('admin.export-chat-media');
