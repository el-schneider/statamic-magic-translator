<?php

declare(strict_types=1);

use ElSchneider\ContentTranslator\Http\Controllers\TranslationController;
use Illuminate\Support\Facades\Route;

Route::post('content-translator/translate', [TranslationController::class, 'trigger']);
Route::get('content-translator/status', [TranslationController::class, 'status']);
