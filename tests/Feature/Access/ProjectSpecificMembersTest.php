<?php

use App\Livewire\Project\AddEmpty;
use App\Livewire\Project\Members as ProjectMembers;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
        'app.maintenance.store' => 'array',
    ]);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    Server::flushIdentityMap();
    Cache::flush();

    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'web-server']);
    $destination = $this->server->standaloneDockers()->firstOrFail();

    $this->shop = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'shop']);
    $shopProduction = Environment::factory()->create(['project_id' => $this->shop->id, 'name' => 'prod']);
    $this->blog = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'blog']);
    $blogStaging = Environment::factory()->create(['project_id' => $this->blog->id, 'name' => 'staging']);

    $this->shopApi = Application::factory()->create([
        'name' => 'shop-api',
        'environment_id' => $shopProduction->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->blogWeb = Application::factory()->create([
        'name' => 'blog-web',
        'environment_id' => $blogStaging->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->owner = projectMembersUser($this->team, 'owner', null);
});

function projectMembersUser(Team $team, string $role, ?array $access, bool $canCreateProjects = false): User
{
    $user = User::factory()->create();
    $team->members()->attach($user->id, [
        'role' => $role,
        'access' => $access === null ? null : json_encode($access),
        'can_create_projects' => $canCreateProjects,
    ]);

    return $user;
}

function projectMembersActAs(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

function projectMembersGrants(Team $team, User $user): ?array
{
    $stored = DB::table('team_user')->where('team_id', $team->id)->where('user_id', $user->id)->value('access');

    return $stored === null ? null : json_decode($stored, true);
}

function shopOnlyGrants(Project $shop): array
{
    return [['scope' => 'project', 'uuid' => $shop->uuid, 'levels' => ['read', 'deploy', 'write']]];
}

it('lets a project-specific member open only their project', function () {
    $member = projectMembersUser($this->team, 'member', shopOnlyGrants($this->shop));
    projectMembersActAs($member, $this->team);

    expect(app(AccessResolver::class)->canOpen($member, $this->shop))->toBeTrue()
        ->and(app(AccessResolver::class)->canOpen($member, $this->blog))->toBeFalse()
        ->and(Gate::allows('view', $this->shop))->toBeTrue()
        ->and(Gate::allows('view', $this->blog))->toBeFalse()
        ->and(Project::ownedByCurrentTeam()->pluck('id')->all())->toBe([$this->shop->id]);
});

it('keeps a project-specific member off servers and private keys they have no resource on', function () {
    $member = projectMembersUser($this->team, 'member', shopOnlyGrants($this->shop));
    $key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    projectMembersActAs($member, $this->team);

    expect(Gate::allows('view', $key))->toBeFalse()
        ->and(Gate::allows('view', $this->server))->toBeTrue()
        ->and($member->can('canAccessTerminal'))->toBeFalse();
});

it('keeps a project-specific member off a server with none of their resources', function () {
    $otherServer = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'other-server']);
    $member = projectMembersUser($this->team, 'member', shopOnlyGrants($this->shop));
    projectMembersActAs($member, $this->team);

    expect(Gate::allows('view', $otherServer))->toBeFalse();
});

it('lets a member who may create projects create one and become its project member', function () {
    $member = projectMembersUser($this->team, 'member', shopOnlyGrants($this->shop), true);
    projectMembersActAs($member, $this->team);

    expect(Gate::allows('create', Project::class))->toBeTrue();

    Livewire::test(AddEmpty::class)
        ->set('name', 'side-project')
        ->call('submit')
        ->assertHasNoErrors();

    $project = Project::where('team_id', $this->team->id)->where('name', 'side-project')->firstOrFail();
    expect(projectMembersGrants($this->team, $member))->toBe([
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read', 'deploy', 'write']],
        ['scope' => 'project', 'uuid' => $project->uuid, 'levels' => ['write', 'deploy']],
    ]);
});

it('does not let a project-specific member create a project without the flag', function () {
    $member = projectMembersUser($this->team, 'member', shopOnlyGrants($this->shop));
    projectMembersActAs($member, $this->team);

    expect(Gate::allows('create', Project::class))->toBeFalse();

    Livewire::test(AddEmpty::class)
        ->set('name', 'side-project')
        ->call('submit');

    expect(Project::where('team_id', $this->team->id)->where('name', 'side-project')->exists())->toBeFalse();
});

it('lets an admin remove a member from a project on the project page', function () {
    $member = projectMembersUser($this->team, 'member', shopOnlyGrants($this->shop));
    projectMembersActAs($this->owner, $this->team);

    Livewire::test(ProjectMembers::class, ['project_uuid' => $this->shop->uuid])
        ->call('removeFromProject', $member->id)
        ->assertHasNoErrors();

    expect($this->team->members()->whereKey($member->id)->exists())->toBeFalse();
});

it('keeps the member in the team when they still have another project after removal', function () {
    $member = projectMembersUser($this->team, 'member', [
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']],
        ['scope' => 'project', 'uuid' => $this->blog->uuid, 'levels' => ['read']],
    ]);
    projectMembersActAs($this->owner, $this->team);

    Livewire::test(ProjectMembers::class, ['project_uuid' => $this->shop->uuid])
        ->call('removeFromProject', $member->id)
        ->assertHasNoErrors();

    expect(projectMembersGrants($this->team, $member))->toBe([
        ['scope' => 'project', 'uuid' => $this->blog->uuid, 'levels' => ['read']],
    ]);
});

it('lists project members and pending invitations on the project page', function () {
    $member = projectMembersUser($this->team, 'member', shopOnlyGrants($this->shop));
    TeamInvitation::create([
        'team_id' => $this->team->id,
        'uuid' => new_public_id(32),
        'email' => 'invited@example.com',
        'role' => 'member',
        'link' => 'https://example.com/invite',
        'via' => 'link',
        'access' => shopOnlyGrants($this->shop),
    ]);
    projectMembersActAs($this->owner, $this->team);

    Livewire::test(ProjectMembers::class, ['project_uuid' => $this->shop->uuid])
        ->assertSee($member->name)
        ->assertSee('invited@example.com');
});

it('lists project members through the API for an admin', function () {
    config()->set('constants.coolify.self_hosted', true);
    $member = projectMembersUser($this->team, 'member', shopOnlyGrants($this->shop));

    session(['currentTeam' => $this->team]);
    $ownerToken = $this->owner->createToken('admin', ['read'])->plainTextToken;

    $this->withToken($ownerToken)
        ->getJson('/api/v1/projects/'.$this->shop->uuid.'/members')
        ->assertOk()
        ->assertJsonPath('members.0.email', $member->email)
        ->assertJsonPath('members.0.access.0.scope', 'project')
        ->assertJsonPath('members.0.can_create_projects', false);
});

it('refuses a project member on the project members API', function () {
    config()->set('constants.coolify.self_hosted', true);
    $member = projectMembersUser($this->team, 'member', shopOnlyGrants($this->shop));

    session(['currentTeam' => $this->team]);
    $token = $member->createToken('member', ['read']);
    // The manage-members policy must refuse this request.
    $token->accessToken->update(['scopes' => [['scope' => 'project', 'uuid' => $this->shop->uuid]]]);

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/projects/'.$this->shop->uuid.'/members')
        ->assertForbidden();
});

it('includes access grants in the team members API', function () {
    config()->set('constants.coolify.self_hosted', true);
    $member = projectMembersUser($this->team, 'member', shopOnlyGrants($this->shop), true);

    session(['currentTeam' => $this->team]);
    $ownerToken = $this->owner->createToken('admin', ['read'])->plainTextToken;

    $this->withToken($ownerToken)
        ->getJson('/api/v1/team/members')
        ->assertOk()
        ->assertJsonFragment([
            'email' => $member->email,
            'role' => 'member',
            'can_create_projects' => true,
        ]);
});
