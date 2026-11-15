<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamInvitation extends Model
{
    protected $fillable = [
        'team_id',
        'uuid',
        'email',
        'role',
        'link',
        'via',
        'access',
        'can_create_projects',
    ];

    protected $casts = [
        'link' => 'encrypted',
        'access' => 'array',
        'can_create_projects' => 'boolean',
    ];

    /**
     * Set the email attribute to lowercase.
     */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower($value);
    }

    /**
     * Pivot attributes for the new `team_user` row. Grants apply only to the member role.
     *
     * @return array{role: string, access: string|null, can_create_projects: bool}
     */
    public function membershipAttributes(): array
    {
        return [
            'role' => $this->role,
            'access' => $this->role === 'member' && $this->access !== null ? json_encode($this->access) : null,
            'can_create_projects' => $this->role === 'member' && (bool) $this->can_create_projects,
        ];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public static function ownedByCurrentTeam()
    {
        return TeamInvitation::whereTeamId(currentTeam()->id);
    }

    public function isValid(): bool
    {
        if (! $this->hasExpired()) {
            return true;
        } else {
            $this->delete();
            $user = User::whereEmail($this->email)->first();
            if (filled($user)) {
                $user->deleteIfNotVerifiedAndForcePasswordReset();
            }

            return false;
        }
    }

    public function hasExpired(): bool
    {
        return $this->created_at->diffInDays(now()) > config('constants.invitation.link.expiration_days');
    }
}
