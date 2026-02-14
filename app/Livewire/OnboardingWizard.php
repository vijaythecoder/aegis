<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Security\ApiKeyManager;
use App\Security\ProviderConfig;
use Livewire\Component;

class OnboardingWizard extends Component
{
    public int $currentStep = 1;

    public string $selectedProvider = 'anthropic';

    public string $apiKey = '';

    public bool $providerSaved = false;

    public ?string $providerError = null;

    private const TOTAL_STEPS = 4;

    public function nextStep(): void
    {
        if ($this->currentStep < self::TOTAL_STEPS) {
            $this->currentStep++;
        }
    }

    public function previousStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    public function goToStep(int $step): void
    {
        if ($step >= 1 && $step <= self::TOTAL_STEPS) {
            $this->currentStep = $step;
        }
    }

    public function saveProvider(): void
    {
        $this->providerError = null;
        $this->providerSaved = false;

        $providerConfig = app(ProviderConfig::class);

        if ($providerConfig->requiresKey($this->selectedProvider)) {
            if (empty($this->apiKey)) {
                $this->addError('apiKey', 'API key is required for this provider.');

                return;
            }

            if (! $providerConfig->validate($this->selectedProvider, $this->apiKey)) {
                $this->addError('apiKey', 'Invalid API key format for this provider.');

                return;
            }

            try {
                app(ApiKeyManager::class)->store($this->selectedProvider, $this->apiKey);
            } catch (\InvalidArgumentException $e) {
                $this->addError('apiKey', $e->getMessage());

                return;
            }
        }

        Setting::query()->updateOrCreate(
            ['group' => 'app', 'key' => 'default_provider'],
            ['value' => $this->selectedProvider, 'is_encrypted' => false],
        );

        $this->providerSaved = true;
        $this->resetErrorBag('apiKey');
    }

    public function skip(): void
    {
        $this->markOnboardingComplete();
        $this->redirect('/');
    }

    public function complete(): void
    {
        $this->markOnboardingComplete();
        $this->redirect('/');
    }

    public function render()
    {
        $providerConfig = app(ProviderConfig::class);

        return view('livewire.onboarding-wizard', [
            'providers' => $providerConfig->providers(),
            'requiresKey' => $providerConfig->requiresKey($this->selectedProvider),
            'totalSteps' => self::TOTAL_STEPS,
        ]);
    }

    private function markOnboardingComplete(): void
    {
        Setting::query()->updateOrCreate(
            ['group' => 'app', 'key' => 'onboarding_completed'],
            ['value' => 'true', 'is_encrypted' => false],
        );
    }
}
