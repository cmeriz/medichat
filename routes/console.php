<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about:medichat', function (): void {
    $this->info('MediChat demo project base is ready.');
})->purpose('Show MediChat demo information');
