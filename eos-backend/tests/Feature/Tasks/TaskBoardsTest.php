<?php

namespace Tests\Feature\Tasks;

use App\Modules\Tasks\Events\BoardChanged;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Comment;
use App\Modules\Tasks\Models\TaskList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Tasks\Concerns\TasksFixture;
use Tests\TestCase;

class TaskBoardsTest extends TestCase
{
    use RefreshDatabase;
    use TasksFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTasks();
    }

    public function test_who_creates_which_board_and_the_default_lists(): void
    {
        // Anyone has a personal board; team boards need tasks.manage; department boards are the manager's.
        $personal = $this->board($this->ali, 'personal');
        $this->assertSame(['normal', 'normal', 'done', 'finished'], array_column($personal['lists'], 'kind'));
        $this->as($this->ali)->postJson('/api/v1/tasks/boards', ['type' => 'team', 'name' => 'X'])->assertForbidden();
        $this->as($this->ali)->postJson('/api/v1/tasks/boards', ['type' => 'department', 'name' => 'X', 'department_id' => $this->engineering->id])->assertForbidden();
        $this->as($this->mona)->postJson('/api/v1/tasks/boards', ['type' => 'department', 'name' => 'X', 'department_id' => $this->finance->id])->assertForbidden();
        $this->board($this->mona, 'department', ['department_id' => $this->engineering->id]);
        $this->board($this->hala, 'team');
        $this->board($this->karim, 'department', ['department_id' => $this->finance->id]);
        $this->as($this->ali)->getJson('/api/v1/tasks/boards/options')->assertJsonPath('data.types', ['personal']);
        // Only people who run boards get the colleague list to invite from.
        $this->as($this->ali)->getJson('/api/v1/tasks/people')->assertForbidden();
        $this->assertSame(['Sara'], array_column($this->as($this->mona)->getJson('/api/v1/tasks/people?q=Sar')->assertOk()->json('data'), 'name'));
        $this->assertCount(6, $this->as($this->hala)->getJson('/api/v1/tasks/people')->json('data'));
        $this->as($this->mona)->getJson('/api/v1/tasks/boards/options')
            ->assertJsonPath('data.types', ['personal', 'team', 'department'])->assertJsonPath('data.departments', [['id' => $this->engineering->id, 'name' => $this->engineering->name]]);
        $this->assertCount(2, $this->as($this->karim)->getJson('/api/v1/tasks/boards/options')->json('data.departments'));
        $this->assertSame('Engineering', collect($this->as($this->hala)->getJson('/api/v1/tasks/people?q=Sara')->json('data'))->first()['department_name']);
    }

    public function test_who_sees_a_board_and_with_which_role(): void
    {
        $department = $this->board($this->mona, 'department', ['department_id' => $this->engineering->id]);
        $id = $department['board']['id'];
        // Department employees are members without a row; the manager is an owner; others do not see it.
        $this->as($this->ali)->getJson("/api/v1/tasks/boards/$id")->assertOk()->assertJsonPath('data.role', 'member');
        $this->as($this->omar)->getJson("/api/v1/tasks/boards/$id")->assertNotFound();
        // The company manager reads every shared board but cannot work on it.
        $this->as($this->karim)->getJson("/api/v1/tasks/boards/$id")->assertJsonPath('data.role', 'viewer');
        $this->as($this->karim)->postJson("/api/v1/tasks/boards/$id/cards", ['list_id' => $this->listOf($department, 'normal'), 'title' => 'x'])->assertForbidden();

        // A personal board is its owner's alone, the company manager included.
        $personal = $this->board($this->ali, 'personal');
        $this->as($this->karim)->getJson('/api/v1/tasks/boards/'.$personal['board']['id'])->assertNotFound();
        $this->as($this->mona)->getJson('/api/v1/tasks/boards/'.$personal['board']['id'])->assertNotFound();

        // A department manager reads team boards that include someone from their department.
        $team = $this->board($this->hala, 'team', ['members' => [['user_id' => $this->sara->id, 'role' => 'member']]]);
        $this->as($this->mona)->getJson('/api/v1/tasks/boards/'.$team['board']['id'])->assertJsonPath('data.role', 'viewer');
        $this->as($this->omar)->getJson('/api/v1/tasks/boards/'.$team['board']['id'])->assertNotFound();
        $this->assertSame(1, $this->notified($this->sara, 'tasks.board_member_added'));

        $names = collect($this->as($this->ali)->getJson('/api/v1/tasks/boards')->json('data'))->pluck('name')->all();
        $this->assertSame(['Department board', 'Personal board'], $names);
        $this->assertCount(2, $this->as($this->karim)->getJson('/api/v1/tasks/boards')->json('data'), 'shared boards only, not personal ones');
    }

    public function test_completion_needs_the_assignee_then_the_owner(): void
    {
        $snap = $this->board($this->mona, 'department', ['department_id' => $this->engineering->id]);
        $card = $this->card($this->ali, $snap, ['assignee_ids' => [$this->sara->id], 'due_date' => '2026-11-12']);
        $this->assertSame([$this->sara->id], $card['assignee_ids']);
        $this->assertSame(1, $this->notified($this->sara, 'tasks.card_assigned'));
        $done = $this->listOf($snap, 'done');
        $finished = $this->listOf($snap, 'finished');
        $doing = $this->listOf($snap, 'normal', 1);

        $this->move($this->ali, $card, $doing)->assertOk();
        // Only an assignee (or an owner) says it is done; only an owner confirms it.
        $this->move($this->ali, $card, $done)->assertForbidden();
        $this->move($this->sara, $card, $done)->assertOk();
        $this->assertSame(1, $this->notified($this->mona, 'tasks.card_review'));
        $this->move($this->sara, $card, $finished)->assertForbidden();
        $this->as($this->sara)->postJson("/api/v1/tasks/cards/{$card['id']}/approve")->assertForbidden();

        // Rejected: back where it was, with the reason as a comment and a notification.
        $this->as($this->mona)->postJson("/api/v1/tasks/cards/{$card['id']}/reject", ['reason' => 'Dimensions missing'])->assertOk()
            ->assertJsonPath('data.list_id', $doing);
        $this->assertSame('لم يُعتمد الإنجاز: Dimensions missing', Comment::where('card_id', $card['id'])->value('body'));
        $this->assertSame(1, $this->notified($this->sara, 'tasks.card_rejected'));

        $this->move($this->sara, $card, $done)->assertOk();
        $this->as($this->mona)->postJson("/api/v1/tasks/cards/{$card['id']}/approve")->assertOk()->assertJsonPath('data.list_id', $finished);
        $row = Card::find($card['id']);
        $this->assertNotNull($row->completed_at);
        $this->assertSame($this->mona->id, $row->completed_by);

        // A finished card is read-only until the owner reopens it.
        $this->as($this->sara)->patchJson("/api/v1/tasks/cards/{$card['id']}", ['version' => $row->version, 'title' => 'x'])->assertUnprocessable();
        $this->move($this->sara, $card, $doing)->assertUnprocessable();
        $this->as($this->sara)->postJson("/api/v1/tasks/cards/{$card['id']}/reopen")->assertForbidden();
        $this->as($this->mona)->postJson("/api/v1/tasks/cards/{$card['id']}/reopen")->assertOk()->assertJsonPath('data.list_id', $doing)
            ->assertJsonPath('data.completed_at', null);
    }

    public function test_a_personal_board_finishes_on_done(): void
    {
        $snap = $this->board($this->ali, 'personal');
        $card = $this->card($this->ali, $snap);
        $this->move($this->ali, $card, $this->listOf($snap, 'done'))->assertOk()->assertJsonPath('data.list_id', $this->listOf($snap, 'finished'));
        $this->assertNotNull(Card::find($card['id'])->completed_at);
        // Nobody else can be added or assigned.
        $this->as($this->ali)->postJson('/api/v1/tasks/boards/'.$snap['board']['id'].'/members', ['user_id' => $this->sara->id, 'role' => 'member'])->assertUnprocessable();
    }

    public function test_assignees_must_work_on_the_board_and_leave_is_only_a_warning(): void
    {
        $snap = $this->board($this->hala, 'team', ['members' => [['user_id' => $this->ali->id, 'role' => 'member'], ['user_id' => $this->omar->id, 'role' => 'viewer']]]);
        $card = $this->card($this->hala, $snap);
        $this->as($this->hala)->putJson("/api/v1/tasks/cards/{$card['id']}/assignees", ['user_ids' => [$this->omar->id]])->assertUnprocessable();
        $this->as($this->hala)->putJson("/api/v1/tasks/cards/{$card['id']}/assignees", ['user_ids' => [$this->sara->id]])->assertUnprocessable();
        $this->as($this->hala)->putJson("/api/v1/tasks/cards/{$card['id']}/assignees", ['user_ids' => [$this->ali->id]])->assertOk()
            ->assertJsonPath('data.assignee_ids', [$this->ali->id])->assertJsonPath('data.warnings', []);
        // Viewers comment but do not edit.
        $this->as($this->omar)->postJson("/api/v1/tasks/cards/{$card['id']}/comments", ['body' => 'Looks good'])->assertCreated();
        $this->as($this->omar)->patchJson("/api/v1/tasks/cards/{$card['id']}", ['version' => 1, 'title' => 'x'])->assertForbidden();
    }

    public function test_cards_keep_their_order_and_edits_detect_conflicts(): void
    {
        $snap = $this->board($this->ali, 'personal');
        $a = $this->card($this->ali, $snap, ['title' => 'A']);
        $b = $this->card($this->ali, $snap, ['title' => 'B']);
        $c = $this->card($this->ali, $snap, ['title' => 'C']);
        $list = $this->listOf($snap, 'normal');
        $this->move($this->ali, $c, $list)->assertOk(); // to the top
        $this->move($this->ali, $a, $list, $b['id'])->assertOk(); // after B
        $order = fn () => Card::where('list_id', $list)->orderBy('position')->pluck('title')->all();
        $this->assertSame(['C', 'B', 'A'], $order());
        // Many drops in the same gap renumber the list instead of colliding.
        for ($i = 0; $i < 60; $i++) {
            $this->move($this->ali, $i % 2 ? $a : $b, $list, $c['id'])->assertOk();
        }
        $positions = Card::where('list_id', $list)->orderBy('position')->pluck('position')->all();
        $this->assertSame(count($positions), count(array_unique($positions)));
        // The gaps never wear down to nothing: the list was renumbered when they did.
        for ($i = 1; $i < count($positions); $i++) {
            $this->assertGreaterThan(1e-6, $positions[$i] - $positions[$i - 1]);
        }
        $this->assertSame('C', $order()[0]);

        $this->as($this->ali)->patchJson("/api/v1/tasks/cards/{$a['id']}", ['version' => Card::find($a['id'])->version, 'title' => 'A2'])->assertOk();
        $this->as($this->ali)->patchJson("/api/v1/tasks/cards/{$a['id']}", ['version' => 1, 'title' => 'A3'])->assertStatus(409);
        $this->as($this->ali)->patchJson("/api/v1/tasks/cards/{$a['id']}", ['version' => Card::find($a['id'])->version, 'start_date' => '2026-11-10', 'due_date' => '2026-11-09'])
            ->assertUnprocessable();
    }

    public function test_comments_mentions_and_watchers(): void
    {
        $snap = $this->board($this->mona, 'department', ['department_id' => $this->engineering->id]);
        $card = $this->card($this->ali, $snap, ['assignee_ids' => [$this->sara->id]]);
        // Mentions are limited to people who can read the board.
        $this->as($this->ali)->postJson("/api/v1/tasks/cards/{$card['id']}/comments", ['body' => '@Omar', 'mentions' => [$this->omar->id]])->assertUnprocessable();
        $this->as($this->ali)->postJson("/api/v1/tasks/cards/{$card['id']}/comments", ['body' => '@Mona please check', 'mentions' => [$this->mona->id]])->assertCreated();
        $this->assertSame(1, $this->notified($this->mona, 'tasks.mentioned'));
        $this->assertSame(1, $this->notified($this->sara, 'tasks.card_comment'), 'assignees watch their cards');
        $this->assertSame(0, $this->notified($this->ali, 'tasks.card_comment'), 'never the author');
        // Unwatching stops it.
        $this->as($this->sara)->postJson("/api/v1/tasks/cards/{$card['id']}/watch", ['watching' => false])->assertOk();
        $this->as($this->ali)->postJson("/api/v1/tasks/cards/{$card['id']}/comments", ['body' => 'again'])->assertCreated();
        $this->assertSame(1, $this->notified($this->sara, 'tasks.card_comment'));
        // Authors edit their own comments only.
        $comment = Comment::where('card_id', $card['id'])->first();
        $this->as($this->sara)->patchJson("/api/v1/tasks/comments/{$comment->id}", ['body' => 'hack'])->assertForbidden();
        $this->as($this->ali)->patchJson("/api/v1/tasks/comments/{$comment->id}", ['body' => 'edited'])->assertOk()->assertJsonPath('data.body', 'edited');
    }

    public function test_attachments_by_type_and_access(): void
    {
        Storage::fake('local');
        $snap = $this->board($this->mona, 'department', ['department_id' => $this->engineering->id]);
        $card = $this->card($this->ali, $snap);
        $drawing = $this->as($this->ali)->post("/api/v1/tasks/cards/{$card['id']}/attachments", ['file' => UploadedFile::fake()->create('plan.dwg', 300)])
            ->assertCreated()->json('data');
        $this->assertArrayNotHasKey('path', $drawing);
        $this->as($this->ali)->post("/api/v1/tasks/cards/{$card['id']}/attachments", ['file' => UploadedFile::fake()->create('run.exe', 10)])->assertStatus(422);
        $this->as($this->ali)->post("/api/v1/tasks/cards/{$card['id']}/attachments", ['file' => UploadedFile::fake()->create('big.pdf', 26000)])->assertStatus(422);
        $this->as($this->sara)->get("/api/v1/tasks/attachments/{$drawing['id']}")->assertOk();
        $this->as($this->omar)->get("/api/v1/tasks/attachments/{$drawing['id']}")->assertNotFound();
        // The uploader or an owner removes it.
        $this->as($this->sara)->deleteJson("/api/v1/tasks/attachments/{$drawing['id']}")->assertForbidden();
        $this->as($this->mona)->deleteJson("/api/v1/tasks/attachments/{$drawing['id']}")->assertOk();
    }

    public function test_change_feed_and_broadcast_after_commit(): void
    {
        Event::fake([BoardChanged::class]);
        $snap = $this->board($this->ali, 'personal');
        $cursor = $snap['cursor'];
        $card = $this->card($this->ali, $snap);
        $changes = $this->as($this->ali)->getJson('/api/v1/tasks/boards/'.$snap['board']['id']."/changes?since=$cursor")->assertOk()->json('data');
        $this->assertGreaterThan($cursor, $changes['cursor']);
        $this->assertSame('card.created', $changes['changes'][0]['action']);
        $this->assertSame($card['id'], $changes['changes'][0]['card_id']);
        Event::assertDispatched(BoardChanged::class, fn (BoardChanged $e) => $e->boardId === $snap['board']['id'] && $e->action === 'card.created'
            && $e->broadcastOn()[0]->name === 'private-tasks.board.'.$snap['board']['id']);
        $this->as($this->sara)->getJson('/api/v1/tasks/boards/'.$snap['board']['id'].'/changes?since=0')->assertNotFound();
    }

    public function test_lists_members_and_archiving(): void
    {
        $snap = $this->board($this->hala, 'team', ['members' => [['user_id' => $this->ali->id, 'role' => 'member']]]);
        $id = $snap['board']['id'];
        // New lists go before "done"; the completion lists cannot be archived; a list with cards cannot either.
        $list = $this->as($this->hala)->postJson("/api/v1/tasks/boards/$id/lists", ['name' => 'Review'])->assertCreated()->json('data');
        $kinds = TaskList::where('board_id', $id)->orderBy('position')->pluck('kind')->all();
        $this->assertSame(['normal', 'normal', 'normal', 'done', 'finished'], $kinds);
        $this->as($this->ali)->postJson("/api/v1/tasks/boards/$id/lists", ['name' => 'Mine'])->assertForbidden();
        $this->as($this->hala)->postJson('/api/v1/tasks/lists/'.$this->listOf($snap, 'done').'/archive', ['archived' => true])->assertUnprocessable();
        $card = $this->card($this->ali, $snap, ['list_id' => $list['id'], 'assignee_ids' => [$this->ali->id]]);
        $this->as($this->hala)->postJson("/api/v1/tasks/lists/{$list['id']}/archive", ['archived' => true])->assertUnprocessable();

        // Removing a member takes them off open cards; the last owner cannot leave.
        $this->as($this->hala)->deleteJson("/api/v1/tasks/boards/$id/members/{$this->ali->id}")->assertOk();
        $this->assertSame([], Card::find($card['id'])->assignees()->pluck('usr_users.id')->all());
        $this->as($this->hala)->deleteJson("/api/v1/tasks/boards/$id/members/{$this->hala->id}")->assertUnprocessable();

        // An archived board is read-only until restored.
        $this->as($this->hala)->postJson("/api/v1/tasks/boards/$id/archive", ['archived' => true])->assertOk();
        $this->as($this->hala)->postJson("/api/v1/tasks/boards/$id/cards", ['list_id' => $list['id'], 'title' => 'x'])->assertStatus(409);
        $this->assertSame([], $this->as($this->hala)->getJson('/api/v1/tasks/boards')->json('data'));
        $this->as($this->hala)->postJson("/api/v1/tasks/boards/$id/archive", ['archived' => false])->assertOk();
        $this->as($this->hala)->postJson("/api/v1/tasks/boards/$id/cards", ['list_id' => $list['id'], 'title' => 'x'])->assertCreated();
    }

    public function test_cards_move_and_copy_between_boards(): void
    {
        $from = $this->board($this->mona, 'department', ['department_id' => $this->engineering->id]);
        $to = $this->board($this->hala, 'team', ['members' => [['user_id' => $this->ali->id, 'role' => 'member']]]);
        $label = $this->as($this->mona)->postJson('/api/v1/tasks/boards/'.$from['board']['id'].'/labels', ['name' => 'Urgent', 'color' => '#dc2626'])->json('data');
        $this->as($this->hala)->postJson('/api/v1/tasks/boards/'.$to['board']['id'].'/labels', ['name' => 'Urgent', 'color' => '#b91c1c']);
        $card = $this->card($this->ali, $from, ['assignee_ids' => [$this->ali->id, $this->sara->id], 'label_ids' => [$label['id']]]);
        $this->as($this->ali)->postJson("/api/v1/tasks/cards/{$card['id']}/checklists", ['title' => 'Steps'])->assertCreated();

        $copy = $this->as($this->ali)->postJson("/api/v1/tasks/cards/{$card['id']}/copy", ['board_id' => $to['board']['id'], 'list_id' => $this->listOf($to, 'normal')])
            ->assertCreated()->json('data');
        // Sara cannot work on the team board, so only Ali stays assigned; the label follows by name.
        $this->assertSame([$this->ali->id], $copy['assignee_ids']);
        $this->assertCount(1, $copy['label_ids']);
        $this->assertSame('Steps', $copy['checklists'][0]['title']);

        // Sara is not on the target board, so she cannot move the card there.
        $this->as($this->sara)->postJson("/api/v1/tasks/cards/{$card['id']}/transfer", ['board_id' => $to['board']['id'], 'list_id' => $this->listOf($to, 'normal')])->assertNotFound();
        $this->as($this->ali)->postJson("/api/v1/tasks/cards/{$card['id']}/transfer", ['board_id' => $to['board']['id'], 'list_id' => $this->listOf($to, 'normal')])
            ->assertOk()->assertJsonPath('data.board_id', $to['board']['id'])->assertJsonPath('data.assignee_ids', [$this->ali->id]);
    }
}
