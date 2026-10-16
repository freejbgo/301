<?php

use App\Http\Controllers\Api\NodeAgentController;
use Illuminate\Support\Facades\Route;

Route::prefix('nodes')->middleware(['redi.node', 'redi.node.version', 'throttle:redi-node'])->group(function () {
    Route::post('register', [NodeAgentController::class, 'register']);
    Route::get('revision', [NodeAgentController::class, 'revision']);
    Route::post('challenges-synced', [NodeAgentController::class, 'challengesSynced']);
    Route::post('heartbeat', [NodeAgentController::class, 'heartbeat']);
    Route::get('acme-challenges', [NodeAgentController::class, 'acmeChallenges']);
    Route::get('certificates', [NodeAgentController::class, 'certificates']);
    Route::post('ack', [NodeAgentController::class, 'ack']);
    Route::get('redirect-rules', [NodeAgentController::class, 'redirectRules']);
});
