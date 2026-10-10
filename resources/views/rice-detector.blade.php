<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=5.0">
<title>ORYZATIX — Rice Leaf Disease Detection Web Application</title>
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#16501a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Oryzatix">
<meta name="mobile-web-app-capable" content="yes">
<meta name="description" content="Image processing-based rice leaf disease detection using MobileNet with management recommendations">
<link rel="manifest" href="{{ asset('manifest.json') }}">
<link rel="apple-touch-icon" href="{{ asset('images/logo.png') }}">
<link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/rice-detector.css') }}?v={{ time() }}">
<meta name="google-signin-client_id" content="{{ config('services.google.client_id') ?? env('GOOGLE_CLIENT_ID', '') }}">
<script src="https://accounts.google.com/gsi/client" async defer></script>
@php
  $currentUserData = null;
  $tokenParam = request()->query('auth_token') ?: session('auth_token');
  $u = null;

  if (Auth::check()) {
      $u = Auth::user();
  } elseif ($tokenParam) {
      try {
          $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($tokenParam);
          if ($accessToken && $accessToken->tokenable) {
              $u = $accessToken->tokenable;
              Auth::guard('web')->login($u);
          }
      } catch (\Throwable $e) {}
  }

  if ($u) {
      $roleLabel = match($u->role) {
          'farmer' => 'Rice Farmer',
          'agri_worker' => 'Agricultural Extension Worker',
          'admin' => 'Administrator / Researcher',
          default => ucfirst($u->role),
      };
      $avatarUrl = null;
      if ($u->avatar) {
          $avatarUrl = (str_starts_with($u->avatar, 'http') || str_starts_with($u->avatar, 'data:'))
              ? $u->avatar
              : asset('storage/' . $u->avatar);
      }
      $currentUserData = [
          'id' => $u->id,
          'name' => $u->name,
          'email' => $u->email,
          'role' => $u->role,
          'role_label' => $roleLabel,
          'location' => $u->location ?: 'Not specified',
          'avatar' => $u->avatar,
          'avatar_url' => $avatarUrl,
          'created_at' => $u->created_at ? $u->created_at->format('M j, Y') : 'N/A',
      ];
  }
@endphp
<script>
  window.INITIAL_AUTH = {
    user: @json($currentUserData),
    token: @json($tokenParam ?: session('auth_token', null)),
    googleLoginSuccess: @json(session('google_login_success', false) || request()->query('google_login') === '1'),
  };
</script>
</head>
<body>

<input type="file" id="fileInput" accept="image/*" style="display:none;">
<input type="hidden" id="chatLanguage" value="tagalog">
<input type="hidden" id="uiLanguage" value="english">

<div class="web-app-shell auth-mode">

  <!-- ═══════════ DESKTOP NAVIGATION SIDEBAR ═══════════ -->
  <aside class="web-sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">
        <img src="{{ asset('images/logo.png') }}" alt="Oryzatix Logo" class="brand-logo-img">
      </div>
      <div class="brand-text">
        <h1>ORYZATIX</h1>
        <p data-en="Rice Disease Detection" data-tl="Pagtukoy ng Sakit sa Palay">Rice Disease Detection</p>
      </div>
    </div>

    <nav class="sidebar-menu">
      <!-- FARMER DASHBOARD -->
      <button class="sidebar-nav-btn active" id="sidebarFarmerHomeBtn" data-screen="home" onclick="showScreen('home'); loadHomeRecentScans();">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        <span data-en="Dashboard" data-tl="Dashboard">Dashboard</span>
      </button>

      <!-- ADMIN DASHBOARD (Top Primary Dashboard for Admin) -->
      <button class="sidebar-nav-btn" id="sidebarAdminHomeBtn" data-screen="admin-dashboard" onclick="showScreen('admin-dashboard'); loadAdminDashboard();" style="display: none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        <span data-en="Admin Dashboard" data-tl="Admin Dashboard">Admin Dashboard</span>
      </button>

      <!-- STAFF / EXTENSION WORKER DASHBOARD (Top Primary Dashboard for Staff) -->
      <button class="sidebar-nav-btn" id="sidebarStaffHomeBtn" data-screen="staff-dashboard" onclick="showScreen('staff-dashboard'); loadStaffDashboard();" style="display: none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        <span data-en="Staff Dashboard" data-tl="Staff Dashboard">Staff Dashboard</span>
      </button>

      <button class="sidebar-nav-btn" data-screen="scan" onclick="showScreen('scan');">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        <span data-en="Disease Scan" data-tl="Pag-scan ng Sakit">Disease Scan</span>
      </button>

      <button class="sidebar-nav-btn" data-screen="history" onclick="showScreen('history'); loadHistory();">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span data-en="Scan History" data-tl="Kasaysayan ng Scan">Scan History</span>
      </button>

      <button class="sidebar-nav-btn" data-screen="consultation" onclick="showScreen('consultation'); loadChatMessages();">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <span data-en="AI Consultation" data-tl="AI Konsulta">AI Consultation</span>
      </button>

      <button class="sidebar-nav-btn" data-screen="treatment" onclick="showScreen('treatment'); loadCurrentTreatment();">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <span data-en="Treatment Guide" data-tl="Gabay sa Gamutan">Treatment Guide</span>
      </button>

      <!-- ADMIN ONLY SECTION -->
      <div id="sidebarAdminGroup" style="display: none; display: contents;">
        <button class="sidebar-nav-btn" data-screen="admin-users" onclick="showScreen('admin-users'); loadAdminUsers();">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <span data-en="User Accounts" data-tl="Mga Account">User Management</span>
        </button>

        <button class="sidebar-nav-btn" data-screen="admin-diseases" onclick="showScreen('admin-diseases'); loadAdminDiseases();">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <span data-en="Disease Management" data-tl="Pamamahala ng Sakit">Disease Info</span>
        </button>

        <button class="sidebar-nav-btn" data-screen="admin-scans-logs" onclick="showScreen('admin-scans-logs'); loadAdminScansLogs();">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <span data-en="Detection Logs" data-tl="Mga Tala ng Scan">Detection Records</span>
        </button>

        <button class="sidebar-nav-btn" data-screen="admin-reports" onclick="showScreen('admin-reports'); loadAdminReports();">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
          <span data-en="Reports & Analytics" data-tl="Ulat at Analytics">Reports & Analytics</span>
        </button>

        <button class="sidebar-nav-btn" data-screen="admin-chatbot" onclick="showScreen('admin-chatbot'); loadAdminChatbot();">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
          <span data-en="Chatbot FAQ / AI" data-tl="Chatbot at FAQ">Chatbot Manager</span>
        </button>
      </div>
    </nav>
  </aside>

  <!-- ═══════════ MOBILE SLIDE-OVER DRAWER NAVIGATION ═══════════ -->
  <div class="mobile-drawer-overlay" id="mobileDrawerOverlay" onclick="closeMobileDrawer()"></div>
  <aside class="mobile-drawer" id="mobileDrawer">
    <div class="drawer-header">
      <div class="sidebar-brand">
        <div class="brand-icon">
          <img src="{{ asset('images/logo.png') }}" alt="Oryzatix Logo" class="brand-logo-img">
        </div>
        <div class="brand-text">
          <h1>ORYZATIX</h1>
          <p data-en="Rice Disease Detection" data-tl="Pagtukoy ng Sakit sa Palay">Rice Disease Detection</p>
        </div>
      </div>
      <button class="drawer-close-btn" onclick="closeMobileDrawer()" title="Close Menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <nav class="drawer-menu sidebar-menu">
      <!-- FARMER DASHBOARD -->
      <button class="sidebar-nav-btn active" id="drawerFarmerHomeBtn" data-screen="home" onclick="showScreen('home'); loadHomeRecentScans(); closeMobileDrawer();">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        <span data-en="Dashboard" data-tl="Dashboard">Dashboard</span>
      </button>

      <!-- ADMIN DASHBOARD -->
      <button class="sidebar-nav-btn" id="drawerAdminHomeBtn" data-screen="admin-dashboard" onclick="showScreen('admin-dashboard'); loadAdminDashboard(); closeMobileDrawer();" style="display: none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        <span data-en="Admin Dashboard" data-tl="Admin Dashboard">Admin Dashboard</span>
      </button>

      <!-- STAFF DASHBOARD -->
      <button class="sidebar-nav-btn" id="drawerStaffHomeBtn" data-screen="staff-dashboard" onclick="showScreen('staff-dashboard'); loadStaffDashboard(); closeMobileDrawer();" style="display: none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        <span data-en="Staff Dashboard" data-tl="Staff Dashboard">Staff Dashboard</span>
      </button>

      <button class="sidebar-nav-btn" data-screen="scan" onclick="showScreen('scan'); closeMobileDrawer();">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        <span data-en="Disease Scan" data-tl="Pag-scan ng Sakit">Disease Scan</span>
      </button>

      <button class="sidebar-nav-btn" data-screen="history" onclick="showScreen('history'); loadHistory(); closeMobileDrawer();">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span data-en="Scan History" data-tl="Kasaysayan ng Scan">Scan History</span>
      </button>

      <button class="sidebar-nav-btn" data-screen="consultation" onclick="showScreen('consultation'); loadChatMessages(); closeMobileDrawer();">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <span data-en="AI Consultation" data-tl="AI Konsulta">AI Consultation</span>
      </button>

      <button class="sidebar-nav-btn" data-screen="treatment" onclick="showScreen('treatment'); loadCurrentTreatment(); closeMobileDrawer();">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <span data-en="Treatment Guide" data-tl="Gabay sa Gamutan">Treatment Guide</span>
      </button>

      <button class="sidebar-nav-btn" onclick="openModal('modalMobileConnect'); closeMobileDrawer();">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
        <span>Connect Mobile / Wi-Fi</span>
      </button>

      <button class="sidebar-nav-btn" id="drawerInstallBtn" onclick="triggerPwaInstall(); closeMobileDrawer();" style="display: none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span>Install App</span>
      </button>

      <!-- ADMIN ONLY SECTION -->
      <div id="drawerAdminGroup" style="display: none; display: contents;">
        <button class="sidebar-nav-btn" data-screen="admin-users" onclick="showScreen('admin-users'); loadAdminUsers(); closeMobileDrawer();">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <span data-en="User Accounts" data-tl="Mga Account">User Management</span>
        </button>

        <button class="sidebar-nav-btn" data-screen="admin-diseases" onclick="showScreen('admin-diseases'); loadAdminDiseases(); closeMobileDrawer();">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <span data-en="Disease Management" data-tl="Pamamahala ng Sakit">Disease Info</span>
        </button>

        <button class="sidebar-nav-btn" data-screen="admin-scans-logs" onclick="showScreen('admin-scans-logs'); loadAdminScansLogs(); closeMobileDrawer();">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <span data-en="Detection Logs" data-tl="Mga Tala ng Scan">Detection Records</span>
        </button>

        <button class="sidebar-nav-btn" data-screen="admin-reports" onclick="showScreen('admin-reports'); loadAdminReports(); closeMobileDrawer();">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
          <span data-en="Reports & Analytics" data-tl="Ulat at Analytics">Reports & Analytics</span>
        </button>

        <button class="sidebar-nav-btn" data-screen="admin-chatbot" onclick="showScreen('admin-chatbot'); loadAdminChatbot(); closeMobileDrawer();">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
          <span data-en="Chatbot FAQ / AI" data-tl="Chatbot at FAQ">Chatbot Manager</span>
        </button>
      </div>
    </nav>
  </aside>

  <!-- ═══════════ MAIN WORKSPACE CONTAINER ═══════════ -->
  <main class="web-main-workspace">

    <!-- Desktop / Mobile Responsive Topbar -->
    <header class="web-topbar">
      <div style="display: flex; align-items: center; gap: 8px;">
        <button type="button" class="mobile-menu-toggle-btn" onclick="toggleMobileDrawer()" title="Open Navigation Menu">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <h2 class="topbar-title" id="webPageTitle" data-en="Dashboard" data-tl="Dashboard">Dashboard</h2>
      </div>
      <div class="topbar-actions">
        <!-- Live Real-Time Date & Time Clock Widget -->
        <div class="topbar-datetime-widget" id="topbarDatetimeWidget" title="Current Real-Time Date & Time">
          <div class="topbar-datetime-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          </div>
          <div class="topbar-datetime-text">
            <span class="topbar-date" id="topbarLiveDate">--</span>
            <span class="topbar-datetime-divider">&bull;</span>
            <span class="topbar-time" id="topbarLiveTime">--:--:-- --</span>
          </div>
        </div>

        <!-- Mobile App LAN QR Code Trigger -->
        <button type="button" class="topbar-action-pill-btn mobile-lan-btn" onclick="openModal('modalMobileConnect')" title="Open Oryzatix on Mobile Device via Wi-Fi">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
          <span class="btn-text-desktop">Mobile App</span>
        </button>

        <!-- Dynamic In-App PWA Install Button -->
        <button type="button" class="topbar-action-pill-btn pwa-install-btn" id="btnInstallAppTopbar" onclick="triggerPwaInstall()" style="display: none;" title="Install Oryzatix App">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          <span class="btn-text-desktop">Install App</span>
        </button>

        <button class="topbar-notif-btn" onclick="openModal('modalNotifications')" title="Notifications">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <div class="notif-dot"></div>
        </button>

        <!-- Topbar User Profile & Settings Dropdown Trigger -->
        <div class="topbar-user-menu-wrap" id="topbarUserMenuWrap">
          <button type="button" class="topbar-user-btn" id="topbarUserBtn" onclick="toggleTopbarUserDropdown(event)" aria-haspopup="true" aria-expanded="false" title="Account & Settings">
            <div class="topbar-user-avatar" id="topbarUserAvatar">
              <span id="topbarUserAvatarInitials">MJ</span>
              <img id="topbarUserAvatarImg" src="" alt="User Avatar" style="display: none;">
            </div>
            <div class="topbar-user-meta">
              <span class="topbar-user-name" id="topbarUserName">Mang Juan</span>
              <span class="topbar-user-role" id="topbarUserRole" data-en="Rice Farmer" data-tl="Magsasaka ng Palay">Rice Farmer</span>
            </div>
            <svg class="topbar-user-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </button>

          <!-- Filtered Settings & Profile Dropdown Panel -->
          <div class="topbar-user-dropdown" id="topbarUserDropdown" style="display: none;">
            <div class="dropdown-user-header">
              <div class="dropdown-avatar-wrap">
                <div class="dropdown-avatar" id="dropdownAvatar">MJ</div>
                <button type="button" class="dropdown-avatar-edit" onclick="openModal('modalEditProfile'); toggleTopbarUserDropdown();" title="Change Photo">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                </button>
              </div>
              <div class="dropdown-user-info">
                <h4 id="dropdownUserName">Mang Juan</h4>
                <p id="dropdownUserEmail">user@oryzatix.ph</p>
                <span class="dropdown-role-badge" id="dropdownUserRoleBadge">Rice Farmer</span>
                <div class="dropdown-location" id="dropdownUserLocation">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                  <span id="dropdownLocationText">Roxas, Oriental Mindoro</span>
                </div>
              </div>
            </div>

            <div class="dropdown-divider"></div>

            <div class="dropdown-menu-list">
              <button type="button" class="dropdown-item" onclick="openModal('modalEditProfile'); toggleTopbarUserDropdown();">
                <div class="item-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
                <div class="item-text">
                  <span class="item-title" data-en="Edit Profile & Password" data-tl="I-edit ang Profile at Password">Edit Profile & Password</span>
                  <span class="item-desc" data-en="Photo, display name, location & security" data-tl="Larawan, pangalan, lokasyon at seguridad">Photo, display name, location & security</span>
                </div>
              </button>

              <button type="button" class="dropdown-item" onclick="showScreen('profile'); toggleTopbarUserDropdown();">
                <div class="item-icon teal"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></div>
                <div class="item-text">
                  <span class="item-title" data-en="Account & App Settings" data-tl="Mga Setting ng Account">Account & App Settings</span>
                  <span class="item-desc" data-en="View all profile details & preferences" data-tl="Tingnan ang lahat ng detalye ng profile">View all profile details & preferences</span>
                </div>
              </button>

              <button type="button" class="dropdown-item" onclick="openModal('modalDiseaseLibrary'); toggleTopbarUserDropdown();">
                <div class="item-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></div>
                <div class="item-text">
                  <span class="item-title" data-en="Disease Library" data-tl="Aklatan ng Sakit">Disease Reference Library</span>
                  <span class="item-desc" data-en="Symptom guides & diagnostic aids" data-tl="Mga gabay sa sintomas">Symptom guides & diagnostic aids</span>
                </div>
              </button>

              <button type="button" class="dropdown-item" onclick="openModal('modalHelpSupport'); toggleTopbarUserDropdown();">
                <div class="item-icon amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
                <div class="item-text">
                  <span class="item-title" data-en="Photography Tips" data-tl="Mga Tip sa Litrato">Photography & Lighting Tips</span>
                  <span class="item-desc" data-en="Best practices for AI leaf scans" data-tl="Wastong pag-scan ng dahon">Best practices for AI leaf scans</span>
                </div>
              </button>

              <button type="button" class="dropdown-item" onclick="openModal('modalAbout'); toggleTopbarUserDropdown();">
                <div class="item-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg></div>
                <div class="item-text">
                  <span class="item-title" data-en="About Oryzatix" data-tl="Tungkol sa Oryzatix">About Oryzatix System</span>
                  <span class="item-desc" data-en="DA-PhilRice v2.0 AI Platform" data-tl="DA-PhilRice v2.0 AI Platform">DA-PhilRice v2.0 AI Platform</span>
                </div>
              </button>

              <button type="button" class="dropdown-item" onclick="openModal('modalMobileConnect'); toggleTopbarUserDropdown();">
                <div class="item-icon purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg></div>
                <div class="item-text">
                  <span class="item-title">Mobile Phone Connect (Wi-Fi)</span>
                  <span class="item-desc">Scan QR code to open & install on phone</span>
                </div>
              </button>

              <button type="button" class="dropdown-item" id="dropdownInstallItem" onclick="triggerPwaInstall(); toggleTopbarUserDropdown();" style="display: none;">
                <div class="item-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></div>
                <div class="item-text">
                  <span class="item-title">Install Oryzatix App</span>
                  <span class="item-desc">Add to Home Screen as native app</span>
                </div>
              </button>
            </div>

            <div class="dropdown-divider"></div>

            <div class="dropdown-footer">
              <button type="button" class="dropdown-signout-btn" onclick="openModal('modalLogoutConfirm'); toggleTopbarUserDropdown();">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span data-en="Sign Out" data-tl="Mag-sign Out">Sign Out</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </header>

    <!-- Page Content View Area -->
    <div class="web-page-content">

      <!-- ═══════════ SCREEN 1: LOGIN ═══════════ -->
      <section class="screen active" id="login">
        <div class="auth-web-card">
          <div class="auth-header-card">
            <div class="auth-logo-badge">
              <img src="{{ asset('images/logo.png') }}" alt="Oryzatix Logo" class="auth-app-logo">
            </div>
            <h1>Welcome Back</h1>
            <p>Rice Leaf Disease Detection</p>
          </div>

          <div class="auth-body">
            @if(session('auth_error'))
              <div class="error-banner show" id="loginError">{{ session('auth_error') }}</div>
            @else
              <div class="error-banner" id="loginError"></div>
            @endif

            @if(session('auth_success'))
              <div class="success-banner show" id="loginSuccess">{{ session('auth_success') }}</div>
            @else
              <div class="success-banner" id="loginSuccess"></div>
            @endif

            <!-- Lockout Countdown Alert Card -->
            <div class="lockout-countdown-card" id="loginLockoutCard" style="display: none;">
              <div class="lockout-header">
                <div class="lockout-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <div class="lockout-text">
                  <h4>Temporary Login Lockout</h4>
                  <p id="loginLockoutMsg">Too many incorrect password attempts.</p>
                </div>
              </div>
              <div class="lockout-timer-wrap">
                <div class="lockout-time-label">Try again in:</div>
                <div class="lockout-timer-display" id="loginLockoutTimer">00:30</div>
              </div>
              <div class="lockout-progress-bar">
                <div class="lockout-progress-fill" id="loginLockoutProgress" style="width: 100%;"></div>
              </div>
            </div>

            <form class="auth-form" id="loginForm" onsubmit="handleLogin(event); return false;" action="javascript:void(0);">
              <div class="form-group">
                <label for="loginEmail">USERNAME OR EMAIL ADDRESS</label>
                <input type="text" id="loginEmail" placeholder="Enter username or email address" required autocomplete="username">
              </div>

              <div class="form-group">
                <div class="form-label-row">
                  <label for="loginPassword">PASSWORD</label>
                  <a href="javascript:void(0)" class="auth-forgot-link" onclick="showScreen('forgot-password')">Forgot Password?</a>
                </div>
                <div class="password-wrapper">
                  <input type="password" id="loginPassword" placeholder="Enter your password" required autocomplete="current-password">
                  <button type="button" class="toggle-password-btn" onclick="togglePassword('loginPassword', this)" aria-label="Show password">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
                <!-- Remaining Attempts Warning Badge below password input -->
                <div class="attempts-warning-badge" id="loginAttemptsBadge" style="display: none;">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  <span id="loginAttemptsText">Remaining login attempts before lockout: 2 / 3</span>
                </div>
              </div>

              <button type="submit" class="auth-btn" id="loginBtn">Sign In</button>
            </form>

            <div class="auth-or-divider">
              <span></span>
              <p>or</p>
              <span></span>
            </div>

            <!-- Instant Direct Google Sign-In Button -->
            <a href="{{ route('auth.google.redirect') }}?mode=login" class="google-auth-btn" style="text-decoration: none;">
              <svg viewBox="0 0 24 24" width="18" height="18">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
              </svg>
              <span>Continue with Google</span>
            </a>

            <div class="auth-switch">
              <span>Don't have an account? </span>
              <a href="javascript:void(0)" onclick="showScreen('register')">Register</a>
            </div>
          </div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 2: REGISTER ═══════════ -->
      <section class="screen" id="register">
        <div class="auth-web-card">
          <div class="auth-header-card">
            <div class="auth-logo-badge">
              <img src="{{ asset('images/logo.png') }}" alt="Oryzatix Logo" class="auth-app-logo">
            </div>
            <h1>Create Account</h1>
            <p>Create your ORYZATIX account</p>
          </div>

          <div class="auth-body">
            <div class="error-banner" id="registerError"></div>
            <div class="success-banner" id="registerSuccess"></div>

            <form class="auth-form" id="registerForm" onsubmit="handleRegister(event); return false;" action="javascript:void(0);">
              <div class="form-group">
                <label for="regName">FULL NAME</label>
                <input type="text" id="regName" placeholder="Enter your full name" required autocomplete="name">
              </div>

              <div class="form-group">
                <label for="regEmail">EMAIL ADDRESS</label>
                <input type="email" id="regEmail" placeholder="Enter your email address" required autocomplete="email">
              </div>

              <div class="form-group">
                <label for="regRole">USER ROLE</label>
                <select id="regRole" required>
                  <option value="farmer">Rice Farmer</option>
                  <option value="agri_worker">Agricultural Extension Worker</option>
                </select>
              </div>

              <div class="form-group">
                <label for="regLocation">FARM LOCATION</label>
                <input type="text" id="regLocation" placeholder="Enter farm location (e.g. Barangay San Jose, Bicol)" autocomplete="address-level2">
              </div>

              <div class="form-group">
                <label for="regPassword">PASSWORD</label>
                <div class="password-wrapper">
                  <input type="password" id="regPassword" placeholder="Enter password (min 6 chars)" minlength="6" required autocomplete="new-password">
                  <button type="button" class="toggle-password-btn" onclick="togglePassword('regPassword', this)" aria-label="Show password">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
              </div>

              <div class="form-group">
                <label for="regPasswordConfirm">CONFIRM PASSWORD</label>
                <div class="password-wrapper">
                  <input type="password" id="regPasswordConfirm" placeholder="Confirm password" minlength="6" required autocomplete="new-password">
                  <button type="button" class="toggle-password-btn" onclick="togglePassword('regPasswordConfirm', this)" aria-label="Show password">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
              </div>

              <button type="submit" class="auth-btn" id="regBtn">Create Account</button>
            </form>

            <div class="auth-or-divider">
              <span></span>
              <p>or</p>
              <span></span>
            </div>

            <!-- Instant Direct Google Sign-Up Button -->
            <a href="{{ route('auth.google.redirect') }}?mode=register" class="google-auth-btn" style="text-decoration: none;">
              <svg viewBox="0 0 24 24" width="18" height="18">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
              </svg>
              <span>Continue with Google</span>
            </a>

            <div class="auth-switch">
              <span>Already have an account? </span>
              <a href="javascript:void(0)" onclick="showScreen('login')">Sign In</a>
            </div>
          </div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 2B: FORGOT PASSWORD (OTP) ═══════════ -->
      <section class="screen" id="forgot-password">
        <div class="auth-web-card">
          <div class="auth-header-card">
            <div class="auth-logo-badge">
              <img src="{{ asset('images/logo.png') }}" alt="Oryzatix Logo" class="auth-app-logo">
            </div>
            <h1>Forgot Password</h1>
            <p>Recover your account using email OTP</p>
          </div>

          <div class="auth-body">
            <div class="error-banner" id="forgotError"></div>
            <div class="success-banner" id="forgotSuccess"></div>

            <!-- STEP 1: Enter Email to receive 6-digit OTP -->
            <div id="forgotStep1">
              <form class="auth-form" onsubmit="handleSendOtp(event); return false;" action="javascript:void(0);">
                <div class="form-group">
                  <label for="forgotEmail">EMAIL ADDRESS</label>
                  <input type="email" id="forgotEmail" placeholder="Enter your email address" required autocomplete="email">
                </div>

                <button type="submit" class="auth-btn" id="btnSendOtp">Send Verification Code</button>
              </form>
            </div>

            <!-- STEP 2: Enter OTP Code and Set New Password -->
            <div id="forgotStep2" style="display: none;">
              <div class="otp-sent-info-card">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <div>
                  <span>Verification code sent to:</span>
                  <strong id="forgotSentEmailText">user@gmail.com</strong>
                </div>
              </div>

              <form class="auth-form" onsubmit="handleResetPassword(event); return false;" action="javascript:void(0);">
                <div class="form-group">
                  <label for="forgotOtp">6-DIGIT VERIFICATION CODE (OTP)</label>
                  <input type="text" id="forgotOtp" placeholder="Enter 6-digit code" maxlength="6" pattern="[0-9]{6}" required autocomplete="one-time-code" style="letter-spacing: 4px; font-weight: 800; font-size: 18px; text-align: center;">
                </div>

                <div class="form-group">
                  <label for="forgotNewPassword">NEW PASSWORD</label>
                  <div class="password-wrapper">
                    <input type="password" id="forgotNewPassword" placeholder="Enter new password (min 6 chars)" minlength="6" required autocomplete="new-password">
                    <button type="button" class="toggle-password-btn" onclick="togglePassword('forgotNewPassword', this)" aria-label="Show password">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                  </div>
                </div>

                <div class="form-group">
                  <label for="forgotNewPasswordConfirm">CONFIRM NEW PASSWORD</label>
                  <div class="password-wrapper">
                    <input type="password" id="forgotNewPasswordConfirm" placeholder="Confirm new password" minlength="6" required autocomplete="new-password">
                    <button type="button" class="toggle-password-btn" onclick="togglePassword('forgotNewPasswordConfirm', this)" aria-label="Show password">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                  </div>
                </div>

                <button type="submit" class="auth-btn" id="btnResetPassword">Reset Password</button>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                  <a href="javascript:void(0)" class="auth-forgot-link" onclick="handleResendOtp()">Resend Code</a>
                  <a href="javascript:void(0)" class="auth-forgot-link" onclick="changeForgotEmail()">Change Email</a>
                </div>
              </form>
            </div>

            <div class="auth-or-divider">
              <span></span>
              <p>or</p>
              <span></span>
            </div>

            <div class="auth-switch">
              <a href="javascript:void(0)" onclick="showScreen('login')">← Back to Sign In</a>
            </div>
          </div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 3: SPLASH ═══════════ -->
      <section class="screen" id="splash" onclick="checkAuthAndProceed()">
        <div class="splash-logo">
          <img src="{{ asset('images/logo.png') }}" alt="Oryzatix Logo" style="width: 100%; height: 100%; object-fit: contain; border-radius: 50%;">
        </div>
        <h1>ORYZATIX</h1>
        <p>An Image Processing-Based System for Rice Leaf Disease Detection using MobileNet with Management Recommendations</p>
        <div class="tap-hint">Click anywhere to continue →</div>
      </section>

      <!-- ═══════════ SCREEN 4: HOME / DASHBOARD ═══════════ -->
      <section class="screen" id="home">
        <div class="dashboard-welcome-header">
          <div class="dwh-user">
            <h2 id="homeUserName">Mang Juan</h2>
            <p data-en="Rice Leaf Health & Disease Diagnostic Dashboard" data-tl="Dashboard sa Kalusugan ng Dahon ng Palay at Pagsusuri ng Sakit">Rice Leaf Health & Disease Diagnostic Dashboard</p>
          </div>
        </div>

        <div class="dashboard-hero-row">
          <div class="quick-scan-card">
            <div>
              <h3>Scan Rice Plant Leaf</h3>
              <p>Capture or upload a leaf photo to diagnose rice diseases instantly with MobileNet deep learning AI.</p>
            </div>
            <button class="scan-action-btn" onclick="showScreen('scan')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
              <span>Start Leaf Scan</span>
            </button>
          </div>
        </div>

        <div class="section-header">
          <h3>Recent Diagnosis Scans</h3>
          <a onclick="showScreen('history'); loadHistory();">View All Logs →</a>
        </div>

        <div class="recent-scans-grid" id="homeRecentScansContainer">
          <div class="empty-scans-card">
            <div class="esc-icon">🌱</div>
            <div class="esc-text">
              <h4>No recent scans yet</h4>
              <p>Capture or upload a leaf photo to diagnose rice diseases.</p>
            </div>
            <button class="esc-btn" onclick="showScreen('scan')">Scan Leaf</button>
          </div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 5: DISEASE SCANNER ═══════════ -->
      <section class="screen" id="scan">
        <div class="scan-box-wrapper">
          <div class="camera-viewfinder" id="cameraViewfinder">
            <video id="cameraStream" autoplay playsinline muted></video>
            <div class="scan-laser-line"></div>
            <div class="corner-marker tl"></div>
            <div class="corner-marker tr"></div>
            <div class="corner-marker bl"></div>
            <div class="corner-marker br"></div>
            <div class="scan-overlay" id="scanOverlay">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><path d="M7 12h10"/><path d="M12 7v10"/></svg>
              <p id="scanOverlayText">Position leaf inside the viewfinder box</p>
            </div>
          </div>

          <div class="scan-controls">
            <button class="secondary-btn" title="Gallery Upload" onclick="document.getElementById('fileInput').click();">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            </button>
            <button class="capture-btn" id="shutterBtn" onclick="handleCaptureClick()" title="Capture Leaf Photo"></button>
            <button class="secondary-btn" id="cameraToggleBtn" title="Toggle Live Camera" onclick="toggleLiveCamera()">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            </button>
          </div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 6: LOADING / ANALYZING ═══════════ -->
      <section class="screen" id="loading">
        <div class="loading-ring"></div>
        <h2>Analyzing Rice Leaf</h2>
        <p>Scanning leaf lesion patterns using MobileNet Convolutional Neural Network architecture...</p>
        <div class="loading-steps">
          <div class="loading-step" id="step1"><div class="step-check"></div><span>Pre-processing & resizing</span></div>
          <div class="loading-step" id="step2"><div class="step-check"></div><span>Feature extraction (edges & lesions)</span></div>
          <div class="loading-step" id="step3"><div class="step-check"></div><span>MobileNet CNN diagnosis</span></div>
          <div class="loading-step" id="step4"><div class="step-check"></div><span>Severity & recommendations</span></div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 7: DETECTION RESULTS ═══════════ -->
      <section class="screen" id="results">
        <div class="result-image-card disease-blast" id="resultImageCard">
          <svg class="default-leaf-icon" id="resultImageCardIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          <div class="result-overlay">
            <div class="date-tag" id="resultDateTag">Today</div>
          </div>
        </div>

        <div class="result-diagnosis warning" id="resultDiagnosis">
          <div class="diag-icon warn" id="resultDiagIcon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          </div>
          <div class="diag-text">
            <h3 id="resultDiseaseName">Leaf Blast</h3>
            <p id="resultScientificName">Caused by <em>Magnaporthe oryzae</em></p>
          </div>
        </div>

        <div class="severity-meter">
          <h4 id="resultSeverityHeading">Severity Level & Leaf Area Affected</h4>
          <div class="severity-bar-track"><div class="severity-bar-fill severe" id="resultSeverityFill"></div></div>
          <div class="severity-labels" id="resultSeverityLabels">
            <span class="sev-label inactive" id="sevLabelMild">Mild (≤ 25%)</span>
            <span class="sev-label inactive" id="sevLabelMod">Moderate (26% – 60%)</span>
            <span class="sev-label active-sev" id="sevLabelSev">Severe (> 60%)</span>
          </div>
        </div>

        <div class="symptoms-card">
          <h4 id="resultSymptomsHeading">Symptoms & Field Impact</h4>
          <p id="resultSymptomsText">Spindle-shaped lesions with reddish-brown borders identified on leaf blades.</p>
          <div class="symptoms-card-footer">
            <button type="button" class="symptoms-action-btn speak-btn" id="resultSpeakBtn" onclick="speakResultSymptoms(this)" title="Read aloud / Stop">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
              <span id="resultSpeakBtnText">Read</span>
            </button>
            <button type="button" class="symptoms-action-btn translate-btn" id="resultTranslateBtn" onclick="toggleResultSymptomsTranslation()" title="Translate Result Symptoms">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1 4-10z"/></svg>
              <span id="resultTranslateBtnText">Translate to Tagalog</span>
            </button>
          </div>
        </div>

        <!-- ═══════════ RECOMMENDED TREATMENT & WHAT TO APPLY CARD ═══════════ -->
        <div class="result-treatment-card" id="resultTreatmentCard" style="margin-top: 16px; background: var(--surface, #ffffff); border: 1px solid var(--neutral-200, #e5e7eb); border-radius: 14px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
          <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
            <div style="display: flex; align-items: center; gap: 8px;">
              <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 8px; background: rgba(22, 101, 52, 0.1); color: #166534;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22v-9"/><path d="M12 13C8 13 4 9 4 4c5 0 9 4 9 9z"/><path d="M12 8c2-3 5-4 8-4 0 5-4 9-8 9"/></svg>
              </span>
              <h4 id="resultTreatmentCardHeading" style="font-size: 15px; font-weight: 700; color: var(--neutral-900, #111827); margin: 0;">Recommended Treatment & Care</h4>
            </div>
            <button type="button" onclick="showScreen('treatment'); loadCurrentTreatment();" style="display: inline-flex; align-items: center; gap: 4px; padding: 5px 12px; font-size: 12px; font-weight: 600; color: #166534; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; cursor: pointer; transition: all 0.2s;">
              <span id="resultTreatmentGuideLinkText">Full Guide & Dosage</span>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </button>
          </div>

          <!-- Official Staff / Agronomist Advisory Callout (If Available) -->
          <div id="resultStaffAdvisoryBox" style="display: none; background: #eff6ff; border-left: 4px solid #3b82f6; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px;">
            <div style="display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: #1e40af; margin-bottom: 4px;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
              <span id="resultStaffAdvisoryHeader">Opisyal na Payo mula sa Agriculturist / Staff</span>
            </div>
            <p id="resultStaffAdvisoryText" style="font-size: 13px; color: #1e3a8a; line-height: 1.5; margin: 0; font-weight: 500;"></p>
          </div>

          <!-- Dynamic List of Suggestions to Apply -->
          <div id="resultTreatmentItemsList" style="display: flex; flex-direction: column; gap: 10px;">
            <!-- JS populated with chemical & organic items with tags and dosages -->
          </div>
        </div>

        <div class="action-buttons" style="margin-top: 16px;">
          <button class="btn-treatment" id="resultBtnTreatment" onclick="showScreen('treatment'); loadCurrentTreatment();">View Treatment Guide</button>
          <button class="btn-ai-consult" id="resultBtnAiConsult" onclick="consultAboutCurrentDisease()">Ask AI Assistant</button>
          <button class="btn-newscan" id="resultBtnNewScan" onclick="showScreen('scan')">New Scan</button>
        </div>
      </section>

      <!-- ═══════════ SCREEN 7B: UNRECOGNIZED / NOT IN DATASET ═══════════ -->
      <section class="screen" id="unrecognized-result">
        <div class="unrecognized-card">
          <div class="unrec-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
          <h2 id="unrecTitle" data-en="Unreadable Image" data-tl="Hindi Mabasa ang Larawan">Unreadable Image</h2>
          <p class="unrec-subtitle" id="unrecMessage" style="font-size: 15px; font-weight: 600; color: #b91c1c;">Cannot read or diagnose this image because it is not found in the system dataset or database.</p>

          <div class="unrec-box">
            <h4 data-en="Active Dataset in Database:" data-tl="Aktibong Dataset sa Database:">Active Dataset in Database:</h4>
            <ul>
              <li><strong>Bacterial Leaf Blight (BLB)</strong> — <em>Mild (≤ 25%), Moderate (26% – 60%), Severe (> 60%)</em></li>
              <li><strong>Rice Leaf Blast (Magnaporthe oryzae)</strong></li>
              <li><strong>Brown Spot (Bipolaris oryzae)</strong></li>
              <li><strong>Rice Tungro Disease (RTBV/RTSV)</strong></li>
              <li><strong>Healthy Rice Leaves</strong></li>
            </ul>
          </div>

          <div class="unrec-tips">
            <p><strong>Scanning Guidelines:</strong></p>
            <p>• Make sure the photo clearly frames a real rice plant leaf.<br>
            • Avoid blurry photos, non-rice objects, or poor indoor lighting.<br>
            • Position leaf blade directly in natural daylight.</p>
          </div>

          <div class="action-buttons" style="margin-top: 24px;">
            <button class="btn-treatment" onclick="showScreen('scan')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
              <span>Scan Another Leaf</span>
            </button>
            <button class="btn-newscan" onclick="showScreen('home')">Back to Dashboard</button>
          </div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 8: TREATMENT GUIDE ═══════════ -->
      <section class="screen" id="treatment">
        <div class="disease-selector-bar" id="treatmentDiseasePills">
          <button class="disease-pill active" onclick="switchTreatmentDisease('blast')">Leaf Blast</button>
          <button class="disease-pill" onclick="switchTreatmentDisease('blb')">Bacterial Blight</button>
          <button class="disease-pill" onclick="switchTreatmentDisease('brown_spot')">Brown Spot</button>
          <button class="disease-pill" onclick="switchTreatmentDisease('sheath_blight')">Sheath Blight</button>
          <button class="disease-pill" onclick="switchTreatmentDisease('tungro')">Rice Tungro</button>
          <button class="disease-pill" onclick="switchTreatmentDisease('healthy')">Healthy Care</button>
        </div>

        <!-- Disease Severity Level Selector (Active for BLB & Tungro) -->
        <div class="blb-severity-pills-bar" id="blbSeverityPillsBar" style="display: none;">
          <button class="blb-sev-pill" id="blbSevMildBtn" onclick="switchDiseaseSeverity('mild')">Mild (≤ 25%)</button>
          <button class="blb-sev-pill active" id="blbSevModBtn" onclick="switchDiseaseSeverity('moderate')">Moderate (26% – 60%)</button>
          <button class="blb-sev-pill" id="blbSevSevBtn" onclick="switchDiseaseSeverity('severe')">Severe (> 60%)</button>
        </div>

        <div class="treat-disease-card">
          <div class="treat-disease-icon blast-bg" id="treatDiseaseIcon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/></svg></div>
          <div class="treat-disease-info">
            <h3 id="treatDiseaseName">Leaf Blast</h3>
            <p id="treatDiseaseSub">Magnaporthe oryzae · Severity: Severe</p>
          </div>
        </div>

        <div class="treatment-tabs" id="treatmentTabs">
          <button class="active" onclick="switchTreatment('chemical')">Chemical Controls</button>
          <button onclick="switchTreatment('organic')">Organic & Cultural</button>
        </div>

        <div class="treatment-list" id="chemicalList"></div>
        <div class="treatment-list" id="organicList" style="display: none;"></div>

        <div class="dosage-calculator-card">
          <h4>Spray Dosage & Field Area Calculator</h4>
          <p>Enter field size to calculate recommended mixture and water volume:</p>
          <div class="dosage-inputs">
            <input type="number" id="calcFieldArea" value="1" min="0.1" step="0.1" oninput="calculateDosage()">
            <select id="calcAreaUnit" onchange="calculateDosage()">
              <option value="hectare">Hectare (ha)</option>
              <option value="sqm">Sq. Meters (m²)</option>
            </select>
          </div>
          <div class="dosage-result-box" id="dosageResultText">
            Water volume needed: <strong>200 Liters</strong> (~12.5 knapsack loads)<br>
            Active fungicide dosage: <strong>200 - 400 ml</strong> Tricyclazole 75% WP.
          </div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 9: SCAN HISTORY ═══════════ -->
      <section class="screen" id="history">
        <div class="history-stats">
          <div class="stat-card"><div class="stat-num green" id="statHealthy">0</div><div class="stat-label">Healthy</div></div>
          <div class="stat-card"><div class="stat-num mild" id="statMild">0</div><div class="stat-label">Mild (≤ 25%)</div></div>
          <div class="stat-card"><div class="stat-num amber" id="statModerate">0</div><div class="stat-label">Moderate (26–60%)</div></div>
          <div class="stat-card"><div class="stat-num red" id="statSevere">0</div><div class="stat-label">Severe (> 60%)</div></div>
        </div>

        <div class="history-filter-card">
          <div class="history-search-input-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" class="history-search-input" id="historySearchInput" placeholder="Search disease name, pathogen, or date..." oninput="filterHistoryList()">
          </div>
          <div class="history-filter-controls">
            <div class="history-select-wrap">
              <label for="historyFilterSelect">Filter:</label>
              <select id="historyFilterSelect" onchange="setHistoryFilter(this.value)">
                <option value="all">All Scans</option>
                <option value="healthy">Healthy</option>
                <option value="mild">Mild (≤ 25%)</option>
                <option value="moderate">Moderate (26% – 60%)</option>
                <option value="severe">Severe (> 60%)</option>
                <option value="blast">Leaf Blast</option>
                <option value="blb">BLB</option>
                <option value="brown_spot">Brown Spot</option>
                <option value="sheath_blight">Sheath Blight</option>
                <option value="tungro">Tungro</option>
              </select>
            </div>
            <button class="btn-refresh" onclick="loadHistory()" title="Refresh Scan History">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
              <span>Refresh</span>
            </button>
          </div>
        </div>

        <div class="history-table-wrapper" id="historyTableWrapper">
          <div class="history-table-responsive">
            <table class="history-data-table">
              <thead>
                <tr>
                  <th style="width: 70px;">Photo</th>
                  <th>Disease Diagnosis</th>
                  <th>Severity Level</th>
                  <th>Date & Time</th>
                  <th style="text-align: center; width: 130px;">Action</th>
                </tr>
              </thead>
              <tbody id="historyTableBody">
                <!-- Rows injected dynamically -->
              </tbody>
            </table>
          </div>

          <div class="history-pagination-bar" id="historyPaginationBar">
            <div class="pagination-info" id="historyPaginationInfo">Showing 1-10 of 0 scans</div>
            <div class="pagination-controls" id="historyPaginationControls">
              <!-- Paginated numbers -->
            </div>
          </div>
        </div>

        <div id="historyEmptyMessage" style="display:none; padding: 40px 20px; text-align:center; color: var(--neutral-500); font-size: 13.5px; font-weight:600;">
          No scan records found.<br>
          <button class="auth-btn" style="width:auto; display:inline-flex; margin-top:14px; padding:10px 20px;" onclick="navigateTo('scan')">Start a Scan</button>
        </div>
      </section>

      <!-- ═══════════ SCREEN 10: AI CONSULTATION ═══════════ -->
      <section class="screen" id="consultation">
        <div class="chat-header">
          <div class="ai-avatar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
          </div>
          <div class="chat-header-info">
            <h3>Rice AI Agricultural Assistant</h3>
            <p>Online · Tagalog / English</p>
          </div>
          <div class="chat-header-actions">
            <button type="button" class="chat-mute-btn" id="aiMuteToggleBtn" onclick="toggleAiVoiceMute()" title="Mute/Unmute AI Voice">
              <svg id="aiMuteIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
              <span id="aiMuteText">Voice: On</span>
            </button>
            <button class="clear-chat-btn" onclick="confirmClearChat()">Clear Chat</button>
          </div>
        </div>

        <div class="chat-messages" id="chatMessages">
          <div class="chat-time">Today · AI Assistant Active</div>
          <div class="chat-bubble ai" data-lang="tagalog" data-raw-content="Kumusta! Ako ang iyong Rice AI Assistant. Pwede mo akong tanungin tungkol sa mga sakit ng palay, tamang dosage ng gamot, organiko o kemikal na gamutan. Mag-type o mag-voice message lang!">
            <div class="chat-text-content">
              <p>Kumusta! Ako ang iyong Rice AI Assistant. Pwede mo akong tanungin tungkol sa mga sakit ng palay, tamang dosage ng gamot, organiko o kemikal na gamutan. Mag-type o mag-voice message lang!</p>
            </div>
            <div class="chat-bubble-footer">
              <button type="button" class="translate-bubble-btn" onclick="translateAiBubble(this)" title="Translate message">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M5 8l6 6"/><path d="M4 14l6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="M22 22l-5-10-5 10"/><path d="M14 18h6"/></svg>
                <span>Translate to English</span>
              </button>
              <button type="button" class="audio-speaker-btn" onclick="toggleSpeechBubble(this)" title="Read aloud / Stop">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                <span>Read</span>
              </button>
            </div>
          </div>
        </div>

        <div class="chat-input-bar">
          <button class="voice-btn" id="voiceBtn" onclick="toggleVoiceRecognition()" title="Voice Input">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>
          </button>
          <input type="text" id="chatInput" placeholder="Ask about rice diseases, dosage, care..." onkeypress="if(event.key==='Enter')sendMessage()">
          <button class="send-btn" onclick="sendMessage()" title="Send">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          </button>
        </div>
      </section>

      <!-- ═══════════ SCREEN 10B: STAFF EXTENSION WORKER DASHBOARD ═══════════ -->
      <section class="screen" id="staff-dashboard">
        <div class="staff-header-banner">
          <div class="staff-banner-content">
            <div class="staff-badge-pill">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              <span>DA-PhilRice Agricultural Extension & Field Surveillance</span>
            </div>
            <h2 id="staffWelcomeTitle">Extension Officer Field Portal</h2>
            <p id="staffWelcomeSubtitle">Surveillance, outbreak tracking, and farmer advisory management for Roxas, Oriental Mindoro</p>
          </div>
          <button class="staff-refresh-btn" onclick="loadStaffDashboard()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            <span>Refresh Data</span>
          </button>
        </div>

        <!-- 4 Staff Metric Stat Cards -->
        <div class="staff-stats-grid">
          <div class="staff-stat-card">
            <div class="staff-stat-icon green">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </div>
            <div class="staff-stat-data">
              <div class="stat-value" id="staffStatTotalScans">0</div>
              <div class="stat-label">Total Field Scans Monitored</div>
            </div>
          </div>

          <div class="staff-stat-card">
            <div class="staff-stat-icon red">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <div class="staff-stat-data">
              <div class="stat-value" id="staffStatActiveOutbreaks">0</div>
              <div class="stat-label">Active Disease Alerts (Mod/Sev)</div>
            </div>
          </div>

          <div class="staff-stat-card">
            <div class="staff-stat-icon blue">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="staff-stat-data">
              <div class="stat-value" id="staffStatTotalFarmers">0</div>
              <div class="stat-label">Registered Farmers in Area</div>
            </div>
          </div>

          <div class="staff-stat-card">
            <div class="staff-stat-icon teal">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22v-9"/><path d="M12 13C8 13 4 9 4 4c5 0 9 4 9 9z"/><path d="M12 8c2-3 5-4 8-4 0 5-4 9-8 9"/></svg>
            </div>
            <div class="staff-stat-data">
              <div class="stat-value" id="staffStatHealthyRatio">100%</div>
              <div class="stat-label">Healthy Crop Index</div>
            </div>
          </div>
        </div>

        <!-- Staff Tab Navigation Bar -->
        <div class="staff-tabs-bar">
          <button class="staff-tab-btn active" id="staffTabScansBtn" onclick="switchStaffTab('scans')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
            <span>Farmer Field Scans</span>
            <span class="staff-tab-count" id="staffScansCountBadge">0</span>
          </button>
          <button class="staff-tab-btn" id="staffTabOutbreakBtn" onclick="switchStaffTab('outbreak')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
            <span>Barangay Outbreak Surveillance</span>
            <span class="staff-tab-count" id="staffLocationCountBadge">0</span>
          </button>
          <button class="staff-tab-btn" id="staffTabProtocolsBtn" onclick="switchStaffTab('protocols')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            <span>Agronomist Action Protocols</span>
          </button>
        </div>

        <!-- TAB 1: FARMER FIELD SCANS TABLE -->
        <div id="staffScansView">
          <div class="staff-filters-row">
            <input type="text" class="staff-search-input" id="staffScansSearch" placeholder="Search farmer name, barangay, or disease..." oninput="filterStaffScans()">
            <div class="staff-filter-chips">
              <button class="staff-chip active" onclick="filterStaffScansByDisease('all', this)">All Scans</button>
              <button class="staff-chip" onclick="filterStaffScansByDisease('blb', this)">BLB</button>
              <button class="staff-chip" onclick="filterStaffScansByDisease('blast', this)">Leaf Blast</button>
              <button class="staff-chip" onclick="filterStaffScansByDisease('brown_spot', this)">Brown Spot</button>
              <button class="staff-chip" onclick="filterStaffScansByDisease('tungro', this)">Tungro</button>
              <button class="staff-chip" onclick="filterStaffScansByDisease('healthy', this)">Healthy</button>
              <button class="staff-chip warning" onclick="filterStaffScansByDisease('urgent', this)">Urgent Alerts</button>
            </div>
          </div>

          <div class="staff-table-card">
            <div class="table-responsive">
              <table class="staff-data-table">
                <thead>
                  <tr>
                    <th>Farmer & Barangay</th>
                    <th>Scanned Leaf</th>
                    <th>Detected Diagnosis</th>
                    <th>Severity Level</th>
                    <th>Scanned Date</th>
                    <th>Staff Action</th>
                  </tr>
                </thead>
                <tbody id="staffScansTableBody">
                  <tr><td colspan="6" class="empty-table-msg">Loading field surveillance records...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- TAB 2: BARANGAY OUTBREAK SURVEILLANCE -->
        <div id="staffOutbreakView" style="display: none;">
          <div class="outbreak-intro-box">
            <div class="outbreak-intro-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
            <div>
              <h4>Barangay Outbreak Early-Warning System</h4>
              <p>Real-time cluster tracking of rice leaf bacterial blight, blast, brown spot, and tungro reports submitted by local farmers across Oriental Mindoro.</p>
            </div>
          </div>

          <div class="barangay-cards-grid" id="staffBarangayCardsContainer">
            <div class="empty-table-msg">Loading barangay surveillance data...</div>
          </div>
        </div>

        <!-- TAB 3: AGRONOMIST ACTION PROTOCOLS -->
        <div id="staffProtocolsView" style="display: none;">
          <div class="protocol-grid">
            <div class="protocol-card">
              <div class="protocol-header blb">
                <h4>Bacterial Leaf Blight (Xanthomonas oryzae)</h4>
                <span class="protocol-tag">Bacterial Pathogen</span>
              </div>
              <div class="protocol-body">
                <p><strong>Immediate Intervention:</strong> At early water-soaked streak appearance (≤25%), advise copper hydroxide/oxychloride (2.0 g/L). Drain paddy water for 2–3 days.</p>
                <p><strong>Severe Epidemic Protocol:</strong> For kresek/systemic wilt (>60%), apply emergency Streptomycin Sulfate + Oxytetracycline (150–200 ppm). Halt all topdress urea applications immediately.</p>
              </div>
            </div>

            <div class="protocol-card">
              <div class="protocol-header blast">
                <h4>Rice Leaf Blast (Magnaporthe oryzae)</h4>
                <span class="protocol-tag">Fungal Pathogen</span>
              </div>
              <div class="protocol-body">
                <p><strong>Immediate Intervention:</strong> Tricyclazole 75% WP (Beam / Blast-Off) at 0.6–1.0 g/L or Kasugamycin (Kasumin) 1.5–2.0 ml/L.</p>
                <p><strong>Field Management:</strong> Maintain 3–5 cm continuous water depth; do not let the field dry out. Avoid excess nitrogen fertilizer.</p>
              </div>
            </div>

            <div class="protocol-card">
              <div class="protocol-header tungro">
                <h4>Rice Tungro Disease (RTBV & RTSV)</h4>
                <span class="protocol-tag">Viral Vector-Borne</span>
              </div>
              <div class="protocol-body">
                <p><strong>Vector Control (Green Leafhopper - GLH):</strong> Tungro has no direct chemical cure. Target GLH vectors with Dinotefuran / Pymetrozine (1.0–1.5 g/L).</p>
                <p><strong>Rogueing:</strong> Immediately uproot and bag infected yellow-orange clumps to stop virus transmission across the field.</p>
              </div>
            </div>

            <div class="protocol-card">
              <div class="protocol-header brown_spot">
                <h4>Brown Spot (Bipolaris oryzae)</h4>
                <span class="protocol-tag">Nutritional/Fungal</span>
              </div>
              <div class="protocol-body">
                <p><strong>Nutrient Correction:</strong> Brown spot heavily correlates with soil potassium (K) and silica deficiency. Apply Muriate of Potash (30–40 kg/ha).</p>
                <p><strong>Foliar Spray:</strong> Difenoconazole + Azoxystrobin (Amistar Top) at 1.0 ml/L if lesions exceed 20% of flag leaf area.</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 10C: ADMIN EXECUTIVE DASHBOARD ═══════════ -->
      <section class="screen" id="admin-dashboard">
        <div class="admin-header-banner">
          <div class="admin-banner-content">
            <div class="admin-badge-pill">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              <span>DA-PhilRice Central Agronomy Administration</span>
            </div>
            <h2 id="adminWelcomeTitle">Admin Executive Command Center</h2>
            <p id="adminWelcomeSubtitle">Provincial surveillance oversight, AI diagnostic performance, and management center</p>
          </div>
        </div>

        <!-- 4 Primary KPI Executive Stat Cards -->
        <div class="admin-stats-grid">
          <div class="admin-stat-card executive">
            <div class="admin-stat-icon green">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="admin-stat-info">
              <div class="stat-val" id="adminDashTotalUsers">0</div>
              <div class="stat-lbl">Total Users</div>
              <div class="stat-sub" id="adminDashUserBreakdown">0 Farmers · 0 Staff</div>
            </div>
          </div>

          <div class="admin-stat-card executive">
            <div class="admin-stat-icon blue">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </div>
            <div class="admin-stat-info">
              <div class="stat-val" id="adminDashTotalScans">0</div>
              <div class="stat-lbl">Total Detections</div>
              <div class="stat-sub">Today: +<span id="adminDashTodayScans">0</span> scans</div>
            </div>
          </div>

          <div class="admin-stat-card executive">
            <div class="admin-stat-icon red">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <div class="admin-stat-info">
              <div class="stat-val" id="adminDashDetectedDiseases">0</div>
              <div class="stat-lbl">Detected Diseases</div>
              <div class="stat-sub" id="adminDashOutbreakPercent">0 Outbreaks (Mod/Sev)</div>
            </div>
          </div>

          <div class="admin-stat-card executive">
            <div class="admin-stat-icon green">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22v-9"/><path d="M12 13C8 13 4 9 4 4c5 0 9 4 9 9z"/><path d="M12 8c2-3 5-4 8-4 0 5-4 9-8 9"/></svg>
            </div>
            <div class="admin-stat-info">
              <div class="stat-val" id="adminDashHealthyLeaves">0</div>
              <div class="stat-lbl">Healthy Rice Leaves</div>
              <div class="stat-sub" id="adminDashHealthyRatio">100% Healthy Index</div>
            </div>
          </div>
        </div>

        <!-- Most Detected Disease & 7-Day Statistics Chart Row -->
        <div class="admin-two-col-grid" style="margin-top: 18px;">
          <!-- Most Detected Disease Highlight Card -->
          <div class="admin-panel-card">
            <div class="panel-card-header">
              <div class="panel-title-group">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                <h3>Most Detected Disease</h3>
              </div>
              <span class="badge-pill" style="background: var(--amber-100); color: var(--amber-800);">Prevalent</span>
            </div>
            <div class="panel-card-body" style="padding: 20px;">
              <div style="display: flex; align-items: center; gap: 16px;">
                <div style="width: 56px; height: 56px; border-radius: 14px; background: var(--red-50); border: 1px solid var(--red-100); display: flex; align-items: center; justify-content: center; color: var(--red-600); flex-shrink: 0;">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
                </div>
                <div>
                  <h4 style="font-size: 19px; font-weight: 800; color: var(--neutral-900); margin: 0;" id="adminDashMostDetectedName">Rice Leaf Blast</h4>
                  <p style="font-size: 13px; color: var(--neutral-500); margin: 4px 0 0;">Total Detections: <strong id="adminDashMostDetectedCount" style="color: var(--red-600);">0</strong> (<span id="adminDashMostDetectedPercent">0%</span> of all scans)</p>
                </div>
              </div>
              <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--neutral-100); display: flex; gap: 8px;">
                <button class="panel-action-btn" onclick="showScreen('admin-reports');" style="flex: 1; justify-content: center;">View Detailed Analytics →</button>
                <button class="panel-action-btn" onclick="showScreen('admin-diseases');" style="flex: 1; justify-content: center;">Disease Information →</button>
              </div>
            </div>
          </div>

          <!-- Detection Statistics / 7-Day Trends Chart -->
          <div class="admin-panel-card">
            <div class="panel-card-header">
              <div class="panel-title-group">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                <h3>Detection Statistics (Last 7 Days)</h3>
              </div>
              <span class="badge-pill" style="background: var(--blue-100); color: var(--blue-700);">Daily Activity</span>
            </div>
            <div class="panel-card-body" style="padding: 16px 20px;">
              <div class="admin-chart-container" id="adminDailyTrendChart">
                <!-- Injected dynamically: 7-day columns -->
              </div>
              <div style="display: flex; justify-content: center; gap: 20px; margin-top: 12px; font-size: 11.5px; font-weight: 700;">
                <span style="display: flex; align-items: center; gap: 5px;"><span style="width: 10px; height: 10px; border-radius: 2px; background: var(--red-500); display: inline-block;"></span> Diseased Leaves</span>
                <span style="display: flex; align-items: center; gap: 5px;"><span style="width: 10px; height: 10px; border-radius: 2px; background: var(--brand-green); display: inline-block;"></span> Healthy Leaves</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick Management Shortcuts Hub -->
        <div class="admin-quick-hub" style="margin-top: 20px;">
          <div class="quick-hub-card" onclick="showScreen('admin-users'); loadAdminUsers();">
            <div class="quick-hub-icon green">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="quick-hub-info">
              <h4>1. User Management</h4>
              <p>Add, edit, change roles, view user scan history, delete accounts</p>
            </div>
            <div class="quick-hub-arrow">→</div>
          </div>

          <div class="quick-hub-card" onclick="showScreen('admin-diseases'); loadAdminDiseases();">
            <div class="quick-hub-icon teal">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div class="quick-hub-info">
              <h4>2. Disease Management</h4>
              <p>Add/edit disease info, symptoms, causes, prevention, treatments</p>
            </div>
            <div class="quick-hub-arrow">→</div>
          </div>

          <div class="quick-hub-card" onclick="showScreen('admin-scans-logs'); loadAdminScansLogs();">
            <div class="quick-hub-icon blue">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="quick-hub-info">
              <h4>3. Detection Records</h4>
              <p>Comprehensive logs of all user scans, photos, confidence, and dates</p>
            </div>
            <div class="quick-hub-arrow">→</div>
          </div>

          <div class="quick-hub-card" onclick="showScreen('admin-reports'); loadAdminReports();">
            <div class="quick-hub-icon amber">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            </div>
            <div class="quick-hub-info">
              <h4>4. Reports & Analytics</h4>
              <p>Daily/weekly/monthly trends, disease frequency, CSV export</p>
            </div>
            <div class="quick-hub-arrow">→</div>
          </div>

          <div class="quick-hub-card" onclick="showScreen('admin-chatbot'); loadAdminChatbot();">
            <div class="quick-hub-icon purple">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
            </div>
            <div class="quick-hub-info">
              <h4>5. Chatbot Management</h4>
              <p>View conversations, manage FAQs, add disease Q&A knowledge</p>
            </div>
            <div class="quick-hub-arrow">→</div>
          </div>
        </div>

        <!-- Full Width: Recent Detections Table -->
        <div class="admin-panel-card" style="margin-top: 24px; margin-bottom: 30px;">
          <div class="panel-card-header">
            <div class="panel-title-group">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <h3>Recent Detections</h3>
            </div>
            <button class="panel-action-btn" onclick="showScreen('admin-scans-logs'); loadAdminScansLogs();">
              <span>View All Detection Records</span>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
            </button>
          </div>
          <div class="panel-card-body" style="padding: 0;">
            <div class="table-responsive">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th style="width: 65px;">Photo</th>
                    <th>User</th>
                    <th>Predicted Disease</th>
                    <th>Confidence / Accuracy</th>
                    <th>Severity Level</th>
                    <th>Date & Time</th>
                    <th style="text-align: center; width: 100px;">Action</th>
                  </tr>
                </thead>
                <tbody id="adminRecentScansTableBody">
                  <tr><td colspan="7" class="empty-table-msg">Loading recent detection records...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 11: USER MANAGEMENT ═══════════ -->
      <section class="screen" id="admin-users">
        <div class="admin-header-row">
          <div class="admin-title">
            <h2>User Management</h2>
            <p>Manage registered Farmers, Agricultural Extension Staff, and Administrator accounts</p>
          </div>
          <button class="admin-add-btn" onclick="openModal('modalAdminAddUser')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Add New User</span>
          </button>
        </div>

        <!-- Summary Statistics Grid -->
        <div class="admin-stats-grid">
          <div class="admin-stat-card">
            <div class="admin-stat-icon green"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22v-9"/><path d="M12 13C8 13 4 9 4 4c5 0 9 4 9 9z"/><path d="M12 8c2-3 5-4 8-4 0 5-4 9-8 9"/></svg></div>
            <div class="admin-stat-info">
              <div class="stat-val" id="adminStatFarmers">0</div>
              <div class="stat-lbl">Registered Farmers</div>
            </div>
          </div>
          <div class="admin-stat-card">
            <div class="admin-stat-icon blue"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
            <div class="admin-stat-info">
              <div class="stat-val" id="adminStatStaff">0</div>
              <div class="stat-lbl">Agricultural Staff</div>
            </div>
          </div>
          <div class="admin-stat-card">
            <div class="admin-stat-icon amber"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
            <div class="admin-stat-info">
              <div class="stat-val" id="adminStatTotal">0</div>
              <div class="stat-lbl">Total User Accounts</div>
            </div>
          </div>
          <div class="admin-stat-card">
            <div class="admin-stat-icon red"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div>
            <div class="admin-stat-info">
              <div class="stat-val" id="adminStatScans">0</div>
              <div class="stat-lbl">Total Field Scans</div>
            </div>
          </div>
        </div>

        <!-- Role Filter Tabs & Search Bar -->
        <div class="admin-tabs-bar">
          <button class="admin-tab-btn active" id="adminTabAllUsersBtn" onclick="switchAdminUsersTab('all')">
            <span>All Users</span>
            <span class="tab-count-pill" id="adminTabAllUsersCount">0</span>
          </button>
          <button class="admin-tab-btn" id="adminTabFarmersBtn" onclick="switchAdminUsersTab('farmer')">
            <span>Farmers</span>
            <span class="tab-count-pill" id="adminTabFarmersCount">0</span>
          </button>
          <button class="admin-tab-btn" id="adminTabStaffBtn" onclick="switchAdminUsersTab('agri_worker')">
            <span>Extension Staff</span>
            <span class="tab-count-pill" id="adminTabStaffCount" style="background: var(--blue-100); color: var(--blue-700);">0</span>
          </button>
          <button class="admin-tab-btn" id="adminTabAdminsBtn" onclick="switchAdminUsersTab('admin')">
            <span>Admins</span>
            <span class="tab-count-pill" id="adminTabAdminsCount" style="background: var(--amber-100); color: var(--amber-700);">0</span>
          </button>
        </div>

        <div class="admin-controls-row">
          <input type="text" class="admin-search-input" id="adminSearchInput" placeholder="Search account by name, email, or location..." oninput="filterAdminUsersList()">
        </div>

        <!-- All Users Table Card -->
        <div class="admin-table-card">
          <div class="table-responsive">
            <table class="admin-table">
              <thead>
                <tr>
                  <th style="width: 55px;">Avatar</th>
                  <th>User Details</th>
                  <th>Role</th>
                  <th>Location</th>
                  <th>Detections</th>
                  <th>Registered</th>
                  <th style="text-align: right; width: 140px;">Actions</th>
                </tr>
              </thead>
              <tbody id="adminUsersTableBody">
                <tr><td colspan="7" class="empty-table-msg">Loading user accounts...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 11B: DISEASE MANAGEMENT ═══════════ -->
      <section class="screen" id="admin-diseases">
        <div class="admin-header-row">
          <div class="admin-title">
            <h2>Disease Management</h2>
            <p>Add, edit, or configure rice disease information, symptoms, causes, prevention, and treatment protocols</p>
          </div>
          <button class="admin-add-btn" onclick="openModal('modalAdminAddDisease')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Add Disease Info</span>
          </button>
        </div>

        <!-- Disease Filter Tabs -->
        <div class="admin-tabs-bar">
          <button class="admin-tab-btn active" id="adminTabDiseaseAll" onclick="filterAdminDiseases('all', this)">
            <span>All Diseases (<span id="adminDiseasesCountAll">5</span>)</span>
          </button>
          <button class="admin-tab-btn" id="adminTabDiseaseActive" onclick="filterAdminDiseases('active', this)">
            <span>Active</span>
          </button>
          <button class="admin-tab-btn" id="adminTabDiseaseInactive" onclick="filterAdminDiseases('inactive', this)">
            <span>Inactive</span>
          </button>
        </div>

        <!-- Disease Cards Grid Container -->
        <div class="admin-diseases-grid" id="adminDiseasesGrid">
          <div class="empty-table-msg">Loading disease records...</div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 11C: DETECTION RECORDS / DETECTION LOGS ═══════════ -->
      <section class="screen" id="admin-scans-logs">
        <div class="admin-header-row">
          <div class="admin-title">
            <h2>Detection Records & Logs</h2>
            <p>Full centralized repository of all leaf scans submitted by farmers and extension workers</p>
          </div>
          <button class="admin-add-btn" onclick="exportAdminScansCsv()" style="background: var(--blue-600);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span>Export CSV</span>
          </button>
        </div>

        <!-- Search & Filter Controls -->
        <div class="staff-filters-row" style="margin-bottom: 14px;">
          <input type="text" class="staff-search-input" id="adminScansSearch" placeholder="Search user name, email, disease name, or scientific name..." oninput="filterAdminScansLogs()">
          <div class="staff-filter-chips">
            <button class="staff-chip active" onclick="filterAdminScansByChip('all', this)">All Detections</button>
            <button class="staff-chip" onclick="filterAdminScansByChip('healthy', this)">Healthy</button>
            <button class="staff-chip" onclick="filterAdminScansByChip('blast', this)">Rice Blast</button>
            <button class="staff-chip" onclick="filterAdminScansByChip('blb', this)">BLB</button>
            <button class="staff-chip" onclick="filterAdminScansByChip('brown_spot', this)">Brown Spot</button>
            <button class="staff-chip" onclick="filterAdminScansByChip('tungro', this)">Tungro</button>
            <button class="staff-chip warning" onclick="filterAdminScansByChip('outbreaks', this)">Severe / Outbreaks</button>
          </div>
        </div>

        <!-- Logs Table Card -->
        <div class="admin-table-card">
          <div class="table-responsive">
            <table class="admin-table">
              <thead>
                <tr>
                  <th style="width: 65px;">Photo</th>
                  <th>User</th>
                  <th>Predicted Disease</th>
                  <th>Confidence / Accuracy</th>
                  <th>Severity Level</th>
                  <th>Date & Time</th>
                  <th style="text-align: right; width: 120px;">Action</th>
                </tr>
              </thead>
              <tbody id="adminScansLogsTableBody">
                <tr><td colspan="7" class="empty-table-msg">Loading all detection records...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 11D: REPORTS & ANALYTICS ═══════════ -->
      <section class="screen" id="admin-reports">
        <div class="admin-header-row">
          <div class="admin-title">
            <h2>Reports & Analytics</h2>
            <p>Statistical summaries, disease frequency, timeline trends, and exportable agronomic reports</p>
          </div>
          <div style="display: flex; gap: 8px;">
            <button class="admin-add-btn" onclick="exportAdminScansCsv()" style="background: var(--blue-600);">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              <span>Export CSV</span>
            </button>
            <button class="admin-add-btn" onclick="window.print()" style="background: var(--neutral-800);">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              <span>Print Report</span>
            </button>
          </div>
        </div>

        <!-- Period Toggle Bar -->
        <div class="admin-tabs-bar">
          <button class="admin-tab-btn active" id="adminReportDailyBtn" onclick="switchAdminReportPeriod('daily')">
            <span>Daily Detections (Last 7 Days)</span>
          </button>
          <button class="admin-tab-btn" id="adminReportWeeklyBtn" onclick="switchAdminReportPeriod('weekly')">
            <span>Weekly Trends (Last 6 Weeks)</span>
          </button>
          <button class="admin-tab-btn" id="adminReportMonthlyBtn" onclick="switchAdminReportPeriod('monthly')">
            <span>Monthly Overview (Last 6 Months)</span>
          </button>
        </div>

        <!-- 2-Column Analytics Layout -->
        <div class="admin-two-col-grid" style="margin-top: 14px;">
          <!-- Left: Disease Frequency Breakdown -->
          <div class="admin-panel-card">
            <div class="panel-card-header">
              <div class="panel-title-group">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <h3>Disease Frequency Breakdown</h3>
              </div>
              <span class="badge-pill" style="background: var(--neutral-100); color: var(--neutral-700);">System-Wide</span>
            </div>
            <div class="panel-card-body">
              <div class="disease-meter-list" id="adminReportsDiseaseList">
                <!-- Injected dynamically -->
              </div>
            </div>
          </div>

          <!-- Right: Detection Timeline Trends Chart -->
          <div class="admin-panel-card">
            <div class="panel-card-header">
              <div class="panel-title-group">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                <h3 id="adminReportChartTitle">Detection Trends</h3>
              </div>
              <span class="badge-pill" style="background: var(--blue-100); color: var(--blue-700);">Timeline</span>
            </div>
            <div class="panel-card-body" style="padding: 20px;">
              <div class="admin-chart-container" id="adminReportTrendChart">
                <!-- Injected dynamically -->
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 11E: CHATBOT MANAGEMENT ═══════════ -->
      <section class="screen" id="admin-chatbot">
        <div class="admin-header-row">
          <div class="admin-title">
            <h2>Chatbot & FAQ Management</h2>
            <p>Monitor user conversation logs, manage FAQ knowledge entries, and add disease question-and-answer rules</p>
          </div>
          <button class="admin-add-btn" onclick="openModal('modalAdminAddFaq')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Add Q&A Knowledge</span>
          </button>
        </div>

        <!-- Chatbot Sub-Tabs -->
        <div class="admin-tabs-bar">
          <button class="admin-tab-btn active" id="adminChatbotTabFaq" onclick="switchAdminChatbotTab('faq')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            <span>Manage FAQ & Knowledge Base (<span id="adminFaqCountBadge">0</span>)</span>
          </button>
          <button class="admin-tab-btn" id="adminChatbotTabLogs" onclick="switchAdminChatbotTab('logs')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <span>User Conversation Logs (<span id="adminChatLogsCountBadge">0</span>)</span>
          </button>
        </div>

        <!-- TAB 1: FAQ & KNOWLEDGE BASE -->
        <div id="adminChatbotFaqView">
          <div class="admin-table-card">
            <div class="table-responsive">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th>Category</th>
                    <th>Question / Prompt</th>
                    <th>AI Agronomist Answer</th>
                    <th>Language</th>
                    <th>Status</th>
                    <th style="text-align: right; width: 120px;">Actions</th>
                  </tr>
                </thead>
                <tbody id="adminFaqTableBody">
                  <tr><td colspan="6" class="empty-table-msg">Loading FAQ knowledge items...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- TAB 2: LIVE CONVERSATION LOGS -->
        <div id="adminChatbotLogsView" style="display: none;">
          <div class="admin-table-card">
            <div class="table-responsive">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th>User</th>
                    <th>Speaker</th>
                    <th>Message Content</th>
                    <th>Language</th>
                    <th>Timestamp</th>
                  </tr>
                </thead>
                <tbody id="adminChatLogsTableBody">
                  <tr><td colspan="5" class="empty-table-msg">Loading conversation history...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <!-- ═══════════ SCREEN 12: ACCOUNT & APP SETTINGS (PROFILE & SECURITY) ═══════════ -->
      <section class="screen" id="profile">
        <div class="profile-card">
          <div class="profile-avatar-wrap">
            <div class="profile-avatar" id="profileAvatar">MJ</div>
            <button type="button" class="profile-avatar-edit-overlay" onclick="openModal('modalEditProfile')" title="Change Photo">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            </button>
          </div>
          <h2 id="profileName">Mang Juan</h2>
          <p id="profileSubtitle">Rice Farmer · Roxas, Oriental Mindoro</p>
        </div>

        <div class="profile-menu">
          <div class="profile-menu-item" onclick="openModal('modalEditProfile')">
            <div class="pm-icon green-bg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
            <span class="pm-text">Edit Profile Information</span>
            <span class="pm-arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></span>
          </div>

          <div class="profile-menu-item" id="profileSecurityMenuItem" style="display: none;" onclick="openModal('modalAdminSecuritySettings'); loadAdminSecuritySettings();">
            <div class="pm-icon purple-bg" style="background: #ede9fe; color: #7c3aed;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
            <span class="pm-text">Login Security & Attempt Policy</span>
            <span class="pm-arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></span>
          </div>

          <div class="profile-menu-item" onclick="openModal('modalDiseaseLibrary')">
            <div class="pm-icon blue-bg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></div>
            <span class="pm-text">Rice Disease Reference Library</span>
            <span class="pm-arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></span>
          </div>

          <div class="profile-menu-item" onclick="openModal('modalHelpSupport')">
            <div class="pm-icon amber-bg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
            <span class="pm-text">Photography & Lighting Tips</span>
            <span class="pm-arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></span>
          </div>

          <div class="profile-menu-item" onclick="openModal('modalAbout')">
            <div class="pm-icon green-bg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg></div>
            <span class="pm-text">About Oryzatix System</span>
            <span class="pm-arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></span>
          </div>

          <div class="profile-menu-item" style="margin-top: 14px;" onclick="openModal('modalLogoutConfirm')">
            <div class="pm-icon red-bg"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></div>
            <span class="pm-text" style="color: var(--red-600);">Sign Out</span>
            <span class="pm-arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></span>
          </div>
        </div>
      </section>

    </div><!-- /.web-page-content -->
  </main>

  <!-- ═══════════ MOBILE BOTTOM NAVIGATION ═══════════ -->
  <nav class="mobile-bottom-nav">
    <button class="mobile-nav-btn active" data-screen="home" onclick="showScreen('home'); loadHomeRecentScans();">
      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
      <span>Home</span>
    </button>
    <button class="mobile-nav-btn" data-screen="history" onclick="showScreen('history'); loadHistory();">
      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>
      <span>History</span>
    </button>
    <button class="mobile-nav-btn scan-circle-btn" onclick="showScreen('scan')">
      <div class="circle-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
      </div>
    </button>
    <button class="mobile-nav-btn" data-screen="consultation" onclick="showScreen('consultation'); loadChatMessages();">
      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z"/></svg>
      <span>AI Consult</span>
    </button>
    <button class="mobile-nav-btn" data-screen="profile" onclick="showScreen('profile')">
      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
      <span>Profile</span>
    </button>
  </nav>
</div><!-- /.web-app-shell -->

<!-- ═══════════ MODALS ═══════════ -->

<!-- Admin: Add New User Modal -->
<div class="modal-backdrop" id="modalAdminAddUser">
  <div class="modal-box edit-profile-modal-box">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div class="modal-header-icon green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
        </div>
        <div>
          <h3 style="margin: 0; font-size: 17px; font-weight: 800;">Add New User Account</h3>
          <p style="font-size: 11.5px; color: var(--neutral-500); margin: 2px 0 0;">Create a farmer, staff, or admin account with role permissions</p>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('modalAdminAddUser')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="error-banner" id="adminAddUserError"></div>
    <div class="success-banner" id="adminAddUserSuccess"></div>
    <form class="auth-form edit-profile-form" id="adminAddUserForm" onsubmit="handleAdminAddUser(event)">
      <div class="form-group">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <span>Full Name</span>
        </label>
        <input type="text" id="adminNewName" class="form-input-styled" placeholder="e.g. Juan Dela Cruz" required>
      </div>
      <div class="form-group">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <span>Email Address</span>
        </label>
        <input type="email" id="adminNewEmail" class="form-input-styled" placeholder="e.g. juan@gmail.com" required>
      </div>
      <div class="form-group">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <span>Account Role Category</span>
        </label>
        <select id="adminNewRole" class="form-input-styled" required>
          <option value="farmer">Registered Farmer</option>
          <option value="agri_worker">Agricultural Extension Worker / Staff</option>
          <option value="admin">Administrator / Researcher</option>
        </select>
      </div>
      <div class="form-group">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
          <span>Farm Location / Assigned Office</span>
        </label>
        <input type="text" id="adminNewLocation" class="form-input-styled" placeholder="e.g. Roxas, Oriental Mindoro">
      </div>
      <div class="form-group">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <span>Initial Password</span>
        </label>
        <input type="password" id="adminNewPassword" class="form-input-styled" placeholder="Minimum 6 characters" minlength="6" required>
      </div>
      <div style="display: flex; gap: 10px; margin-top: 14px;">
        <button type="button" class="btn-modal-cancel" onclick="closeModal('modalAdminAddUser')">Cancel</button>
        <button type="submit" class="auth-btn" id="adminAddUserBtn" style="margin-top: 0; flex: 1;">Create Account</button>
      </div>
    </form>
  </div>
</div>

<!-- Admin: Edit User Modal -->
<div class="modal-backdrop" id="modalAdminEditUser">
  <div class="modal-box edit-profile-modal-box">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div class="modal-header-icon blue">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </div>
        <div>
          <h3 style="margin: 0; font-size: 17px; font-weight: 800;">Edit User Account</h3>
          <p style="font-size: 11.5px; color: var(--neutral-500); margin: 2px 0 0;">Update account credentials, role assignment, and location</p>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('modalAdminEditUser')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>

    <div class="error-banner" id="adminEditUserError"></div>
    <div class="success-banner" id="adminEditUserSuccess"></div>

    <form class="auth-form edit-profile-form" id="adminEditUserForm" onsubmit="handleAdminEditUser(event)">
      <input type="hidden" id="adminEditUserId">

      <!-- User Preview Strip -->
      <div class="profile-preview-strip">
        <div class="profile-avatar-small" id="adminEditUserAvatar" style="background: var(--blue-600);">U</div>
        <div style="flex: 1;">
          <div style="font-weight: 800; font-size: 14px; color: var(--neutral-900);" id="adminEditUserPreviewName">User Name</div>
          <div style="font-size: 11.5px; color: var(--neutral-500); margin-top: 1px;" id="adminEditUserPreviewRole">Role · Location</div>
        </div>
      </div>

      <div class="form-group">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <span>Full Name</span>
        </label>
        <input type="text" id="adminEditName" class="form-input-styled" required placeholder="e.g. Juan Dela Cruz">
      </div>

      <div class="form-group">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <span>Email Address</span>
        </label>
        <input type="email" id="adminEditEmail" class="form-input-styled" required placeholder="e.g. juan@gmail.com">
      </div>

      <div class="form-group">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <span>Account Role</span>
        </label>
        <select id="adminEditRole" class="form-input-styled" required>
          <option value="farmer">Registered Farmer</option>
          <option value="agri_worker">Agricultural Extension Worker</option>
          <option value="admin">Administrator / Researcher</option>
        </select>
      </div>

      <div class="form-group">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
          <span>Farm Location / Office</span>
        </label>
        <input type="text" id="adminEditLocation" class="form-input-styled" placeholder="e.g. Roxas, Oriental Mindoro">
      </div>

      <!-- Password Section Divider -->
      <div class="profile-password-divider">
        <div class="divider-line"></div>
        <span class="divider-text">Reset Password (Optional)</span>
        <div class="divider-line"></div>
      </div>

      <div class="form-group">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <span>New Password</span>
        </label>
        <input type="password" id="adminEditPassword" class="form-input-styled" placeholder="Leave blank to keep unchanged (min 6 chars)" minlength="6">
      </div>

      <div style="display: flex; gap: 10px; margin-top: 14px;">
        <button type="button" class="btn-modal-cancel" onclick="closeModal('modalAdminEditUser')">Cancel</button>
        <button type="submit" class="auth-btn" id="adminEditUserBtn" style="margin-top: 0; flex: 1;">Update Account</button>
      </div>
    </form>
  </div>
</div>

<!-- Admin: Delete User Confirmation Modal -->
<div class="modal-backdrop" id="modalAdminDeleteUser">
  <div class="modal-box delete-confirm-modal-box">
    <div class="delete-modal-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="3 6 5 6 21 6"/>
        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
        <line x1="10" y1="11" x2="10" y2="17"/>
        <line x1="14" y1="11" x2="14" y2="17"/>
      </svg>
    </div>
    <h3 class="delete-modal-title">Delete User Account?</h3>
    <p class="delete-modal-desc">Are you sure you want to delete <strong id="adminDeleteUserName" style="color: var(--neutral-900);">this user</strong>? All associated scan records for this user will also be removed. This action cannot be undone.</p>
    <div class="error-banner" id="adminDeleteUserError" style="margin-bottom: 14px;"></div>
    <div class="delete-modal-actions">
      <button type="button" class="btn-modal-cancel" onclick="closeModal('modalAdminDeleteUser')">Cancel</button>
      <button type="button" class="btn-modal-danger" id="confirmDeleteUserBtn" onclick="confirmAdminDeleteUser()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
          <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
        </svg>
        <span>Yes, Delete Account</span>
      </button>
    </div>
  </div>
</div>

<!-- Admin: Login Security & Attempt Lockout Settings Modal -->
<div class="modal-backdrop" id="modalAdminSecuritySettings">
  <div class="modal-box edit-profile-modal-box" style="max-width: 680px; max-height: 88vh; overflow-y: auto;">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div class="modal-header-icon green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </div>
        <div>
          <h3 style="margin: 0; font-size: 17px; font-weight: 800;">Login Security & Attempt Policy</h3>
          <p style="font-size: 11.5px; color: var(--neutral-500); margin: 2px 0 0;">Configure password attempt limits and lockout durations</p>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('modalAdminSecuritySettings')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>

    <div class="success-banner" id="adminSecuritySuccessBanner"></div>
    <div class="error-banner" id="adminSecurityErrorBanner"></div>

    <!-- Active Security Overview Banner -->
    <div class="admin-panel-card" style="margin: 14px 0 18px;">
      <div class="panel-card-header">
        <div class="panel-title-group">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <h3>Current Active Security Rules</h3>
        </div>
        <span class="badge-pill" style="background: transparent; color: var(--brand-green); font-weight: 800; padding: 0; display: inline-flex; align-items: center; gap: 6px;"><span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--brand-green);"></span>Active &amp; Enforcing</span>
      </div>
      <div class="panel-card-body" style="padding: 16px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
          <div style="background: var(--neutral-50); border: 1px solid var(--neutral-200); border-radius: var(--radius-md); padding: 12px;">
            <div style="font-size: 10.5px; font-weight: 800; color: var(--neutral-500); text-transform: uppercase; letter-spacing: 0.5px;">Max Failed Attempts</div>
            <div style="font-size: 20px; font-weight: 900; color: var(--brand-green); margin-top: 2px;" id="dispActiveMaxAttempts">3 Attempts</div>
            <div style="font-size: 11.5px; color: var(--neutral-600); margin-top: 2px;">Wrong passwords before lockout</div>
          </div>
          <div style="background: var(--neutral-50); border: 1px solid var(--neutral-200); border-radius: var(--radius-md); padding: 12px;">
            <div style="font-size: 10.5px; font-weight: 800; color: var(--neutral-500); text-transform: uppercase; letter-spacing: 0.5px;">Lockout Penalty</div>
            <div style="font-size: 20px; font-weight: 900; color: #e11d48; margin-top: 2px;" id="dispActiveLockoutSeconds">30 Seconds</div>
            <div style="font-size: 11.5px; color: var(--neutral-600); margin-top: 2px;">Cooling-off wait time</div>
          </div>
          <div style="background: var(--neutral-50); border: 1px solid var(--neutral-200); border-radius: var(--radius-md); padding: 12px;">
            <div style="font-size: 10.5px; font-weight: 800; color: var(--neutral-500); text-transform: uppercase; letter-spacing: 0.5px;">Protection Scope</div>
            <div style="font-size: 15px; font-weight: 800; color: var(--neutral-800); margin-top: 4px;">IP &amp; Account Level</div>
            <div style="font-size: 11.5px; color: var(--neutral-600); margin-top: 2px;">Anti brute-force protection</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Security Adjustment Configuration Cards -->
    <div class="admin-security-grid" style="grid-template-columns: 1fr; gap: 16px;">
      <!-- CARD 1: MAX LOGIN ATTEMPTS -->
      <div class="security-setting-card">
        <div>
          <div class="security-card-header">
            <div class="security-card-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div class="security-card-title">
              <h3 style="font-size: 15px;">Maximum Failed Login Attempts</h3>
              <p>Allowed consecutive incorrect password attempts before imposing lockout.</p>
            </div>
          </div>

          <div class="security-preset-pills" id="attemptPillsContainer">
            <button type="button" class="sec-preset-pill active" onclick="selectAttemptPreset(3, this)">3 Attempts (Default)</button>
            <button type="button" class="sec-preset-pill" onclick="selectAttemptPreset(5, this)">5 Attempts</button>
            <button type="button" class="sec-preset-pill" onclick="selectAttemptPreset(10, this)">10 Attempts</button>
          </div>

          <div class="form-group" style="margin-top: 14px;">
            <label for="adminMaxAttemptsInput" style="font-size: 11px; font-weight: 800; color: var(--neutral-700);">CUSTOM ATTEMPT LIMIT</label>
            <div class="security-input-row">
              <input type="number" id="adminMaxAttemptsInput" min="1" max="20" value="3" oninput="onCustomAttemptChange()">
              <span>attempts before lockout</span>
            </div>
          </div>
        </div>
        <div style="font-size: 11.5px; color: var(--neutral-500); margin-top: 10px; padding-top: 8px; border-top: 1px solid var(--neutral-100);">
          💡 Recommended: <strong>3 attempts</strong> for optimal protection against unauthorized access.
        </div>
      </div>

      <!-- CARD 2: LOCKOUT PENALTY DURATION -->
      <div class="security-setting-card">
        <div>
          <div class="security-card-header">
            <div class="security-card-icon amber">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="security-card-title">
              <h3 style="font-size: 15px;">Lockout Penalty Duration</h3>
              <p>Base cooling-off duration the user must wait before attempting to sign in again.</p>
            </div>
          </div>

          <div class="security-preset-pills" id="durationPillsContainer">
            <button type="button" class="sec-preset-pill active" onclick="selectDurationPreset(30, this)">30 Seconds (Default)</button>
            <button type="button" class="sec-preset-pill" onclick="selectDurationPreset(60, this)">1 Minute</button>
            <button type="button" class="sec-preset-pill" onclick="selectDurationPreset(120, this)">2 Minutes</button>
            <button type="button" class="sec-preset-pill" onclick="selectDurationPreset(300, this)">5 Minutes</button>
          </div>

          <div class="form-group" style="margin-top: 14px;">
            <label for="adminLockoutDurationInput" style="font-size: 11px; font-weight: 800; color: var(--neutral-700);">CUSTOM LOCKOUT SECONDS</label>
            <div class="security-input-row">
              <input type="number" id="adminLockoutDurationInput" min="5" max="3600" value="30" oninput="onCustomDurationChange()">
              <span>seconds cooling-off period</span>
            </div>
          </div>
        </div>
        <div style="font-size: 11.5px; color: var(--neutral-500); margin-top: 10px; padding-top: 8px; border-top: 1px solid var(--neutral-100);">
          ⏱️ Choose between fast <strong>30 seconds</strong>, <strong>1 minute</strong>, or <strong>2 minutes</strong> base penalty.
        </div>
      </div>
    </div>

    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; border-top: 1px solid var(--neutral-200); padding-top: 14px;">
      <button type="button" class="btn-modal-cancel" onclick="closeModal('modalAdminSecuritySettings')">Cancel</button>
      <button type="button" class="auth-btn" onclick="saveAdminSecuritySettings()" id="btnSaveSecuritySettings" style="margin-top: 0; min-width: 180px;">Save Security Settings</button>
    </div>
  </div>
</div>

<!-- Enhanced Edit Profile Modal -->
<div class="modal-backdrop" id="modalEditProfile">
  <div class="modal-box edit-profile-modal-box">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div class="modal-header-icon green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
        <div>
          <h3 data-en="Edit Profile Information" data-tl="I-edit ang Profile" style="margin: 0; font-size: 17px; font-weight: 800;">Edit Profile Information</h3>
          <p style="font-size: 11.5px; color: var(--neutral-500); margin: 2px 0 0;">Update your photo, display name, location, and account password</p>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('modalEditProfile')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>

    <div class="error-banner" id="editProfileError"></div>
    <div class="success-banner" id="editProfileSuccess"></div>

    <form class="auth-form edit-profile-form" id="editProfileForm" onsubmit="handleUpdateProfile(event)">
      <!-- Interactive Profile Photo & User Preview Strip -->
      <div class="profile-preview-strip">
        <div class="photo-preview-avatar" id="editProfilePhotoPreview" onclick="document.getElementById('editProfilePhotoInput').click()" title="Click to choose profile picture from Gallery or Files">
          <span id="editProfilePhotoInitials">MJ</span>
          <img id="editProfilePhotoImg" src="" alt="Profile Photo" style="display: none;">
          <div class="photo-overlay-badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          </div>
        </div>
        <input type="file" id="editProfilePhotoInput" accept="image/*" style="display: none;" onchange="handleProfilePhotoSelected(event)">

        <div style="flex: 1; min-width: 0;">
          <div style="font-weight: 800; font-size: 13.5px; color: var(--neutral-900); line-height: 1.2;" id="editProfileDisplayName">User</div>
          <div style="font-size: 11px; color: var(--neutral-500); margin: 1px 0 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" id="editProfileDisplayRole">Rice Farmer · Roxas, Oriental Mindoro</div>
          <div class="photo-uploader-actions">
            <button type="button" class="btn-avatar-pick" onclick="document.getElementById('editProfilePhotoInput').click()">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              <span data-en="Change Photo" data-tl="Palitan ang Larawan">Change Photo</span>
            </button>
            <button type="button" class="btn-avatar-remove" id="btnRemoveProfilePhoto" onclick="handleRemoveProfilePhoto()" style="display: none;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              <span data-en="Remove" data-tl="Alisin">Remove</span>
            </button>
          </div>
        </div>
      </div>

      <div class="form-group">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <span data-en="Full Name" data-tl="Buong Pangalan">Full Name</span>
        </label>
        <input type="text" id="editName" class="form-input-styled" required placeholder="e.g. Mang Juan Dela Cruz">
      </div>

      <div class="form-group">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
          <span data-en="Farm Location / Assigned Area" data-tl="Lokasyon ng Sakahan">Farm Location / Assigned Area</span>
        </label>
        <input type="text" id="editLocation" class="form-input-styled" placeholder="e.g. Brgy. San Mariano, Roxas, Oriental Mindoro">
      </div>

      <!-- Password Section Divider -->
      <div class="profile-password-divider">
        <div class="divider-line"></div>
        <span class="divider-text" data-en="Change Password (Optional)" data-tl="Baguhin ang Password (Opsyonal)">Change Password (Optional)</span>
        <div class="divider-line"></div>
      </div>

      <div class="form-group">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <span data-en="New Password" data-tl="Bagong Password">New Password</span>
        </label>
        <div class="password-input-wrap">
          <input type="password" id="editPassword" class="form-input-styled" placeholder="Leave blank to keep current password" minlength="6">
          <button type="button" class="btn-toggle-pw" onclick="togglePassword('editPassword', this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>

      <div class="form-group">
        <label>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <span data-en="Confirm New Password" data-tl="Kumpirmahin ang Password">Confirm New Password</span>
        </label>
        <div class="password-input-wrap">
          <input type="password" id="editPasswordConfirm" class="form-input-styled" placeholder="Re-type new password" minlength="6">
          <button type="button" class="btn-toggle-pw" onclick="togglePassword('editPasswordConfirm', this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>

      <div class="edit-profile-actions">
        <button type="button" class="btn-secondary-cancel" onclick="closeModal('modalEditProfile')">
          <span data-en="Cancel" data-tl="Kanselahin">Cancel</span>
        </button>
        <button type="submit" class="btn-primary-save" id="saveProfileBtn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          <span data-en="Save Profile" data-tl="I-save ang Profile">Save Profile</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Centered Logout Confirmation Modal -->
<div class="modal-backdrop" id="modalLogoutConfirm">
  <div class="modal-box logout-modal-box">
    <div class="logout-modal-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
    </div>
    <h3 class="logout-modal-title" data-en="Sign Out from Oryzatix?" data-tl="Mag-sign Out sa Oryzatix?">Sign Out from Oryzatix?</h3>
    <p class="logout-modal-desc" data-en="Are you sure you want to end your current session? You can sign back in anytime with your credentials." data-tl="Sigurado ka ba na nais mong mag-sign out? Maaari kang mag-sign in muli anumang oras gamit ang iyong account.">Are you sure you want to end your current session? You can sign back in anytime with your credentials.</p>
    <div class="logout-modal-actions">
      <button type="button" class="logout-btn-cancel" onclick="closeModal('modalLogoutConfirm')">
        <span data-en="Cancel" data-tl="Kanselahin">Cancel</span>
      </button>
      <button type="button" class="logout-btn-confirm" id="confirmLogoutBtn" onclick="confirmLogoutAction()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
        <span data-en="Yes, Sign Out" data-tl="Oo, Mag-sign Out">Yes, Sign Out</span>
      </button>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="modalDiseaseLibrary">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Disease Reference Library</h3>
      <button class="modal-close-btn" onclick="closeModal('modalDiseaseLibrary')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div style="display:flex; flex-direction:column; gap:10px;">
      <div class="treatment-item" style="cursor:pointer;" onclick="closeModal('modalDiseaseLibrary'); selectAndShowTreatment('blast');">
        <h4>Leaf Blast (Magnaporthe oryzae)</h4>
        <p>Spindle-shaped lesions with grayish center and brown margins.</p>
      </div>
      <div class="treatment-item" style="cursor:pointer;" onclick="closeModal('modalDiseaseLibrary'); selectAndShowTreatment('blb');">
        <h4>Bacterial Leaf Blight (Xanthomonas oryzae)</h4>
        <p>Wavy yellow-orange margins drying from leaf tip downwards.</p>
      </div>
      <div class="treatment-item" style="cursor:pointer;" onclick="closeModal('modalDiseaseLibrary'); selectAndShowTreatment('brown_spot');">
        <h4>Brown Spot (Bipolaris oryzae)</h4>
        <p>Small circular brown spots scattered across leaf blade.</p>
      </div>
      <div class="treatment-item" style="cursor:pointer;" onclick="closeModal('modalDiseaseLibrary'); selectAndShowTreatment('tungro');">
        <h4>Rice Tungro Disease (RTBV / RTSV)</h4>
        <p>Stunted plant growth and yellow-orange discoloration.</p>
      </div>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="modalHelpSupport">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Photography & Lighting Tips</h3>
      <button class="modal-close-btn" onclick="closeModal('modalHelpSupport')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div style="font-size:13.5px; line-height:1.6; color:#4b5563;">
      <p><strong>Center the Leaf:</strong> Keep the affected blade in the center of the box.</p>
      <p><strong>Bright Daylight:</strong> Scan in natural daylight without harsh shadows.</p>
      <p><strong>Hold Steady:</strong> Ensure sharp focus on lesion spots.</p>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="modalAbout">
  <div class="modal-box">
    <div class="modal-header">
      <h3>About Oryzatix System</h3>
      <button class="modal-close-btn" onclick="closeModal('modalAbout')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div style="font-size:13.5px; line-height:1.6; color:#4b5563;">
      <p><strong>ORYZATIX</strong> is an image processing-based rice leaf disease detection system utilizing MobileNet deep convolutional neural network architecture with automated management recommendations.</p>
      <p>Developed by: John Paul College · Department of Information Technology</p>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="modalNotifications">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Crop Notifications</h3>
      <button class="modal-close-btn" onclick="closeModal('modalNotifications')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div style="padding: 14px; background: var(--amber-100); border-radius: 12px; font-size: 13px; color: var(--amber-800);">
      <strong>Wet Weather Disease Advisory</strong><br>
      High humidity detected in the region. Inspect leaf blades for blast lesions and ensure field drainage.
    </div>
  </div>
</div>

<!-- Staff: Add Advisory Note Modal -->
<div class="modal-backdrop" id="modalStaffAdvisory">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Extension Advisory Note</h3>
      <button class="modal-close-btn" onclick="closeModal('modalStaffAdvisory')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="error-banner" id="staffAdvisoryError"></div>
    <div class="success-banner" id="staffAdvisorySuccess"></div>
    <div class="staff-advisory-scan-preview" id="staffAdvisoryPreview" style="margin-bottom: 14px; padding: 12px; background: var(--neutral-50); border-radius: var(--radius-md); border: 1px solid var(--neutral-200); font-size: 13px;"></div>
    <form class="auth-form" id="staffAdvisoryForm" onsubmit="handleStaffAdvisorySubmit(event)">
      <input type="hidden" id="staffAdvisoryScanId">
      <div class="form-group">
        <label style="font-weight: 700; font-size: 13px; color: var(--neutral-800);">Official Agronomist / Extension Advice</label>
        <textarea id="staffAdvisoryText" rows="4" style="width:100%; padding:10px 12px; border:1.5px solid var(--neutral-300); border-radius:var(--radius-md); font-family:inherit; font-size:13px; resize: vertical;" placeholder="Type your specific field advice, recommended chemical brand, dosage, or cultural action for this farmer..." required></textarea>
      </div>
      <button type="submit" class="auth-btn" id="staffAdvisorySubmitBtn" style="background:var(--brand-green); margin-top: 10px;">Submit Official Advisory</button>
    </form>
  </div>
</div>

<!-- ═══════════ ADMIN DISEASE MANAGEMENT MODALS ═══════════ -->

<!-- Admin: Add Disease Modal -->
<div class="modal-backdrop" id="modalAdminAddDisease">
  <div class="modal-box edit-profile-modal-box" style="max-width: 620px;">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div class="modal-header-icon green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <div>
          <h3 style="margin: 0; font-size: 17px; font-weight: 800;">Add Rice Disease Record</h3>
          <p style="font-size: 11.5px; color: var(--neutral-500); margin: 2px 0 0;">Add new pathogen information, agronomic symptoms, causes, and treatments</p>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('modalAdminAddDisease')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="error-banner" id="adminAddDiseaseError"></div>
    <div class="success-banner" id="adminAddDiseaseSuccess"></div>
    <form class="auth-form edit-profile-form" id="adminAddDiseaseForm" onsubmit="handleAdminAddDisease(event)">
      <div class="form-group">
        <label><span>Disease Name</span></label>
        <input type="text" id="adminAddDiseaseName" class="form-input-styled" placeholder="e.g. Bacterial Leaf Blight" required>
      </div>
      <div class="form-group">
        <label><span>Scientific Name / Pathogen</span></label>
        <input type="text" id="adminAddDiseaseSciName" class="form-input-styled" placeholder="e.g. Xanthomonas oryzae pv. oryzae">
      </div>
      <div class="form-group">
        <label><span>Image (Choose File or leave blank for default)</span></label>
        <input type="file" id="adminAddDiseaseImage" accept="image/*" class="form-input-styled">
      </div>
      <div class="form-group">
        <label><span>Description Overview</span></label>
        <textarea id="adminAddDiseaseDesc" rows="3" class="form-input-styled" placeholder="General background of the disease..." required></textarea>
      </div>
      <div class="form-group">
        <label><span>Symptoms</span></label>
        <textarea id="adminAddDiseaseSymptoms" rows="3" class="form-input-styled" placeholder="Visual lesion cues, leaf discoloration..." required></textarea>
      </div>
      <div class="form-group">
        <label><span>Causes & Favorable Factors</span></label>
        <textarea id="adminAddDiseaseCauses" rows="3" class="form-input-styled" placeholder="Weather conditions, excessive nitrogen, vectors..."></textarea>
      </div>
      <div class="form-group">
        <label><span>Prevention Strategies</span></label>
        <textarea id="adminAddDiseasePrevention" rows="3" class="form-input-styled" placeholder="Field sanitation, resistant varieties, spacing..."></textarea>
      </div>
      <div class="form-group">
        <label><span>Recommended Management & Treatment</span></label>
        <textarea id="adminAddDiseaseTreatment" rows="3" class="form-input-styled" placeholder="Chemical bactericides/fungicides, biocontrol, water drainage..." required></textarea>
      </div>
      <div class="form-group">
        <label><span>Status</span></label>
        <select id="adminAddDiseaseStatus" class="form-input-styled">
          <option value="1">Active (Visible in Library & Detection)</option>
          <option value="0">Inactive (Draft / Hidden)</option>
        </select>
      </div>
      <div style="display: flex; gap: 10px; margin-top: 14px;">
        <button type="button" class="btn-modal-cancel" onclick="closeModal('modalAdminAddDisease')">Cancel</button>
        <button type="submit" class="auth-btn" id="adminAddDiseaseBtn" style="margin-top: 0; flex: 1;">Save Disease Info</button>
      </div>
    </form>
  </div>
</div>

<!-- Admin: Edit Disease Modal -->
<div class="modal-backdrop" id="modalAdminEditDisease">
  <div class="modal-box edit-profile-modal-box" style="max-width: 620px;">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div class="modal-header-icon blue">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </div>
        <div>
          <h3 style="margin: 0; font-size: 17px; font-weight: 800;">Edit Disease Information</h3>
          <p style="font-size: 11.5px; color: var(--neutral-500); margin: 2px 0 0;">Update agronomic recommendations, symptoms, and treatment guidelines</p>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('modalAdminEditDisease')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="error-banner" id="adminEditDiseaseError"></div>
    <div class="success-banner" id="adminEditDiseaseSuccess"></div>
    <form class="auth-form edit-profile-form" id="adminEditDiseaseForm" onsubmit="handleAdminEditDisease(event)">
      <input type="hidden" id="adminEditDiseaseId">
      <div class="form-group">
        <label><span>Disease Name</span></label>
        <input type="text" id="adminEditDiseaseName" class="form-input-styled" required>
      </div>
      <div class="form-group">
        <label><span>Scientific Name / Pathogen</span></label>
        <input type="text" id="adminEditDiseaseSciName" class="form-input-styled">
      </div>
      <div class="form-group">
        <label><span>Update Image (Leave blank to keep existing)</span></label>
        <input type="file" id="adminEditDiseaseImage" accept="image/*" class="form-input-styled">
      </div>
      <div class="form-group">
        <label><span>Description Overview</span></label>
        <textarea id="adminEditDiseaseDesc" rows="3" class="form-input-styled" required></textarea>
      </div>
      <div class="form-group">
        <label><span>Symptoms</span></label>
        <textarea id="adminEditDiseaseSymptoms" rows="3" class="form-input-styled" required></textarea>
      </div>
      <div class="form-group">
        <label><span>Causes & Favorable Factors</span></label>
        <textarea id="adminEditDiseaseCauses" rows="3" class="form-input-styled"></textarea>
      </div>
      <div class="form-group">
        <label><span>Prevention Strategies</span></label>
        <textarea id="adminEditDiseasePrevention" rows="3" class="form-input-styled"></textarea>
      </div>
      <div class="form-group">
        <label><span>Recommended Management & Treatment</span></label>
        <textarea id="adminEditDiseaseTreatment" rows="3" class="form-input-styled" required></textarea>
      </div>
      <div class="form-group">
        <label><span>Status</span></label>
        <select id="adminEditDiseaseStatus" class="form-input-styled">
          <option value="1">Active</option>
          <option value="0">Inactive</option>
        </select>
      </div>
      <div style="display: flex; gap: 10px; margin-top: 14px;">
        <button type="button" class="btn-modal-cancel" onclick="closeModal('modalAdminEditDisease')">Cancel</button>
        <button type="submit" class="auth-btn" id="adminEditDiseaseBtn" style="margin-top: 0; flex: 1;">Update Disease</button>
      </div>
    </form>
  </div>
</div>

<!-- Admin: View Full Disease Details Modal -->
<div class="modal-backdrop" id="modalAdminViewDisease">
  <div class="modal-box edit-profile-modal-box" style="max-width: 680px; max-height: 85vh; overflow-y: auto;">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div class="modal-header-icon green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <div>
          <h3 id="adminViewDiseaseTitle" style="margin: 0; font-size: 17px; font-weight: 800;">Disease Details</h3>
          <p id="adminViewDiseaseSciTitle" style="font-size: 12px; font-style: italic; color: var(--neutral-500); margin: 2px 0 0;">Scientific Name</p>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('modalAdminViewDisease')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div id="adminViewDiseaseContent" style="padding: 10px 0;">
      <!-- Content populated dynamically -->
    </div>
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 14px; border-top: 1px solid var(--neutral-200); padding-top: 12px;">
      <button type="button" id="adminViewDiseaseDeleteBtn" class="btn-danger-outline" style="display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; font-size: 12.5px; font-weight: 700; color: #e11d48; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; cursor: pointer; transition: all 0.2s;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        Delete Disease
      </button>
      <button type="button" class="btn-modal-cancel" onclick="closeModal('modalAdminViewDisease')">Close</button>
    </div>
  </div>
</div>

<!-- Admin: Delete Disease Confirmation Modal -->
<div class="modal-backdrop" id="modalAdminDeleteDisease">
  <div class="modal-box delete-confirm-modal-box">
    <div class="delete-modal-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
        <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
      </svg>
    </div>
    <h3 class="delete-modal-title">Delete Disease Entry?</h3>
    <p class="delete-modal-desc">Are you sure you want to delete <strong id="adminDeleteDiseaseName" style="color: var(--neutral-900);">this disease</strong> from the reference library?</p>
    <div class="error-banner" id="adminDeleteDiseaseError" style="margin-bottom: 14px;"></div>
    <div class="delete-modal-actions">
      <button type="button" class="btn-modal-cancel" onclick="closeModal('modalAdminDeleteDisease')">Cancel</button>
      <button type="button" class="btn-modal-danger" id="confirmDeleteDiseaseBtn" onclick="confirmAdminDeleteDisease()">Yes, Delete Disease</button>
    </div>
  </div>
</div>

<!-- ═══════════ ADMIN DETECTION LOGS & USER SCAN MODALS ═══════════ -->

<!-- Admin: View User Scans History Modal -->
<div class="modal-backdrop" id="modalAdminUserScans">
  <div class="modal-box edit-profile-modal-box" style="max-width: 720px;">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div class="modal-header-icon green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div>
          <h3 style="margin: 0; font-size: 17px; font-weight: 800;" id="adminUserScansModalTitle">User Detection History</h3>
          <p style="font-size: 11.5px; color: var(--neutral-500); margin: 2px 0 0;" id="adminUserScansModalSubtitle">All leaf disease scans performed by this user</p>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('modalAdminUserScans')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="table-responsive" style="max-height: 400px; overflow-y: auto; margin-top: 10px;">
      <table class="admin-table">
        <thead>
          <tr>
            <th style="width: 50px;">Photo</th>
            <th>Disease Result</th>
            <th>Confidence</th>
            <th>Severity</th>
            <th>Date & Time</th>
          </tr>
        </thead>
        <tbody id="adminUserScansTableBody">
          <tr><td colspan="5" class="empty-table-msg">Loading user scans...</td></tr>
        </tbody>
      </table>
    </div>
    <div style="margin-top: 16px; text-align: right;">
      <button type="button" class="btn-modal-cancel" onclick="closeModal('modalAdminUserScans')">Close</button>
    </div>
  </div>
</div>

<!-- Admin: Scan Zoom / Preview Modal -->
<div class="modal-backdrop" id="modalAdminScanPreview">
  <div class="modal-box edit-profile-modal-box" style="max-width: 540px;">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div class="modal-header-icon green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </div>
        <div>
          <h3 style="margin: 0; font-size: 17px; font-weight: 800;">Detection Record Details</h3>
          <p style="font-size: 11.5px; color: var(--neutral-500); margin: 2px 0 0;" id="adminScanPreviewSub">High-resolution specimen view</p>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('modalAdminScanPreview')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div id="adminScanPreviewContent" style="margin-top: 10px;">
      <!-- Populated dynamically -->
    </div>
    <div style="margin-top: 16px; text-align: right;">
      <button type="button" class="btn-modal-cancel" onclick="closeModal('modalAdminScanPreview')">Close</button>
    </div>
  </div>
</div>

<!-- Admin: Delete Scan Record Modal -->
<div class="modal-backdrop" id="modalAdminDeleteScan">
  <div class="modal-box delete-confirm-modal-box">
    <div class="delete-modal-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
        <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
      </svg>
    </div>
    <h3 class="delete-modal-title">Delete Detection Record?</h3>
    <p class="delete-modal-desc">Are you sure you want to delete scan record <strong id="adminDeleteScanId" style="color: var(--neutral-900);">#0</strong>? This record will be permanently deleted from system logs.</p>
    <div class="error-banner" id="adminDeleteScanError" style="margin-bottom: 14px;"></div>
    <div class="delete-modal-actions">
      <button type="button" class="btn-modal-cancel" onclick="closeModal('modalAdminDeleteScan')">Cancel</button>
      <button type="button" class="btn-modal-danger" id="confirmDeleteScanBtn" onclick="confirmAdminDeleteScan()">Yes, Delete Record</button>
    </div>
  </div>
</div>

<!-- ═══════════ ADMIN CHATBOT FAQ / KNOWLEDGE MODALS ═══════════ -->

<!-- Admin: Add FAQ Modal -->
<div class="modal-backdrop" id="modalAdminAddFaq">
  <div class="modal-box edit-profile-modal-box" style="max-width: 600px;">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div class="modal-header-icon green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div>
          <h3 style="margin: 0; font-size: 17px; font-weight: 800;">Add Chatbot Knowledge Rule</h3>
          <p style="font-size: 11.5px; color: var(--neutral-500); margin: 2px 0 0;">Teach the AI Agronomist chatbot new rice-farming answers and FAQs</p>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('modalAdminAddFaq')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="error-banner" id="adminAddFaqError"></div>
    <div class="success-banner" id="adminAddFaqSuccess"></div>
    <form class="auth-form edit-profile-form" id="adminAddFaqForm" onsubmit="handleAdminAddFaq(event)">
      <div class="form-group">
        <label><span>Category / Tag</span></label>
        <select id="adminAddFaqCategory" class="form-input-styled" required>
          <option value="general">General Rice Farming</option>
          <option value="bacterial_leaf_blight">Bacterial Leaf Blight</option>
          <option value="rice_blast">Rice Blast</option>
          <option value="brown_spot">Brown Spot</option>
          <option value="tungro">Rice Tungro</option>
          <option value="prevention">Pest & Disease Prevention</option>
          <option value="fertilizer">Fertilizer & Nutrition</option>
        </select>
      </div>
      <div class="form-group">
        <label><span>User Question / Trigger Words</span></label>
        <input type="text" id="adminAddFaqQuestion" class="form-input-styled" placeholder="e.g. Paano maiiwasan ang bacterial leaf blight?" required>
      </div>
      <div class="form-group">
        <label><span>AI Agronomist Answer</span></label>
        <textarea id="adminAddFaqAnswer" rows="4" class="form-input-styled" placeholder="Detailed agronomic response to give the farmer..." required></textarea>
      </div>
      <div class="form-group">
        <label><span>Language</span></label>
        <select id="adminAddFaqLang" class="form-input-styled">
          <option value="both">Both Tagalog & English</option>
          <option value="tl">Tagalog (Filipino)</option>
          <option value="en">English</option>
        </select>
      </div>
      <div class="form-group">
        <label><span>Status</span></label>
        <select id="adminAddFaqStatus" class="form-input-styled">
          <option value="1">Active (Available to Chatbot)</option>
          <option value="0">Inactive</option>
        </select>
      </div>
      <div style="display: flex; gap: 10px; margin-top: 14px;">
        <button type="button" class="btn-modal-cancel" onclick="closeModal('modalAdminAddFaq')">Cancel</button>
        <button type="submit" class="auth-btn" id="adminAddFaqBtn" style="margin-top: 0; flex: 1;">Save Q&A Rule</button>
      </div>
    </form>
  </div>
</div>

<!-- Admin: Edit FAQ Modal -->
<div class="modal-backdrop" id="modalAdminEditFaq">
  <div class="modal-box edit-profile-modal-box" style="max-width: 600px;">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div class="modal-header-icon blue">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </div>
        <div>
          <h3 style="margin: 0; font-size: 17px; font-weight: 800;">Edit Chatbot Knowledge Rule</h3>
          <p style="font-size: 11.5px; color: var(--neutral-500); margin: 2px 0 0;">Update agronomic answer or trigger question</p>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('modalAdminEditFaq')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="error-banner" id="adminEditFaqError"></div>
    <div class="success-banner" id="adminEditFaqSuccess"></div>
    <form class="auth-form edit-profile-form" id="adminEditFaqForm" onsubmit="handleAdminEditFaq(event)">
      <input type="hidden" id="adminEditFaqId">
      <div class="form-group">
        <label><span>Category / Tag</span></label>
        <select id="adminEditFaqCategory" class="form-input-styled" required>
          <option value="general">General Rice Farming</option>
          <option value="bacterial_leaf_blight">Bacterial Leaf Blight</option>
          <option value="rice_blast">Rice Blast</option>
          <option value="brown_spot">Brown Spot</option>
          <option value="tungro">Rice Tungro</option>
          <option value="prevention">Pest & Disease Prevention</option>
          <option value="fertilizer">Fertilizer & Nutrition</option>
        </select>
      </div>
      <div class="form-group">
        <label><span>User Question / Trigger Words</span></label>
        <input type="text" id="adminEditFaqQuestion" class="form-input-styled" required>
      </div>
      <div class="form-group">
        <label><span>AI Agronomist Answer</span></label>
        <textarea id="adminEditFaqAnswer" rows="4" class="form-input-styled" required></textarea>
      </div>
      <div class="form-group">
        <label><span>Language</span></label>
        <select id="adminEditFaqLang" class="form-input-styled">
          <option value="both">Both Tagalog & English</option>
          <option value="tl">Tagalog (Filipino)</option>
          <option value="en">English</option>
        </select>
      </div>
      <div class="form-group">
        <label><span>Status</span></label>
        <select id="adminEditFaqStatus" class="form-input-styled">
          <option value="1">Active</option>
          <option value="0">Inactive</option>
        </select>
      </div>
      <div style="display: flex; gap: 10px; margin-top: 14px;">
        <button type="button" class="btn-modal-cancel" onclick="closeModal('modalAdminEditFaq')">Cancel</button>
        <button type="submit" class="auth-btn" id="adminEditFaqBtn" style="margin-top: 0; flex: 1;">Update Rule</button>
      </div>
    </form>
  </div>
</div>

<!-- Admin: Delete FAQ Modal -->
<div class="modal-backdrop" id="modalAdminDeleteFaq">
  <div class="modal-box delete-confirm-modal-box">
    <div class="delete-modal-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
        <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
      </svg>
    </div>
    <h3 class="delete-modal-title">Delete Knowledge Rule?</h3>
    <p class="delete-modal-desc">Are you sure you want to delete <strong id="adminDeleteFaqQuestion" style="color: var(--neutral-900);">this Q&A entry</strong> from the chatbot database?</p>
    <div class="error-banner" id="adminDeleteFaqError" style="margin-bottom: 14px;"></div>
    <div class="delete-modal-actions">
      <button type="button" class="btn-modal-cancel" onclick="closeModal('modalAdminDeleteFaq')">Cancel</button>
      <button type="button" class="btn-modal-danger" id="confirmDeleteFaqBtn" onclick="confirmAdminDeleteFaq()">Yes, Delete Entry</button>
    </div>
  </div>
</div>

<!-- ═══════════ PWA MOBILE APP & CONNECTIVITY MODALS ═══════════ -->

<!-- Smart PWA Floating Install Banner -->
<div class="pwa-install-banner" id="pwaInstallBanner" style="display: none;">
  <div class="pwa-banner-card">
    <div class="pwa-banner-media">
      <img src="{{ asset('images/logo.png') }}" alt="Oryzatix Logo" class="pwa-banner-icon">
    </div>
    <div class="pwa-banner-body">
      <h4 class="pwa-banner-title">Install ORYZATIX App</h4>
      <p class="pwa-banner-desc">Get fast full-screen leaf disease detection directly from your home screen.</p>
    </div>
    <div class="pwa-banner-actions">
      <button type="button" class="pwa-btn-dismiss" onclick="dismissPwaBanner()">Not Now</button>
      <button type="button" class="pwa-btn-install" onclick="triggerPwaInstall()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span>Install</span>
      </button>
    </div>
  </div>
</div>

<!-- Offline Mode Toast Banner -->
<div class="offline-indicator-banner" id="offlineIndicatorBanner" style="display: none;">
  <div class="offline-banner-pill">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" width="16" height="16"><line x1="1" y1="1" x2="23" y2="23"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/><path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/><path d="M10.71 5.05A16 16 0 0 1 22.58 9"/><path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
    <span>Offline Mode Active — Previously loaded scans and disease library remain available.</span>
  </div>
</div>

<!-- Mobile Phone Connect (Wi-Fi LAN QR Code) Modal -->
<div class="modal-backdrop" id="modalMobileConnect">
  <div class="modal-box edit-profile-modal-box mobile-connect-modal" style="max-width: 580px;">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div class="modal-header-icon green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
        </div>
        <div>
          <h3 style="margin: 0; font-size: 17px; font-weight: 800;">Open & Install on Mobile Device</h3>
          <p style="font-size: 11.5px; color: var(--neutral-500); margin: 2px 0 0;">Connect your smartphone over local Wi-Fi and install as an App</p>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('modalMobileConnect')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>

    <div class="mobile-connect-body">
      <!-- QR Code Display Card -->
      <div class="mobile-qr-card">
        <div class="qr-canvas-wrapper" id="mobileConnectQrCanvasWrap">
          <canvas id="mobileConnectQrCanvas" width="220" height="220"></canvas>
        </div>
        <div class="qr-caption">
          <div class="qr-live-badge"><span class="pulse-dot"></span> Wi-Fi Live Sync</div>
          <p>Scan with your iPhone or Android camera to open instantly</p>
        </div>
      </div>

      <!-- LAN URL Box with Copy Button -->
      <div class="mobile-url-box">
        <label for="mobileLanUrlInput">Direct Mobile Web App URL:</label>
        <div class="url-input-action-row">
          <input type="text" id="mobileLanUrlInput" readonly value="http://192.168.1.34:8000">
          <button type="button" class="btn-copy-url" id="btnCopyMobileUrl" onclick="copyMobileLanUrl()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            <span id="copyUrlLabel">Copy Link</span>
          </button>
        </div>
      </div>

      <!-- Step-by-Step Mobile Setup Guide -->
      <div class="mobile-instructions-grid">
        <div class="step-card">
          <div class="step-num">1</div>
          <div class="step-info">
            <h5>Connect to Wi-Fi</h5>
            <p>Ensure your phone and this computer are connected to the same Wi-Fi network.</p>
          </div>
        </div>

        <div class="step-card">
          <div class="step-num">2</div>
          <div class="step-info">
            <h5>Scan or Open URL</h5>
            <p>Open in <strong>Chrome</strong> (Android) or <strong>Safari</strong> (iPhone).</p>
          </div>
        </div>

        <div class="step-card">
          <div class="step-num">3</div>
          <div class="step-info">
            <h5>Install App</h5>
            <p>Tap <strong>"Install App"</strong> inside Oryzatix to add it to your home screen!</p>
          </div>
        </div>
      </div>
    </div>

    <div style="margin-top: 18px; display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: 10px; border-top: 1px solid var(--neutral-200); padding-top: 14px;">
      <a href="{{ asset('downloads/oryzatix.apk') }}" download="oryzatix-release.apk" class="btn-copy-url" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 10px 14px; background: #047857; color: #fff; border: 1px solid #047857; border-radius: 8px; font-weight: 700; font-size: 13px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Download Android APK
      </a>
      <button type="button" class="btn-modal-cancel" onclick="closeModal('modalMobileConnect')">Close</button>
      <button type="button" class="auth-btn" onclick="triggerPwaInstall(); closeModal('modalMobileConnect');" style="margin-top: 0; min-width: 160px;">Install on this Device</button>
    </div>
  </div>
</div>

<!-- iOS Safari Add to Home Screen Instructions Modal -->
<div class="modal-backdrop" id="modalIosInstall">
  <div class="modal-box edit-profile-modal-box" style="max-width: 480px;">
    <div class="modal-header">
      <div style="display: flex; align-items: center; gap: 10px;">
        <div class="modal-header-icon green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
        </div>
        <div>
          <h3 style="margin: 0; font-size: 17px; font-weight: 800;">Install on iPhone / iPad</h3>
          <p style="font-size: 11.5px; color: var(--neutral-500); margin: 2px 0 0;">Add Oryzatix directly to your home screen</p>
        </div>
      </div>
      <button class="modal-close-btn" onclick="closeModal('modalIosInstall')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>

    <div class="ios-install-steps" style="padding: 16px 0;">
      <div class="ios-step-item">
        <div class="ios-step-badge">1</div>
        <div class="ios-step-text">
          <p>In Safari, tap the <strong>Share</strong> button <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="vertical-align: middle; color: #0284c7;"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg> at the bottom of the screen.</p>
        </div>
      </div>

      <div class="ios-step-item">
        <div class="ios-step-badge">2</div>
        <div class="ios-step-text">
          <p>Scroll down the share sheet and tap <strong>"Add to Home Screen"</strong> <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="vertical-align: middle;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>.</p>
        </div>
      </div>

      <div class="ios-step-item">
        <div class="ios-step-badge">3</div>
        <div class="ios-step-text">
          <p>Tap <strong>"Add"</strong> in the top-right corner. Oryzatix will appear on your home screen like a native app!</p>
        </div>
      </div>
    </div>

    <div style="text-align: right; border-top: 1px solid var(--neutral-200); padding-top: 14px;">
      <button type="button" class="auth-btn" onclick="closeModal('modalIosInstall')" style="margin-top: 0; min-width: 120px;">Got it!</button>
    </div>
  </div>
</div>

<script src="{{ asset('js/qrcode.min.js') }}"></script>
<script src="{{ asset('js/rice-detector.js') }}?v={{ time() }}"></script>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('{{ asset('sw.js') }}').catch(function() {});
  });
}
</script>
</body>
</html>

