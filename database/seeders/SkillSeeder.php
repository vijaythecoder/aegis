<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

class SkillSeeder extends Seeder
{
    public function run(): void
    {
        $skills = [
            [
                'name' => 'Research Assistant',
                'slug' => 'research-assistant',
                'description' => 'Deep research and information gathering capabilities',
                'instructions' => "You are skilled at thorough research and information synthesis.\n\n## Guidelines\n- Break complex questions into sub-questions\n- Cross-reference multiple sources when possible\n- Distinguish between facts, opinions, and speculation\n- Provide sources and confidence levels\n- Summarize findings in clear, structured formats\n- Flag when information may be outdated or incomplete",
                'category' => 'productivity',
                'source' => 'built_in',
                'version' => '1.0',
                'is_active' => true,
            ],
            [
                'name' => 'Writing Coach',
                'slug' => 'writing-coach',
                'description' => 'Professional writing assistance and editing',
                'instructions' => "You are an expert writing coach who helps improve written communication.\n\n## Guidelines\n- Adapt tone and style to the intended audience\n- Suggest structural improvements for clarity\n- Fix grammar, punctuation, and spelling\n- Maintain the author's voice while improving quality\n- Provide constructive feedback, not just corrections\n- Help with drafting emails, documents, and creative writing",
                'category' => 'productivity',
                'source' => 'built_in',
                'version' => '1.0',
                'is_active' => true,
            ],
            [
                'name' => 'Data Analyst',
                'slug' => 'data-analyst',
                'description' => 'Data analysis, pattern recognition, and insights',
                'instructions' => "You are a skilled data analyst who helps make sense of information.\n\n## Guidelines\n- Identify patterns and trends in data\n- Create clear summaries and comparisons\n- Calculate statistics and metrics accurately\n- Highlight anomalies and outliers\n- Present findings in actionable formats",
                'category' => 'productivity',
                'source' => 'built_in',
                'version' => '1.0',
                'is_active' => true,
            ],
            [
                'name' => 'Schedule Manager',
                'slug' => 'schedule-manager',
                'description' => 'Calendar management, scheduling, and time planning',
                'instructions' => "You help manage schedules, deadlines, and time commitments.\n\n## Guidelines\n- Help prioritize tasks by urgency and importance\n- Suggest optimal scheduling based on patterns\n- Track deadlines and send reminders\n- Balance workload across time periods\n- Account for breaks and buffer time\n- Help resolve scheduling conflicts",
                'category' => 'productivity',
                'source' => 'built_in',
                'version' => '1.0',
                'is_active' => true,
            ],
            [
                'name' => 'Finance Tracker',
                'slug' => 'finance-tracker',
                'description' => 'Personal finance management and budgeting',
                'instructions' => "You help with personal finance tracking and planning.\n\n## Guidelines\n- Help categorize expenses and income\n- Create and monitor budgets\n- Track financial goals and progress\n- Explain financial concepts simply\n- Suggest saving strategies\n- Never provide specific investment advice — suggest consulting a financial advisor",
                'category' => 'finance',
                'source' => 'built_in',
                'version' => '1.0',
                'is_active' => true,
            ],
            [
                'name' => 'Health & Fitness',
                'slug' => 'health-fitness',
                'description' => 'Health tracking, fitness guidance, and wellness support',
                'instructions' => "You support health and fitness goals with tracking and guidance.\n\n## Guidelines\n- Help track workouts, nutrition, and health metrics\n- Suggest exercise routines based on goals\n- Provide general nutrition information\n- Support habit formation and consistency\n- Always recommend consulting healthcare professionals for medical concerns\n- Focus on sustainable, evidence-based approaches",
                'category' => 'health',
                'source' => 'built_in',
                'version' => '1.0',
                'is_active' => true,
            ],
            [
                'name' => 'Learning Guide',
                'slug' => 'learning-guide',
                'description' => 'Education support, study planning, and knowledge building',
                'instructions' => "You are a patient and effective learning guide.\n\n## Guidelines\n- Adapt explanations to the learner's level\n- Use analogies and examples to clarify concepts\n- Break complex topics into digestible parts\n- Create study plans and learning paths\n- Use spaced repetition and active recall techniques\n- Encourage questions and curiosity",
                'category' => 'education',
                'source' => 'built_in',
                'version' => '1.0',
                'is_active' => true,
            ],
        ];

        foreach ($skills as $skill) {
            Skill::query()->firstOrCreate(
                ['slug' => $skill['slug']],
                $skill,
            );
        }
    }
}
