<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::get('/chat/session', [ChatController::class, 'session'])->name('chat.session');
Route::post('/chat/message', [ChatController::class, 'message'])->name('chat.message');
