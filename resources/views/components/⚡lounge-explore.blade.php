<?php

use App\Models\Community;
use App\Models\Conversation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public string $search = '';

    public string $topicFilter = '';

    public bool $createModalOpen = false;

    public string $communityName = '';

    public string $communityDescription = '';

    public string $communityTopics = '';

    public string $communityRules = '';

    public string $onboardingQuestion = '';

    public string $communityVisibility = 'public';

    public string $applicationAnswer = '';

    public ?int $applyingCommunityId = null;

    public function createCommunity(): void
    {
        abort_unless(Auth::check(), 401);
        $validated = $this->validate([
            'communityName' => ['required', 'string', 'max:80'],
            'communityDescription' => ['nullable', 'string', 'max:1200'],
            'communityTopics' => ['nullable', 'string', 'max:300'],
            'communityRules' => ['nullable', 'string', 'max:3000'],
            'onboardingQuestion' => ['nullable', 'string', 'max:240'],
            'communityVisibility' => ['required', Rule::in(['public', 'approval', 'invite'])],
        ]);

        $slugBase = Str::slug($validated['communityName']);
        if ($slugBase === '') {
            $this->addError('communityName', 'Use letters or numbers in the community name.');

            return;
        }
        $slug = $slugBase;
        $suffix = 2;
        while (Community::where('slug', $slug)->exists()) {
            $slug = $slugBase.'-'.$suffix++;
        }

        $topics = collect(explode(',', $validated['communityTopics'] ?? ''))
            ->map(fn (string $topic): string => Str::of($topic)->trim()->lower()->replace(' ', '-')->toString())
            ->filter()
            ->unique()
            ->take(8)
            ->values()
            ->all();

        $community = DB::transaction(function () use ($validated, $slug, $topics): Community {
            $community = Community::create([
                'owner_id' => Auth::id(),
                'name' => $validated['communityName'],
                'slug' => $slug,
                'description' => $validated['communityDescription'] ?? null,
                'visibility' => $validated['communityVisibility'],
                'topics' => $topics,
                'rules' => $validated['communityRules'] ?? null,
                'onboarding_questions' => filled($validated['onboardingQuestion'])
                    ? [$validated['onboardingQuestion']]
                    : [],
                'member_count' => 1,
            ]);

            DB::table('community_members')->insert([
                'community_id' => $community->id,
                'user_id' => Auth::id(),
                'status' => 'active',
                'presence' => 'online',
                'last_seen_at' => now(),
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('community_channels')->insert([
                ['community_id' => $community->id, 'name' => 'general', 'slug' => 'general', 'type' => 'text', 'description' => 'Start the conversation.', 'position' => 0, 'created_at' => now(), 'updated_at' => now()],
                ['community_id' => $community->id, 'name' => 'show-and-tell', 'slug' => 'show-and-tell', 'type' => 'forum', 'description' => 'Share work and get thoughtful feedback.', 'position' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['community_id' => $community->id, 'name' => 'events', 'slug' => 'events', 'type' => 'events', 'description' => 'Schedule community events.', 'position' => 2, 'created_at' => now(), 'updated_at' => now()],
            ]);

            DB::table('community_action_logs')->insert([
                'community_id' => $community->id,
                'actor_id' => Auth::id(),
                'action' => 'community_created',
                'details' => json_encode(['name' => $community->name]),
                'created_at' => now(),
            ]);
            DB::table('admin_audit_logs')->insert([
                'actor_id' => Auth::id(), 'action' => 'community.created', 'target_type' => 'community', 'target_id' => $community->id,
                'details' => json_encode(['slug' => $community->slug, 'visibility' => $community->visibility]),
                'ip_address' => request()->ip(), 'user_agent' => Str::limit((string) request()->userAgent(), 500, ''), 'created_at' => now(),
            ]);

            return $community;
        });

        $this->createModalOpen = false;
        $this->reset(['communityName', 'communityDescription', 'communityTopics', 'communityRules', 'onboardingQuestion']);
        $this->redirect(route('lounge.community', ['slug' => $community->slug, 'channel' => 'general']));
    }

    public function beginApplication(int $communityId): void
    {
        abort_unless(Auth::check(), 401);
        $community = Community::findOrFail($communityId);
        abort_if($community->visibility === 'invite', 404);
        $this->applyingCommunityId = $community->id;
        $this->applicationAnswer = '';
    }

    public function submitApplication(): void
    {
        abort_unless(Auth::check(), 401);
        $validated = $this->validate([
            'applyingCommunityId' => ['required', 'integer', 'exists:communities,id'],
            'applicationAnswer' => ['nullable', 'string', 'max:1000'],
        ]);
        $community = Community::findOrFail($validated['applyingCommunityId']);
        abort_if($community->visibility === 'invite', 404);
        $status = $community->visibility === 'approval' ? 'pending' : 'active';
        $existing = DB::table('community_members')
            ->where('community_id', $community->id)
            ->where('user_id', Auth::id())
            ->first();
        abort_if($existing?->status === 'banned', 403);

        if (! $existing) {
            DB::table('community_members')->insert([
                'community_id' => $community->id,
                'user_id' => Auth::id(),
                'status' => $status,
                'onboarding_answers' => json_encode(array_filter([$validated['applicationAnswer'] ?? null])),
                'presence' => $status === 'active' ? 'online' : 'offline',
                'last_seen_at' => $status === 'active' ? now() : null,
                'joined_at' => $status === 'active' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($status === 'active') {
                $community->increment('member_count');
            }
        } elseif ($existing->status !== 'active') {
            DB::table('community_members')->where('id', $existing->id)->update([
                'status' => $status,
                'onboarding_answers' => json_encode(array_filter([$validated['applicationAnswer'] ?? null])),
                'joined_at' => $status === 'active' ? now() : null,
                'updated_at' => now(),
            ]);
            if ($status === 'active') {
                $community->increment('member_count');
            }
        } else {
            $status = 'active';
        }

        $this->applyingCommunityId = null;
        $this->dispatch('notify', $status === 'pending' ? 'Your request is waiting for community approval.' : 'You joined the community.');
    }

    public function render()
    {
        $query = Community::query()->whereIn('visibility', ['public', 'approval']);
        if (filled($this->search)) {
            $search = trim($this->search);
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        if (filled($this->topicFilter)) {
            $query->whereJsonContains('topics', Str::slug($this->topicFilter));
        }
        $communities = $query->orderByDesc('member_count')->limit(30)->get();

        $myCommunities = Auth::check()
            ? Community::query()
                ->join('community_members', 'community_members.community_id', '=', 'communities.id')
                ->where('community_members.user_id', Auth::id())
                ->whereIn('community_members.status', ['active', 'pending'])
                ->select('communities.*', 'community_members.status as membership_status')
                ->orderBy('communities.name')
                ->get()
            : collect();
        $membershipStatuses = Auth::check()
            ? DB::table('community_members')->where('user_id', Auth::id())->pluck('status', 'community_id')
            : collect();

        $recentConversations = Auth::check()
            ? Conversation::query()->visibleFor(Auth::user())
                ->orderByDesc('last_message_at')->limit(8)->with(['userOne', 'userTwo'])->get()
            : collect();

        return view('components.⚡lounge-explore', [
            'communities' => $communities,
            'myCommunities' => $myCommunities,
            'membershipStatuses' => $membershipStatuses,
            'recentConversations' => $recentConversations,
            'community' => null,
        ]);
    }
};
?>

<div class="flex h-full min-h-0 w-full overflow-hidden bg-[var(--bg-surface-elevated)] text-[var(--text-main)]">
    @include('lounge.server-rail')

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="flex h-12 shrink-0 items-center gap-3 border-b border-black/20 px-4 shadow-sm">
            <svg class="h-5 w-5 shrink-0 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <h1 class="shrink-0 text-[15px] font-bold">Discover communities</h1>
            <span class="hidden text-[12px] text-[var(--text-dim)] sm:inline">{{ $communities->count() }} public servers</span>

            <div class="ml-auto flex shrink-0 items-center gap-2">
                <div class="hidden items-center rounded-md bg-[var(--bg-page)] px-2 sm:flex">
                    <input wire:model.live.debounce.250ms="search" type="search" placeholder="Search servers" class="w-28 bg-transparent px-1 py-1 text-[13px] text-[var(--text-main)] outline-none placeholder:text-[var(--text-dim)] lg:w-44">
                </div>
                <select wire:model.live="topicFilter" class="rounded-md bg-[var(--bg-page)] px-2 py-1 text-[13px] text-[var(--text-muted)] outline-none">
                    <option value="">All topics</option>
                    <option value="illustration">Illustration</option>
                    <option value="manga">Manga</option>
                    <option value="concept-art">Concept art</option>
                    <option value="digital-painting">Digital painting</option>
                    <option value="animation">Animation</option>
                </select>
                @auth
                    <button wire:click="$set('createModalOpen', true)" class="rounded-md bg-emerald-600 px-3 py-1.5 text-[13px] font-bold text-white transition hover:bg-emerald-500">＋ Create server</button>
                @else
                    <a href="{{ route('login') }}" class="rounded-md bg-emerald-600 px-3 py-1.5 text-[13px] font-bold text-white">Log in</a>
                @endauth
            </div>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <div class="mx-auto w-full max-w-5xl space-y-8 p-4 sm:p-6">
                @if($myCommunities->isNotEmpty())
                    <section>
                        <h2 class="mb-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-dim)]">Your servers</h2>
                        <div class="space-y-1">
                            @foreach($myCommunities as $joined)
                                <a href="{{ route('lounge.community', ['slug' => $joined->slug]) }}" class="group flex items-center gap-3 rounded-lg p-2 transition hover:bg-[var(--bg-surface)]">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-[var(--bg-page)] text-base font-black">
                                        @if($joined->icon_url)
                                            <img src="{{ $joined->icon_url }}" alt="" class="h-full w-full object-cover">
                                        @else
                                            {{ mb_strtoupper(mb_substr($joined->name, 0, 1)) }}
                                        @endif
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center gap-2">
                                            <span class="truncate text-sm font-bold">{{ $joined->name }}</span>
                                            @if($joined->membership_status === 'pending')
                                                <span class="rounded bg-amber-500/20 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-400">Pending</span>
                                            @endif
                                        </span>
                                        <span class="block text-[11px] text-[var(--text-dim)]">{{ number_format($joined->member_count) }} members</span>
                                    </span>
                                    <span class="shrink-0 text-[var(--text-dim)] transition group-hover:translate-x-0.5 group-hover:text-[var(--text-main)]">→</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section>
                    <div class="mb-3 flex items-end justify-between">
                        <h2 class="text-[11px] font-bold uppercase tracking-wider text-[var(--text-dim)]">Explore public servers</h2>
                        <span class="text-[11px] text-[var(--text-dim)]">Sorted by membership</span>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @forelse($communities as $listed)
                            <article wire:key="community-{{ $listed->id }}" class="group flex flex-col overflow-hidden rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] transition hover:border-[var(--border-medium)]">
                                <div class="relative h-20 bg-gradient-to-br from-violet-600/40 via-sky-500/20 to-pink-500/30" @if($listed->banner_url) style="background-image:url('{{ $listed->banner_url }}');background-position:center;background-size:cover" @endif>
                                    <span class="absolute -bottom-5 left-4 flex h-12 w-12 items-center justify-center overflow-hidden rounded-2xl border-4 border-[var(--bg-surface)] bg-[var(--bg-page)] text-lg font-black">
                                        @if($listed->icon_url)
                                            <img src="{{ $listed->icon_url }}" alt="" class="h-full w-full object-cover">
                                        @else
                                            {{ mb_strtoupper(mb_substr($listed->name, 0, 1)) }}
                                        @endif
                                    </span>
                                </div>
                                <div class="flex flex-1 flex-col px-4 pb-4 pt-6">
                                    <div class="flex items-start justify-between gap-2">
                                        <h3 class="min-w-0 break-words text-[15px] font-bold">{{ $listed->name }}</h3>
                                        <span class="flex shrink-0 items-center gap-1 text-[11px] text-[var(--text-dim)]">
                                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>{{ number_format($listed->member_count) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 line-clamp-3 min-h-[48px] text-[12px] text-[var(--text-muted)]">{{ $listed->description ?: 'No description yet.' }}</p>
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        @foreach(array_slice($listed->topics ?? [], 0, 3) as $topic)
                                            <span class="rounded-full bg-[var(--bg-page)] px-2 py-0.5 text-[10px] font-semibold text-[var(--text-dim)]">#{{ $topic }}</span>
                                        @endforeach
                                    </div>
                                    <div class="mt-4 flex gap-2">
                                        <a href="{{ route('lounge.community', ['slug' => $listed->slug]) }}" class="flex-1 rounded-lg border border-[var(--border-subtle)] px-3 py-2 text-center text-[13px] font-bold text-[var(--text-main)] transition hover:bg-[var(--bg-surface-elevated)]">Preview</a>
                                        @auth
                                            @php($membershipStatus = $membershipStatuses[$listed->id] ?? null)
                                            @if($membershipStatus === 'active')
                                                <a href="{{ route('lounge.community', ['slug' => $listed->slug]) }}" class="flex-1 rounded-lg bg-emerald-600 px-3 py-2 text-center text-[13px] font-bold text-white transition hover:bg-emerald-500">Open</a>
                                            @elseif($membershipStatus === 'banned')
                                                <button disabled class="flex-1 rounded-lg bg-[var(--bg-page)] px-3 py-2 text-[13px] font-bold text-rose-400">Banned</button>
                                            @elseif($membershipStatus === 'pending')
                                                <button disabled class="flex-1 rounded-lg bg-[var(--bg-page)] px-3 py-2 text-[13px] font-bold text-amber-400">Pending</button>
                                            @else
                                                <button wire:click="beginApplication({{ $listed->id }})" class="flex-1 rounded-lg bg-emerald-600 px-3 py-2 text-[13px] font-bold text-white transition hover:bg-emerald-500">Join</button>
                                            @endif
                                        @else
                                            <a href="{{ route('login') }}" class="flex-1 rounded-lg bg-emerald-600 px-3 py-2 text-center text-[13px] font-bold text-white">Join</a>
                                        @endauth
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div class="col-span-full rounded-xl border border-dashed border-[var(--border-subtle)] p-12 text-center text-sm text-[var(--text-muted)]">No communities match that search yet.</div>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>
    </div>

    {{-- create server --}}
    @if($createModalOpen)
        <div class="fixed inset-0 z-[70] flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm" wire:click.self="$set('createModalOpen', false)">
            <form wire:submit="createCommunity" class="max-h-[90vh] w-full max-w-lg space-y-3 overflow-y-auto rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 shadow-2xl">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-[15px] font-bold">Create a server</h2>
                        <p class="mt-0.5 text-[12px] text-[var(--text-muted)]">Your server starts with #general chat, a forum channel and an events channel.</p>
                    </div>
                    <button type="button" wire:click="$set('createModalOpen', false)" class="text-[var(--text-dim)] hover:text-[var(--text-main)]">✕</button>
                </div>
                <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Server name
                    <input wire:model="communityName" maxlength="80" required class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                </label>
                @error('communityName') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Description
                    <textarea wire:model="communityDescription" maxlength="1200" rows="3" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none"></textarea>
                </label>
                <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Topics (comma separated)
                    <input wire:model="communityTopics" placeholder="illustration, manga, concept art" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                </label>
                <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Rules
                    <textarea wire:model="communityRules" rows="3" placeholder="Set expectations for members" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none"></textarea>
                </label>
                <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Optional join question
                    <input wire:model="onboardingQuestion" maxlength="240" placeholder="What are you hoping to share or learn?" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                </label>
                <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Who can join
                    <select wire:model="communityVisibility" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                        <option value="public">Public</option>
                        <option value="approval">Request to join</option>
                        <option value="invite">Invite only</option>
                    </select>
                </label>
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" wire:click="$set('createModalOpen', false)" class="rounded-xl px-4 py-2 text-sm text-[var(--text-muted)]">Cancel</button>
                    <button class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-bold text-white">Create server</button>
                </div>
            </form>
        </div>
    @endif

    {{-- join application --}}
    @if($applyingCommunityId)
        @php($applyingCommunity = \App\Models\Community::find($applyingCommunityId))
        <div class="fixed inset-0 z-[70] flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm">
            <form wire:submit="submitApplication" class="w-full max-w-lg space-y-3 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 shadow-2xl">
                <h2 class="text-[15px] font-bold">Join {{ $applyingCommunity?->name }}</h2>
                @if($applyingCommunity?->onboarding_questions)
                    <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">{{ $applyingCommunity->onboarding_questions[0] ?? 'Introduce yourself' }}
                        <textarea wire:model="applicationAnswer" rows="4" maxlength="1000" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none"></textarea>
                    </label>
                @endif
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="$set('applyingCommunityId', null)" class="rounded-xl px-4 py-2 text-sm text-[var(--text-muted)]">Cancel</button>
                    <button class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-bold text-white">{{ $applyingCommunity?->visibility === 'approval' ? 'Request to join' : 'Join server' }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
