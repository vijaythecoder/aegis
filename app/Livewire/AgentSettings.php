<?php

namespace App\Livewire;

use App\Models\Agent;
use App\Models\Skill;
use App\Tools\ToolRegistry;
use Illuminate\Support\Str;
use Livewire\Component;

class AgentSettings extends Component
{
    public bool $showForm = false;

    public ?int $editingAgentId = null;

    public string $name = '';

    public string $avatar = '🤖';

    public string $persona = '';

    public ?string $provider = null;

    public ?string $model = null;

    public bool $isActive = true;

    /** @var array<int, int> */
    public array $selectedSkills = [];

    /** @var array<int, string> */
    public array $selectedTools = [];

    public string $flashMessage = '';

    public string $flashType = 'success';

    public function createAgent(): void
    {
        $this->validate([
            'name' => 'required|string|max:50',
            'avatar' => 'required|string|max:10',
            'persona' => 'required|string|max:5000',
        ]);

        if (Agent::query()->count() >= 10) {
            $this->flash('Maximum of 10 agents reached.', 'error');

            return;
        }

        $agent = Agent::query()->create([
            'name' => $this->name,
            'slug' => Str::slug($this->name),
            'avatar' => $this->avatar,
            'persona' => $this->persona,
            'provider' => $this->provider ?: null,
            'model' => $this->model ?: null,
            'is_active' => $this->isActive,
        ]);

        if ($this->selectedSkills !== []) {
            $agent->skills()->sync($this->selectedSkills);
        }

        if ($this->selectedTools !== []) {
            $agent->tools()->delete();
            foreach ($this->selectedTools as $toolClass) {
                $agent->tools()->create(['tool_class' => $toolClass]);
            }
        }

        $this->resetForm();
        $this->flash("Agent \"{$agent->name}\" created.", 'success');
    }

    public function editAgent(int $id): void
    {
        $agent = Agent::query()->findOrFail($id);
        $this->editingAgentId = $agent->id;
        $this->name = $agent->name;
        $this->avatar = $agent->avatar ?? '🤖';
        $this->persona = $agent->persona ?? '';
        $this->provider = $agent->provider;
        $this->model = $agent->model;
        $this->isActive = (bool) $agent->is_active;
        $this->selectedSkills = $agent->skills()->pluck('skills.id')->map(fn ($id) => (int) $id)->all();
        $this->selectedTools = $agent->tools()->pluck('tool_class')->all();
        $this->showForm = true;
    }

    public function updateAgent(): void
    {
        $this->validate([
            'name' => 'required|string|max:50',
            'avatar' => 'required|string|max:10',
            'persona' => 'required|string|max:5000',
        ]);

        $agent = Agent::query()->findOrFail($this->editingAgentId);
        $agent->update([
            'name' => $this->name,
            'slug' => Str::slug($this->name),
            'avatar' => $this->avatar,
            'persona' => $this->persona,
            'provider' => $this->provider ?: null,
            'model' => $this->model ?: null,
            'is_active' => $this->isActive,
        ]);

        $agent->skills()->sync($this->selectedSkills);

        $agent->tools()->delete();
        foreach ($this->selectedTools as $toolClass) {
            $agent->tools()->create(['tool_class' => $toolClass]);
        }

        $this->resetForm();
        $this->flash("Agent \"{$agent->name}\" updated.", 'success');
    }

    public function deleteAgent(int $id): void
    {
        $agent = Agent::query()->findOrFail($id);

        if ($agent->slug === 'aegis') {
            $this->flash('Cannot delete the default Aegis agent.', 'error');

            return;
        }

        $name = $agent->name;
        $agent->skills()->detach();
        $agent->tools()->delete();
        $agent->delete();
        $this->flash("Agent \"{$name}\" deleted.", 'success');
    }

    public function toggleActive(int $id): void
    {
        $agent = Agent::query()->findOrFail($id);
        $agent->update(['is_active' => ! $agent->is_active]);
        $status = $agent->is_active ? 'enabled' : 'disabled';
        $this->flash("Agent \"{$agent->name}\" {$status}.", 'success');
    }

    public function newAgent(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function render()
    {
        return view('livewire.agent-settings', [
            'agents' => Agent::query()->where('slug', '!=', 'aegis')->orderBy('name')->get(),
            'availableSkills' => Skill::query()->where('is_active', true)->orderBy('name')->get(),
            'availableTools' => app(ToolRegistry::class)->names(),
            'agentCount' => Agent::query()->count(),
            'atLimit' => Agent::query()->count() >= 10,
        ]);
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingAgentId = null;
        $this->name = '';
        $this->avatar = '🤖';
        $this->persona = '';
        $this->provider = null;
        $this->model = null;
        $this->isActive = true;
        $this->selectedSkills = [];
        $this->selectedTools = [];
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }
}
