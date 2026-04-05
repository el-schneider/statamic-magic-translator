<?php

declare(strict_types=1);

use ElSchneider\MagicTranslator\Http\Controllers\TranslationController;
use Illuminate\Support\Facades\Route;

Route::post('magic-translator/translate', [TranslationController::class, 'trigger']);
Route::post('magic-translator/mark-current', [TranslationController::class, 'markCurrent']);
Route::get('magic-translator/status', [TranslationController::class, 'status']);
