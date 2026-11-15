<?php

namespace App\Livewire\Project;

use App\Enums\AccessLevel;
use App\Models\Project;
use App\Services\Access\AccessResolver;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class AddEmpty extends Component
{
    use AuthorizesRequests;

    public string $name;

    public string $description = '';

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
        ];
    }

    protected function messages(): array
    {
        return ValidationPatterns::combinedMessages();
    }

    public function submit()
    {
        try {
            $this->authorize('create', Project::class);
            $this->validate();
            $project = Project::create([
                'name' => $this->name,
                'description' => $this->description,
                'team_id' => currentTeam()->id,
                'uuid' => new_public_id(),
            ]);
            app(AccessResolver::class)->grantProjectAccess(
                auth()->user(), currentTeam()->id, $project, [AccessLevel::Write, AccessLevel::Deploy]
            );

            $productionEnvironment = $project->environments()->where('name', 'production')->first();

            return redirect()->route('project.resource.index', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $productionEnvironment->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
