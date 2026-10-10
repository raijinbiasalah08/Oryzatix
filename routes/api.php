<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RiceScanController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\AuthController;

use App\Http\Controllers\AdminController;
use App\Http\Controllers\StaffDashboardController;

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'app' => 'ORYZATIX — Rice Disease Detection System',
        'version' => '1.0.0',
        'api' => 'v1',
        'status' => 'operational',
        'message' => 'Oryzatix REST API v1 is running and accessible.',
        'endpoints' => [
            'health' => url('/api/v1/health'),
            'treatment_guides' => url('/api/v1/treatment-guides'),
            'public_stats' => url('/api/v1/dashboard/public-stats'),
            'auth_login' => url('/api/v1/auth/login'),
            'auth_register' => url('/api/v1/auth/register'),
            'rice_detector_upload' => url('/api/v1/rice-detector/upload'),
        ],
        'web_app_url' => url('/'),
    ]);
});

Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'app' => 'Oryzatix',
        'version' => '1.0.0',
        'api' => 'v1',
        'platforms' => ['web', 'mobile'],
    ]);
})->name('api.health');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
    Route::get('/security-config', [AuthController::class, 'getSecurityConfig'])->name('auth.security-config');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot-password');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset-password');
    Route::post('/google-login', [AuthController::class, 'googleLogin'])->name('auth.google-login');
    Route::get('/google-accounts', [AuthController::class, 'googleAccounts'])->name('auth.google-accounts');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/auth/user', [AuthController::class, 'user'])->name('auth.user');
    Route::match(['put', 'post'], '/auth/profile', [AuthController::class, 'updateProfile'])->name('auth.profile');

    Route::prefix('staff')->group(function () {
        Route::get('/overview', [StaffDashboardController::class, 'overview'])->name('staff.overview');
        Route::post('/scan/{id}/advisory', [StaffDashboardController::class, 'addAdvisory'])->name('staff.advisory');
    });

    Route::prefix('admin')->group(function () {
        // 1. Overview & Dashboard
        Route::get('/overview', [AdminController::class, 'overview'])->name('admin.overview');

        // 2. User Management
        Route::get('/users', [AdminController::class, 'index'])->name('admin.users.index');
        Route::post('/users', [AdminController::class, 'store'])->name('admin.users.store');
        Route::match(['put', 'post'], '/users/{id}', [AdminController::class, 'update'])->name('admin.users.update');
        Route::delete('/users/{id}', [AdminController::class, 'destroy'])->name('admin.users.destroy');
        Route::get('/users/{id}/scans', [AdminController::class, 'getUserScans'])->name('admin.users.scans');

        // 3. Disease Management
        Route::get('/diseases', [AdminController::class, 'getDiseases'])->name('admin.diseases.index');
        Route::post('/diseases', [AdminController::class, 'storeDisease'])->name('admin.diseases.store');
        Route::match(['put', 'post'], '/diseases/{id}', [AdminController::class, 'updateDisease'])->name('admin.diseases.update');
        Route::delete('/diseases/{id}', [AdminController::class, 'destroyDisease'])->name('admin.diseases.destroy');
        Route::post('/diseases/{id}/toggle-status', [AdminController::class, 'toggleDiseaseStatus'])->name('admin.diseases.toggle');

        // 4. Detection Records / Detection Logs
        Route::get('/scans', [AdminController::class, 'getScans'])->name('admin.scans.index');
        Route::delete('/scans/{id}', [AdminController::class, 'destroyScan'])->name('admin.scans.destroy');

        // 5. Reports & Analytics
        Route::get('/reports', [AdminController::class, 'getReports'])->name('admin.reports.index');
        Route::get('/reports/export', [AdminController::class, 'exportScansCsv'])->name('admin.reports.export');

        // 6. Chatbot Management
        Route::get('/chatbot/conversations', [AdminController::class, 'getChatConversations'])->name('admin.chatbot.conversations');
        Route::get('/chatbot/knowledge', [AdminController::class, 'getChatbotKnowledge'])->name('admin.chatbot.knowledge');
        Route::post('/chatbot/knowledge', [AdminController::class, 'storeChatbotKnowledge'])->name('admin.chatbot.knowledge.store');
        Route::match(['put', 'post'], '/chatbot/knowledge/{id}', [AdminController::class, 'updateChatbotKnowledge'])->name('admin.chatbot.knowledge.update');
        Route::delete('/chatbot/knowledge/{id}', [AdminController::class, 'destroyChatbotKnowledge'])->name('admin.chatbot.knowledge.destroy');
        Route::post('/chatbot/knowledge/{id}/toggle-status', [AdminController::class, 'toggleChatbotKnowledgeStatus'])->name('admin.chatbot.knowledge.toggle');

        // 7. Security Settings (Login Attempt & Penalty Lockout Management)
        Route::get('/security-settings', [AdminController::class, 'getSecuritySettings'])->name('admin.security.get');
        Route::post('/security-settings', [AdminController::class, 'updateSecuritySettings'])->name('admin.security.update');
        Route::post('/security-settings/reset-lockouts', [AdminController::class, 'resetLockouts'])->name('admin.security.reset-lockouts');
    });

    Route::prefix('rice-detector')->group(function () {
        Route::get('/history', [RiceScanController::class, 'history'])->name('rice-detector.history');
        Route::delete('/scan/{id}', [RiceScanController::class, 'destroy'])->name('rice-detector.destroy');
    });

    Route::prefix('consultation')->group(function () {
        Route::get('/messages', [ConsultationController::class, 'index'])->name('consultation.messages');
        Route::post('/send', [ConsultationController::class, 'send'])->name('consultation.send');
        Route::post('/translate', [ConsultationController::class, 'translate'])->name('consultation.translate');
        Route::delete('/clear', [ConsultationController::class, 'clear'])->name('consultation.clear');
    });

    Route::get('/dashboard/stats', [RiceScanController::class, 'dashboardStats'])->name('dashboard.stats');
});

// Public endpoints accessible across all devices
Route::get('/treatment-guides', [RiceScanController::class, 'treatmentGuides'])->name('treatment-guides.index');
Route::get('/dashboard/public-stats', [RiceScanController::class, 'dashboardStats'])->name('dashboard.public-stats');

// Scan upload with optional user attachment (accessible for seamless scanning across all auth modes)
Route::post('/rice-detector/upload', [RiceScanController::class, 'upload'])->name('rice-detector.upload');

