{{--
    Server settings (tabbed), channel settings and user profile modals.
--}}
@php
    $serverTabs = array_filter([
        'overview' => $canManageServer ? 'Overview' : null,
        'roles' => $canManageServer ? 'Roles' : null,
        'emoji' => $canManageServer || $permissions['manage_channels'] ? 'Emoji' : null,
        'members' => $permissions['manage_members'] ? 'Members' : null,
        'invites' => $permissions['manage_members'] ? 'Invites' : null,
        'audit' => $canManageServer || $permissions['manage_channels'] ? 'Audit log' : null,
        'moderation' => ($permissions['review_reports'] || $permissions['resolve_reports']) ? 'Moderation' : null,
        'danger' => 'Danger zone',
    ]);
@endphp

{{-- ================================================= server settings modal --}}
<div x-show="$wire.serverSettingsOpen" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center bg-black/70 p-3 backdrop-blur-sm" @click.self="$wire.set('serverSettingsOpen', false)">
    <div class="flex h-[86vh] w-full max-w-4xl overflow-hidden rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] shadow-2xl">
        <nav class="hidden w-52 shrink-0 space-y-0.5 overflow-y-auto bg-[var(--bg-page)] p-3 sm:block">
            <div class="mb-3 truncate px-2 text-[11px] font-bold uppercase tracking-wider text-[var(--text-dim)]">{{ $community->name }}</div>
            @foreach($serverTabs as $key => $label)
                <button wire:click="$set('serverTab', '{{ $key }}')" class="w-full rounded-lg px-2.5 py-2 text-left text-sm font-semibold transition {{ $serverTab === $key ? 'bg-[var(--bg-surface-elevated)] text-[var(--text-main)]' : ($key === 'danger' ? 'text-rose-400 hover:bg-rose-500/10' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]') }}">{{ $label }}</button>
            @endforeach
        </nav>

        <div class="flex min-w-0 flex-1 flex-col">
            <div class="flex h-12 shrink-0 items-center justify-between border-b border-[var(--border-subtle)] px-5">
                <h2 class="text-[15px] font-bold text-[var(--text-main)]">{{ $serverTabs[$serverTab] ?? 'Overview' }}</h2>
                <button wire:click="$set('serverSettingsOpen', false)" class="text-[var(--text-dim)] transition hover:text-[var(--text-main)]">✕</button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-5">
                @if($serverTab === 'overview' && $canManageServer)
                    <form wire:submit="saveServerSettings" class="space-y-3">
                        <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Server name
                            <input wire:model="serverName" maxlength="80" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                        </label>
                        @error('serverName') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                        <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Description
                            <textarea wire:model="serverDescription" rows="3" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none"></textarea>
                        </label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Icon URL
                                <input wire:model="serverIconUrl" placeholder="https://…" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                            </label>
                            <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Banner URL
                                <input wire:model="serverBannerUrl" placeholder="https://…" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                            </label>
                        </div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Topics (comma separated)
                            <input wire:model="serverTopics" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                        </label>
                        <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Rules
                            <textarea wire:model="serverRules" rows="3" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none"></textarea>
                        </label>
                        <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Who can join
                            <select wire:model="serverVisibility" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                                <option value="public">Public</option>
                                <option value="approval">Request to join</option>
                                <option value="invite">Invite only</option>
                            </select>
                        </label>
                        <div class="flex justify-end"><button class="rounded-xl accent-bg px-5 py-2 text-sm font-bold text-white">Save changes</button></div>
                    </form>
                @elseif($serverTab === 'roles' && $canManageServer)
                    <form wire:submit="createModeratorRole" class="space-y-3 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-4">
                        <div class="flex flex-wrap gap-3">
                            <input wire:model="newRoleName" placeholder="Role name" class="min-w-0 flex-1 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-main)] outline-none">
                            <input wire:model="newRoleColor" type="color" class="h-9 w-14 rounded-lg border border-[var(--border-subtle)] bg-[var(--bg-surface)]">
                        </div>
                        <div class="flex flex-wrap gap-4 text-sm text-[var(--text-muted)]">
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model="newRoleHoist" class="rounded"> Display separately</label>
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model="newRoleMentionable" class="rounded"> Mentionable</label>
                        </div>
                        <div class="grid gap-1.5 sm:grid-cols-2">
                            @foreach(\App\Models\Community::PERMISSIONS as $permission)
                                <label class="flex items-center gap-2 text-sm text-[var(--text-muted)]">
                                    <input type="checkbox" wire:model="rolePermissions" value="{{ $permission }}" class="rounded">
                                    {{ str_replace('_', ' ', ucfirst($permission)) }}
                                </label>
                            @endforeach
                        </div>
                        @error('newRoleName') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                        <button class="rounded-xl border border-[var(--border-subtle)] px-4 py-2 text-sm font-bold text-[var(--text-main)]">Create role</button>
                    </form>

                    <div class="mt-4 space-y-2">
                        @forelse($roles as $role)
                            <div wire:key="role-{{ $role->id }}" class="flex items-center gap-3 rounded-xl bg-[var(--bg-page)] p-3">
                                <span class="h-3 w-3 rounded-full" style="background: {{ $role->color ?: 'var(--text-dim)' }}"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="font-semibold text-[var(--text-main)]">{{ $role->name }}</div>
                                    <div class="truncate text-[11px] text-[var(--text-dim)]">{{ implode(', ', $role->permissions ?? []) ?: 'no permissions' }}</div>
                                </div>
                                <span class="text-[11px] text-[var(--text-dim)]">{{ $role->members()->count() }} members</span>
                                <button wire:click="deleteRole({{ $role->id }})" wire:confirm="Delete this role?" class="text-xs text-rose-400">Delete</button>
                            </div>
                        @empty
                            <p class="text-sm text-[var(--text-dim)]">No roles yet.</p>
                        @endforelse
                    </div>

                    @if($activeMembers->isNotEmpty() && $roles->isNotEmpty())
                        <form wire:submit="assignModeratorRole" class="mt-4 flex flex-wrap gap-2 rounded-xl bg-[var(--bg-page)] p-3">
                            <select wire:model="assignUserId" class="min-w-0 flex-1 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-main)]">
                                @foreach($activeMembers as $member)
                                    <option value="{{ $member->user_id }}">{{ $member->displayName() }}</option>
                                @endforeach
                            </select>
                            <select wire:model="assignRoleId" class="min-w-0 flex-1 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-main)]">
                                @foreach($roles as $role)
                                    <option value="{{ $role->id }}">{{ $role->name }}</option>
                                @endforeach
                            </select>
                            <button class="rounded-xl border border-[var(--border-subtle)] px-3 py-2 text-sm font-bold text-[var(--text-main)]">Assign role</button>
                        </form>
                    @endif
                @elseif($serverTab === 'emoji' && ($canManageServer || $permissions['manage_channels']))
                    <form wire:submit="createEmoji" class="flex flex-wrap gap-2 rounded-xl bg-[var(--bg-page)] p-3">
                        <input wire:model="newEmojiName" placeholder="emoji_name" class="min-w-0 flex-1 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-main)] outline-none">
                        <input wire:model="newEmojiUrl" placeholder="https://…/emoji.png" class="min-w-0 flex-[2] rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-main)] outline-none">
                        <button class="rounded-xl accent-bg px-4 py-2 text-sm font-bold text-white">Upload emoji</button>
                    </form>
                    @error('newEmojiName') <p class="mt-1 text-xs text-rose-400">{{ $message }}</p> @enderror
                    @error('newEmojiUrl') <p class="mt-1 text-xs text-rose-400">{{ $message }}</p> @enderror

                    <div class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-5">
                        @forelse($emoji as $custom)
                            <div wire:key="emoji-{{ $custom->id }}" class="group flex flex-col items-center gap-1 rounded-xl bg-[var(--bg-page)] p-3">
                                <img src="{{ $custom->image_url }}" alt=":{{ $custom->name }}:" class="h-10 w-10 object-contain">
                                <span class="w-full truncate text-center text-[11px] text-[var(--text-muted)]">:{{ $custom->name }}:</span>
                                <button wire:click="deleteEmoji({{ $custom->id }})" wire:confirm="Delete this emoji?" class="text-[11px] text-rose-400 opacity-0 transition group-hover:opacity-100">Delete</button>
                            </div>
                        @empty
                            <p class="col-span-full text-sm text-[var(--text-dim)]">No custom emoji yet.</p>
                        @endforelse
                    </div>
                @elseif($serverTab === 'members' && $permissions['manage_members'])
                    <div class="space-y-2">
                        @foreach($members as $member)
                            <div wire:key="manage-member-{{ $member->id }}" class="rounded-xl bg-[var(--bg-page)] p-3">
                                <div class="flex flex-wrap items-center gap-3">
                                    <x-lounge.avatar :user="$member->user" :presence="$member->presenceState()" :size="8" />
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-semibold text-[var(--text-main)]">{{ $member->displayName() }} <span class="text-[var(--text-dim)]">{{ '@'.$member->user?->username }}</span></div>
                                        <div class="text-[11px] text-[var(--text-dim)]">
                                            {{ $member->status }}
                                            @if($member->role) · {{ $member->role->name }} @endif
                                            @if($member->isTimedOut()) · timed out {{ $member->timeout_until->diffForHumans() }} @endif
                                        </div>
                                    </div>
                                    @if($member->user_id !== $community->owner_id)
                                        <div class="flex flex-wrap items-center gap-2 text-xs">
                                            @if($member->status === 'pending')
                                                <button wire:click="reviewMember({{ $member->user_id }}, 'approve')" class="rounded-lg bg-emerald-500/20 px-2 py-1 font-semibold text-emerald-400">Approve</button>
                                            @endif
                                            <button wire:click="$set('timeoutUserId', {{ $member->user_id }})" class="rounded-lg bg-amber-500/15 px-2 py-1 font-semibold text-amber-400">Timeout</button>
                                            <button wire:click="reviewMember({{ $member->user_id }}, 'remove')" wire:confirm="Kick this member?" class="rounded-lg bg-[var(--bg-surface)] px-2 py-1 font-semibold text-[var(--text-muted)]">Kick</button>
                                            @if($member->status !== 'banned')
                                                <button wire:click="reviewMember({{ $member->user_id }}, 'ban')" wire:confirm="Ban this member?" class="rounded-lg bg-rose-500/15 px-2 py-1 font-semibold text-rose-400">Ban</button>
                                            @else
                                                <button wire:click="reviewMember({{ $member->user_id }}, 'unban')" class="rounded-lg bg-[var(--bg-surface)] px-2 py-1 font-semibold text-[var(--text-muted)]">Unban</button>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-[11px] text-[var(--text-dim)]">Owner</span>
                                    @endif
                                </div>

                                @if($timeoutUserId === $member->user_id)
                                    <form wire:submit="timeoutMember({{ $member->user_id }})" class="mt-3 flex flex-wrap items-center gap-2 border-t border-[var(--border-subtle)] pt-3">
                                        <select wire:model="timeoutMinutes" class="rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-main)]">
                                            <option value="5">5 minutes</option>
                                            <option value="10">10 minutes</option>
                                            <option value="60">1 hour</option>
                                            <option value="1440">1 day</option>
                                            <option value="10080">1 week</option>
                                        </select>
                                        <input wire:model="timeoutReason" placeholder="Reason (optional)" class="min-w-0 flex-1 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-main)] outline-none">
                                        <button class="rounded-xl bg-amber-500 px-3 py-2 text-sm font-bold text-white">Apply timeout</button>
                                        <button type="button" wire:click="$set('timeoutUserId', null)" class="text-xs text-[var(--text-muted)]">Cancel</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @elseif($serverTab === 'invites' && $permissions['manage_members'])
                    <div class="flex flex-wrap items-center gap-2">
                        <button wire:click="createInvite" class="rounded-xl accent-bg px-4 py-2 text-sm font-bold text-white">Create invite</button>
                        @if($inviteUrl)
                            <code class="min-w-0 flex-1 break-all rounded-xl bg-[var(--bg-page)] px-3 py-2 text-xs text-[var(--accent-light)]">{{ $inviteUrl }}</code>
                        @endif
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse($invites as $invite)
                            <div wire:key="invite-{{ $invite->id }}" class="flex items-center gap-3 rounded-xl bg-[var(--bg-page)] p-3 text-xs">
                                <code class="min-w-0 flex-1 truncate text-[var(--text-muted)]">{{ route('lounge.invite', $invite->code) }}</code>
                                <span class="text-[var(--text-dim)]">{{ $invite->used_count }} uses</span>
                                <span class="text-[var(--text-dim)]">{{ $invite->expires_at ? \Illuminate\Support\Carbon::parse($invite->expires_at)->diffForHumans() : 'never expires' }}</span>
                                <button wire:click="revokeInvite({{ $invite->id }})" class="text-rose-400">Revoke</button>
                            </div>
                        @empty
                            <p class="text-sm text-[var(--text-dim)]">No active invites.</p>
                        @endforelse
                    </div>
                @elseif($serverTab === 'audit' && ($canManageServer || $permissions['manage_channels']))
                    <div class="space-y-2">@include('lounge.audit-list')</div>
                @elseif($serverTab === 'moderation' && ($permissions['review_reports'] || $permissions['resolve_reports']))
                    <div class="space-y-2">
                        @forelse($reports as $report)
                            <div wire:key="report-{{ $report->id }}" class="rounded-xl bg-[var(--bg-page)] p-3 text-sm">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-bold text-[var(--text-main)]">{{ $report->reason }}</span>
                                    <span class="text-[var(--text-dim)]">{{ $report->target_type }} #{{ $report->target_id }}</span>
                                    <span class="text-[var(--text-dim)]">by {{ $report->reporter_name }}</span>
                                </div>
                                @if($report->details) <p class="mt-1 text-[var(--text-muted)]">{{ $report->details }}</p> @endif
                                <div class="mt-2 flex gap-3 text-xs">
                                    <button wire:click="assignReport({{ $report->id }})" class="text-[var(--accent-light)]">Take report</button>
                                    @if($permissions['resolve_reports'])
                                        <button wire:click="resolveReport({{ $report->id }}, 'hide')" class="text-rose-400">Hide content</button>
                                        <button wire:click="resolveReport({{ $report->id }}, 'dismiss')" class="text-[var(--text-muted)]">Dismiss</button>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="py-8 text-center text-sm text-[var(--text-dim)]">No open reports. 🎉</p>
                        @endforelse
                    </div>
                @elseif($serverTab === 'danger')
                    <div class="space-y-4">
                        <div class="rounded-xl border border-rose-500/30 bg-rose-500/5 p-4">
                            <h3 class="font-bold text-rose-400">Leave {{ $community->name }}</h3>
                            <p class="mt-1 text-sm text-[var(--text-muted)]">You will lose access to its channels until you rejoin. The owner cannot leave.</p>
                            <button wire:click="leaveCommunity" wire:confirm="Leave this community?" class="mt-3 rounded-xl bg-rose-500 px-4 py-2 text-sm font-bold text-white">Leave server</button>
                        </div>
                        @if($permissions['manage_channels'])
                            <div class="rounded-xl border border-rose-500/30 bg-rose-500/5 p-4">
                                <h3 class="font-bold text-rose-400">Delete #{{ $activeChannel->name }}</h3>
                                <p class="mt-1 text-sm text-[var(--text-muted)]">Permanently deletes this channel, its messages, threads and pins.</p>
                                <button wire:click="deleteChannel({{ $activeChannel->id }})" wire:confirm="Delete #{{ $activeChannel->name }}? This cannot be undone." class="mt-3 rounded-xl border border-rose-500/40 px-4 py-2 text-sm font-bold text-rose-400">Delete channel</button>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ================================================ channel settings modal --}}
<div x-show="$wire.channelSettingsOpen" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center bg-black/70 p-3 backdrop-blur-sm" @click.self="$wire.set('channelSettingsOpen', false)">
    <form wire:submit="saveChannelSettings" class="w-full max-w-xl space-y-3 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 shadow-2xl">
        <div class="flex items-center justify-between">
            <h2 class="text-[15px] font-bold text-[var(--text-main)]">Channel settings</h2>
            <button type="button" wire:click="$set('channelSettingsOpen', false)" class="text-[var(--text-dim)] hover:text-[var(--text-main)]">✕</button>
        </div>
        <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Channel name
            <input wire:model="settingsChannelName" maxlength="80" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
        </label>
        @error('settingsChannelName') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
        <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Topic
            <textarea wire:model="settingsChannelTopic" rows="2" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none"></textarea>
        </label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Category
                <select wire:model="settingsCategoryId" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                    <option value="">No category</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Slowmode
                <select wire:model="settingsSlowmode" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                    <option value="0">Off</option>
                    <option value="5">5 seconds</option>
                    <option value="10">10 seconds</option>
                    <option value="30">30 seconds</option>
                    <option value="60">1 minute</option>
                    <option value="300">5 minutes</option>
                    <option value="3600">1 hour</option>
                </select>
            </label>
        </div>
        <div class="flex flex-wrap gap-4 text-sm text-[var(--text-muted)]">
            <label class="flex items-center gap-2"><input type="checkbox" wire:model="settingsReadOnly" class="rounded"> Read only</label>
            <label class="flex items-center gap-2"><input type="checkbox" wire:model="settingsNsfw" class="rounded"> Age restricted</label>
        </div>
        <div class="flex items-center justify-between pt-1">
            <button type="button" wire:click="deleteChannel({{ $settingsChannelId }})" wire:confirm="Delete this channel permanently?" class="text-sm font-semibold text-rose-400">Delete channel</button>
            <div class="flex gap-2">
                <button type="button" wire:click="$set('channelSettingsOpen', false)" class="rounded-xl px-4 py-2 text-sm text-[var(--text-muted)]">Cancel</button>
                <button class="rounded-xl accent-bg px-5 py-2 text-sm font-bold text-white">Save changes</button>
            </div>
        </div>
    </form>
</div>

{{-- =================================================== user settings modal --}}
<div x-show="$wire.userSettingsOpen" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center bg-black/70 p-3 backdrop-blur-sm" @click.self="$wire.set('userSettingsOpen', false)">
    <form wire:submit="saveUserSettings" class="w-full max-w-lg space-y-3 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 shadow-2xl">
        <div class="flex items-center justify-between">
            <h2 class="text-[15px] font-bold text-[var(--text-main)]">Profile &amp; status</h2>
            <button type="button" wire:click="$set('userSettingsOpen', false)" class="text-[var(--text-dim)] hover:text-[var(--text-main)]">✕</button>
        </div>

        <div class="flex items-center gap-3 rounded-xl bg-[var(--bg-page)] p-3">
            <x-lounge.avatar :user="Auth::user()" :presence="$presence" :size="12" />
            <div class="min-w-0">
                <div class="truncate font-bold text-[var(--text-main)]">{{ Auth::user()->name }}</div>
                <div class="text-xs text-[var(--text-dim)]">{{ '@'.Auth::user()->username }}</div>
            </div>
        </div>

        <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Server nickname
            <input wire:model="nickname" maxlength="40" placeholder="Shown only in {{ $community->name }}" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
        </label>
        <div class="grid grid-cols-[80px_1fr] gap-2">
            <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Emoji
                <input wire:model="statusEmoji" maxlength="16" placeholder="🎨" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-center text-lg text-[var(--text-main)] outline-none">
            </label>
            <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Custom status
                <input wire:model="statusText" maxlength="140" placeholder="What are you working on?" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
            </label>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Status
                <select wire:model="presence" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                    <option value="online">Online</option>
                    <option value="idle">Idle</option>
                    <option value="dnd">Do Not Disturb</option>
                    <option value="invisible">Invisible</option>
                </select>
            </label>
            <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Notifications
                <select wire:model="notifyLevel" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                    <option value="all">All messages</option>
                    <option value="mentions">Only @mentions</option>
                    <option value="none">Nothing</option>
                </select>
            </label>
        </div>
        @error('nickname') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
        @error('statusText') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror

        <div class="flex items-center justify-between pt-1">
            <a href="{{ route('settings') }}" class="text-xs font-semibold text-[var(--accent-light)] hover:underline">App appearance &amp; theme →</a>
            <div class="flex gap-2">
                <button type="button" wire:click="$set('userSettingsOpen', false)" class="rounded-xl px-4 py-2 text-sm text-[var(--text-muted)]">Cancel</button>
                <button class="rounded-xl accent-bg px-5 py-2 text-sm font-bold text-white">Save</button>
            </div>
        </div>
    </form>
</div>

{{-- ========================================================= standalone audit --}}
<div x-show="$wire.auditOpen" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center bg-black/70 p-3 backdrop-blur-sm" @click.self="$wire.set('auditOpen', false)">
    <div class="flex h-[80vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] shadow-2xl">
        <div class="flex h-12 shrink-0 items-center justify-between border-b border-[var(--border-subtle)] px-5">
            <h2 class="text-[15px] font-bold text-[var(--text-main)]">Audit log</h2>
            <button wire:click="$set('auditOpen', false)" class="text-[var(--text-dim)] hover:text-[var(--text-main)]">✕</button>
        </div>
        <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-4">
            @include('lounge.audit-list')
        </div>
    </div>
</div>
