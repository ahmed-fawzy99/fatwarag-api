<?php

namespace Tests\Feature\Api;

use App\Models\ContactMessage;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\MakesContent;
use Tests\TestCase;

class ReportContactTest extends TestCase
{
    use MakesContent;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fatwarag.telegram.token' => 'test-token',
            'fatwarag.telegram.chat_id' => '42',
            'fatwarag.site_url' => 'https://fatwarag.test',
            'fatwarag.admin_url' => 'https://api.fatwarag.test/admin',
        ]);
    }

    private function fakeHttp(bool $turnstile = true, bool $telegram = true): void
    {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => $turnstile]),
            'api.telegram.org/*' => $telegram ? Http::response(['ok' => true]) : Http::response(['ok' => false], 500),
        ]);
    }

    private function sentToTelegram(): ?string
    {
        $sent = Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.telegram.org'));

        return $sent->isEmpty() ? null : $sent->first()[0]['text'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function reportPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'reason' => 'misrepresents',
            'comment' => 'The second point is not what the fatwa says.',
            'locale' => 'en',
            'turnstileToken' => 'ok-token',
            'context' => [
                'pageUrl' => 'https://fatwarag.test/en/ask?q=wudu',
                'part' => 'summary',
                'item' => [],
                'question' => 'Does touching a woman break wudu?',
                'summary' => ['kind' => 'differ', 'headline' => 'Sources differ on touching', 'agreed' => null, 'views' => [['title' => 'View one', 'heldBy' => 'IslamQA', 'body' => 'It does not break wudu.', 'citations' => ['islamqa-1'], 'evidence' => []]], 'preferences' => [], 'caution' => null],
            ],
        ], $overrides);
    }

    public function test_report_saved_and_telegram_message_has_full_context(): void
    {
        $this->fakeHttp();
        $fatwa = $this->makeFatwa(['title' => 'حكم لمس المرأة']);
        $referrer = $this->makeFatwa(['title' => 'فتوى مرجعية'], 'islamqa');

        $response = $this->postJson('/api/v1/reports', $this->reportPayload([
            'reason' => 'wrongLink',
            'context' => [
                'part' => 'fatwa',
                'pageUrl' => 'https://fatwarag.test/en/ask?q=wudu',
                'item' => ['fatwaId' => $fatwa->public_id],
                'summary' => null,
                'quote' => 'the quoted words',
                'pointedTo' => 'Quran 2:255',
                'referrerFatwaId' => $referrer->public_id,
            ],
        ]), ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0']);

        $response->assertCreated();
        $report = Report::query()->sole();
        $this->assertSame('wrongLink', $report->reason);
        $this->assertSame('en', $report->locale);
        $this->assertSame($fatwa->public_id, $report->context['item']['fatwaId']);
        $this->assertStringContainsString('Firefox', $report->context['device']);
        $this->assertNotNull($report->notified_at);

        $text = $this->sentToTelegram();
        $this->assertNotNull($text, 'the owner was messaged');
        $this->assertStringContainsString('Wrong link or highlight', $text);
        $this->assertStringContainsString('The second point is not what the fatwa says.', $text);
        $this->assertStringContainsString('https://fatwarag.test/en/ask?q=wudu', $text, 'the page it was reported on');
        $this->assertStringContainsString('English', $text, 'the page language');
        $this->assertStringContainsString('Fatwa in the answer list', $text, 'the part of the page');
        $this->assertStringContainsString('حكم لمس المرأة', $text, 'the item title');
        $this->assertStringContainsString($fatwa->public_id, $text);
        $this->assertStringContainsString("https://fatwarag.test/en/fatwas/{$fatwa->public_id}", $text, 'the link in the app');
        $this->assertStringContainsString($fatwa->url, $text, 'the link on the original source');
        $this->assertStringContainsString('Does touching a woman break wudu?', $text, 'the question');
        $this->assertStringContainsString('the quoted words', $text, 'the quote for a wrong link');
        $this->assertStringContainsString('Quran 2:255', $text, 'where it pointed');
        $this->assertStringContainsString('Referring fatwa: فتوى مرجعية', $text, 'the fatwa whose quote link was followed');
        $this->assertStringContainsString("https://fatwarag.test/en/fatwas/{$referrer->public_id}", $text);
        $this->assertStringContainsString($referrer->url, $text);
        $this->assertStringContainsString("https://api.fatwarag.test/admin/reports/{$report->id}", $text, 'the admin link');
        $this->assertStringContainsString('Firefox', $text, 'the browser');
    }

    public function test_summary_report_carries_the_summary_shown(): void
    {
        $this->fakeHttp();

        $this->postJson('/api/v1/reports', $this->reportPayload())->assertCreated();

        $text = $this->sentToTelegram();
        $this->assertStringContainsString('AI summary', $text);
        $this->assertStringContainsString('Sources differ on touching', $text);
        $this->assertStringContainsString('View one', $text);
        $this->assertStringContainsString('It does not break wudu.', $text);
        $this->assertStringContainsString('IslamQA', $text);
    }

    public function test_a_summary_in_parts_is_reported_part_by_part(): void
    {
        $this->fakeHttp();
        $point = fn (string $body): array => ['title' => 'ما اتفقت عليه المصادر', 'heldBy' => null, 'body' => $body, 'citations' => ['binbaz-1'], 'evidence' => []];
        $summary = ['kind' => 'agree', 'headline' => 'سؤالك من جزأين', 'agreed' => null, 'views' => [], 'preferences' => [], 'caution' => null, 'parts' => [
            ['label' => 'إذا كان الشك أثناء الصلاة', 'kind' => 'agree', 'headline' => 'يبني على الأقل', 'agreed' => $point('يبني على الأقل ويسجد للسهو.'), 'views' => [], 'preferences' => [], 'caution' => null],
            ['label' => 'إذا كان الشك بعد السلام', 'kind' => 'unanswered', 'headline' => null, 'agreed' => null, 'views' => [], 'preferences' => [], 'caution' => null],
        ]];

        $this->postJson('/api/v1/reports', $this->reportPayload(['context' => ['summary' => $summary]]))->assertCreated();

        $text = $this->sentToTelegram();
        $this->assertStringContainsString('Part 1: إذا كان الشك أثناء الصلاة', $text);
        $this->assertStringContainsString('يبني على الأقل ويسجد للسهو.', $text);
        $this->assertStringContainsString('لم نجد في الفتاوى أدناه جوابًا عن: إذا كان الشك بعد السلام', $text);
    }

    public function test_a_long_summary_in_parts_never_pushes_the_admin_link_out_of_the_message(): void
    {
        $this->fakeHttp();
        $long = str_repeat('كلمة طويلة ', 400);
        $part = ['label' => mb_substr($long, 0, 300), 'kind' => 'agree', 'headline' => mb_substr($long, 0, 600), 'agreed' => ['title' => 't', 'heldBy' => null, 'body' => $long, 'citations' => [], 'evidence' => []], 'views' => [], 'preferences' => [], 'caution' => null];

        $this->postJson('/api/v1/reports', $this->reportPayload(['comment' => substr($long, 0, 900), 'context' => ['question' => mb_substr($long, 0, 1900), 'summary' => ['kind' => 'agree', 'headline' => 'سؤالك من جزأين', 'agreed' => null, 'views' => [], 'preferences' => [], 'caution' => null, 'parts' => [$part, $part]]]]))->assertCreated();

        $text = $this->sentToTelegram();
        $this->assertLessThan(4000, mb_strlen($text));
        $this->assertStringContainsString('/admin/reports/', $text);
    }

    public function test_report_from_a_shared_answer_page_carries_the_share_id(): void
    {
        $this->fakeHttp();

        $this->postJson('/api/v1/reports', $this->reportPayload(['context' => ['pageUrl' => 'https://fatwarag.test/s/AbCdEf123456', 'shareId' => 'AbCdEf123456']]))
            ->assertCreated();

        $this->assertSame('AbCdEf123456', Report::query()->sole()->context['shareId']);
    }

    public function test_quran_and_hadith_reports_link_the_item(): void
    {
        $this->fakeHttp();
        $hadith = $this->makeHadith('نص');
        $hadith->update(['lookup_url' => 'https://dorar.net/hadith/search?q=x']);

        $this->postJson('/api/v1/reports', $this->reportPayload(['reason' => 'other', 'context' => ['part' => 'quran', 'item' => ['surah' => 2, 'ayah' => 255], 'summary' => null, 'question' => null]]))->assertCreated();
        $quran = $this->sentToTelegram();
        $this->assertStringContainsString('Quran 2:255', $quran);
        $this->assertStringContainsString('https://fatwarag.test/en/quran/2?ayah=255', $quran);
        $this->assertStringContainsString('https://quran.com/2/255', $quran);

        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true]), 'api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->postJson('/api/v1/reports', $this->reportPayload(['reason' => 'other', 'context' => ['part' => 'hadith', 'item' => ['collection' => 'dorar', 'number' => $hadith->id], 'summary' => null, 'question' => null]]))->assertCreated();
        $text = $this->sentToTelegram();
        $this->assertStringContainsString("https://fatwarag.test/en/hadith/dorar/{$hadith->id}", $text);
        $this->assertStringContainsString('https://dorar.net/hadith/search?q=x', $text);
    }

    public function test_report_for_a_fatwa_that_no_longer_exists_still_saves(): void
    {
        $this->fakeHttp();

        $this->postJson('/api/v1/reports', $this->reportPayload(['context' => ['part' => 'fatwa', 'item' => ['fatwaId' => 'islamweb-gone'], 'summary' => null]]))->assertCreated();

        $this->assertSame(1, Report::query()->count());
        $this->assertStringContainsString('islamweb-gone', $this->sentToTelegram());
    }

    public function test_report_validation(): void
    {
        $this->fakeHttp();

        $this->postJson('/api/v1/reports', $this->reportPayload(['reason' => 'nonsense']))->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->postJson('/api/v1/reports', $this->reportPayload(['comment' => str_repeat('a', 1001)]))->assertStatus(422);
        $this->postJson('/api/v1/reports', $this->reportPayload(['locale' => 'fr']))->assertStatus(422);
        $this->postJson('/api/v1/reports', $this->reportPayload(['context' => ['part' => 'elsewhere']]))->assertStatus(422);

        $payload = $this->reportPayload();
        unset($payload['context']);
        $this->postJson('/api/v1/reports', $payload)->assertStatus(422);

        $this->assertSame(0, Report::query()->count());
    }

    public function test_contact_saved_and_owner_messaged(): void
    {
        $this->fakeHttp();

        $this->postJson('/api/v1/contact', [
            'name' => 'Aisha', 'email' => 'aisha@example.com', 'message' => 'Please add more fatwas about zakat.', 'locale' => 'ar', 'turnstileToken' => 'ok',
        ])->assertCreated();

        $message = ContactMessage::query()->sole();
        $this->assertSame('aisha@example.com', $message->email);
        $this->assertNotNull($message->notified_at);
        $text = $this->sentToTelegram();
        $this->assertStringContainsString('aisha@example.com', $text);
        $this->assertStringContainsString('Aisha', $text);
        $this->assertStringContainsString('Please add more fatwas about zakat.', $text);
        $this->assertStringContainsString('Arabic', $text);
        $this->assertStringContainsString("https://api.fatwarag.test/admin/contact-messages/{$message->id}", $text);
    }

    public function test_contact_requires_email(): void
    {
        $this->fakeHttp();
        $base = ['message' => 'A long enough message.', 'locale' => 'en', 'turnstileToken' => 'ok'];

        $this->postJson('/api/v1/contact', $base)->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->postJson('/api/v1/contact', $base + ['email' => 'not-an-email'])->assertStatus(422);
        $this->postJson('/api/v1/contact', ['email' => 'a@b.co', 'message' => 'short', 'locale' => 'en', 'turnstileToken' => 'ok'])->assertStatus(422);

        $this->assertSame(0, ContactMessage::query()->count());
    }

    public function test_name_is_optional_for_contact(): void
    {
        $this->fakeHttp();

        $this->postJson('/api/v1/contact', ['name' => null, 'email' => 'a@b.co', 'message' => 'A long enough message.', 'locale' => 'en', 'turnstileToken' => 'ok'])->assertCreated();

        $this->assertNull(ContactMessage::query()->sole()->name);
    }

    public function test_turnstile_required(): void
    {
        $this->fakeHttp(turnstile: false);
        $contact = ['email' => 'a@b.co', 'message' => 'A long enough message.', 'locale' => 'en'];

        // Rejected by Cloudflare.
        $this->postJson('/api/v1/contact', $contact + ['turnstileToken' => 'bad'])->assertForbidden()->assertJsonPath('error.code', 'turnstile_failed');
        $this->postJson('/api/v1/reports', $this->reportPayload(['turnstileToken' => 'bad']))->assertForbidden()->assertJsonPath('error.code', 'turnstile_failed');

        // No token at all.
        $this->postJson('/api/v1/contact', $contact)->assertForbidden()->assertJsonPath('error.code', 'turnstile_failed');
        $payload = $this->reportPayload();
        unset($payload['turnstileToken']);
        $this->postJson('/api/v1/reports', $payload)->assertForbidden()->assertJsonPath('error.code', 'turnstile_failed');

        $this->assertSame(0, Report::query()->count());
        $this->assertSame(0, ContactMessage::query()->count());
        $this->assertNull($this->sentToTelegram());
    }

    public function test_telegram_failure_still_saves(): void
    {
        $this->fakeHttp(telegram: false);

        $this->postJson('/api/v1/reports', $this->reportPayload())->assertCreated();
        $this->postJson('/api/v1/contact', ['email' => 'a@b.co', 'message' => 'A long enough message.', 'locale' => 'en', 'turnstileToken' => 'ok'])->assertCreated();

        $this->assertNull(Report::query()->sole()->notified_at);
        $this->assertNull(ContactMessage::query()->sole()->notified_at);
    }

    public function test_long_text_never_pushes_the_admin_link_out_of_the_message(): void
    {
        $this->fakeHttp();
        $long = str_repeat('كلمة طويلة ', 400);

        $this->postJson('/api/v1/reports', $this->reportPayload(['comment' => substr($long, 0, 900), 'context' => ['question' => mb_substr($long, 0, 1900), 'summary' => ['kind' => 'agree', 'headline' => $long, 'agreed' => ['title' => 't', 'heldBy' => null, 'body' => $long, 'citations' => [], 'evidence' => []], 'views' => [], 'preferences' => [], 'caution' => null]]]))->assertCreated();

        $text = $this->sentToTelegram();
        $this->assertLessThan(4000, mb_strlen($text));
        $this->assertStringContainsString('/admin/reports/', $text);
    }

    public function test_turnstile_sees_the_visitors_ip_not_the_bffs(): void
    {
        $this->fakeHttp();
        config(['fatwarag.bff_key' => 'bff-secret']);
        $headers = ['X-BFF-Key' => 'bff-secret', 'X-Client-IP' => '203.0.113.7'];

        $this->postJson('/api/v1/reports', $this->reportPayload(), $headers)->assertCreated();
        $this->postJson('/api/v1/contact', ['email' => 'a@b.co', 'message' => 'A long enough message.', 'locale' => 'en', 'turnstileToken' => 'ok'], $headers)->assertCreated();

        $checks = Http::recorded(fn (Request $request) => str_contains($request->url(), 'challenges.cloudflare.com'));
        $this->assertCount(2, $checks);
        foreach ($checks as [$request]) {
            $this->assertSame('203.0.113.7', $request['remoteip']);
        }

        // A BFF call without the visitor's IP is refused rather than counted against the BFF's own address.
        $this->postJson('/api/v1/reports', $this->reportPayload(), ['X-BFF-Key' => 'bff-secret'])->assertStatus(400);
    }

    public function test_oversized_context_is_rejected(): void
    {
        $this->fakeHttp();

        $this->postJson('/api/v1/reports', $this->reportPayload(['context' => ['summary' => ['headline' => str_repeat('x', 120_000)]]]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation');
    }
}
