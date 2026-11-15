<div>
    @if ($canManage)
    <x-application.settings-section title="Members"
        description="People who can see this project. Team admins and owners, and members with team-wide access, see every project.">
        @if ($canManage)
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <x-forms.button type="button" defaultClass="button button-highlighted"
                    wire:click="$dispatch('open-invitation-access', { access: {{ Js::from($this->invitationGrants()) }} })">
                    <x-reicon name="plus" class="size-3.5" />
                    Invite to this project
                </x-forms.button>
                <span class="text-[12px] text-neutral-500 dark:text-fg-faint">Invites them with Write and Deploy on this project only. Change the scopes, or allow them to create their own projects, in the invite form below.</span>
            </div>
            <livewire:team.invite-link />
        @endif

        @if (empty($projectMembers) && $projectInvitations->isEmpty())
            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">No project-specific members yet.</p>
        @else
            <div class="mt-2 flex flex-col gap-2">
                @foreach ($projectMembers as $entry)
                    <div class="flex flex-col gap-2 rounded-lg border border-neutral-200 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.08]">
                        <div class="min-w-0">
                            <div class="truncate text-[13px] font-medium text-neutral-800 dark:text-fg">{{ $entry['user']->name }}</div>
                            <div class="truncate text-[12px] text-neutral-500 dark:text-fg-dim">{{ $entry['user']->email }}</div>
                            <div class="mt-0.5 text-[11px] text-neutral-400 dark:text-fg-faint">
                                {{ \App\Livewire\Team\Member\Access::summary(\App\Services\Access\Grant::listToArray($entry['grants'])) }}@if ($entry['can_create_projects']) + Can create projects @endif
                            </div>
                        </div>
                        @if ($canManage)
                            <div class="flex shrink-0 gap-2">
                                <x-forms.button type="button"
                                    wire:click="$dispatch('open-member-access', { memberId: {{ $entry['user']->id }} })">
                                    Access
                                </x-forms.button>
                                <x-forms.button type="button" wire:click="removeFromProject({{ $entry['user']->id }})"
                                    wire:confirm="Remove this member from this project? If this is their only project, they also leave the team.">
                                    Remove
                                </x-forms.button>
                            </div>
                        @endif
                    </div>
                @endforeach
                @foreach ($projectInvitations as $invitation)
                    <div class="flex flex-col gap-2 rounded-lg border border-dashed border-neutral-200 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.08]">
                        <div class="min-w-0">
                            <div class="truncate text-[13px] font-medium text-neutral-800 dark:text-fg">{{ $invitation->email }}</div>
                            <div class="mt-0.5 text-[11px] text-neutral-400 dark:text-fg-faint">
                                Invitation pending: {{ \App\Livewire\Team\Member\Access::summary($invitation->access, (bool) $invitation->can_create_projects) }}
                            </div>
                        </div>
                        @if ($canManage)
                            <div class="flex shrink-0 gap-2">
                                <x-forms.button type="button" wire:click="revokeInvitation({{ $invitation->id }})"
                                    wire:confirm="Revoke this invitation?">
                                    Revoke
                                </x-forms.button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($teamWideMembers->isNotEmpty())
            <p class="mt-3 text-[12px] text-neutral-500 dark:text-fg-faint">
                Also see this project: {{ $teamWideMembers->pluck('name')->join(', ') }} (team-wide access).
            </p>
        @endif
    </x-application.settings-section>

    <livewire:team.member.access />
    @endif
</div>
