<?php

namespace App\Livewire;

use App\Models\Skill;
use Illuminate\Support\Str;
use Livewire\Component;

class SkillSettings extends Component
{
    public bool $showForm = false;

    public ?int $editingSkillId = null;

    public ?int $viewingSkillId = null;

    public string $name = '';

    public string $description = '';

    public string $instructions = '';

    public string $category = 'general';

    public string $flashMessage = '';

    public string $flashType = 'success';

    public function viewSkill(int $id): void
    {
        $this->viewingSkillId = $id;
        $this->showForm = false;
    }

    public function closeView(): void
    {
        $this->viewingSkillId = null;
    }

    public function newSkill(): void
    {
        $this->resetForm();
        $this->showForm = true;
        $this->viewingSkillId = null;
    }

    public function editSkill(int $id): void
    {
        $skill = Skill::query()->findOrFail($id);

        if ($skill->source === 'built_in') {
            $this->flash('Built-in skills cannot be edited.', 'error');

            return;
        }

        $this->editingSkillId = $skill->id;
        $this->name = $skill->name;
        $this->description = $skill->description ?? '';
        $this->instructions = $skill->instructions ?? '';
        $this->category = $skill->category ?? 'general';
        $this->showForm = true;
        $this->viewingSkillId = null;
    }

    public function createSkill(): void
    {
        $this->validate([
            'name' => 'required|string|max:100',
            'instructions' => 'required|string|max:15000',
            'category' => 'required|string|max:50',
        ]);

        Skill::query()->create([
            'name' => $this->name,
            'slug' => Str::slug($this->name),
            'description' => $this->description,
            'instructions' => $this->instructions,
            'category' => $this->category,
            'source' => 'user_created',
            'is_active' => true,
        ]);

        $name = $this->name;
        $this->resetForm();
        $this->flash("Skill \"{$name}\" created.", 'success');
    }

    public function updateSkill(): void
    {
        $this->validate([
            'name' => 'required|string|max:100',
            'instructions' => 'required|string|max:15000',
            'category' => 'required|string|max:50',
        ]);

        $skill = Skill::query()->findOrFail($this->editingSkillId);

        if ($skill->source === 'built_in') {
            $this->flash('Built-in skills cannot be edited.', 'error');

            return;
        }

        $skill->update([
            'name' => $this->name,
            'slug' => Str::slug($this->name),
            'description' => $this->description,
            'instructions' => $this->instructions,
            'category' => $this->category,
        ]);

        $this->resetForm();
        $this->flash('Skill updated.', 'success');
    }

    public function deleteSkill(int $id): void
    {
        $skill = Skill::query()->findOrFail($id);

        if ($skill->source === 'built_in') {
            $this->flash('Built-in skills cannot be deleted.', 'error');

            return;
        }

        $name = $skill->name;
        $skill->agents()->detach();
        $skill->delete();
        $this->flash("Skill \"{$name}\" deleted.", 'success');
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function render()
    {
        $viewingSkill = $this->viewingSkillId
            ? Skill::query()->withCount('agents')->find($this->viewingSkillId)
            : null;

        return view('livewire.skill-settings', [
            'builtInSkills' => Skill::query()
                ->where('source', 'built_in')
                ->where('is_active', true)
                ->withCount('agents')
                ->orderBy('name')
                ->get(),
            'customSkills' => Skill::query()
                ->where('source', 'user_created')
                ->withCount('agents')
                ->orderBy('name')
                ->get(),
            'viewingSkill' => $viewingSkill,
            'categories' => ['general', 'productivity', 'finance', 'health', 'education', 'creative', 'technical'],
        ]);
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingSkillId = null;
        $this->name = '';
        $this->description = '';
        $this->instructions = '';
        $this->category = 'general';
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }
}
