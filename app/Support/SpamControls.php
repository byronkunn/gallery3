<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class SpamControls
{
    public static function enforce(string $action, int $defaultLimit, int $decaySeconds, ?string $content = null, int $weight = 1): void
    {
        if (! Cache::get('site_spam_controls_enabled', true)) {
            return;
        }

        $maximumAttempts = max(1, (int) Cache::get('site_spam_'.$action.'_limit', $defaultLimit));
        $identity = Auth::id() ? 'user:'.Auth::id() : 'ip:'.request()->ip();
        $key = 'spam-control:'.$action.':'.hash('sha256', $identity);

        if (RateLimiter::tooManyAttempts($key, $maximumAttempts)
            || RateLimiter::attempts($key) + $weight > $maximumAttempts) {
            abort(429, 'You are doing that too often. Please wait a bit and try again.');
        }

        RateLimiter::increment($key, $decaySeconds, max(1, $weight));

        if (filled($content)) {
            $normalizedContent = Str::of($content)->lower()->squish()->toString();
            $fingerprint = hash('sha256', $identity.'|'.$action.'|'.$normalizedContent);
            $window = max(15, (int) Cache::get('site_spam_duplicate_window', 45));

            if (! Cache::add('spam-duplicate:'.$fingerprint, true, now()->addSeconds($window))) {
                abort(429, 'That looks like a duplicate. Please wait before posting it again.');
            }
        }

        if (Auth::id() && Cache::add('user-last-active-recorded:'.Auth::id(), true, now()->addMinutes(10))) {
            User::whereKey(Auth::id())->update(['last_active_at' => now()]);
        }
    }
}
