<?php

use App\Models\Tag;
use App\Models\TagHistory;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public string $name = '';

    public string $type = 'general';

    public string $shortDescription = '';

    public string $wikiSummary = '';

    public string $wikiUsage = '';

    public string $wikiDoNotUse = '';

    public string $wikiExamples = '';

    public string $wikiNotes = '';

    public string $editSummary = '';

    public string $activeMode = 'edit'; // 'edit', 'preview'

    public string $message = '';

    public function mount(string $name): void
    {
        $this->name = Tag::normalizeName($name);
        $tag = Tag::where('name', $this->name)->orWhere('slug', $this->name)->firstOrFail();

        $this->type = $tag->type;
        $this->shortDescription = $tag->short_description ?? '';
        $this->wikiSummary = $tag->wiki_summary ?? '';
        $this->wikiUsage = $tag->wiki_usage ?? '';
        $this->wikiDoNotUse = $tag->wiki_do_not_use ?? '';
        $this->wikiExamples = $tag->wiki_examples ?? '';
        $this->wikiNotes = $tag->wiki_notes ?? '';
    }

    public function saveWiki(): void
    {
        if (! Auth::check()) {
            $this->message = 'You must be logged in to edit tag wikis.';

            return;
        }

        if (empty(trim($this->editSummary))) {
            $this->message = 'Please provide a brief edit summary explaining your changes.';

            return;
        }

        $tag = Tag::where('name', $this->name)->firstOrFail();

        if ($tag->is_locked && ! Auth::user()->isAdmin()) {
            $this->message = 'This tag wiki is locked and cannot be edited directly.';

            return;
        }

        $oldWikiData = [
            'type' => $tag->type,
            'short_description' => $tag->short_description,
            'wiki_summary' => $tag->wiki_summary,
            'wiki_usage' => $tag->wiki_usage,
            'wiki_do_not_use' => $tag->wiki_do_not_use,
            'wiki_examples' => $tag->wiki_examples,
            'wiki_notes' => $tag->wiki_notes,
        ];

        $tag->update([
            'type' => $this->type,
            'short_description' => $this->shortDescription ?: null,
            'wiki_summary' => $this->wikiSummary ?: null,
            'wiki_usage' => $this->wikiUsage ?: null,
            'wiki_do_not_use' => $this->wikiDoNotUse ?: null,
            'wiki_examples' => $this->wikiExamples ?: null,
            'wiki_notes' => $this->wikiNotes ?: null,
        ]);

        $newWikiData = [
            'type' => $tag->type,
            'short_description' => $tag->short_description,
            'wiki_summary' => $tag->wiki_summary,
            'wiki_usage' => $tag->wiki_usage,
            'wiki_do_not_use' => $tag->wiki_do_not_use,
            'wiki_examples' => $tag->wiki_examples,
            'wiki_notes' => $tag->wiki_notes,
        ];

        TagHistory::create([
            'tag_id' => $tag->id,
            'user_id' => Auth::id(),
            'action' => 'wiki_edit',
            'old_wiki' => $oldWikiData,
            'new_wiki' => $newWikiData,
            'edit_summary' => $this->editSummary,
        ]);

        session()->flash('message', 'Tag wiki updated successfully!');

        $this->redirect(route('tags.show', ['name' => $tag->name, 'tab' => 'wiki']), navigate: true);
    }
}; ?>

<div class="min-h-screen pb-16">
    <div class="border-b border-[var(--border-subtle)] bg-[var(--bg-surface)] px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <div class="flex items-center justify-between">
                <div>
                    <a href="{{ route('tags.show', $name) }}" class="text-xs font-bold accent-text hover:underline flex items-center gap-1">
                        ← Back to #{{ $name }}
                    </a>
                    <h1 class="mt-2 text-2xl sm:text-3xl font-black text-[var(--text-main)]">
                        Edit Wiki — #{{ $name }}
                    </h1>
                </div>

                <div class="flex items-center rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] p-1">
                    <button wire:click="$set('activeMode', 'edit')" 
                            class="rounded-xl px-4 py-2 text-xs font-bold transition {{ $activeMode === 'edit' ? 'accent-bg text-white shadow' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                        Edit Form
                    </button>
                    <button wire:click="$set('activeMode', 'preview')" 
                            class="rounded-xl px-4 py-2 text-xs font-bold transition {{ $activeMode === 'preview' ? 'accent-bg text-white shadow' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                        Live Preview
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
        @if($message)
            <div class="mb-6 rounded-2xl bg-rose-500/10 border border-rose-500/20 p-4 text-xs font-bold text-rose-500">
                {{ $message }}
            </div>
        @endif

        @if($activeMode === 'edit')
            <div class="rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 sm:p-8 shadow-lg space-y-6">
                <!-- Tag Category -->
                <div>
                    <label class="block text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">
                        Category *
                    </label>
                    <select wire:model="type" class="w-full rounded-2xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-3 text-sm text-[var(--text-main)] focus:outline-none">
                        <option value="general">General</option>
                        <option value="artist">Artist</option>
                        <option value="character">Character</option>
                        <option value="copyright">Copyright / Series</option>
                        <option value="meta">Meta</option>
                    </select>
                </div>

                <!-- Short Description -->
                <div>
                    <label class="block text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">
                        Short Description
                    </label>
                    <input type="text" 
                           wire:model="shortDescription" 
                           placeholder="Header summary shown on tag hover & cards..." 
                           class="w-full rounded-2xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-3 text-sm text-[var(--text-main)] focus:outline-none">
                </div>

                <!-- Wiki Summary -->
                <div>
                    <label class="block text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">
                        Summary
                    </label>
                    <textarea wire:model="wikiSummary" 
                              rows="4" 
                              placeholder="Overview of what this tag represents..." 
                              class="w-full rounded-2xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-3 text-sm text-[var(--text-main)] focus:outline-none"></textarea>
                </div>

                <!-- Usage Guidelines -->
                <div>
                    <label class="block text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">
                        Usage Guidelines
                    </label>
                    <textarea wire:model="wikiUsage" 
                              rows="4" 
                              placeholder="When should users apply this tag to posts?" 
                              class="w-full rounded-2xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-3 text-sm text-[var(--text-main)] focus:outline-none"></textarea>
                </div>

                <!-- Do Not Use When -->
                <div>
                    <label class="block text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">
                        Do Not Use When
                    </label>
                    <textarea wire:model="wikiDoNotUse" 
                              rows="4" 
                              placeholder="Common tagging mistakes or exclusions..." 
                              class="w-full rounded-2xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-3 text-sm text-[var(--text-main)] focus:outline-none"></textarea>
                </div>

                <!-- Examples & Notes -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">
                            Examples
                        </label>
                        <textarea wire:model="wikiExamples" 
                                  rows="3" 
                                  placeholder="List example scenarios..." 
                                  class="w-full rounded-2xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-3 text-sm text-[var(--text-main)] focus:outline-none"></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">
                            Notes & Edge Cases
                        </label>
                        <textarea wire:model="wikiNotes" 
                                  rows="3" 
                                  placeholder="Additional nuances or notes..." 
                                  class="w-full rounded-2xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-3 text-sm text-[var(--text-main)] focus:outline-none"></textarea>
                    </div>
                </div>

                <!-- Edit Summary -->
                <div class="border-t border-[var(--border-subtle)] pt-6">
                    <label class="block text-xs font-bold text-[var(--text-main)] uppercase tracking-wider mb-2">
                        Edit Summary * (Required)
                    </label>
                    <input type="text" 
                           wire:model="editSummary" 
                           placeholder="Explain what was changed (e.g. 'Clarified landscape usage')" 
                           class="w-full rounded-2xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-3 text-sm text-[var(--text-main)] focus:border-[var(--accent-primary)] focus:outline-none">
                </div>

                <!-- Submit Button -->
                <div class="flex items-center justify-end gap-3 pt-4">
                    <a href="{{ route('tags.show', $name) }}" class="rounded-2xl border border-[var(--border-subtle)] px-5 py-3 text-xs font-bold text-[var(--text-muted)] hover:text-[var(--text-main)]">
                        Cancel
                    </a>
                    <button wire:click="saveWiki" class="rounded-2xl accent-bg px-6 py-3 text-xs font-bold text-white shadow-lg hover:brightness-110">
                        Submit Changes
                    </button>
                </div>
            </div>
        @else
            <!-- Live Preview Mode -->
            <div class="rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 sm:p-8 shadow-lg space-y-6">
                <div class="border-b border-[var(--border-subtle)] pb-4">
                    <span class="text-xs font-bold text-[var(--text-muted)] uppercase">Live Wiki Preview</span>
                    <h2 class="text-2xl font-black text-[var(--text-main)]">#{{ $name }}</h2>
                    <p class="mt-1 text-xs text-[var(--text-muted)]">{{ $shortDescription ?: 'No short description' }}</p>
                </div>

                <div class="space-y-4 text-sm text-[var(--text-main)]">
                    <div>
                        <h4 class="text-xs font-bold uppercase text-[var(--text-muted)]">Summary</h4>
                        <p class="mt-1 whitespace-pre-line leading-relaxed">{{ $wikiSummary ?: 'No summary' }}</p>
                    </div>

                    @if($wikiUsage)
                        <div>
                            <h4 class="text-xs font-bold uppercase text-emerald-500">Usage</h4>
                            <p class="mt-1 whitespace-pre-line leading-relaxed bg-[var(--bg-page)] p-3 rounded-xl border border-[var(--border-subtle)]">{{ $wikiUsage }}</p>
                        </div>
                    @endif

                    @if($wikiDoNotUse)
                        <div>
                            <h4 class="text-xs font-bold uppercase text-rose-500">Do Not Use When</h4>
                            <p class="mt-1 whitespace-pre-line leading-relaxed bg-[var(--bg-page)] p-3 rounded-xl border border-rose-500/20">{{ $wikiDoNotUse }}</p>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
