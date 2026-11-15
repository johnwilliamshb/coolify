<?php

namespace App\Livewire\Project;

use App\Actions\User\RevokeUserTeamTokens;
use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Models\Project;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Access\Grant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** Members of one project, managed from the project page. */
class Members extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public function mount(string $project_uuid): void
    {
        $this->project = Project::where('team_id', currentTeam()->id)
            ->where('uuid', $project_uuid)
            ->firstOrFail();
        $this->authorize('view', $this->project);
    }

    /** @return list<array{user: User, grants: list<Grant>, can_create_projects: bool}> */
    public function projectMembers(): array
    {
        $members = [];
        foreach ($this->project->team->members()->wherePivot('role', 'member')->get() as $member) {
            $grants = array_values(array_filter(
                Grant::listFrom($member->pivot->access),
                fn (Grant $grant) => $grant->scope === AccessScope::Project && $grant->uuid === $this->project->uuid,
            ));
            if ($grants === []) {
                continue;
            }
            $members[] = [
                'user' => $member,
                'grants' => $grants,
                'can_create_projects' => (bool) ($member->pivot->can_create_projects ?? false),
            ];
        }

        return $members;
    }

    /** @return Collection<int, TeamInvitation> */
    public function projectInvitations()
    {
        return TeamInvitation::whereTeamId($this->project->team_id)
            ->get()
            ->filter(function (TeamInvitation $invitation) {
                return collect(Grant::listFrom($invitation->access))
                    ->contains(fn (Grant $grant) => $grant->scope === AccessScope::Project && $grant->uuid === $this->project->uuid);
            })
            ->values();
    }

    /** @return list<array{scope: string, uuid: string|null, levels: list<string>}> */
    public function invitationGrants(): array
    {
        return Grant::listToArray([
            new Grant(AccessScope::Project, $this->project->uuid, [AccessLevel::Write, AccessLevel::Deploy]),
        ]);
    }

    public function removeFromProject(int $memberId): void
    {
        try {
            $this->authorize('manageMembers', $this->project->team);
            $member = $this->project->team->members()->whereKey($memberId)->firstOrFail();
            abort_if($member->pivot->role !== 'member', 404);

            $grants = array_values(array_filter(
                Grant::listFrom($member->pivot->access),
                fn (Grant $grant) => ! ($grant->scope === AccessScope::Project && $grant->uuid === $this->project->uuid),
            ));

            DB::transaction(function () use ($member, $grants): void {
                if ($grants === []) {
                    $member->teams()->detach($this->project->team_id);
                    RevokeUserTeamTokens::forUserTeam($member, $this->project->team_id);
                    $member->clearStoredTeamIfMatches($this->project->team_id);
                } else {
                    $member->teams()->updateExistingPivot($this->project->team_id, [
                        'access' => json_encode(Grant::listToArray($grants)),
                    ]);
                }
            });
            app(AccessResolver::class)->flush();

            auditLog('ui.project_member.removed', [
                'team_id' => $this->project->team_id,
                'project_uuid' => $this->project->uuid,
                'project_name' => $this->project->name,
                'member_id' => $member->id,
                'member_name' => $member->name,
            ]);
            $this->dispatch('success', $grants === []
                ? 'Member removed from the project and the team.'
                : 'Member removed from the project.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function revokeInvitation(int $invitationId): void
    {
        try {
            $this->authorize('manageInvitations', $this->project->team);
            $invitation = TeamInvitation::whereTeamId($this->project->team_id)->findOrFail($invitationId);
            $invitationEmail = $invitation->email;
            $invitationUuid = $invitation->uuid;
            DB::transaction(function () use ($invitation): void {
                $invitation->delete();
                $user = User::whereEmail($invitation->email)->first();
                if (filled($user)) {
                    $user->deleteIfNotVerifiedAndForcePasswordReset();
                }
            });
            auditLog('ui.team_invitation.revoked', [
                'team_id' => $this->project->team_id,
                'invitation_uuid' => $invitationUuid,
                'invitation_email' => $invitationEmail,
            ]);
            $this->dispatch('success', 'Invitation revoked.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        $team = $this->project->team;

        return view('livewire.project.members', [
            'team' => $team,
            'canManage' => auth()->user()->can('manageMembers', $team),
            'projectMembers' => $this->projectMembers(),
            'projectInvitations' => $this->projectInvitations(),
            'teamWideMembers' => $team->members->filter(fn (User $member) => $member->pivot->role !== 'member'
                || $member->pivot->access === null),
        ]);
    }
}
