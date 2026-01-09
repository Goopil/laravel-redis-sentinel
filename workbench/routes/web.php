<?php

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\SessionController;
use Workbench\App\Http\Middleware\TrackSession;

/*
|--------------------------------------------------------------------------
| Web Routes for Testing
|--------------------------------------------------------------------------
|
| These routes are used for testing session functionality with Orchestra.
|
*/

Route::middleware(['web', TrackSession::class])->group(function () {
    // Session CRUD operations
    Route::post('/session/store', [SessionController::class, 'store'])->name('session.store');
    Route::get('/session/get/{key}', [SessionController::class, 'get'])->name('session.get');
    Route::post('/session/store-many', [SessionController::class, 'storeMany'])->name('session.storeMany');
    Route::post('/session/flash', [SessionController::class, 'flash'])->name('session.flash');
    Route::get('/session/all', [SessionController::class, 'all'])->name('session.all');
    Route::get('/session/has/{key}', [SessionController::class, 'has'])->name('session.has');
    Route::delete('/session/forget/{key}', [SessionController::class, 'forget'])->name('session.forget');
    Route::delete('/session/flush', [SessionController::class, 'flush'])->name('session.flush');
    Route::post('/session/regenerate', [SessionController::class, 'regenerate'])->name('session.regenerate');
    Route::post('/session/invalidate', [SessionController::class, 'invalidate'])->name('session.invalidate');
    Route::post('/session/increment/{key}', [SessionController::class, 'increment'])->name('session.increment');
    Route::get('/session/test-persistence', [SessionController::class, 'testPersistence'])->name('session.testPersistence');
    Route::get('/session/metadata', [SessionController::class, 'metadata'])->name('session.metadata');
});
