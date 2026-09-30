<?php

namespace App\Support;

use App\Models\CommunityEmoji;
use App\Models\CommunityMember;
use App\Models\CommunityRole;
use Illuminate\Support\Collection;

/**
 * Renders Discord-style message bodies: inline markdown, links, member / role
 * mentions, #channel references and custom :emoji: shortcodes.
 */
class LoungeFormatter
{
    /**
     * @param  Collection<int, CommunityMember>  $members
     * @param  Collection<int, CommunityRole>  $roles
     * @param  Collection<int, CommunityEmoji>  $emoji
     */
    public function __construct(
        private Collection $members,
        private Collection $roles,
        private Collection $emoji,
        private ?int $viewerId = null,
    ) {}

    /**
     * @param  array<int, int>  $channelIds  Channel id keyed by slug, used for #refs.
     */
    public function render(?string $body, array $channelIds = []): string
    {
        if ($body === null || $body === '') {
            return '';
        }

        $html = e($body);

        // Protect backslash escapes (e.g. the shrug `\_`) from markdown passes.
        $escapes = [];
        $html = preg_replace_callback('/\\\\([\\\\`*_{}\[\]()<>#+\-.!~|>])/', function (array $matches) use (&$escapes): string {
            $escapes[] = e($matches[1]);

            return "\x00ESC".(count($escapes) - 1)."\x00";
        }, $html) ?? $html;

        // Protect code spans so markdown/mention passes cannot touch them.
        $code = [];
        $html = preg_replace_callback('/`([^`\n]+)`/', function (array $matches) use (&$code): string {
            $code[] = '<code class="rounded bg-black/30 px-1.5 py-0.5 font-mono text-[0.85em]">'.$matches[1].'</code>';

            return "\x00CODE".(count($code) - 1)."\x00";
        }, $html) ?? $html;

        // Protect URLs before inline formatting runs.
        $links = [];
        $html = preg_replace_callback('/https?:\/\/[^\s<]+/i', function (array $matches) use (&$links): string {
            $raw = html_entity_decode($matches[0], ENT_QUOTES | ENT_HTML5);
            $url = rtrim($raw, '.,!?;:)');
            $suffix = substr($raw, strlen($url));
            $links[] = '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="text-[var(--accent-light)] underline decoration-[var(--accent-light)]/40 hover:decoration-[var(--accent-light)]">'.e($url).'</a>'.e($suffix);

            return "\x00LINK".(count($links) - 1)."\x00";
        }, $html) ?? $html;

        $html = $this->applyInlineMarkdown($html);
        $html = $this->applyMentions($html);
        $html = $this->applyChannelRefs($html, $channelIds);
        $html = $this->applyEmoji($html);

        // Restore protected spans.
        $html = preg_replace_callback('/\x00ESC(\d+)\x00/', fn (array $m): string => $escapes[(int) $m[1]] ?? '', $html) ?? $html;
        $html = preg_replace_callback('/\x00CODE(\d+)\x00/', fn (array $m): string => $code[(int) $m[1]] ?? '', $html) ?? $html;
        $html = preg_replace_callback('/\x00LINK(\d+)\x00/', fn (array $m): string => $links[(int) $m[1]] ?? '', $html) ?? $html;

        return nl2br($html, false);
    }

    /**
     * @return array{user_ids: array<int, int>, role_ids: array<int, int>, everyone: bool}
     */
    public function extractMentions(string $body): array
    {
        $userIds = [];
        $roleIds = [];
        $everyone = (bool) preg_match('/(^|\s)@(everyone|here)\b/i', $body);

        preg_match_all('/@([A-Za-z0-9_.\-]{2,40})/', $body, $matches);
        $handles = array_map('strtolower', $matches[1] ?? []);

        foreach ($this->members as $member) {
            $keys = array_filter([
                strtolower((string) $member->user?->username),
                strtolower((string) $member->nickname),
            ]);
            if (array_intersect($keys, $handles)) {
                $userIds[] = (int) $member->user_id;
            }
        }

        foreach ($this->roles as $role) {
            if (! $role->mentionable) {
                continue;
            }
            $slug = strtolower(str_replace(' ', '', $role->name));
            if (in_array($slug, $handles, true)) {
                $roleIds[] = (int) $role->id;
            }
        }

        return [
            'user_ids' => array_values(array_unique($userIds)),
            'role_ids' => array_values(array_unique($roleIds)),
            'everyone' => $everyone,
        ];
    }

    private function applyInlineMarkdown(string $html): string
    {
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html) ?? $html;
        $html = preg_replace('/__(.+?)__/s', '<u>$1</u>', $html) ?? $html;
        $html = preg_replace('/~~(.+?)~~/s', '<s>$1</s>', $html) ?? $html;
        $html = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $html) ?? $html;
        $html = preg_replace('/(^|[\s(])_(?!\s)(.+?)(?<!\s)_($|[\s).,!?;:])/s', '$1<em>$2</em>$3', $html) ?? $html;

        return $html;
    }

    private function applyMentions(string $html): string
    {
        $handleMap = [];
        foreach ($this->members as $member) {
            foreach (array_filter([$member->user?->username, $member->nickname]) as $handle) {
                if (str_contains($handle, ' ')) {
                    continue;
                }
                $handleMap[strtolower($handle)] = (int) $member->user_id;
            }
        }

        $mentionClass = fn (int $userId, string $name): string => $userId === $this->viewerId
            ? '<span class="rounded bg-[var(--accent-primary)]/30 px-1 py-0.5 font-semibold text-[var(--text-main)]">'.$name.'</span>'
            : '<span class="rounded bg-[var(--bg-page)] px-1 py-0.5 font-semibold text-[var(--accent-light)]">'.$name.'</span>';

        $html = preg_replace_callback('/@([A-Za-z0-9_.\-]{2,40})/', function (array $matches) use ($handleMap, $mentionClass): string {
            $found = $handleMap[strtolower($matches[1])] ?? null;

            return $found ? $mentionClass($found, '@'.$matches[1]) : $matches[0];
        }, $html) ?? $html;

        return preg_replace_callback('/@(everyone|here)\b/i', function (array $matches) use ($mentionClass): string {
            return $mentionClass($this->viewerId ? -1 : 0, '@'.strtolower($matches[1]));
        }, $html) ?? $html;
    }

    /**
     * @param  array<int, int>  $channelIds
     */
    private function applyChannelRefs(string $html, array $channelIds): string
    {
        if ($channelIds === []) {
            return $html;
        }

        return preg_replace_callback('/#([a-z0-9\-]{1,100})/i', function (array $matches) use ($channelIds): string {
            $slug = strtolower($matches[1]);
            if (! isset($channelIds[$slug])) {
                return $matches[0];
            }

            return '<span class="rounded bg-[var(--bg-page)] px-1 py-0.5 font-semibold text-[var(--accent-light)]">#'.e($slug).'</span>';
        }, $html) ?? $html;
    }

    private function applyEmoji(string $html): string
    {
        if ($this->emoji->isEmpty()) {
            return $html;
        }

        $map = $this->emoji->pluck('image_url', 'name')->all();

        return preg_replace_callback('/:([A-Za-z0-9_]{2,40}):/', function (array $matches) use ($map): string {
            $url = $map[$matches[1]] ?? null;
            if (! $url) {
                return $matches[0];
            }

            return '<img src="'.e($url).'" alt=":'.e($matches[1]).':" title=":'.e($matches[1]).':" class="inline-block h-5 w-5 align-[-0.15em]" loading="lazy">';
        }, $html) ?? $html;
    }
}
