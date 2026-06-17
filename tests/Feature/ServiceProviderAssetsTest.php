<?php

declare(strict_types=1);

use Statamic\Statamic;

it('publishes compiled control panel assets after install', function () {
    $command = new class
    {
        public array $calls = [];

        public function call(string $command, array $arguments = []): void
        {
            $this->calls[] = [$command, $arguments];
        }
    };

    Statamic::runAfterInstalledCallbacks($command);

    expect($command->calls)->toContain([
        'vendor:publish',
        [
            '--tag' => 'statamic-magic-translator',
            '--force' => true,
        ],
    ]);
});
