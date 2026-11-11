<?php

namespace Tests\Feature\Tasks;

use App\Modules\HR\Models\LeavePolicy;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\OfficeHoliday;
use App\Modules\HR\Services\EmployeeTerminationService;
use App\Modules\Tasks\Models\Board;
use App\Modules\Tasks\Models\BoardMember;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Recurrence;
use App\Modules\Tasks\Services\RecurrenceService;
use App\Modules\Tasks\Services\ReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Tasks\Concerns\TasksFixture;
use Tests\TestCase;

class TaskAutomationTest extends TestCase
{
    use RefreshDatabase;
    use TasksFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTasks();
    }

    public function test_due_reminders_are_sent_once_per_due_date(): void
    {
        $snap = $this->board($this->mona, 'department', ['department_id' => $this->engineering->id]);
        // Sunday 8 Nov: the next working day is Monday 9.
        $soon = $this->card($this->ali, $snap, ['title' => 'Soon', 'due_date' => '2026-11-09', 'assignee_ids' => [$this->sara->id]]);
        $this->card($this->ali, $snap, ['title' => 'Later', 'due_date' => '2026-11-12', 'assignee_ids' => [$this->sara->id]]);
        $late = $this->card($this->ali, $snap, ['title' => 'Late', 'due_date' => '2026-11-05', 'assignee_ids' => [$this->sara->id]]);
        $doneLate = $this->card($this->ali, $snap, ['title' => 'Waiting review', 'due_date' => '2026-11-04', 'assignee_ids' => [$this->sara->id]]);
        $this->move($this->sara, $doneLate, $this->listOf($snap, 'done'))->assertOk();

        $this->assertSame(['soon' => 1, 'overdue' => 1], app(ReminderService::class)->run());
        $this->assertSame(['soon' => 0, 'overdue' => 0], app(ReminderService::class)->run());
        $this->assertSame(1, $this->notified($this->sara, 'tasks.card_due_soon'));
        $this->assertSame(1, $this->notified($this->sara, 'tasks.card_overdue'));
        // A new due date re-arms the reminder.
        $this->as($this->ali)->patchJson("/api/v1/tasks/cards/{$late['id']}", ['version' => Card::find($late['id'])->version, 'due_date' => '2026-11-06'])->assertOk();
        $this->assertSame(['soon' => 0, 'overdue' => 1], app(ReminderService::class)->run());
        $this->assertSame(1, $this->notified($this->sara, 'tasks.card_due_changed'));
        $this->artisan('tasks:reminders')->assertSuccessful();
        $this->assertNotNull(Card::find($soon['id'])->reminded_soon_for);
    }

    public function test_recurring_cards_on_working_days_only_and_never_twice(): void
    {
        $snap = $this->board($this->mona, 'department', ['department_id' => $this->engineering->id]);
        $label = $this->as($this->mona)->postJson('/api/v1/tasks/boards/'.$snap['board']['id'].'/labels', ['name' => 'Site', 'color' => '#2563eb'])->json('data');
        // Weekly on Sunday (0) and Tuesday (2), due two working days later.
        $recurrence = $this->as($this->mona)->postJson('/api/v1/tasks/boards/'.$snap['board']['id'].'/recurrences', [
            'list_id' => $this->listOf($snap, 'normal'), 'frequency' => 'weekly', 'weekdays' => [0, 2], 'due_in_days' => 2,
            'template' => ['title' => 'Site visit report', 'priority' => 'high', 'assignee_ids' => [$this->ali->id], 'label_ids' => [$label['id']],
                'checklist' => [['title' => 'Visit', 'items' => ['Photos', 'Measurements']]]],
        ])->assertCreated()->json('data');
        $this->assertSame('2026-11-08', $recurrence['next_run_on']);

        $service = app(RecurrenceService::class);
        $this->assertSame(1, $service->run('2026-11-08'));
        $this->assertSame(0, $service->run('2026-11-08'));
        $card = Card::where('recurrence_id', $recurrence['id'])->sole();
        $this->assertSame(['Site visit report', 'high', '2026-11-08', '2026-11-10'], [$card->title, $card->priority, $card->start_date->format('Y-m-d'), $card->due_date->format('Y-m-d')]);
        $this->assertSame([$this->ali->id], $card->assignees()->pluck('usr_users.id')->all());
        $this->assertSame(2, $card->checklists()->first()->items()->count());
        $this->assertSame(1, $this->notified($this->ali, 'tasks.card_assigned'));

        // Tuesday 10 Nov is a public holiday: skipped, not moved; the next run is Sunday 15.
        OfficeHoliday::create(['date' => '2026-11-10', 'name' => 'Holiday']);
        $this->assertSame(0, $service->run('2026-11-10'));
        $this->assertSame('2026-11-15', Recurrence::find($recurrence['id'])->next_run_on->format('Y-m-d'));
        $this->assertSame(1, Card::where('recurrence_id', $recurrence['id'])->count());
        // Before the office day starts the scheduled run does nothing.
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(5, 0, 0)); // the app runs on office time (Asia/Damascus)
        $this->artisan('tasks:recurring')->assertSuccessful();
        $this->assertSame(1, Card::where('recurrence_id', $recurrence['id'])->count());
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(8, 30, 0)); // half an hour before the 09:00 shift
        $this->artisan('tasks:recurring')->assertSuccessful();
        $this->assertSame(2, Card::where('recurrence_id', $recurrence['id'])->count());
    }

    public function test_my_cards_and_the_manager_report(): void
    {
        $snap = $this->board($this->mona, 'department', ['department_id' => $this->engineering->id]);
        $onTime = $this->card($this->ali, $snap, ['title' => 'On time', 'due_date' => '2026-11-09', 'assignee_ids' => [$this->ali->id]]);
        $this->card($this->ali, $snap, ['title' => 'Overdue', 'due_date' => '2026-11-05', 'assignee_ids' => [$this->ali->id]]);
        $this->card($this->ali, $snap, ['title' => 'Sara', 'assignee_ids' => [$this->sara->id]]);
        $personal = $this->board($this->ali, 'personal');
        $this->card($this->ali, $personal, ['title' => 'Private', 'assignee_ids' => [$this->ali->id]]);
        $this->move($this->ali, $onTime, $this->listOf($snap, 'done'))->assertOk();
        $this->as($this->mona)->postJson("/api/v1/tasks/cards/{$onTime['id']}/approve")->assertOk();

        $mine = $this->as($this->ali)->getJson('/api/v1/tasks/my-cards')->assertOk()->json('data.cards');
        $this->assertSame(['Overdue', 'Private'], array_column($mine, 'title'));
        $this->assertSame(['Overdue'], array_column($this->as($this->ali)->getJson('/api/v1/tasks/my-cards?due=overdue')->json('data.cards'), 'title'));
        $this->assertSame(['On time'], array_column($this->as($this->ali)->getJson('/api/v1/tasks/my-cards?state=finished')->json('data.cards'), 'title'));

        $report = $this->as($this->mona)->getJson('/api/v1/tasks/reports?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
        $ali = collect($report['people'])->firstWhere('user_id', $this->ali->id);
        $this->assertEquals([1, 1, 1, 1, 1.0], [$ali['open'], $ali['overdue'], $ali['finished'], $ali['on_time'], $ali['on_time_rate']]);
        $this->assertSame(1, collect($report['people'])->firstWhere('user_id', $this->sara->id)['open']);
        // Omar cannot see the department board, so his report is empty; personal boards never count.
        $this->assertSame([], $this->as($this->omar)->getJson('/api/v1/tasks/reports?from=2026-11-01&to=2026-11-30')->json('data.people'));
    }

    public function test_termination_releases_cards_and_hands_boards_over(): void
    {
        $department = $this->board($this->mona, 'department', ['department_id' => $this->engineering->id]);
        $card = $this->card($this->ali, $department, ['assignee_ids' => [$this->ali->id, $this->sara->id]]);
        // Ali is the only owner of a team board (created by Hala, who then left it).
        $team = $this->board($this->hala, 'team', ['members' => [['user_id' => $this->ali->id, 'role' => 'owner']]]);
        BoardMember::where('board_id', $team['board']['id'])->where('user_id', $this->hala->id)->delete();
        $personal = $this->board($this->ali, 'personal');

        app(EmployeeTerminationService::class)->terminate($this->employees[$this->ali->id], ['termination_date' => '2026-11-08', 'reason' => 'Resigned'], $this->hala);

        $this->assertSame([$this->sara->id], Card::find($card['id'])->assignees()->pluck('usr_users.id')->all());
        $this->assertSame(1, $this->notified($this->mona, 'tasks.cards_unassigned'));
        $this->assertSame('owner', BoardMember::where('board_id', $team['board']['id'])->where('user_id', $this->mona->id)->value('role'));
        $this->assertNull(BoardMember::where('board_id', $team['board']['id'])->where('user_id', $this->ali->id)->first());
        $this->assertSame(1, $this->notified($this->mona, 'tasks.board_handed_over'));
        $this->assertNotNull(Board::find($personal['board']['id'])->archived_at);
        // No longer in the department, so no longer on its board.
        $this->as($this->ali)->getJson('/api/v1/tasks/boards/'.$department['board']['id'])->assertNotFound();
    }

    public function test_assignee_on_approved_leave_on_the_due_date_is_a_warning(): void
    {
        $type = LeaveType::firstOrFail();
        $type->update(['active' => true]);
        LeavePolicy::create(['leave_type_id' => $type->id, 'effective_from' => '2026-01-01', 'annual_days' => 10, 'pay_percent' => 100,
            'grant_method' => 'upfront', 'year_basis' => 'fixed', 'year_start' => '01-01', 'retroactive_days' => 10]);
        $input = ['leave_type_id' => $type->id, 'start_date' => '2026-11-10', 'end_date' => '2026-11-10', 'unit' => 'day', 'reason' => 'Family'];
        $quote = $this->as($this->sara)->postJson('/api/v1/leaves/preview', $input)->assertOk()->json('data');
        $leave = $this->postJson('/api/v1/leaves', [...$input, 'quote_token' => $quote['quote_token'], 'submission_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $snap = $this->board($this->mona, 'department', ['department_id' => $this->engineering->id]);
        $card = $this->card($this->ali, $snap, ['due_date' => '2026-11-10']);

        // Pending leave does not warn; approved leave does, and assigning still works.
        $this->as($this->ali)->putJson("/api/v1/tasks/cards/{$card['id']}/assignees", ['user_ids' => [$this->sara->id]])->assertOk()->assertJsonPath('data.warnings', []);
        $this->as($this->hala)->postJson("/api/v1/leaves/{$leave['id']}/approve", ['version' => $leave['version'], 'reason' => 'OK'])->assertOk();
        $this->as($this->ali)->getJson("/api/v1/tasks/cards/{$card['id']}")->assertOk()
            ->assertJsonPath('data.warnings', [['user_id' => $this->sara->id, 'date' => '2026-11-10']]);
    }
}
