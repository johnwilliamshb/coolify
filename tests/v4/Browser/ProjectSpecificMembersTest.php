<?php

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Project;
use App\Models\Server;
use App\Models\TeamInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack([
        'projectUuid' => 'project-shop',
        'projectName' => 'Shop',
        'projectDescription' => 'Shop project',
    ]);

    Project::create([
        'uuid' => 'project-blog',
        'name' => 'Blog',
        'description' => 'Blog project',
        'team_id' => 0,
    ]);

    $this->shopApp = createBrowserApplication($this->stack, [
        'uuid' => 'app-shop-api',
        'name' => 'Shop API',
    ]);

    Server::create([
        'uuid' => 'other-server',
        'name' => 'other-server',
        'description' => 'Server with no member resources',
        'ip' => '10.0.0.9',
        'team_id' => 0,
        'private_key_id' => 1,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => ProxyStatus::EXITED->value,
        ],
    ]);
});

function inviteMemberToProject(mixed $page, string $projectUuid, string $email): void
{
    $page->navigate('/project/'.$projectUuid);
    $page->assertSee('Members');
    $page->assertSee('Invite to this project');
    $page->click('Invite to this project');
    $page->wait(1.5);

    $page->script(<<<'JS'
        (() => {
            const buttons = Array.from(document.querySelectorAll('button[wire\\:click="save"]'))
                .filter((button) => button.offsetParent !== null);
            if (buttons.length === 0) {
                return 'no-visible-save';
            }
            buttons[0].click();
            return 'saved';
        })()
    JS);
    $page->wait(1.5);
    $page->assertSee('1 project');

    $page->fill('email', $email);
    $page->click('Generate link');
    $page->wait(2);
    $page->assertSee('Invitation link generated.');
}

it('owner can invite a member to one project from the project page', function () {
    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->wait(1.5);

    $page->navigate('/project/project-shop');
    $page->assertSee('Members');
    $page->assertSee('Invite to this project');
    $page->assertSee('No project-specific members yet.');
    $page->assertSee('Can create their own projects');
    $page->screenshot(filename: 'project-specific-members-before-invite');

    inviteMemberToProject($page, 'project-shop', 'member@example.com');

    // The list does not live-refresh, reload to see the pending invitation.
    $page->navigate('/project/project-shop');
    $page->assertSee('Invitation pending');
    $page->assertSee('member@example.com');
    $page->screenshot(filename: 'project-specific-members-invite-sent');

    $page->navigate('/team/members');
    $page->assertSee('Invite a member');
    $page->assertSee('Can create their own projects');
    $page->screenshot(filename: 'project-specific-members-team-invite');

    $invitation = TeamInvitation::whereEmail('member@example.com')->firstOrFail();
    expect($invitation->role)->toBe('member')
        ->and($invitation->access)->toHaveCount(1)
        ->and($invitation->access[0]['scope'])->toBe('project')
        ->and($invitation->access[0]['uuid'])->toBe('project-shop')
        ->and($invitation->can_create_projects)->toBeFalse();
});

it('invited member sees only their project and can deploy there', function () {
    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->wait(1.5);

    inviteMemberToProject($page, 'project-shop', 'member@example.com');

    $invitation = TeamInvitation::whereEmail('member@example.com')->firstOrFail();
    parse_str((string) parse_url($invitation->link, PHP_URL_QUERY), $params);
    $token = $params['token'];

    $page->navigate('/auth/link?token='.urlencode($token));
    $page->assertSee('Accept invitation');
    $page->click('Accept invitation');
    $page->wait(2);
    $page->screenshot(filename: 'project-specific-members-accept-invitation');

    $page->assertSee('Set a new password');
    $page->fill('password', 'MemberPass123!');
    $page->fill('password_confirmation', 'MemberPass123!');
    $page->click('Reset password');
    $page->wait(2);

    if ($page->script('window.location.pathname') === '/login') {
        $page->fill('email', 'member@example.com');
        $page->fill('password', 'MemberPass123!');
        $page->click('Login');
        $page->wait(1.5);
    }

    $page->assertSee('Shop');
    $page->assertDontSee('Blog');
    $page->screenshot(filename: 'project-specific-members-member-dashboard');

    $page->navigate('/project/project-blog');
    $page->assertSee('404');
    $page->screenshot(filename: 'project-specific-members-member-other-project');

    $page->navigate('/servers');
    $page->assertSee('403');
    $page->assertSee("You don't have permission to access this page.");

    $page->navigate('/security/private-key');
    $page->assertDontSee('Test Key');
    $page->screenshot(filename: 'project-specific-members-member-private-keys');

    $page->navigate('/project/project-shop/environment/'.$this->stack['environment']->uuid.'/application/app-shop-api');
    $page->assertSee('Shop API');
    $page->assertSee('Deploy');
    $page->screenshot(filename: 'project-specific-members-member-deploy');
});
