<?php

namespace Tests\Unit;

use App\Models\CommunityEmoji;
use App\Models\CommunityMember;
use App\Models\CommunityRole;
use App\Models\User;
use App\Support\LoungeFormatter;
use PHPUnit\Framework\TestCase;

class LoungeFormatterTest extends TestCase
{
    private function formatter(?int $viewerId = 1): LoungeFormatter
    {
        $member = new CommunityMember(['user_id' => 2, 'nickname' => 'Ren']);
        $member->setRelation('user', new User(['username' => 'phantom_ren', 'name' => 'Ren Amamiya']));

        $role = new CommunityRole(['name' => 'Moderator', 'mentionable' => true]);
        $role->id = 7;

        $emoji = new CommunityEmoji(['name' => 'wip', 'image_url' => 'https://example.test/wip.png']);

        return new LoungeFormatter(collect([$member]), collect([$role]), collect([$emoji]), $viewerId);
    }

    public function test_it_renders_bold_code_and_links(): void
    {
        $html = $this->formatter()->render('**bold** and `code` and https://example.test/a');

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<code', $html);
        $this->assertStringContainsString('>code</code>', $html);
        $this->assertStringContainsString('href="https://example.test/a"', $html);
    }

    public function test_shrug_is_not_treated_as_italic_markdown(): void
    {
        $html = $this->formatter()->render('¯\_(ツ)_/¯');

        $this->assertStringNotContainsString('<em>', $html);
        $this->assertStringContainsString('_', $html);
    }

    public function test_underscore_italics_still_work_at_word_boundaries(): void
    {
        $html = $this->formatter()->render('this is _very_ nice');

        $this->assertStringContainsString('<em>very</em>', $html);
    }

    public function test_it_highlights_the_viewers_own_mention(): void
    {
        $own = $this->formatter(viewerId: 2)->render('hey @phantom_ren');
        $other = $this->formatter(viewerId: 99)->render('hey @phantom_ren');

        $this->assertStringContainsString('@phantom_ren', $own);
        $this->assertStringContainsString('accent-primary', $own);
        $this->assertStringNotContainsString('accent-primary', $other);
    }

    public function test_it_renders_everyone_and_channel_refs_and_custom_emoji(): void
    {
        $html = $this->formatter()->render('@everyone see #general :wip:', ['general' => 5]);

        $this->assertStringContainsString('@everyone', $html);
        $this->assertStringContainsString('#general', $html);
        $this->assertStringContainsString('https://example.test/wip.png', $html);
    }

    public function test_it_extracts_mentions_roles_and_everyone(): void
    {
        $mentions = $this->formatter()->extractMentions('@phantom_ren @Moderator @everyone hi');

        $this->assertSame([2], $mentions['user_ids']);
        $this->assertSame([7], $mentions['role_ids']);
        $this->assertTrue($mentions['everyone']);
    }
}
