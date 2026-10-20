<?php

use App\Http\Controllers\Api\App\V1\MenuAlertsController;
use App\Http\Controllers\Api\App\V1\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notifications — the app's bell
|--------------------------------------------------------------------------
|
| Mirrors the web notification bell (App\Livewire\Notifications\
| {NotificationIndex,NotificationSidebarBadge}) — see
| App\Services\Notifications\AppNotificationFeed and
| App\Http\Controllers\Api\App\V1\NotificationController. 'unread-count' is
| registered ahead of the numeric {notification} route for clarity, same
| reasoning as Projects' 'statuses'.
*/
Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->whereNumber('notification')->name('notifications.read');

// The Menu's alert rows (Bank Error / Menards Sign-in / Unplaced email) —
// the web sidebar's alert cluster, see App\Services\Navigation\SidebarAlerts.
Route::get('menu/alerts', MenuAlertsController::class)->name('menu.alerts');
