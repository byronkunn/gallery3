<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Typed, persistent access to the platform settings the admin console edits.
 *
 * Values live in the `settings` table (JSON encoded) and are memoised for the
 * life of the request. Only keys declared in DEFAULTS can be written, so a typo
 * can never create a phantom setting.
 */
class SiteSettings
{
    /**
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        // Identity & policy
        'site_name' => 'Booru.art',
        'site_tagline' => 'Modern Art & Media Social Platform',
        'site_description' => 'A visual-first modern booru-style art gallery with rich chat, collections, and manga pools.',
        'site_meta_keywords' => 'booru, art gallery, anime art, manga, illustration',
        'site_logo_url' => null,
        'site_favicon_url' => null,
        'site_contact_email' => 'support@booru.art',
        'site_tos_url' => null,
        'site_privacy_url' => null,

        // Broadcast & availability
        'site_announcement' => '',
        'site_maintenance' => false,
        'site_maintenance_message' => 'The gallery is temporarily unavailable for maintenance.',
        'site_maintenance_starts_at' => null,
        'site_maintenance_ends_at' => null,
        'site_maintenance_allow_admins' => true,
        'site_maintenance_allowlist' => [],

        // Registration
        'site_registrations' => true,
        'site_registration_approval' => false,
        'site_registration_invite_only' => false,
        'site_registration_invite_codes' => [],

        // Feature flags
        'site_uploads_enabled' => true,
        'site_dms_enabled' => true,
        'site_lounge_enabled' => true,
        'site_global_commissions' => true,

        // Abuse controls
        'site_spam_controls_enabled' => true,
        'site_spam_messages_limit' => 20,
        'site_spam_comments_limit' => 8,
        'site_spam_posts_limit' => 12,
        'site_spam_duplicate_window' => 45,
        'site_inactive_owner_days' => 90,
    ];

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $memo = null;

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $settings = self::DEFAULTS;

        if (Schema::hasTable('settings')) {
            foreach (DB::table('settings')->get() as $row) {
                if (! array_key_exists($row->key, self::DEFAULTS)) {
                    continue;
                }

                $settings[$row->key] = json_decode((string) $row->value, true);
            }
        }

        return self::$memo = $settings;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $settings = self::all();

        return $settings[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    /**
     * @return array<int, string>
     */
    public static function list(string $key): array
    {
        $value = self::get($key);

        return is_array($value) ? array_values(array_filter($value, fn ($item) => filled($item))) : [];
    }

    public static function set(string $key, mixed $value): void
    {
        self::setMany([$key => $value]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function setMany(array $values): void
    {
        $unknown = array_diff(array_keys($values), array_keys(self::DEFAULTS));
        abort_if($unknown !== [], 500, 'Unknown setting(s): '.implode(', ', $unknown));

        if (! Schema::hasTable('settings')) {
            return;
        }

        $now = now();

        $rows = collect($values)->map(fn ($value, $key): array => [
            'key' => $key,
            'value' => json_encode($value),
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        DB::table('settings')->upsert($rows, ['key'], ['value', 'updated_at']);

        self::$memo = null;
    }

    /**
     * Is a scheduled or manual maintenance window in effect right now?
     */
    public static function maintenanceActive(Carbon|string|null $moment = null): bool
    {
        $moment = $moment ? Carbon::parse($moment) : now();

        if (self::bool('site_maintenance')) {
            return true;
        }

        $startsAt = self::get('site_maintenance_starts_at');
        $endsAt = self::get('site_maintenance_ends_at');

        if (! $startsAt && ! $endsAt) {
            return false;
        }

        $starts = $startsAt ? Carbon::parse($startsAt) : null;
        $ends = $endsAt ? Carbon::parse($endsAt) : null;

        if ($starts && $moment->lessThan($starts)) {
            return false;
        }

        return ! ($ends && $moment->greaterThanOrEqualTo($ends));
    }

    public static function maintenanceMessage(): string
    {
        return (string) (self::get('site_maintenance_message') ?: self::DEFAULTS['site_maintenance_message']);
    }

    /**
     * Reset the per-request memo. Used by tests that write settings directly.
     */
    public static function flush(): void
    {
        self::$memo = null;
    }
}
