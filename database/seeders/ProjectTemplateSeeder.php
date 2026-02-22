<?php

namespace Database\Seeders;

use App\Models\ProjectTemplate;
use Illuminate\Database\Seeder;

class ProjectTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Tax Preparation',
                'slug' => 'tax-preparation',
                'description' => 'Organize and complete your annual tax filing with a structured checklist.',
                'category' => 'finance',
                'tasks' => [
                    ['title' => 'Gather income documents', 'description' => 'Collect W-2s, 1099s, and other income statements', 'assigned_type' => 'user', 'priority' => 'high', 'order' => 1],
                    ['title' => 'Gather deduction receipts', 'description' => 'Collect receipts for charitable donations, medical expenses, and other deductions', 'assigned_type' => 'user', 'priority' => 'high', 'order' => 2],
                    ['title' => 'Review previous year return', 'description' => 'Compare with last year to ensure nothing is missed', 'assigned_type' => 'user', 'priority' => 'medium', 'order' => 3],
                    ['title' => 'File with tax software', 'description' => 'Enter all information and file electronically', 'assigned_type' => 'user', 'priority' => 'high', 'order' => 4],
                    ['title' => 'Review before submit', 'description' => 'Double-check all entries and confirm filing', 'assigned_type' => 'user', 'priority' => 'high', 'order' => 5],
                ],
                'is_built_in' => true,
            ],
            [
                'name' => 'Home Project',
                'slug' => 'home-project',
                'description' => 'Plan and execute a home improvement or maintenance project.',
                'category' => 'home',
                'tasks' => [
                    ['title' => 'Define scope', 'description' => 'Outline what needs to be done and set a budget', 'assigned_type' => 'user', 'priority' => 'high', 'order' => 1],
                    ['title' => 'Research options', 'description' => 'Look into materials, contractors, or DIY approaches', 'assigned_type' => 'user', 'priority' => 'medium', 'order' => 2],
                    ['title' => 'Get quotes', 'description' => 'Request estimates from contractors or price materials', 'assigned_type' => 'user', 'priority' => 'medium', 'order' => 3],
                    ['title' => 'Execute work', 'description' => 'Complete the project or oversee contractor work', 'assigned_type' => 'user', 'priority' => 'high', 'order' => 4],
                    ['title' => 'Final inspection', 'description' => 'Review completed work and confirm satisfaction', 'assigned_type' => 'user', 'priority' => 'medium', 'order' => 5],
                ],
                'is_built_in' => true,
            ],
            [
                'name' => 'Learning Goal',
                'slug' => 'learning-goal',
                'description' => 'Structure a learning objective with milestones and practice sessions.',
                'category' => 'education',
                'tasks' => [
                    ['title' => 'Identify learning resources', 'description' => 'Find courses, books, tutorials, or mentors', 'assigned_type' => 'user', 'priority' => 'high', 'order' => 1],
                    ['title' => 'Create study schedule', 'description' => 'Plan regular study sessions with specific goals', 'assigned_type' => 'user', 'priority' => 'high', 'order' => 2],
                    ['title' => 'Complete module 1', 'description' => 'Finish the first major section or milestone', 'assigned_type' => 'user', 'priority' => 'medium', 'order' => 3],
                    ['title' => 'Practice exercises', 'description' => 'Apply what you learned through hands-on practice', 'assigned_type' => 'user', 'priority' => 'medium', 'order' => 4],
                    ['title' => 'Assessment and review', 'description' => 'Test your knowledge and identify areas for improvement', 'assigned_type' => 'user', 'priority' => 'medium', 'order' => 5],
                ],
                'is_built_in' => true,
            ],
            [
                'name' => 'Health Goal',
                'slug' => 'health-goal',
                'description' => 'Track and achieve a health or fitness objective with structured milestones.',
                'category' => 'health',
                'tasks' => [
                    ['title' => 'Set baseline metrics', 'description' => 'Record starting measurements, weight, or fitness levels', 'assigned_type' => 'user', 'priority' => 'high', 'order' => 1],
                    ['title' => 'Create routine', 'description' => 'Design a daily or weekly exercise and nutrition plan', 'assigned_type' => 'user', 'priority' => 'high', 'order' => 2],
                    ['title' => 'Week 1 tracking', 'description' => 'Follow the routine and log daily progress', 'assigned_type' => 'user', 'priority' => 'medium', 'order' => 3],
                    ['title' => 'Mid-point review', 'description' => 'Assess progress and adjust the plan if needed', 'assigned_type' => 'user', 'priority' => 'medium', 'order' => 4],
                    ['title' => 'Goal assessment', 'description' => 'Compare results to initial goals and set next targets', 'assigned_type' => 'user', 'priority' => 'medium', 'order' => 5],
                ],
                'is_built_in' => true,
            ],
        ];

        foreach ($templates as $template) {
            ProjectTemplate::query()->firstOrCreate(
                ['slug' => $template['slug']],
                $template,
            );
        }
    }
}
