<?php

declare(strict_types=1);

use App\Services\Content\DefaultPageTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SF-70, D-055, D-058: template pages; idempotent by slug. The privacy policy and the consent pages are active
 * (the consent checkbox needs a document to link to, SF-71; the template marker stays at the top), the other three
 * are drafts. The privacy policy and the consent pages are wired into the legal settings unless the owner already chose other ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DefaultPageTemplates::all() as $page) {
            DB::table('pages')->insertOrIgnore([
                'title' => $page['title'],
                'slug' => $page['slug'],
                'content' => $page['content'],
                'is_active' => in_array($page['slug'], [DefaultPageTemplates::PRIVACY_SLUG, DefaultPageTemplates::CONSENT_SLUG], true),
                'meta_title' => null,
                'meta_description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->point('privacy_page_id', DefaultPageTemplates::PRIVACY_SLUG);
        $this->point('consent_page_id', DefaultPageTemplates::CONSENT_SLUG);
    }

    public function down(): void
    {
        foreach (DefaultPageTemplates::all() as $page) {
            // Only pages that still carry the untouched template text are removed.
            DB::table('pages')->where('slug', $page['slug'])->where('content', $page['content'])->delete();
        }

        foreach (['privacy_page_id', 'consent_page_id'] as $name) {
            $this->setPayload($name, null, onlyIfDangling: true);
        }
    }

    private function point(string $name, string $slug): void
    {
        $id = DB::table('pages')->where('slug', $slug)->value('id');

        if ($id === null) {
            return;
        }

        $current = DB::table('settings')->where('group', 'legal')->where('name', $name)->value('payload');

        // Only when the setting exists and is still empty.
        if ($current === null || json_decode((string) $current, true) !== null) {
            return;
        }

        $this->setPayload($name, (int) $id);
    }

    private function setPayload(string $name, ?int $id, bool $onlyIfDangling = false): void
    {
        $query = DB::table('settings')->where('group', 'legal')->where('name', $name);

        if ($onlyIfDangling) {
            $current = json_decode((string) $query->value('payload'), true);

            if ($current !== null && DB::table('pages')->where('id', $current)->exists()) {
                return;
            }
        }

        $query->update(['payload' => json_encode($id), 'updated_at' => now()]);
    }
};
