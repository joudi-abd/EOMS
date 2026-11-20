<?php

namespace Tests\Feature\Tasks;

use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Recurrence;
use App\Modules\Tasks\Services\RecurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Tasks\Concerns\TasksFixture;
use Tests\TestCase;

/** Tasks regressions found in the backend audit. */
class TaskHardeningTest extends TestCase
{
    use RefreshDatabase;
    use TasksFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTasks();
    }

    private function daily(array $snap, array $extra = []): array
    {
        return [
            'list_id' => $this->listOf($snap, 'normal'), 'frequency' => 'daily',
            'template' => ['title' => 'Daily check'], ...$extra,
        ];
    }

    public function test_editing_a_recurrence_after_todays_card_does_not_make_a_second_one_or_restart_it(): void
    {
        $snap = $this->board($this->mona, 'department', ['department_id' => $this->engineering->id]);
        $url = '/api/v1/tasks/boards/'.$snap['board']['id'].'/recurrences';
        $recurrence = $this->as($this->mona)->postJson($url, $this->daily($snap))->assertCreated()->json('data');
        $service = app(RecurrenceService::class);
        $this->assertSame(1, $service->run('2026-11-08'));

        $this->as($this->mona)->putJson('/api/v1/tasks/recurrences/'.$recurrence['id'], $this->daily($snap, ['template' => ['title' => 'Daily check (fixed)']]))->assertOk();
        $this->assertSame(0, $service->run('2026-11-08'));
        $this->assertSame(1, Card::where('recurrence_id', $recurrence['id'])->count());

        $this->as($this->mona)->postJson('/api/v1/tasks/recurrences/'.$recurrence['id'].'/stop')->assertOk();
        $this->as($this->mona)->putJson('/api/v1/tasks/recurrences/'.$recurrence['id'], $this->daily($snap))->assertOk();
        $this->assertFalse(Recurrence::find($recurrence['id'])->active);
    }

    public function test_todays_occurrence_is_made_even_when_the_stored_next_day_is_in_the_past(): void
    {
        $snap = $this->board($this->mona, 'department', ['department_id' => $this->engineering->id]);
        $recurrence = $this->as($this->mona)->postJson('/api/v1/tasks/boards/'.$snap['board']['id'].'/recurrences', $this->daily($snap))
            ->assertCreated()->json('data');
        // The scheduler was down for a few days.
        Recurrence::whereKey($recurrence['id'])->update(['next_run_on' => '2026-11-02']);

        $this->assertSame(1, app(RecurrenceService::class)->run('2026-11-08'));
    }

    public function test_the_last_owner_cannot_demote_themselves_through_add_member(): void
    {
        $snap = $this->board($this->mona, 'team');

        $this->as($this->mona)->postJson('/api/v1/tasks/boards/'.$snap['board']['id'].'/members', ['user_id' => $this->mona->id, 'role' => 'viewer'])
            ->assertUnprocessable();
    }

    public function test_an_archived_board_is_read_only(): void
    {
        $snap = $this->board($this->mona, 'team');
        $id = $snap['board']['id'];
        $this->as($this->mona)->postJson("/api/v1/tasks/boards/{$id}/archive", ['archived' => true])->assertOk();

        $this->as($this->mona)->postJson("/api/v1/tasks/boards/{$id}/labels", ['name' => 'X', 'color' => '#2563eb'])->assertConflict();
        $this->as($this->mona)->postJson("/api/v1/tasks/boards/{$id}/lists", ['name' => 'More'])->assertConflict();
        $this->as($this->mona)->patchJson("/api/v1/tasks/boards/{$id}", ['name' => 'Renamed'])->assertConflict();
        $this->as($this->mona)->postJson("/api/v1/tasks/boards/{$id}/archive", ['archived' => false])->assertOk();
    }

    public function test_an_svg_disguised_as_an_image_is_downloaded_not_rendered(): void
    {
        Storage::fake('local');
        $snap = $this->board($this->mona, 'team');
        $card = $this->card($this->mona, $snap);
        // A real file, so the type is sniffed from the content (as in production), not taken from the name.
        $path = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($path, '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="1" height="1"/></svg>');
        $svg = new UploadedFile($path, 'pic.png', null, null, true);
        $attachment = $this->as($this->mona)->postJson("/api/v1/tasks/cards/{$card['id']}/attachments", ['file' => $svg])->assertCreated()->json('data');

        $response = $this->as($this->mona)->get('/api/v1/tasks/attachments/'.$attachment['id'])->assertOk();
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
    }
}
