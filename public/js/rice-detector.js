let currentScanResult = null;
let currentUser = null;
let speechRecognition = null;
let isRecording = false;
let cameraStream = null;
let allHistoryScans = [];
let activeHistoryFilter = 'all';

const ALL_DISEASES = {
  blast: {
    key: 'blast',
    name: 'Leaf Blast',
    scientific: 'Magnaporthe oryzae',
    severity: 'severe',
    cls: 'disease-blast',
    thumb: 'blast',
    symptoms: 'Spindle-shaped lesions with reddish-brown margins and gray centers. Attacks leaves, nodes, and panicles.',
  },
  blb: {
    key: 'blb',
    name: 'Bacterial Leaf Blight',
    scientific: 'Xanthomonas oryzae pv. oryzae',
    severity: 'moderate',
    cls: 'disease-blb',
    thumb: 'blb',
    symptoms: 'Wavy water-soaked yellowing stripes drying from the leaf margins down toward the base.',
  },
  brown_spot: {
    key: 'brown_spot',
    name: 'Brown Spot',
    scientific: 'Bipolaris oryzae',
    severity: 'moderate',
    cls: 'disease-blb',
    thumb: 'blb',
    symptoms: 'Numerous small oval or circular brown spots scattered across leaf blades, common in poor/potassium-deficient soils.',
  },
  tungro: {
    key: 'tungro',
    name: 'Rice Tungro Disease',
    scientific: 'RTBV + RTSV (Viral)',
    severity: 'severe',
    cls: 'disease-blast',
    thumb: 'blast',
    symptoms: 'Stunted plant growth, reduced tillering, and pronounced yellow-orange discoloration of upper leaves.',
  },
  sheath_blight: {
    key: 'sheath_blight',
    name: 'Sheath Blight',
    scientific: 'Rhizoctonia solani',
    severity: 'moderate',
    cls: 'disease-blb',
    thumb: 'blb',
    symptoms: 'Irregular oval or snake-skin lesions with grayish-white centers and dark reddish-brown margins ascending from lower sheaths.',
  },
  healthy: {
    key: 'healthy',
    name: 'Healthy Rice Leaf',
    scientific: null,
    severity: 'healthy',
    cls: 'disease-healthy',
    thumb: 'healthy',
    symptoms: 'Vibrant green coloration, robust leaf blade, zero fungal lesions or bacterial streaks detected.',
  },
};

let csrfReady = false;

function getCookie(name) {
  const value = '; ' + document.cookie;
  const parts = value.split('; ' + name + '=');
  if (parts.length === 2) {
    const last = parts.pop();
    if (last) return decodeURIComponent(last.split(';').shift() || '');
  }
  return '';
}

function getCsrfToken() {
  const cookie = getCookie('XSRF-TOKEN');
  if (cookie) return cookie;
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? (meta.getAttribute('content') || '') : '';
}

function apiBase() {
  const loc = window.location;
  const path = loc.pathname;
  // If running in subfolder like /Oryzatix/public/ or /Gregorio_Alaissa/public/
  const publicIndex = path.indexOf('/public');
  if (publicIndex !== -1) {
    return loc.origin + path.substring(0, publicIndex + 7);
  }
  if (path.toLowerCase().startsWith('/oryzatix')) {
    return loc.origin + '/Oryzatix/public';
  }
  if (path.startsWith('/Gregorio_Alaissa')) {
    return loc.origin + '/Gregorio_Alaissa/public';
  }
  // Otherwise standard root URL
  return loc.origin;
}

function apiUrl(path) {
  const clean = path.replace(/^\//, '');
  return apiBase() + '/api/v1/' + clean;
}

function getAuthToken() {
  try {
    if (typeof window !== 'undefined' && window.localStorage) {
      window.localStorage.removeItem('oryzatix_auth_token');
      window.localStorage.removeItem('oryzatix_token');
    }
    const ses = (typeof window !== 'undefined' && window.sessionStorage) ? window.sessionStorage.getItem('oryzatix_auth_token') : null;
    return ses || null;
  } catch (e) {
    return null;
  }
}

function setAuthToken(token) {
  try {
    if (typeof window !== 'undefined' && window.localStorage) {
      window.localStorage.removeItem('oryzatix_auth_token');
      window.localStorage.removeItem('oryzatix_token');
    }
    if (typeof window !== 'undefined' && window.sessionStorage) {
      if (token) {
        window.sessionStorage.setItem('oryzatix_auth_token', token);
      } else {
        window.sessionStorage.removeItem('oryzatix_auth_token');
      }
    }
  } catch (e) {}
}

function authHeaders(extra = {}) {
  const token = getAuthToken();
  const headers = {
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-XSRF-TOKEN': getCsrfToken(),
    ...extra,
  };
  if (token) {
    headers['Authorization'] = 'Bearer ' + token;
  }
  return headers;
}

async function ensureCsrfCookie(force) {
  if (csrfReady && !force) return;
  try {
    await fetch(apiBase() + '/sanctum/csrf-cookie', {
      credentials: 'include',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    csrfReady = true;
  } catch (e) {
    console.warn('CSRF cookie fetch:', e);
  }
}

function setUILanguage(lang) {
  if (window.localStorage) window.localStorage.setItem('oryzatix_ui_lang', lang);
  const hiddenInput = document.getElementById('uiLanguage');
  if (hiddenInput) hiddenInput.value = lang;

  document.querySelectorAll('[id^="langEn"], [id^="langTl"], #webLangEn, #webLangTl').forEach(btn => {
    const isEn = /langEn|webLangEn/.test(btn.id);
    btn.classList.toggle('active', (lang === 'english' && isEn) || (lang === 'tagalog' && !isEn));
  });

  const attr = lang === 'english' ? 'data-en' : 'data-tl';
  const attrPlaceholder = lang === 'english' ? 'data-en-placeholder' : 'data-tl-placeholder';

  document.querySelectorAll('[data-en], [data-tl]').forEach(el => {
    const val = el.getAttribute(attr);
    if (val !== null) el.textContent = val;
  });

  document.querySelectorAll('[data-en-placeholder], [data-tl-placeholder]').forEach(el => {
    const val = el.getAttribute(attrPlaceholder);
    if (val !== null) el.setAttribute('placeholder', val);
  });

  const welcomeTag = document.getElementById('welcomeLangTag');
  if (welcomeTag) {
    welcomeTag.textContent = lang === 'english' ? 'English' : 'Tagalog';
    welcomeTag.className = 'lang-tag ' + (lang === 'english' ? 'english' : 'tagalog');
  }

  // Update active screen title in desktop topbar
  const activeScreen = document.querySelector('.screen.active');
  if (activeScreen) {
    setActiveSidebar(activeScreen.id);
  }
}

const SCREEN_TITLES = {
  home:               { en: 'Dashboard',                   tl: 'Dashboard' },
  'admin-dashboard':  { en: 'Admin Executive Dashboard',   tl: 'Admin Dashboard' },
  'staff-dashboard':  { en: 'Staff Extension Dashboard',   tl: 'Staff Dashboard' },
  scan:               { en: 'Leaf Disease Scanner',        tl: 'Scanner ng Dahon' },
  loading:            { en: 'Analyzing Leaf Image',        tl: 'Sinusuri ang Larawan' },
  results:            { en: 'Detection Results',           tl: 'Resulta ng Pagsusuri' },
  treatment:          { en: 'Treatment & Care Guide',      tl: 'Gabay sa Gamutan' },
  history:            { en: 'Scan History & Records',      tl: 'Kasaysayan ng mga Scan' },
  consultation:       { en: 'AI Agricultural Consult',     tl: 'AI Konsulta' },
  'admin-users':      { en: 'User Management',             tl: 'Pamamahala ng mga Account' },
  'admin-diseases':   { en: 'Disease Management',          tl: 'Pamamahala ng Impormasyon ng Sakit' },
  'admin-scans-logs': { en: 'Detection Records & Logs',    tl: 'Mga Tala ng Diagnosis Scan' },
  'admin-reports':    { en: 'Reports & Analytics',         tl: 'Mga Ulat at Pagsusuri' },
  'admin-chatbot':    { en: 'Chatbot & FAQ Management',    tl: 'Pamamahala ng Chatbot at FAQ' },
  'admin-settings':   { en: 'Account & App Settings',      tl: 'Mga Setting ng Account at App' },
  profile:            { en: 'Account & App Settings',      tl: 'Mga Setting ng Account at App' },
  login:              { en: 'Sign In',                     tl: 'Mag-Log In' },
  register:           { en: 'Create Account',              tl: 'Gumawa ng Account' },
  'forgot-password':  { en: 'Forgot Password',             tl: 'Nakalimutan ang Password' },
  splash:             { en: 'ORYZATIX',                    tl: 'ORYZATIX' },
};

function setActiveSidebar(screenId) {
  document.querySelectorAll('.sidebar-nav-btn').forEach(btn => {
    const match = btn.getAttribute('data-screen') === screenId;
    btn.classList.toggle('active', match);
  });

  document.querySelectorAll('.mobile-nav-btn').forEach(btn => {
    const onClick = (btn.getAttribute('onclick') || '').toLowerCase();
    let isMatch = false;
    if (screenId === 'home' && onClick.includes("'home'")) isMatch = true;
    if (screenId === 'profile' && onClick.includes("'profile'")) isMatch = true;
    btn.classList.toggle('active', isMatch);
  });

  const titleEl = document.getElementById('webPageTitle');
  if (titleEl && SCREEN_TITLES[screenId]) {
    const curLang = (document.getElementById('uiLanguage') || {}).value || 'english';
    const t = SCREEN_TITLES[screenId];
    titleEl.textContent = curLang === 'english' ? t.en : t.tl;
  }
}

const AUTH_SCREENS = ['login', 'register', 'splash', 'forgot-password'];

function setAuthMode(isAuthScreen) {
  const appShell = document.querySelector('.web-app-shell, .app-shell');
  if (!appShell) return;
  if (isAuthScreen) {
    appShell.classList.add('auth-mode');
  } else {
    appShell.classList.remove('auth-mode');
  }
}

function showScreen(id) {
  document.querySelectorAll('.screen').forEach(s => {
    s.classList.remove('active');
    s.style.setProperty('display', 'none', 'important');
  });
  const el = document.getElementById(id);
  if (el) {
    el.classList.add('active');
    el.style.removeProperty('display');
  }

  const isAuth = AUTH_SCREENS.includes(id);
  setAuthMode(isAuth);
  setActiveSidebar(id);

  if (!isAuth) {
    try {
      sessionStorage.setItem('oryzatix_active_screen', id);
      sessionStorage.removeItem('oryzatix_active_auth_screen');
    } catch (e) {}
  } else {
    try {
      sessionStorage.removeItem('oryzatix_active_screen');
      sessionStorage.setItem('oryzatix_active_auth_screen', id);
    } catch (e) {}
  }

  // Sync Mobile Bottom Nav Buttons
  document.querySelectorAll('.mobile-bottom-nav .mobile-nav-btn').forEach(btn => {
    const match = btn.getAttribute('data-screen') === id;
    btn.classList.toggle('active', match);
  });

  // Sync Mobile Drawer Nav Buttons
  document.querySelectorAll('.drawer-menu .sidebar-nav-btn').forEach(btn => {
    const match = btn.getAttribute('data-screen') === id;
    btn.classList.toggle('active', match);
  });

  // Automatically stop any AI reading when switching screens
  if (typeof stopAiSpeech === 'function') {
    stopAiSpeech();
  }

  if (id === 'home') {
    loadHomeRecentScans();
  } else if (id === 'history') {
    loadHistory();
  } else if (id === 'treatment') {
    loadCurrentTreatment();
  } else if (id === 'consultation') {
    loadChatMessages();
    if (typeof initAiVoiceMuteUI === 'function') initAiVoiceMuteUI();
  } else if (id === 'admin-dashboard') {
    loadAdminDashboard();
  } else if (id === 'admin-users') {
    loadAdminUsers();
  } else if (id === 'admin-diseases') {
    loadAdminDiseases();
  } else if (id === 'admin-scans-logs') {
    loadAdminScansLogs();
  } else if (id === 'admin-reports') {
    loadAdminReports();
  } else if (id === 'admin-chatbot') {
    loadAdminChatbot();
  } else if (id === 'admin-settings') {
    showScreen('profile');
    return;
  } else if (id === 'profile') {
    const secMenuItem = document.getElementById('profileSecurityMenuItem');
    if (secMenuItem) {
      secMenuItem.style.display = (currentUser && currentUser.role === 'admin') ? 'flex' : 'none';
    }
  } else if (id === 'staff-dashboard') {
    loadStaffDashboard();
  } else if (id === 'results') {
    if (!currentScanResult) {
      try {
        const savedScan = (typeof sessionStorage !== 'undefined') ? sessionStorage.getItem('oryzatix_last_scan_result') : null;
        if (savedScan) {
          applyScanResult(JSON.parse(savedScan));
        }
      } catch (e) {}
    }
  } else if (id === 'login') {
    resetAuthForms();
    checkLoginLockoutState();
    fetchSecurityConfig();
  } else if (id === 'register') {
    resetAuthForms();
  }

  if (id !== 'scan' && cameraStream) {
    stopLiveCamera();
  }

  if (el) el.scrollTop = 0;
}

function toggleMobileDrawer() {
  const overlay = document.getElementById('mobileDrawerOverlay');
  const drawer = document.getElementById('mobileDrawer');
  if (drawer && overlay) {
    const isOpen = drawer.classList.contains('show');
    if (isOpen) {
      closeMobileDrawer();
    } else {
      drawer.classList.add('show');
      overlay.classList.add('show');
    }
  }
}

function closeMobileDrawer() {
  const overlay = document.getElementById('mobileDrawerOverlay');
  const drawer = document.getElementById('mobileDrawer');
  if (drawer) drawer.classList.remove('show');
  if (overlay) overlay.classList.remove('show');
}

function navigateTo(screenId) {
  showScreen(screenId);
}

/* ═══════════ PROFILE PHOTO UPLOAD LOGIC ═══════════ */
let selectedProfilePhotoFile = null;
let removeProfilePhotoFlag = false;

function handleProfilePhotoSelected(event) {
  const file = event.target.files && event.target.files[0];
  if (!file) return;

  if (!file.type.startsWith('image/')) {
    const errBanner = document.getElementById('editProfileError');
    if (errBanner) {
      errBanner.textContent = 'Please select a valid image file (JPG, PNG, WEBP, etc.).';
      errBanner.classList.add('show');
    }
    event.target.value = '';
    return;
  }

  // Max 5MB
  if (file.size > 5 * 1024 * 1024) {
    const errBanner = document.getElementById('editProfileError');
    if (errBanner) {
      errBanner.textContent = 'Image file is too large. Maximum size is 5MB.';
      errBanner.classList.add('show');
    }
    event.target.value = '';
    return;
  }

  const errBanner = document.getElementById('editProfileError');
  if (errBanner) errBanner.classList.remove('show');

  selectedProfilePhotoFile = file;
  removeProfilePhotoFlag = false;

  const reader = new FileReader();
  reader.onload = function(e) {
    const previewImg = document.getElementById('editProfilePhotoImg');
    const initialsSpan = document.getElementById('editProfilePhotoInitials');
    const removeBtn = document.getElementById('btnRemoveProfilePhoto');

    if (previewImg) {
      previewImg.src = e.target.result;
      previewImg.style.display = 'block';
    }
    if (initialsSpan) initialsSpan.style.display = 'none';
    if (removeBtn) removeBtn.style.display = 'inline-flex';
  };
  reader.readAsDataURL(file);
}

function handleRemoveProfilePhoto() {
  selectedProfilePhotoFile = null;
  removeProfilePhotoFlag = true;

  const input = document.getElementById('editProfilePhotoInput');
  if (input) input.value = '';

  const previewImg = document.getElementById('editProfilePhotoImg');
  const initialsSpan = document.getElementById('editProfilePhotoInitials');
  const removeBtn = document.getElementById('btnRemoveProfilePhoto');

  if (previewImg) {
    previewImg.src = '';
    previewImg.style.display = 'none';
  }
  const initials = (currentUser && currentUser.name ? currentUser.name : 'MJ').split(' ').map(x => x[0]).join('').slice(0, 2).toUpperCase() || 'MJ';
  if (initialsSpan) {
    initialsSpan.textContent = initials;
    initialsSpan.style.display = 'block';
  }
  if (removeBtn) removeBtn.style.display = 'none';
}

/* ═══════════ MODAL HANDLERS ═══════════ */
function openModal(modalId) {
  const m = document.getElementById(modalId);
  if (m) {
    m.classList.add('show');
    if (modalId === 'modalEditProfile' && currentUser) {
      selectedProfilePhotoFile = null;
      removeProfilePhotoFlag = false;
      const fileInput = document.getElementById('editProfilePhotoInput');
      if (fileInput) fileInput.value = '';

      const n = document.getElementById('editName');
      const l = document.getElementById('editLocation');
      if (n) n.value = currentUser.name || '';
      if (l) l.value = currentUser.location || '';
      const p1 = document.getElementById('editPassword');
      const p2 = document.getElementById('editPasswordConfirm');
      if (p1) p1.value = '';
      if (p2) p2.value = '';

      const dn = document.getElementById('editProfileDisplayName');
      const dr = document.getElementById('editProfileDisplayRole');
      const initials = (currentUser.name || 'MJ').split(' ').map(x => x[0]).join('').slice(0, 2).toUpperCase();

      const previewImg = document.getElementById('editProfilePhotoImg');
      const initialsSpan = document.getElementById('editProfilePhotoInitials');
      const removeBtn = document.getElementById('btnRemoveProfilePhoto');

      if (currentUser.avatar_url) {
        if (previewImg) {
          previewImg.src = currentUser.avatar_url;
          previewImg.style.display = 'block';
        }
        if (initialsSpan) initialsSpan.style.display = 'none';
        if (removeBtn) removeBtn.style.display = 'inline-flex';
      } else {
        if (previewImg) {
          previewImg.src = '';
          previewImg.style.display = 'none';
        }
        if (initialsSpan) {
          initialsSpan.textContent = initials;
          initialsSpan.style.display = 'block';
        }
        if (removeBtn) removeBtn.style.display = 'none';
      }

      if (dn) dn.textContent = currentUser.name || 'User';

      const roleLabels = {
        farmer: 'Rice Farmer',
        agri_worker: 'Agri Extension Worker',
        admin: 'Administrator',
      };
      const rLabel = roleLabels[currentUser.role] || 'User';
      const locStr = currentUser.location ? ` · ${currentUser.location}` : '';
      if (dr) dr.textContent = `${rLabel}${locStr}`;

      const err = document.getElementById('editProfileError');
      const ok = document.getElementById('editProfileSuccess');
      if (err) err.classList.remove('show');
      if (ok) ok.classList.remove('show');
    }
  }
}

function closeModal(modalId) {
  const m = document.getElementById(modalId);
  if (m) m.classList.remove('show');
}

/* ═══════════ TOPBAR USER PROFILE & SETTINGS DROPDOWN ═══════════ */
function toggleTopbarUserDropdown(e) {
  if (e) {
    e.stopPropagation();
    e.preventDefault();
  }
  const dd = document.getElementById('topbarUserDropdown');
  const btn = document.getElementById('topbarUserBtn');
  if (!dd) return;

  const isCurrentlyOpen = dd.style.display === 'block';
  if (isCurrentlyOpen) {
    dd.style.display = 'none';
    if (btn) {
      btn.setAttribute('aria-expanded', 'false');
      btn.classList.remove('active');
    }
  } else {
    dd.style.display = 'block';
    if (btn) {
      btn.setAttribute('aria-expanded', 'true');
      btn.classList.add('active');
    }
  }
}

function closeTopbarUserDropdown() {
  const dd = document.getElementById('topbarUserDropdown');
  const btn = document.getElementById('topbarUserBtn');
  if (dd) dd.style.display = 'none';
  if (btn) {
    btn.setAttribute('aria-expanded', 'false');
    btn.classList.remove('active');
  }
}

// Close modals when clicking backdrop, or close topbar dropdown on outside click
window.addEventListener('click', function(e) {
  if (e.target.classList && e.target.classList.contains('modal-backdrop')) {
    e.target.classList.remove('show');
  }
  const userMenuWrap = document.getElementById('topbarUserMenuWrap');
  if (userMenuWrap && !userMenuWrap.contains(e.target)) {
    closeTopbarUserDropdown();
  }
});

// Close modals and dropdowns on Escape key
window.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-backdrop.show').forEach(m => m.classList.remove('show'));
    closeTopbarUserDropdown();
    closeMobileDrawer();
  }
});

/* ═══════════ AUTHENTICATION ═══════════ */
function navigateAfterAuth() {
  if (!currentUser) {
    showScreen('login');
    return;
  }
  if (currentUser.role === 'admin') {
    showScreen('admin-dashboard');
    loadAdminDashboard();
  } else if (currentUser.role === 'agri_worker') {
    showScreen('staff-dashboard');
    loadStaffDashboard();
  } else {
    showScreen('home');
    loadHomeRecentScans();
  }
}

async function checkAuthAndProceed() {
  const urlParams = new URLSearchParams(window.location.search);
  const paramToken = urlParams.get('auth_token');
  const isGoogleParam = urlParams.get('google_login') === '1';

  if (paramToken) {
    setAuthToken(paramToken);
    try {
      sessionStorage.setItem('oryzatix_is_logged_in', 'true');
    } catch (e) {}
    // Clean URL query string without page reload
    try {
      window.history.replaceState({}, document.title, window.location.pathname);
    } catch (e) {}
  }

  const isSessionLoggedIn = (typeof sessionStorage !== 'undefined') && sessionStorage.getItem('oryzatix_is_logged_in') === 'true';
  const isGoogleCallback = isGoogleParam || !!(window.INITIAL_AUTH && window.INITIAL_AUTH.googleLoginSuccess);
  const savedScreen = (typeof sessionStorage !== 'undefined') ? sessionStorage.getItem('oryzatix_active_screen') : null;
  const savedAuthScreen = (typeof sessionStorage !== 'undefined') ? sessionStorage.getItem('oryzatix_active_auth_screen') : null;

  // 1. If user is pre-rendered in INITIAL_AUTH or returning from Google login
  if (window.INITIAL_AUTH && window.INITIAL_AUTH.user) {
    try {
      sessionStorage.setItem('oryzatix_is_logged_in', 'true');
    } catch (e) {}
    currentUser = window.INITIAL_AUTH.user;
    if (window.INITIAL_AUTH.token) {
      setAuthToken(window.INITIAL_AUTH.token);
    }
    applyUserToUI();

    if (savedScreen && !AUTH_SCREENS.includes(savedScreen) && document.getElementById(savedScreen)) {
      showScreen(savedScreen);
    } else {
      navigateAfterAuth();
    }
    return;
  }

  // 2. If token exists (from URL param or storage), fetch user info directly
  const activeToken = getAuthToken() || paramToken;
  if (activeToken || isSessionLoggedIn || isGoogleCallback) {
    if (activeToken) {
      setAuthToken(activeToken);
    }
    try {
      const res = await fetch(apiUrl('/auth/user'), {
        credentials: 'include',
        headers: authHeaders(),
      });
      const data = await res.json();
      if (res.ok && data && data.success && data.user) {
        try {
          sessionStorage.setItem('oryzatix_is_logged_in', 'true');
        } catch (e) {}
        currentUser = data.user;
        applyUserToUI();
        if (savedScreen && !AUTH_SCREENS.includes(savedScreen) && document.getElementById(savedScreen)) {
          showScreen(savedScreen);
        } else {
          navigateAfterAuth();
        }
        return;
      }
    } catch (e) {
      console.warn('Auth token verification error:', e);
    }
  }

  // 3. User is NOT in an active session or is on an auth screen (login, register, forgot-password)
  setAuthToken(null);
  currentUser = null;
  if (typeof sessionStorage !== 'undefined') {
    sessionStorage.removeItem('oryzatix_is_logged_in');
    sessionStorage.removeItem('oryzatix_active_screen');
    sessionStorage.removeItem('oryzatix_last_scan_result');
  }
  resetAuthForms();
  const targetAuthScreen = (savedAuthScreen && AUTH_SCREENS.includes(savedAuthScreen) && document.getElementById(savedAuthScreen))
    ? savedAuthScreen
    : 'login';
  showScreen(targetAuthScreen);
}

function applyUserToUI() {
  if (!currentUser) return;
  const u = currentUser;
  const uname = document.getElementById('homeUserName');
  if (uname) uname.textContent = u.name || 'Mang Juan';
  const pname = document.getElementById('profileName');
  if (pname) pname.textContent = u.name || 'Mang Juan';

  const roleMap = {
    farmer: { en: 'Rice Farmer', tl: 'Magsasaka ng Palay' },
    agri_worker: { en: 'Agri Extension Worker', tl: 'Agri Extension Worker' },
    admin: { en: 'Administrator', tl: 'Administrator' },
  };
  const roleObj = roleMap[u.role] || { en: 'Rice Farmer', tl: 'Magsasaka ng Palay' };
  const loc = u.location ? ' · ' + u.location : ' · Roxas, Oriental Mindoro';

  const psub = document.getElementById('profileSubtitle');
  if (psub) psub.textContent = roleObj.en + loc;

  const initials = (u.name || 'MJ').split(' ').map(n => n[0]).join('').slice(0,2).toUpperCase();
  const avatar = document.getElementById('profileAvatar');
  if (avatar) {
    if (u.avatar_url) {
      avatar.innerHTML = `<img src="${u.avatar_url}" alt="${escapeHtml(u.name)}" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">`;
    } else {
      avatar.textContent = initials;
    }
  }

  // Topbar user trigger avatar & meta
  const topbarUserAvatarImg = document.getElementById('topbarUserAvatarImg');
  const topbarUserAvatarInitials = document.getElementById('topbarUserAvatarInitials');
  if (topbarUserAvatarImg && topbarUserAvatarInitials) {
    if (u.avatar_url) {
      topbarUserAvatarImg.src = u.avatar_url;
      topbarUserAvatarImg.style.display = 'block';
      topbarUserAvatarInitials.style.display = 'none';
    } else {
      topbarUserAvatarImg.src = '';
      topbarUserAvatarImg.style.display = 'none';
      topbarUserAvatarInitials.textContent = initials;
      topbarUserAvatarInitials.style.display = 'block';
    }
  }
  const topbarUserName = document.getElementById('topbarUserName');
  if (topbarUserName) topbarUserName.textContent = u.name || 'Mang Juan';
  const topbarUserRole = document.getElementById('topbarUserRole');
  if (topbarUserRole) {
    topbarUserRole.setAttribute('data-en', roleObj.en);
    topbarUserRole.setAttribute('data-tl', roleObj.tl);
    const curLang = (document.getElementById('uiLanguage') || {}).value || 'english';
    topbarUserRole.textContent = curLang === 'english' ? roleObj.en : roleObj.tl;
  }

  // Topbar dropdown user card details
  const dropdownAvatar = document.getElementById('dropdownAvatar');
  if (dropdownAvatar) {
    if (u.avatar_url) {
      dropdownAvatar.innerHTML = `<img src="${u.avatar_url}" alt="${escapeHtml(u.name)}" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">`;
    } else {
      dropdownAvatar.textContent = initials;
    }
  }
  const dropdownUserName = document.getElementById('dropdownUserName');
  if (dropdownUserName) dropdownUserName.textContent = u.name || 'Mang Juan';
  const dropdownUserEmail = document.getElementById('dropdownUserEmail');
  if (dropdownUserEmail) dropdownUserEmail.textContent = u.email || 'user@oryzatix.ph';
  const dropdownUserRoleBadge = document.getElementById('dropdownUserRoleBadge');
  if (dropdownUserRoleBadge) dropdownUserRoleBadge.textContent = roleObj.en;
  const dropdownLocationText = document.getElementById('dropdownLocationText');
  if (dropdownLocationText) dropdownLocationText.textContent = u.location || 'Roxas, Oriental Mindoro';

  // Edit profile display preview sync
  const editProfileDisplayName = document.getElementById('editProfileDisplayName');
  if (editProfileDisplayName) editProfileDisplayName.textContent = u.name || 'User';
  const editProfileDisplayRole = document.getElementById('editProfileDisplayRole');
  if (editProfileDisplayRole) editProfileDisplayRole.textContent = roleObj.en + (u.location ? ' · ' + u.location : ' · Roxas, Oriental Mindoro');
  const editProfilePhotoImg = document.getElementById('editProfilePhotoImg');
  const editProfilePhotoInitials = document.getElementById('editProfilePhotoInitials');
  const btnRemoveProfilePhoto = document.getElementById('btnRemoveProfilePhoto');
  if (editProfilePhotoImg && editProfilePhotoInitials) {
    if (u.avatar_url) {
      editProfilePhotoImg.src = u.avatar_url;
      editProfilePhotoImg.style.display = 'block';
      editProfilePhotoInitials.style.display = 'none';
      if (btnRemoveProfilePhoto) btnRemoveProfilePhoto.style.display = 'inline-flex';
    } else {
      editProfilePhotoImg.src = '';
      editProfilePhotoImg.style.display = 'none';
      editProfilePhotoInitials.textContent = initials;
      editProfilePhotoInitials.style.display = 'block';
      if (btnRemoveProfilePhoto) btnRemoveProfilePhoto.style.display = 'none';
    }
  }

  const sbAvatar = document.getElementById('sidebarUserAvatar');
  if (sbAvatar) {
    if (u.avatar_url) {
      sbAvatar.innerHTML = `<img src="${u.avatar_url}" alt="${escapeHtml(u.name)}" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">`;
    } else {
      sbAvatar.textContent = initials;
    }
  }
  const sbName = document.getElementById('sidebarUserName');
  if (sbName) sbName.textContent = u.name || 'User';
  const sbRole = document.getElementById('sidebarUserRole');
  if (sbRole) {
    sbRole.setAttribute('data-en', roleObj.en);
    sbRole.setAttribute('data-tl', roleObj.tl);
    const curLang = (document.getElementById('uiLanguage') || {}).value || 'english';
    sbRole.textContent = curLang === 'english' ? roleObj.en : roleObj.tl;
  }

  const farmerHomeBtn = document.getElementById('sidebarFarmerHomeBtn');
  const adminHomeBtn = document.getElementById('sidebarAdminHomeBtn');
  const staffHomeBtn = document.getElementById('sidebarStaffHomeBtn');

  if (u.role === 'agri_worker') {
    if (farmerHomeBtn) farmerHomeBtn.style.display = 'none';
    if (adminHomeBtn) adminHomeBtn.style.display = 'none';
    if (staffHomeBtn) staffHomeBtn.style.display = 'flex';
  } else if (u.role === 'admin') {
    if (farmerHomeBtn) farmerHomeBtn.style.display = 'none';
    if (adminHomeBtn) adminHomeBtn.style.display = 'flex';
    if (staffHomeBtn) staffHomeBtn.style.display = 'none';
  } else {
    if (farmerHomeBtn) farmerHomeBtn.style.display = 'flex';
    if (adminHomeBtn) adminHomeBtn.style.display = 'none';
    if (staffHomeBtn) staffHomeBtn.style.display = 'none';
  }

  const drawerFarmerHomeBtn = document.getElementById('drawerFarmerHomeBtn');
  const drawerAdminHomeBtn = document.getElementById('drawerAdminHomeBtn');
  const drawerStaffHomeBtn = document.getElementById('drawerStaffHomeBtn');

  if (u.role === 'agri_worker') {
    if (drawerFarmerHomeBtn) drawerFarmerHomeBtn.style.display = 'none';
    if (drawerAdminHomeBtn) drawerAdminHomeBtn.style.display = 'none';
    if (drawerStaffHomeBtn) drawerStaffHomeBtn.style.display = 'flex';
  } else if (u.role === 'admin') {
    if (drawerFarmerHomeBtn) drawerFarmerHomeBtn.style.display = 'none';
    if (drawerAdminHomeBtn) drawerAdminHomeBtn.style.display = 'flex';
    if (drawerStaffHomeBtn) drawerStaffHomeBtn.style.display = 'none';
  } else {
    if (drawerFarmerHomeBtn) drawerFarmerHomeBtn.style.display = 'flex';
    if (drawerAdminHomeBtn) drawerAdminHomeBtn.style.display = 'none';
    if (drawerStaffHomeBtn) drawerStaffHomeBtn.style.display = 'none';
  }

  const adminGroup = document.getElementById('sidebarAdminGroup');
  if (adminGroup) {
    adminGroup.style.display = (u.role === 'admin') ? 'block' : 'none';
  }

  const drawerAdminGroup = document.getElementById('drawerAdminGroup');
  if (drawerAdminGroup) {
    drawerAdminGroup.style.display = (u.role === 'admin') ? 'block' : 'none';
  }

  const secMenuItem = document.getElementById('profileSecurityMenuItem');
  if (secMenuItem) {
    secMenuItem.style.display = (u.role === 'admin') ? 'flex' : 'none';
  }

  // Mobile Bottom Nav Sync
  const mobHomeBtn = document.querySelector('.mobile-bottom-nav .mobile-nav-btn[data-screen="home"], .mobile-bottom-nav .mobile-nav-btn[data-screen="staff-dashboard"], .mobile-bottom-nav .mobile-nav-btn[data-screen="admin-dashboard"]');
  if (mobHomeBtn) {
    if (u.role === 'admin') {
      mobHomeBtn.setAttribute('data-screen', 'admin-dashboard');
      mobHomeBtn.setAttribute('onclick', "showScreen('admin-dashboard'); loadAdminDashboard();");
    } else if (u.role === 'agri_worker') {
      mobHomeBtn.setAttribute('data-screen', 'staff-dashboard');
      mobHomeBtn.setAttribute('onclick', "showScreen('staff-dashboard'); loadStaffDashboard();");
    } else {
      mobHomeBtn.setAttribute('data-screen', 'home');
      mobHomeBtn.setAttribute('onclick', "showScreen('home'); loadHomeRecentScans();");
    }
  }

  const adminWelcomeTitle = document.getElementById('adminWelcomeTitle');
  if (adminWelcomeTitle && u.name) {
    adminWelcomeTitle.textContent = u.name + ' · Executive Command Center';
  }
  const adminWelcomeSubtitle = document.getElementById('adminWelcomeSubtitle');
  if (adminWelcomeSubtitle && u.location) {
    adminWelcomeSubtitle.textContent = 'Provincial surveillance oversight, AI diagnostic performance, and field operations for ' + u.location;
  }

  const staffWelcomeTitle = document.getElementById('staffWelcomeTitle');
  if (staffWelcomeTitle && u.name) {
    staffWelcomeTitle.textContent = u.name + ' · Staff Dashboard';
  }
  const staffWelcomeSubtitle = document.getElementById('staffWelcomeSubtitle');
  if (staffWelcomeSubtitle && u.location) {
    staffWelcomeSubtitle.textContent = 'Surveillance, outbreak tracking, and farmer advisory management for ' + u.location;
  }
}

function isValidGmail(email) {
  if (!email) return false;
  return /^[a-zA-Z0-9._%+-]+@gmail\.com$/i.test(email.trim());
}

let currentGoogleAuthMode = 'login';

/* ═══════════ GOOGLE / GMAIL 1-TAP AUTHENTICATION ═══════════ */
function handleGoogleCredentialResponse(response) {
  if (response && response.credential) {
    executeGoogleLogin('', '', response.credential);
  }
}

function initGoogleIdentityServices() {
  const meta = document.querySelector('meta[name="google-signin-client_id"]');
  const clientId = meta ? meta.getAttribute('content') : '';
  if (window.google && window.google.accounts && window.google.accounts.id && clientId) {
    try {
      google.accounts.id.initialize({
        client_id: clientId,
        callback: handleGoogleCredentialResponse,
        auto_select: false,
      });

      const btnWrapper = document.getElementById('googleOfficialBtnWrapper');
      if (btnWrapper) {
        google.accounts.id.renderButton(btnWrapper, {
          theme: 'outline',
          size: 'large',
          type: 'standard',
          shape: 'rectangular',
          text: 'signin_with',
          logo_alignment: 'left',
          width: 320,
        });
      }
    } catch (e) {
      console.warn('Google Identity Services notice:', e);
    }
  }
}

async function openGoogleAuthModal(mode = 'login') {
  currentGoogleAuthMode = mode || 'login';
  const err = document.getElementById('googleAuthError');
  if (err) { err.classList.remove('show'); err.textContent = ''; }

  const meta = document.querySelector('meta[name="google-signin-client_id"]');
  const clientId = meta ? meta.getAttribute('content') : '';

  // If official Google Client ID is configured and Google SDK is loaded, render button and trigger native prompt
  if (window.google && window.google.accounts && window.google.accounts.id && clientId) {
    try {
      google.accounts.id.initialize({
        client_id: clientId,
        callback: handleGoogleCredentialResponse,
        auto_select: false,
      });

      const btnWrapper = document.getElementById('googleOfficialBtnWrapper');
      if (btnWrapper) {
        btnWrapper.innerHTML = '';
        google.accounts.id.renderButton(btnWrapper, {
          theme: 'outline',
          size: 'large',
          type: 'standard',
          shape: 'rectangular',
          text: currentGoogleAuthMode === 'register' ? 'signup_with' : 'signin_with',
          logo_alignment: 'left',
          width: 320,
        });
      }

      google.accounts.id.prompt();
    } catch (e) {}
  }

  openModal('modalGoogleAuth');
}

async function executeGoogleLogin(email, name, idToken) {
  const errBanner = document.getElementById('googleAuthError');
  if (errBanner) { errBanner.classList.remove('show'); errBanner.textContent = ''; }

  try {
    await ensureCsrfCookie();
    const res = await fetch(apiUrl('/auth/google-login'), {
      method: 'POST',
      credentials: 'include',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ email, name, id_token: idToken, mode: currentGoogleAuthMode }),
    });

    const data = await res.json();

    if (res.ok && data && data.success) {
      if (currentGoogleAuthMode === 'register' || data.requires_login) {
        closeModal('modalGoogleAuth');
        resetAuthForms();
        showScreen('login');
        showLoginSuccess(data.message || 'Account has been created successfully! Please sign in with Continue with Google.', 3000);
        return;
      }

      currentUser = data.user;
      if (data.token) setAuthToken(data.token);
      applyUserToUI();
      closeModal('modalGoogleAuth');
      resetAuthForms();
      navigateAfterAuth();
    } else {
      let msg = (data && data.message) ? data.message : 'Walang account na nakarehistro para sa Google account na ito. Mangyaring magrehistro muna.';
      if (errBanner) {
        errBanner.textContent = msg;
        errBanner.classList.add('show');
      }
    }
  } catch (err) {
    console.error('Google Sign-in error:', err);
    if (errBanner) {
      errBanner.textContent = 'Hindi makakonekta sa server. Pakisuri ang koneksyon.';
      errBanner.classList.add('show');
    }
  }
}

function handleCustomGoogleLogin(e) {
  if (e) e.preventDefault();
  const input = document.getElementById('googleCustomEmail');
  const email = (input ? input.value : '').trim().toLowerCase();
  const errBanner = document.getElementById('googleAuthError');

  if (!email || !isValidGmail(email)) {
    if (errBanner) {
      errBanner.textContent = 'Pakilagay ang inyong valid na Gmail address (@gmail.com).';
      errBanner.classList.add('show');
    }
    return;
  }

  executeGoogleLogin(email, '');
}

/* ═══════════ FORGOT PASSWORD & OTP HANDLERS ═══════════ */
async function handleSendOtp(e) {
  if (e) e.preventDefault();
  const emailInput = document.getElementById('forgotEmail');
  const email = (emailInput ? emailInput.value : '').trim().toLowerCase();
  const btn = document.getElementById('btnSendOtp');
  const errBanner = document.getElementById('forgotError');
  const okBanner = document.getElementById('forgotSuccess');

  if (errBanner) { errBanner.classList.remove('show'); errBanner.textContent = ''; }
  if (okBanner) { okBanner.classList.remove('show'); okBanner.textContent = ''; }

  if (!email || !isValidGmail(email)) {
    if (errBanner) {
      errBanner.textContent = 'Please enter a valid email address.';
      errBanner.classList.add('show');
    }
    return;
  }

  if (btn) { btn.disabled = true; btn.textContent = 'Sending Verification Code...'; }

  try {
    await ensureCsrfCookie();
    const res = await fetch(apiUrl('/auth/forgot-password'), {
      method: 'POST',
      credentials: 'include',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ email }),
    });

    const data = await res.json();

    if (res.ok && data && data.success) {
      const step1 = document.getElementById('forgotStep1');
      const step2 = document.getElementById('forgotStep2');
      const sentText = document.getElementById('forgotSentEmailText');
      if (step1) step1.style.display = 'none';
      if (step2) step2.style.display = 'block';
      if (sentText) sentText.textContent = email;

      const otpField = document.getElementById('forgotOtp');
      if (otpField) {
        otpField.value = '';
        otpField.focus();
      }

      if (okBanner) {
        okBanner.textContent = data.message || 'A 6-digit verification code has been sent to your email!';
        okBanner.classList.add('show');
      }
    } else {
      let msg = (data && data.message) ? data.message : 'Unable to process request. Please check your email address.';
      if (errBanner) {
        errBanner.textContent = msg;
        errBanner.classList.add('show');
      }
    }
  } catch (err) {
    console.error('Send OTP error:', err);
    if (errBanner) {
      errBanner.textContent = 'Cannot connect to the server. Please check your connection.';
      errBanner.classList.add('show');
    }
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Send Verification Code'; }
  }
}

async function handleResetPassword(e) {
  if (e) e.preventDefault();
  const emailInput = document.getElementById('forgotEmail');
  const email = (emailInput ? emailInput.value : '').trim().toLowerCase();
  const token = (document.getElementById('forgotOtp') || {}).value || '';
  const password = (document.getElementById('forgotNewPassword') || {}).value || '';
  const password_confirmation = (document.getElementById('forgotNewPasswordConfirm') || {}).value || '';

  const btn = document.getElementById('btnResetPassword');
  const errBanner = document.getElementById('forgotError');
  const okBanner = document.getElementById('forgotSuccess');

  if (errBanner) { errBanner.classList.remove('show'); errBanner.textContent = ''; }
  if (okBanner) { okBanner.classList.remove('show'); okBanner.textContent = ''; }

  if (!token || token.length !== 6) {
    if (errBanner) {
      errBanner.textContent = 'Please enter the 6-digit verification code from your email.';
      errBanner.classList.add('show');
    }
    return;
  }

  if (!password || password.length < 6) {
    if (errBanner) {
      errBanner.textContent = 'New password must be at least 6 characters.';
      errBanner.classList.add('show');
    }
    return;
  }

  if (password !== password_confirmation) {
    if (errBanner) {
      errBanner.textContent = 'New password and confirm password do not match.';
      errBanner.classList.add('show');
    }
    return;
  }

  if (btn) { btn.disabled = true; btn.textContent = 'Resetting Password...'; }

  try {
    await ensureCsrfCookie();
    const res = await fetch(apiUrl('/auth/reset-password'), {
      method: 'POST',
      credentials: 'include',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ email, token, password, password_confirmation }),
    });

    const data = await res.json();

    if (res.ok && data && data.success) {
      const resetEmail = email;
      resetAuthForms();
      showScreen('login');
      showLoginSuccess(data.message || 'Your password has been successfully reset! Please log in with your new password.', 3000);
      const loginEmailField = document.getElementById('loginEmail');
      if (loginEmailField && resetEmail) {
        loginEmailField.value = resetEmail;
      }
      const loginPassField = document.getElementById('loginPassword');
      if (loginPassField) {
        loginPassField.focus();
      }
    } else {
      let msg = (data && data.message) ? data.message : 'Invalid or expired verification code. Please try again.';
      if (errBanner) {
        errBanner.textContent = msg;
        errBanner.classList.add('show');
      }
    }
  } catch (err) {
    console.error('Reset password error:', err);
    if (errBanner) {
      errBanner.textContent = 'Cannot connect to the server. Please check your connection.';
      errBanner.classList.add('show');
    }
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Reset Password & Sign In'; }
  }
}

function handleResendOtp() {
  handleSendOtp();
}

function changeForgotEmail() {
  const step1 = document.getElementById('forgotStep1');
  const step2 = document.getElementById('forgotStep2');
  if (step1) step1.style.display = 'block';
  if (step2) step2.style.display = 'none';
  const errBanner = document.getElementById('forgotError');
  const okBanner = document.getElementById('forgotSuccess');
  if (errBanner) { errBanner.classList.remove('show'); errBanner.textContent = ''; }
  if (okBanner) { okBanner.classList.remove('show'); okBanner.textContent = ''; }
}

let loginLockoutInterval = null;
let currentSecurityConfig = { max_login_attempts: 3, lockout_duration_seconds: 30 };

async function fetchSecurityConfig() {
  try {
    const res = await fetch(apiUrl('/auth/security-config'), {
      headers: authHeaders(),
    });
    if (res.ok) {
      const data = await res.json();
      if (data && data.success) {
        currentSecurityConfig = {
          max_login_attempts: data.max_login_attempts || 3,
          lockout_duration_seconds: data.lockout_duration_seconds || 30,
        };
        if (data.is_locked && data.remaining_seconds > 0) {
          startLoginLockoutTimer(data.remaining_seconds, data.lockout_duration_seconds);
        }
      }
    }
  } catch (e) {
    console.warn('Could not fetch security config:', e);
  }
}

function checkLoginLockoutState() {
  const storedUntil = parseInt(localStorage.getItem('oryzatix_login_lockout_until') || '0', 10);
  const now = Date.now();
  if (storedUntil > now) {
    const remainingSeconds = Math.ceil((storedUntil - now) / 1000);
    const totalDuration = parseInt(localStorage.getItem('oryzatix_login_lockout_total') || '30', 10);
    startLoginLockoutTimer(remainingSeconds, totalDuration);
  } else {
    clearLoginLockoutUI();
  }
}

function startLoginLockoutTimer(remainingSeconds, totalDuration = 30, customMsg = '') {
  if (loginLockoutInterval) clearInterval(loginLockoutInterval);

  const lockoutCard = document.getElementById('loginLockoutCard');
  const timerDisplay = document.getElementById('loginLockoutTimer');
  const progressFill = document.getElementById('loginLockoutProgress');
  const lockoutMsg = document.getElementById('loginLockoutMsg');
  const attemptsBadge = document.getElementById('loginAttemptsBadge');
  const btn = document.getElementById('loginBtn');
  const passwordInput = document.getElementById('loginPassword');
  const emailInput = document.getElementById('loginEmail');
  const errBanner = document.getElementById('loginError');

  if (errBanner) {
    errBanner.classList.remove('show');
    errBanner.textContent = '';
  }
  if (attemptsBadge) attemptsBadge.style.display = 'none';

  if (lockoutCard) lockoutCard.style.display = 'block';
  if (lockoutMsg && customMsg) lockoutMsg.textContent = customMsg;

  // Persist lockout timestamp to localStorage
  const expireAt = Date.now() + (remainingSeconds * 1000);
  localStorage.setItem('oryzatix_login_lockout_until', expireAt.toString());
  localStorage.setItem('oryzatix_login_lockout_total', totalDuration.toString());

  let currentSec = remainingSeconds;

  const updateUI = () => {
    if (currentSec <= 0) {
      clearInterval(loginLockoutInterval);
      loginLockoutInterval = null;
      localStorage.removeItem('oryzatix_login_lockout_until');
      localStorage.removeItem('oryzatix_login_lockout_total');

      if (lockoutCard) lockoutCard.style.display = 'none';
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Sign In';
      }
      if (passwordInput) passwordInput.disabled = false;
      if (emailInput) emailInput.disabled = false;

      const errBanner = document.getElementById('loginError');
      if (errBanner) { errBanner.classList.remove('show'); errBanner.textContent = ''; }
      showLoginSuccess('Lockout period ended. You may now sign in again.', 3000);
      return;
    }

    const mins = Math.floor(currentSec / 60);
    const secs = currentSec % 60;
    const timeStr = (mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs;

    if (timerDisplay) timerDisplay.textContent = timeStr;
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Locked out (' + timeStr + ')...';
    }
    if (passwordInput) passwordInput.disabled = true;

    if (progressFill) {
      const pct = Math.max(0, Math.min(100, (currentSec / totalDuration) * 100));
      progressFill.style.width = pct + '%';
    }

    currentSec--;
  };

  updateUI();
  loginLockoutInterval = setInterval(updateUI, 1000);
}

function clearLoginLockoutUI() {
  if (loginLockoutInterval) {
    clearInterval(loginLockoutInterval);
    loginLockoutInterval = null;
  }
  localStorage.removeItem('oryzatix_login_lockout_until');
  localStorage.removeItem('oryzatix_login_lockout_total');

  const lockoutCard = document.getElementById('loginLockoutCard');
  const attemptsBadge = document.getElementById('loginAttemptsBadge');
  const btn = document.getElementById('loginBtn');
  const passwordInput = document.getElementById('loginPassword');
  const emailInput = document.getElementById('loginEmail');

  if (lockoutCard) lockoutCard.style.display = 'none';
  if (attemptsBadge) attemptsBadge.style.display = 'none';
  if (btn) {
    btn.disabled = false;
    btn.textContent = 'Sign In';
  }
  if (passwordInput) passwordInput.disabled = false;
  if (emailInput) emailInput.disabled = false;
}

let loginErrorTimeout = null;
let loginSuccessTimeout = null;

function showLoginError(msg, autoHideMs = 3000) {
  const errBanner = document.getElementById('loginError');
  const attemptsBadge = document.getElementById('loginAttemptsBadge');
  if (loginErrorTimeout) {
    clearTimeout(loginErrorTimeout);
    loginErrorTimeout = null;
  }
  if (errBanner) {
    errBanner.textContent = msg;
    errBanner.classList.add('show');
  }
  if (autoHideMs && autoHideMs > 0) {
    loginErrorTimeout = setTimeout(() => {
      if (errBanner) {
        errBanner.classList.remove('show');
        errBanner.textContent = '';
      }
      if (attemptsBadge && !loginLockoutInterval) {
        attemptsBadge.style.display = 'none';
      }
    }, autoHideMs);
  }
}

function showLoginSuccess(msg, autoHideMs = 3000) {
  const okBanner = document.getElementById('loginSuccess');
  if (loginSuccessTimeout) {
    clearTimeout(loginSuccessTimeout);
    loginSuccessTimeout = null;
  }
  if (okBanner) {
    okBanner.textContent = msg;
    okBanner.classList.add('show');
  }
  if (autoHideMs && autoHideMs > 0) {
    loginSuccessTimeout = setTimeout(() => {
      if (okBanner) {
        okBanner.classList.remove('show');
        okBanner.textContent = '';
      }
    }, autoHideMs);
  }
}

async function handleLogin(e) {
  if (e) {
    if (e.preventDefault) e.preventDefault();
    if (e.stopPropagation) e.stopPropagation();
  }

  const emailEl = document.getElementById('loginEmail');
  const passwordEl = document.getElementById('loginPassword');
  let email = (emailEl ? emailEl.value : '').trim();
  const password = passwordEl ? passwordEl.value : '';
  const btn = document.getElementById('loginBtn');
  const errBanner = document.getElementById('loginError');
  const okBanner = document.getElementById('loginSuccess');
  const attemptsBadge = document.getElementById('loginAttemptsBadge');
  const attemptsText = document.getElementById('loginAttemptsText');

  if (loginErrorTimeout) {
    clearTimeout(loginErrorTimeout);
    loginErrorTimeout = null;
  }
  if (errBanner) { errBanner.classList.remove('show'); errBanner.textContent = ''; }
  if (okBanner) { okBanner.classList.remove('show'); okBanner.textContent = ''; }
  if (attemptsBadge) { attemptsBadge.style.display = 'none'; }

  if (!email || !password) {
    showLoginError('Please enter your username or email address and password.', 3000);
    return;
  }

  if (btn) { btn.disabled = true; btn.textContent = 'Signing in...'; }

  try {
    await ensureCsrfCookie();
    const res = await fetch(apiUrl('/auth/login'), {
      method: 'POST',
      credentials: 'include',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ email, password }),
    });

    const data = await res.json();

    if (res.ok && data && data.success) {
      clearLoginLockoutUI();
      currentUser = data.user;
      if (data.token) setAuthToken(data.token);
      try {
        sessionStorage.setItem('oryzatix_is_logged_in', 'true');
      } catch (e) {}
      applyUserToUI();
      if (okBanner) {
        okBanner.textContent = data.message || 'Login successful!';
        okBanner.classList.add('show');
      }
      resetAuthForms();
      navigateAfterAuth();
    } else {
      // 1. Check if user is locked out (HTTP 429 or data.locked)
      if (res.status === 429 || (data && data.locked)) {
        const remainingSec = data.remaining_seconds || 30;
        const totalDuration = data.lockout_duration || remainingSec;
        const lockMsg = data.message_en || data.message || 'Too many failed login attempts. Login is temporarily locked out.';
        startLoginLockoutTimer(remainingSec, totalDuration, lockMsg);
        if (errBanner) {
          errBanner.classList.remove('show');
          errBanner.textContent = '';
        }
        return;
      }

      // 2. Check remaining attempts warning (HTTP 401 with remaining_attempts)
      if (data && typeof data.remaining_attempts !== 'undefined' && data.remaining_attempts > 0) {
        if (attemptsBadge && attemptsText) {
          attemptsBadge.style.display = 'flex';
          attemptsBadge.classList.toggle('severe', data.remaining_attempts <= 1);
          attemptsText.textContent = `Remaining login attempts before lockout: ${data.remaining_attempts} / ${data.max_attempts || 3}`;
        }
      }

      let msg = 'Invalid username/email or password. Please try again.';
      if (data && data.errors) {
        msg = Object.values(data.errors).flat().join(' ');
      } else if (data && (data.message_en || data.message)) {
        msg = data.message_en || data.message;
      }
      showLoginError(msg, 3000);
    }
  } catch (err) {
    console.error('Login error:', err);
    showLoginError('Could not connect to the server. Please check your network connection.', 3000);
  } finally {
    if (btn && !loginLockoutInterval) { btn.disabled = false; btn.textContent = 'Sign In'; }
  }
}

async function handleRegister(e) {
  if (e) {
    if (e.preventDefault) e.preventDefault();
    if (e.stopPropagation) e.stopPropagation();
  }

  const nameEl = document.getElementById('regName');
  const emailEl = document.getElementById('regEmail');
  const roleEl = document.getElementById('regRole');
  const locEl = document.getElementById('regLocation');
  const passEl = document.getElementById('regPassword');
  const passConfirmEl = document.getElementById('regPasswordConfirm');
  const btn = document.getElementById('regBtn');
  const errBanner = document.getElementById('registerError');
  const okBanner = document.getElementById('registerSuccess');

  if (errBanner) { errBanner.classList.remove('show'); errBanner.textContent = ''; }
  if (okBanner) { okBanner.classList.remove('show'); okBanner.textContent = ''; }

  const name = nameEl ? nameEl.value.trim() : '';
  const email = emailEl ? emailEl.value.trim().toLowerCase() : '';
  const role = roleEl ? roleEl.value : 'farmer';
  const location = locEl ? locEl.value.trim() : '';
  const password = passEl ? passEl.value : '';
  const password_confirmation = passConfirmEl ? passConfirmEl.value : '';

  if (!name || !email || !password || !password_confirmation) {
    if (errBanner) {
      errBanner.textContent = 'Pakipunan ang lahat ng kinakailangang impormasyon.';
      errBanner.classList.add('show');
    }
    return;
  }

  if (password.length < 6) {
    if (errBanner) {
      errBanner.textContent = 'Ang password ay dapat hindi bababa sa 6 na karakter.';
      errBanner.classList.add('show');
    }
    return;
  }

  if (password !== password_confirmation) {
    if (errBanner) {
      errBanner.textContent = 'Hindi nagtutugma ang Password at Confirm Password.';
      errBanner.classList.add('show');
    }
    return;
  }

  if (btn) { btn.disabled = true; btn.textContent = 'Creating Account...'; }

  try {
    await ensureCsrfCookie();
    const res = await fetch(apiUrl('/auth/register'), {
      method: 'POST',
      credentials: 'include',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ name, email, role, location, password, password_confirmation }),
    });

    const data = await res.json();

    if (res.ok && data && data.success) {
      const createdEmail = email;
      resetAuthForms();
      showScreen('login');
      showLoginSuccess(data.message || 'Account has been created successfully! Please sign in with your credentials.', 3000);
      const loginEmailField = document.getElementById('loginEmail');
      if (loginEmailField && createdEmail) {
        loginEmailField.value = createdEmail;
      }
      const loginPassField = document.getElementById('loginPassword');
      if (loginPassField) {
        loginPassField.focus();
      }
    } else {
      let msg = 'Hindi ma-proseso ang pagrehistro. Pakisuri ang impormasyon.';
      if (data && data.errors) {
        msg = Object.values(data.errors).flat().join(' ');
      } else if (data && data.message) {
        msg = data.message;
      }
      if (errBanner) {
        errBanner.textContent = msg;
        errBanner.classList.add('show');
      }
    }
  } catch (err) {
    console.error('Register error:', err);
    if (errBanner) {
      errBanner.textContent = 'Hindi makakonekta sa server. Pakisuri ang koneksyon.';
      errBanner.classList.add('show');
    }
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Create Account'; }
  }
}


async function handleUpdateProfile(e) {
  if (e) e.preventDefault();
  const name = document.getElementById('editName').value.trim();
  const location = document.getElementById('editLocation').value.trim();
  const password = document.getElementById('editPassword').value;
  const password_confirmation = document.getElementById('editPasswordConfirm').value;

  const errBanner = document.getElementById('editProfileError');
  const okBanner = document.getElementById('editProfileSuccess');
  const saveBtn = document.getElementById('saveProfileBtn');

  if (errBanner) errBanner.classList.remove('show');
  if (okBanner) okBanner.classList.remove('show');

  if (password && password !== password_confirmation) {
    if (errBanner) {
      errBanner.textContent = 'New passwords do not match. Please re-type.';
      errBanner.classList.add('show');
    }
    return;
  }

  const formData = new FormData();
  formData.append('name', name);
  formData.append('location', location);
  if (password) {
    formData.append('password', password);
    formData.append('password_confirmation', password_confirmation);
  }
  if (selectedProfilePhotoFile) {
    formData.append('avatar', selectedProfilePhotoFile);
  }
  if (removeProfilePhotoFlag) {
    formData.append('remove_avatar', '1');
  }

  if (saveBtn) {
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="loading-spinner-sm"></span> Saving Profile...';
  }

  await ensureCsrfCookie();
  fetch(apiUrl('/auth/profile'), {
    method: 'POST',
    credentials: 'include',
    headers: authHeaders(),
    body: formData,
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        currentUser = data.user;
        selectedProfilePhotoFile = null;
        removeProfilePhotoFlag = false;
        applyUserToUI();
        if (okBanner) {
          okBanner.textContent = data.message || 'Profile updated successfully!';
          okBanner.classList.add('show');
        }
        setTimeout(() => {
          closeModal('modalEditProfile');
          if (okBanner) okBanner.classList.remove('show');
        }, 900);
      } else {
        if (errBanner) {
          errBanner.textContent = data.message || 'Failed to update profile.';
          errBanner.classList.add('show');
        }
      }
    })
    .catch(() => {
      if (errBanner) {
        errBanner.textContent = 'Network error while updating profile. Please try again.';
        errBanner.classList.add('show');
      }
    })
    .finally(() => {
      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg><span>Save Profile</span>';
      }
    });
}

function resetAuthForms() {
  const loginForm = document.getElementById('loginForm');
  if (loginForm && typeof loginForm.reset === 'function') {
    loginForm.reset();
  }

  const registerForm = document.getElementById('registerForm');
  if (registerForm && typeof registerForm.reset === 'function') {
    registerForm.reset();
  }

  // Clear specific login inputs directly
  const loginEmail = document.getElementById('loginEmail');
  if (loginEmail) loginEmail.value = '';

  const loginPass = document.getElementById('loginPassword');
  if (loginPass) {
    loginPass.value = '';
    loginPass.type = 'password';
  }

  // Clear register inputs directly
  const regName = document.getElementById('regName');
  if (regName) regName.value = '';
  const regEmail = document.getElementById('regEmail');
  if (regEmail) regEmail.value = '';
  const regLoc = document.getElementById('regLocation');
  if (regLoc) regLoc.value = '';
  const regRole = document.getElementById('regRole');
  if (regRole) regRole.selectedIndex = 0;
  const regPass = document.getElementById('regPassword');
  if (regPass) {
    regPass.value = '';
    regPass.type = 'password';
  }
  const regPassConf = document.getElementById('regPasswordConfirm');
  if (regPassConf) {
    regPassConf.value = '';
    regPassConf.type = 'password';
  }

  // Clear error & success banners
  const loginErr = document.getElementById('loginError');
  if (loginErr) {
    loginErr.classList.remove('show');
    loginErr.textContent = '';
  }

  const loginOk = document.getElementById('loginSuccess');
  if (loginOk) {
    loginOk.classList.remove('show');
    loginOk.textContent = '';
  }

  const regErr = document.getElementById('registerError');
  if (regErr) {
    regErr.classList.remove('show');
    regErr.textContent = '';
  }

  const regOk = document.getElementById('registerSuccess');
  if (regOk) {
    regOk.classList.remove('show');
    regOk.textContent = '';
  }

  // Clear Forgot Password fields
  const forgotEmail = document.getElementById('forgotEmail');
  if (forgotEmail) forgotEmail.value = '';
  const forgotOtp = document.getElementById('forgotOtp');
  if (forgotOtp) forgotOtp.value = '';
  const forgotNewPass = document.getElementById('forgotNewPassword');
  if (forgotNewPass) forgotNewPass.value = '';
  const forgotNewPassConf = document.getElementById('forgotNewPasswordConfirm');
  if (forgotNewPassConf) forgotNewPassConf.value = '';

  const forgotStep1 = document.getElementById('forgotStep1');
  const forgotStep2 = document.getElementById('forgotStep2');
  if (forgotStep1) forgotStep1.style.display = 'block';
  if (forgotStep2) forgotStep2.style.display = 'none';

  const forgotErr = document.getElementById('forgotError');
  if (forgotErr) { forgotErr.classList.remove('show'); forgotErr.textContent = ''; }
  const forgotOk = document.getElementById('forgotSuccess');
  if (forgotOk) { forgotOk.classList.remove('show'); forgotOk.textContent = ''; }

  // Reset submit buttons
  const loginBtn = document.getElementById('loginBtn');
  if (loginBtn) {
    loginBtn.disabled = false;
    loginBtn.textContent = 'Sign In';
  }

  const regBtn = document.getElementById('regBtn');
  if (regBtn) {
    regBtn.disabled = false;
    regBtn.textContent = 'Create Account';
  }

  // Reset all password toggle buttons back to closed eye icon
  document.querySelectorAll('.toggle-password-btn').forEach(btn => {
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  });
}

function handleLogout() {
  openModal('modalLogoutConfirm');
}

async function confirmLogoutAction() {
  const btn = document.getElementById('confirmLogoutBtn');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="loading-spinner-sm"></span> Signing Out...';
  }

  await ensureCsrfCookie();
  fetch(apiUrl('/auth/logout'), {
    method: 'POST',
    credentials: 'include',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
  })
    .finally(() => {
      setAuthToken(null);
      currentUser = null;
      if (typeof sessionStorage !== 'undefined') {
        sessionStorage.removeItem('oryzatix_is_logged_in');
        sessionStorage.removeItem('oryzatix_active_screen');
      }
      resetAuthForms();
      const adminGroup = document.getElementById('sidebarAdminGroup');
      if (adminGroup) adminGroup.style.display = 'none';
      const adminHomeBtn = document.getElementById('sidebarAdminHomeBtn');
      if (adminHomeBtn) adminHomeBtn.style.display = 'none';
      const staffHomeBtn = document.getElementById('sidebarStaffHomeBtn');
      if (staffHomeBtn) staffHomeBtn.style.display = 'none';
      const farmerHomeBtn = document.getElementById('sidebarFarmerHomeBtn');
      if (farmerHomeBtn) farmerHomeBtn.style.display = 'flex';
      closeModal('modalLogoutConfirm');
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg><span>Yes, Sign Out</span>';
      }
      showScreen('login');
    });
}

function togglePassword(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;
  if (input.type === 'password') {
    input.type = 'text';
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
  } else {
    input.type = 'password';
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  }
}

/* ═══════════ CAMERA & SCANNING ═══════════ */
async function toggleLiveCamera() {
  const video = document.getElementById('cameraStream');
  const toggleBtn = document.getElementById('cameraToggleBtn');
  const overlay = document.getElementById('scanOverlay');

  if (cameraStream) {
    stopLiveCamera();
    if (toggleBtn) toggleBtn.classList.remove('active-flash');
    return;
  }

  if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
    try {
      cameraStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } },
      });
      if (video) {
        video.srcObject = cameraStream;
        video.classList.add('active');
      }
      if (overlay) overlay.style.display = 'none';
      if (toggleBtn) toggleBtn.classList.add('active-flash');
    } catch (err) {
      alert('Unable to access camera. Please check camera permissions or upload an image.');
    }
  } else {
    alert('Camera API is not supported on this browser.');
  }
}

function stopLiveCamera() {
  if (cameraStream) {
    cameraStream.getTracks().forEach(track => track.stop());
    cameraStream = null;
  }
  const video = document.getElementById('cameraStream');
  const overlay = document.getElementById('scanOverlay');
  const toggleBtn = document.getElementById('cameraToggleBtn');
  if (video) {
    video.srcObject = null;
    video.classList.remove('active');
  }
  if (overlay) overlay.style.display = '';
  if (toggleBtn) toggleBtn.classList.remove('active-flash');
}

function handleCaptureClick() {
  const video = document.getElementById('cameraStream');
  if (cameraStream && video && video.videoWidth) {
    // Capture snapshot from video
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    canvas.toBlob(blob => {
      if (blob) {
        const file = new File([blob], 'captured_leaf_' + Date.now() + '.jpg', { type: 'image/jpeg' });
        stopLiveCamera();
        uploadAndAnalyze(file);
      }
    }, 'image/jpeg', 0.9);
  } else {
    const fileInput = document.getElementById('fileInput');
    if (fileInput) fileInput.click();
  }
}

function testSampleLeaf(diseaseKey) {
  // Generate a test canvas representing the requested disease
  const canvas = document.createElement('canvas');
  canvas.width = 400;
  canvas.height = 400;
  const ctx = canvas.getContext('2d');

  // Green leaf background
  ctx.fillStyle = '#2d6a3e';
  ctx.fillRect(0, 0, 400, 400);

  if (diseaseKey === 'blast') {
    // Leaf blast spindle lesions
    ctx.fillStyle = '#8B4513';
    ctx.beginPath();
    ctx.ellipse(200, 200, 80, 25, Math.PI / 4, 0, 2 * Math.PI);
    ctx.fill();
    ctx.fillStyle = '#D3D3D3';
    ctx.beginPath();
    ctx.ellipse(200, 200, 50, 14, Math.PI / 4, 0, 2 * Math.PI);
    ctx.fill();
  } else if (diseaseKey === 'blb') {
    // Bacterial leaf blight wavy margins
    ctx.fillStyle = '#DAA520';
    ctx.fillRect(0, 0, 100, 400);
    ctx.fillStyle = '#F5DEB3';
    ctx.fillRect(0, 0, 40, 400);
  } else if (diseaseKey === 'brown_spot') {
    // Small brown spots
    ctx.fillStyle = '#5C2C16';
    for (let i = 0; i < 20; i++) {
      const x = 50 + (i * 35) % 300;
      const y = 60 + (i * 45) % 300;
      ctx.beginPath();
      ctx.arc(x, y, 8 + (i % 5), 0, 2 * Math.PI);
      ctx.fill();
    }
  } else if (diseaseKey === 'tungro') {
    // Yellow-orange tint
    ctx.fillStyle = '#FFA500';
    ctx.globalAlpha = 0.55;
    ctx.fillRect(0, 0, 400, 400);
    ctx.globalAlpha = 1.0;
  }

  canvas.toBlob(blob => {
    if (blob) {
      const filename = diseaseKey + '_sample.jpg';
      const file = new File([blob], filename, { type: 'image/jpeg' });
      uploadAndAnalyze(file);
    }
  }, 'image/jpeg', 0.92);
}

function handleFileUpload(event) {
  const file = event.target.files[0];
  if (!file) return;
  uploadAndAnalyze(file);
  event.target.value = '';
}

async function uploadAndAnalyze(file) {
  showScreen('loading');
  const localFileUrl = (typeof URL !== 'undefined' && URL.createObjectURL) ? URL.createObjectURL(file) : '';

  ['step1','step2','step3','step4'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('done');
    const sc = el.querySelector('.step-check');
    if (sc) sc.innerHTML = '';
  });

  // Progressive visual feedback for steps 1, 2, 3
  const stepDelays = [400, 950, 1500];
  stepDelays.forEach((delay, idx) => {
    setTimeout(() => {
      const stepEl = document.getElementById('step' + (idx + 1));
      if (stepEl) {
        stepEl.classList.add('done');
        const sc = stepEl.querySelector('.step-check');
        if (sc) sc.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
      }
    }, delay);
  });

  const formData = new FormData();
  formData.append('image', file);

  try {
    await ensureCsrfCookie();
    const res = await fetch(apiUrl('/rice-detector/upload'), {
      method: 'POST',
      credentials: 'include',
      headers: authHeaders(),
      body: formData,
    });

    const data = await res.json();

    // Mark step 4 as complete
    const step4 = document.getElementById('step4');
    if (step4) {
      step4.classList.add('done');
      const sc = step4.querySelector('.step-check');
      if (sc) sc.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    }

    setTimeout(() => {
      if (data && data.success && data.recognized !== false && data.scan && data.scan.disease) {
        if (!data.scan.image_url && localFileUrl) {
          data.scan.image_url = localFileUrl;
        }
        applyScanResult(data.scan);
      } else {
        showUnrecognizedScreen(data);
      }
    }, 450);
  } catch (err) {
    console.error('Upload and scan error:', err);
    showUnrecognizedScreen({ message: 'Network connection issue or analysis timed out. Please check your connection and retry.' });
  }
}

function showUnrecognizedScreen(data) {
  const msgEl = document.getElementById('unrecMessage');
  if (msgEl) {
    const curLang = (document.getElementById('uiLanguage') || {}).value || 'english';
    if (curLang === 'tagalog' && data && data.message_tl) {
      msgEl.textContent = data.message_tl;
    } else if (data && data.message_en) {
      msgEl.textContent = data.message_en;
    } else if (data && data.message) {
      msgEl.textContent = data.message;
    } else {
      msgEl.textContent = 'Cannot read or diagnose this image because it is not found in the system dataset or database.';
    }
  }
  showScreen('unrecognized-result');
}

function getDefaultTreatments(key, severity = 'moderate') {
  if (key === 'blast') {
    if (severity === 'mild') {
      return {
        chemical: [
          { name: 'Tricyclazole 75% WP (Beam / Blast-Off)', desc: 'Apply 0.6–1.0 g/L (300–400 g/ha) as early preventive foliar spray. DA-PhilRice standard systemic protective fungicide that inhibits fungal melanin biosynthesis.', tag: 'Fungicide', tag_class: 'fungicide' },
          { name: 'Kasugamycin 2% SL (Kasumin)', desc: 'Apply 1.5–2.0 ml/L. Systemic protective agricultural bio-fungicide with translaminar action that prevents fungal spore penetration and hyphal growth.', tag: 'Bio-Fungicide', tag_class: 'fungicide' },
        ],
        organic: [
          { name: 'Balanced Nitrogen (Follow DA Leaf Color Chart - LCC)', desc: 'Avoid excess urea application during vegetative stage. Split nitrogen fertilizer into 3-4 split applications based on LCC reading.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Maintain Continuous Shallow Water (3–5 cm)', desc: 'Do not allow the paddy field to dry out during tillering. Water-stressed/dry paddies significantly heighten blast vulnerability.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Carbonized Rice Hull (CRH) / Silica Application', desc: 'Apply 200–300 kg/ha CRH or calcium silicate to enrich soil silica and toughen leaf epidermal silica cells against fungal piercing.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Trichoderma harzianum (Bio-Control Agent)', desc: 'Spray 5–10 g/L Trichoderma suspension on leaf canopy in late afternoon to biologically compete with fungal spores.', tag: 'Biological', tag_class: 'biological' },
        ],
      };
    } else if (severity === 'severe') {
      return {
        chemical: [
          { name: 'Therapeutic Tricyclazole + Propiconazole / Mancozeb Tank Mix', desc: 'Emergency therapeutic tank spray (1.5–2.0 g/L) directed at upper leaves and panicle boot to save productive tillers and prevent catastrophic neck blast.', tag: 'Emergency Therapeutic', tag_class: 'fungicide' },
          { name: 'Carbendazim 50% WP + Epoxiconazole', desc: 'Apply 1.5–2.0 g/L for rapid curative eradication of active sporulating mycelial masses.', tag: 'Fungicide', tag_class: 'fungicide' },
        ],
        organic: [
          { name: 'Field Sanitation & Burning of Severely Stricken Residues', desc: 'Carefully collect and burn heavily blasted crop stubbles away from paddies to eradicate overwintering conidia/spore reserves.', tag: 'Sanitation', tag_class: 'cultural' },
          { name: 'Plant DA-PhilRice Recommended Blast-Resistant Varieties', desc: 'Shift strictly next cropping season to certified resistant varieties such as NSIC Rc222, NSIC Rc160, NSIC Rc402, PSB Rc18, or Tubigan series.', tag: 'Varietal Selection', tag_class: 'cultural' },
          { name: 'Certified Clean Seed Treatment', desc: 'Source only PhilRice certified seeds and treat seeds with warm water (52–54°C for 15 mins) or bio-fungicide prior to soaking and incubation.', tag: 'Cultural', tag_class: 'cultural' },
        ],
      };
    } else {
      // Moderate (26% - 60%)
      return {
        chemical: [
          { name: 'Isoprothiolane 40% EC (Fuji-One)', desc: 'Apply 1.5–2.0 ml/L (750–1000 ml/ha) foliar spray. Systemic fungicide with strong translaminar and acropetal translocation that arrests active lesion expansion.', tag: 'Fungicide', tag_class: 'fungicide' },
          { name: 'Azoxystrobin + Difenoconazole (Amistar Top 325 SC)', desc: 'Apply 1.0 ml/L spray. Dual systemic strobilurin + triazole active ingredients providing curative inhibition of mycelial growth and anti-sporulant action.', tag: 'Systemic Fungicide', tag_class: 'fungicide' },
          { name: 'Tebuconazole + Trifloxystrobin (Nativo 75 WG)', desc: 'Apply 0.5–0.75 g/L spray. Provides broad-spectrum curative and mesostemic protection across canopy leaves.', tag: 'Fungicide', tag_class: 'fungicide' },
        ],
        organic: [
          { name: 'Complete Nitrogen (Urea) Suspension', desc: 'Immediately halt all topdressing of nitrogenous fertilizers until blast spots completely dry up and active sporulation ceases.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Potassium Boost (Muriate of Potash - 0-0-60)', desc: 'Apply 30–40 kg/ha K₂O to strengthen cell walls and enhance plant physiological resistance against fungal enzymes.', tag: 'Nutritional', tag_class: 'cultural' },
          { name: 'Canopy Aeration & Water Flow Management', desc: 'Maintain proper spacing and avoid water stagnant overflow from infected field sections to uninfected plots.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Compost Tea & Seaweed Extract Foliar Spray', desc: 'Foliar application to stimulate Systemic Acquired Resistance (SAR) and boost plant vigor.', tag: 'Biological', tag_class: 'biological' },
        ],
      };
    }
  }
  if (key === 'blb') {
    if (severity === 'mild') {
      return {
        chemical: [
          { name: 'Copper Hydroxide 77% WP', desc: 'Apply 2.0 g/L of water as preventive contact foliar spray to sanitize leaf surfaces and inhibit bacterial entry through hydathodes.', tag: 'Bactericide', tag_class: 'bactericide' },
          { name: 'Copper Oxychloride 50% WP', desc: 'Use 2.5–3.0 g/L spray during early vegetative stage. Provides an active protective barrier against Xanthomonas multiplication.', tag: 'Bactericide', tag_class: 'bactericide' },
        ],
        organic: [
          { name: 'Field Drainage & Humidity Control', desc: 'Drain standing water from the paddy for 2–3 days to reduce canopy relative humidity and stop bacterial spread.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Potassium & Silica Fertilization', desc: 'Apply Muriate of Potash (30–40 kg K₂O/ha) and silica/rice hull ash to strengthen leaf epidermal cell walls.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Bacillus subtilis / Pseudomonas fluorescens', desc: 'Apply antagonistic bio-agent foliar spray at 5–10 g/L to naturally colonize leaf phyllosphere and suppress blight bacteria.', tag: 'Biological', tag_class: 'biological' },
          { name: 'Halt High Nitrogen (Urea)', desc: 'Temporarily suspend topdress urea to avoid excessive succulent leaf tissue vulnerable to bacterial invasion.', tag: 'Cultural', tag_class: 'cultural' },
        ],
      };
    } else if (severity === 'severe') {
      return {
        chemical: [
          { name: 'Therapeutic Streptomycin-Tetracycline (200 ppm)', desc: 'Emergency therapeutic application (2.0–2.5 g/L) directed at upper foliage and flag leaves to salvage productive tillers.', tag: 'Emergency Antibiotic', tag_class: 'bactericide' },
          { name: 'Zinc Thiazole 20% SC + Copper Hydroxide Tank Mix', desc: 'Dual-action systemic + contact application to rapidly arrest active bacterial streaming from cuticular cracks.', tag: 'Bactericide', tag_class: 'bactericide' },
        ],
        organic: [
          { name: 'Deep Field Aeration & Sun Drying', desc: 'Completely drain water from the field and allow the soil surface to crack and sun-dry to eradicate bacterial ooze.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Rogueing Severely Stricken Clumps', desc: 'Carefully pull out completely wilted/kresek tillers at field borders, place in bags, and destroy away from paddy.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Post-Harvest Sanitation & Deep Plowing', desc: 'Plow down and decompose all crop residues and stubbles immediately after harvest to destroy bacterial overwintering shelters.', tag: 'Sanitation', tag_class: 'cultural' },
          { name: 'Switch to Resistant Varieties Next Season', desc: 'In the next cropping cycle, plant certified rice varieties with proven multi-gene resistance (Xa4, Xa7, Xa21) such as NSIC Rc152, PSB Rc82, or IRBB varieties.', tag: 'Varietal Selection', tag_class: 'cultural' },
        ],
      };
    } else {
      // Moderate (26% - 60%)
      return {
        chemical: [
          { name: 'Streptomycin Sulfate + Oxytetracycline (Plantomycin / Agrimycin)', desc: 'Apply 150–200 ppm (1.5–2.0 g/L) foliar spray. Systemic agricultural antibiotic that penetrates vascular bundles to arrest bacterial replication. Repeat after 7–10 days.', tag: 'Antibiotic', tag_class: 'bactericide' },
          { name: 'Zinc Thiazole / Bismerthiazol 20% SC', desc: 'Apply 1.5–2.0 ml/L. Highly effective systemic bactericide specifically targeting Xanthomonas bacterial cells.', tag: 'Bactericide', tag_class: 'bactericide' },
          { name: 'Kasugamycin + Copper Oxychloride', desc: 'Apply 2.0 ml/L for combined protective and curative bactericidal action across the mid-canopy.', tag: 'Bactericide', tag_class: 'bactericide' },
        ],
        organic: [
          { name: 'Strict Nitrogen Suspension', desc: 'Strictly suspend all top-dress nitrogen applications until disease spread is completely halted.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Avoid Field Operations During Morning Dew', desc: 'Do not walk through, weed, or touch the crop while morning dew is on the leaves to prevent mechanical bacterial transmission.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Irrigation Water Isolation', desc: 'Ensure irrigation water does not flow from infected fields into healthy neighboring rice plots.', tag: 'Cultural', tag_class: 'cultural' },
        ],
      };
    }
  }
  if (key === 'brown_spot') {
    if (severity === 'mild') {
      return {
        chemical: [
          { name: 'Mancozeb 80% WP (Dithane M-45)', desc: 'Apply 2.0–2.5 g/L protective contact foliar spray at early tillering to halt spore germination and shield young rice leaves.', tag: 'Fungicide', tag_class: 'fungicide' },
          { name: 'Propiconazole 25% EC (Tilt)', desc: 'Apply 1.0 ml/L spray at first emergence of pinpoint brown specks. Provides translaminar protection against Bipolaris oryzae.', tag: 'Systemic Fungicide', tag_class: 'fungicide' },
        ],
        organic: [
          { name: 'Potassium & Zinc Soil Amendment', desc: 'Apply Muriate of Potash (0-0-60 @ 30–40 kg/ha) and Zinc Sulfate (25 kg/ha) to rectify nutrient deficiencies causing brown spot vulnerability.', tag: 'Nutritional', tag_class: 'cultural' },
          { name: 'Well-Decomposed Organic Compost', desc: 'Incorporate 2–3 tons/ha compost or decomposed rice straw to enrich soil biological activity and increase micronutrient availability.', tag: 'Soil Health', tag_class: 'cultural' },
          { name: 'Trichoderma / Bacillus subtilis Biopesticide', desc: 'Apply antagonistic bio-agent foliar spray at 5–10 g/L to naturally colonize leaf surfaces and suppress fungal sporulation.', tag: 'Biological', tag_class: 'biological' },
        ],
      };
    } else if (severity === 'severe') {
      return {
        chemical: [
          { name: 'Propiconazole 25% EC + Mancozeb 80% WP Tank Mix', desc: 'Therapeutic emergency spray (1.0 ml + 2.0 g/L). Combines systemic curative eradication with protective surface contact against aggressive blighting.', tag: 'Emergency Fungicide', tag_class: 'fungicide' },
          { name: 'Carbendazim 50% WP + Tebuconazole 250 EC', desc: 'Apply 1.0–1.5 g/L spray targeted at flag leaves and emerging panicles to prevent glume discoloration and pecky rice grain damage.', tag: 'Systemic Fungicide', tag_class: 'fungicide' },
          { name: 'Difenoconazole 250 EC (Score)', desc: 'Apply 1.0 ml/L to arrest extensive lesion coalescence and protect remaining functional leaf area.', tag: 'Curative Fungicide', tag_class: 'fungicide' },
        ],
        organic: [
          { name: 'Certified Resistant Seeds Next Cropping', desc: 'Source certified disease-free seeds with high field tolerance against brown spot (NSIC Rc216, NSIC Rc222, PSB Rc14).', tag: 'Varietal Selection', tag_class: 'cultural' },
          { name: 'Hot Water Seed Disinfection', desc: 'Soak seeds in 52–54°C warm water for 15 minutes before pre-germination to eliminate seed-borne fungal mycelia.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Deep Plowing & Crop Residue Incorporation', desc: 'Plow down and decompose all infected stubbles and straw immediately after harvest to destroy fungal overwintering inoculum.', tag: 'Sanitation', tag_class: 'cultural' },
          { name: 'Field Drainage & Soil Aeration', desc: 'Drain toxic stagnant field water and allow root aeration to eliminate root rot and iron/hydrogen sulfide toxicity.', tag: 'Cultural', tag_class: 'cultural' },
        ],
      };
    } else {
      // Moderate (26% - 60%)
      return {
        chemical: [
          { name: 'Tebuconazole 250 EC (Folicur)', desc: 'Apply 1.0 ml/L foliar spray. Strong systemic triazole fungicide that halts mycelial elongation and controls spreading brown circular lesions.', tag: 'Systemic Fungicide', tag_class: 'fungicide' },
          { name: 'Azoxystrobin + Difenoconazole (Amistar Top 325 SC)', desc: 'Apply 1.0 ml/L spray. Dual systemic strobilurin + triazole with powerful curative and anti-sporulant action.', tag: 'Fungicide', tag_class: 'fungicide' },
          { name: 'Hexaconazole 5% EC', desc: 'Apply 2.0 ml/L as canopy spray to protect middle and upper leaves from coalescing brown spot patches.', tag: 'Fungicide', tag_class: 'fungicide' },
        ],
        organic: [
          { name: 'Split Potassium Topdressing (MOP 0-0-60)', desc: 'Apply 50% potash at basal and 50% at panicle initiation stage (15–20 kg/ha K₂O) to strengthen leaf tissue against fungal penetration.', tag: 'Nutritional', tag_class: 'cultural' },
          { name: 'Alternate Wetting and Drying (AWD)', desc: 'Practice controlled AWD irrigation to improve soil aeration, enhance root vigor, and prevent nutrient lock-up.', tag: 'Water Management', tag_class: 'cultural' },
          { name: 'Foliar Micronutrient Spray (Silica + Boron + Zinc)', desc: 'Foliar spray with potassium silicate and zinc chelate to thicken epidermal silicon-cellulose layer on leaf blades.', tag: 'Biological', tag_class: 'biological' },
        ],
      };
    }
  }
  if (key === 'tungro') {
    if (severity === 'mild') {
      return {
        chemical: [
          { name: 'Imidacloprid 17.8% SL', desc: 'Apply 0.5–0.75 ml/L foliar spray. Rapid systemic knockdown of Green Leafhopper (GLH) vectors before viral inoculation.', tag: 'Insecticide', tag_class: 'bactericide' },
          { name: 'Thiamethoxam 25% WG', desc: 'Apply 0.2–0.3 g/L as systemic neonicotinoid protective vector barrier across field borders.', tag: 'Insecticide', tag_class: 'bactericide' },
        ],
        organic: [
          { name: 'Synchronous Community Planting', desc: 'Coordinate community planting within a 2-week window to break the continuous insect vector breeding cycle.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Yellow Sticky Vector Traps', desc: 'Install 20–25 yellow sticky insect traps per hectare to monitor and trap green leafhoppers.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Potassium & Zinc Nutrition', desc: 'Apply Muriate of Potash (30–40 kg K₂O/ha) and Zinc Sulfate (25 kg/ha) to fortify plant vascular health.', tag: 'Cultural', tag_class: 'cultural' },
        ],
      };
    } else if (severity === 'severe') {
      return {
        chemical: [
          { name: 'Etofenprox 10% EC', desc: 'Apply 1.5–2.0 ml/L pyrethroid ether for rapid emergency knockdown of high-density leafhopper populations.', tag: 'Insecticide', tag_class: 'bactericide' },
          { name: 'Fipronil 5% SC / Clothianidin 50% WDG', desc: 'Emergency vector eradication directed at the base and foliage of the crop.', tag: 'Insecticide', tag_class: 'bactericide' },
        ],
        organic: [
          { name: 'Systemic Rogueing & Field Sanitation', desc: 'Pull out completely stunted, unheading hills and burn away from the field.', tag: 'Sanitation', tag_class: 'cultural' },
          { name: 'Foliar Micronutrient & Amino Acid Boost', desc: 'Spray liquid potassium silicate + seaweed extract to salvage productive border tillers.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Switch to Resistant Varieties Next Season', desc: 'Plant certified Tungro-resistant rice varieties (NSIC Rc160, NSIC Rc120, PSB Rc10, IR64-Sub1, or Matatag lines) in the following cropping season.', tag: 'Varietal Selection', tag_class: 'cultural' },
          { name: 'Fallow Period & Deep Plowing', desc: 'Implement a strict 30-day crop-free fallow period after harvest and deep-plow all ratoon growths to eliminate overwintering RTBV/RTSV viral reservoirs.', tag: 'Cultural', tag_class: 'cultural' },
        ],
      };
    } else {
      // Moderate (26% - 60%)
      return {
        chemical: [
          { name: 'Dinotefuran 20% SG', desc: 'Apply 0.5–1.0 g/L. Fast-acting 3rd-generation neonicotinoid with systemic and translaminar activity against leafhopper nymphs and adults.', tag: 'Insecticide', tag_class: 'bactericide' },
          { name: 'Clothianidin + Pymetrozine', desc: 'Apply 1.0 g/L. Paralyzes insect feeding mouthparts and arrests vector transmission immediately.', tag: 'Insecticide', tag_class: 'bactericide' },
          { name: 'Buprofezin 25% SC', desc: 'Apply 1.5–2.0 ml/L. Insect growth regulator (IGR) that inhibits nymphal molting of leafhopper vectors.', tag: 'IGR', tag_class: 'bactericide' },
        ],
        organic: [
          { name: 'Selective Rogueing', desc: 'Uproot and bury individual severely yellowed hills displaying distinct stunting to reduce field viral inoculum sources.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Neem Seed Kernel Extract (NSKE 5%)', desc: 'Spray 5% neem extract to act as an antifeedant and oviposition deterrent against vectors.', tag: 'Biological', tag_class: 'biological' },
          { name: 'Water Management', desc: 'Maintain shallow water depth (2–3 cm) to hinder leafhopper nymph movement between tillers.', tag: 'Cultural', tag_class: 'cultural' },
        ],
      };
    }
  }
  if (key === 'sheath_blight') {
    if (severity === 'mild') {
      return {
        chemical: [
          { name: 'Validamycin 3% L (Sheathmar / Validacin)', desc: 'Apply 2.0–2.5 ml/L foliar spray targeted at the lower canopy/culm base. Highly effective antibiotic fungicide that halts Rhizoctonia hyphal elongation.', tag: 'Bio-Fungicide', tag_class: 'fungicide' },
          { name: 'Hexaconazole 5% SC (Contaf)', desc: 'Apply 1.5–2.0 ml/L spray to arrest mycelial growth on lower leaf sheaths.', tag: 'Fungicide', tag_class: 'fungicide' },
        ],
        organic: [
          { name: 'Canopy Aeration & Proper Spacing', desc: 'Maintain 20x20 cm plant spacing to improve sunlight penetration and air circulation across the lower culm.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Trichoderma viride / harzianum', desc: 'Apply antagonistic bio-agent foliar/soil drench at 5–10 g/L to biologically suppress Rhizoctonia sclerotia.', tag: 'Biological', tag_class: 'biological' },
          { name: 'Potassium & Silicon Amendment', desc: 'Apply Muriate of Potash (30–40 kg K₂O/ha) and Carbonized Rice Hull (CRH) to toughen sheath epidermal cell walls.', tag: 'Nutritional', tag_class: 'cultural' },
        ],
      };
    } else if (severity === 'severe') {
      return {
        chemical: [
          { name: 'Therapeutic Thifluzamide + Tebuconazole Tank Mix', desc: 'Emergency therapeutic spray (1.5–2.0 g/L) to salvage flag leaves and booting panicles from catastrophic lodging and blighting.', tag: 'Emergency Therapeutic', tag_class: 'fungicide' },
          { name: 'Carbendazim 50% WP + Difenoconazole', desc: 'Apply 1.5–2.0 g/L for rapid curative eradication of active ascending fungal colonies.', tag: 'Fungicide', tag_class: 'fungicide' },
        ],
        organic: [
          { name: 'Destroy Stricken Stubbles & Floating Sclerotia', desc: 'Skim floating sclerotia during final land leveling and burn severely infected crop residues after harvest.', tag: 'Sanitation', tag_class: 'cultural' },
          { name: 'Strict 30-Day Fallow Period & Deep Plowing', desc: 'Deep-plow stubble to bury sclerotia at least 15 cm deep where they lose viability.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Plant Tolerant Varieties Next Cropping', desc: 'Shift to certified erect-leaf, moderate-tillering tolerant varieties (NSIC Rc222, NSIC Rc216, PSB Rc14).', tag: 'Varietal Selection', tag_class: 'cultural' },
        ],
      };
    } else {
      // Moderate (26% - 60%)
      return {
        chemical: [
          { name: 'Azoxystrobin + Difenoconazole (Amistar Top 325 SC)', desc: 'Apply 1.0 ml/L spray directed at mid-canopy. Dual systemic strobilurin + triazole with powerful translaminar curative action.', tag: 'Systemic Fungicide', tag_class: 'fungicide' },
          { name: 'Thifluzamide 24% SC (Pulsor)', desc: 'Apply 0.75–1.0 ml/L. Highly potent succinate dehydrogenase inhibitor (SDHI) fungicide specifically active against Rhizoctonia sheath blight.', tag: 'Fungicide', tag_class: 'fungicide' },
          { name: 'Propiconazole 25% EC (Tilt)', desc: 'Apply 1.0 ml/L foliar spray to halt lesion ascension up the rice tiller.', tag: 'Fungicide', tag_class: 'fungicide' },
        ],
        organic: [
          { name: 'Alternate Wetting and Drying (AWD)', desc: 'Drain field water intermittently for 2–3 days to reduce relative humidity inside the crop canopy.', tag: 'Water Management', tag_class: 'cultural' },
          { name: 'Complete Urea Suspension', desc: 'Halt all topdress nitrogen applications immediately to prevent succulent tissue expansion.', tag: 'Cultural', tag_class: 'cultural' },
          { name: 'Neem Seed Kernel Extract (NSKE 5%)', desc: 'Spray 5% neem extract to act as natural anti-fungal repellent and plant tonic.', tag: 'Biological', tag_class: 'biological' },
        ],
      };
    }
  }
  return {
    chemical: [
      { name: 'Preventive Mild Fungicide', desc: 'Apply mild protective spray only during prolonged wet periods.', tag: 'Fungicide', tag_class: 'fungicide' },
    ],
    organic: [
      { name: 'Good Agricultural Practices', desc: 'Maintain 20x20 cm plant spacing, balanced NPK fertilization, and clean irrigation.', tag: 'Cultural', tag_class: 'cultural' },
      { name: 'Compost & Organic Matter', desc: 'Incorporate 2-3 tons/ha well-decomposed compost into field soil.', tag: 'Cultural', tag_class: 'cultural' },
    ],
  };
}

function applyScanResult(scan) {
  currentScanResult = scan;
  try {
    if (typeof sessionStorage !== 'undefined' && scan) {
      sessionStorage.setItem('oryzatix_last_scan_result', JSON.stringify(scan));
    }
  } catch (e) {}

  const imgCard = document.getElementById('resultImageCard');
  if (imgCard) {
    imgCard.classList.remove('disease-blast', 'disease-blb', 'disease-healthy');
    const lower = (scan.disease || '').toLowerCase();
    let cls = 'disease-blast';
    if (lower.includes('blight') || lower.includes('blb') || lower.includes('brown')) cls = 'disease-blb';
    else if (lower.includes('healthy')) cls = 'disease-healthy';
    imgCard.classList.add(cls);
  }

  const icon = document.getElementById('resultImageCardIcon');
  if (icon && imgCard) {
    if (scan.image_url) {
      icon.style.display = 'none';
      let img = imgCard.querySelector('img.result-uploaded-img');
      if (!img) {
        img = document.createElement('img');
        img.className = 'result-uploaded-img';
        img.style.position = 'absolute';
        img.style.inset = '0';
        img.style.width = '100%';
        img.style.height = '100%';
        img.style.objectFit = 'cover';
        imgCard.insertBefore(img, imgCard.firstChild);
      }
      img.src = scan.image_url;
    } else {
      icon.style.display = '';
      const existing = imgCard.querySelector('img.result-uploaded-img');
      if (existing) existing.remove();
    }
  }

  const isHealthy = (scan.severity === 'healthy' || scan.disease_key === 'healthy' || (scan.disease || '').toLowerCase().includes('healthy'));

  const conf = document.getElementById('resultConfidence');
  if (conf) {
    conf.style.display = 'none';
  }
  const date = document.getElementById('resultDateTag');
  if (date) date.textContent = scan.date || 'Today';

  const diag = document.getElementById('resultDiagnosis');
  const diagIcon = document.getElementById('resultDiagIcon');
  if (diag && diagIcon) {
    diag.classList.remove('warning', 'safe');
    diagIcon.classList.remove('warn', 'ok');
    if (isHealthy) {
      diag.classList.add('safe');
      diagIcon.classList.add('ok');
    } else {
      diag.classList.add('warning');
      diagIcon.classList.add('warn');
    }
  }

  // Hide severity meter completely for Healthy Rice Leaf
  const sevMeter = document.querySelector('.severity-meter');
  if (sevMeter) {
    sevMeter.style.display = isHealthy ? 'none' : 'block';
  }

  if (!isHealthy) {
    const fill = document.getElementById('resultSeverityFill');
    if (fill) {
      fill.classList.remove('severe', 'moderate', 'mild', 'healthy');
      const pct = parseFloat(scan.affected_percentage) || (scan.severity === 'severe' ? 80 : (scan.severity === 'moderate' ? 45 : 15));
      fill.style.width = Math.min(100, Math.max(10, pct)) + '%';

      if (scan.severity === 'severe') fill.classList.add('severe');
      else if (scan.severity === 'moderate') fill.classList.add('moderate');
      else fill.classList.add('healthy');
    }

    const labels = document.getElementById('resultSeverityLabels');
    if (labels) {
      const lMild = document.getElementById('sevLabelMild') || labels.children[0];
      const lMod = document.getElementById('sevLabelMod') || labels.children[1];
      const lSev = document.getElementById('sevLabelSev') || labels.children[2];

      if (lMild) { lMild.className = 'sev-label inactive'; }
      if (lMod) { lMod.className = 'sev-label inactive'; }
      if (lSev) { lSev.className = 'sev-label inactive'; }

      if (scan.severity === 'severe' && lSev) {
        lSev.className = 'sev-label active-sev';
      } else if (scan.severity === 'moderate' && lMod) {
        lMod.className = 'sev-label active-sev amber';
      } else if (lMild) {
        lMild.className = 'sev-label active-sev green';
      }
    }
  }

  const dname = document.getElementById('resultDiseaseName');
  if (dname) dname.textContent = isHealthy ? 'Healthy Rice Leaf' : (scan.disease || 'Leaf Blast');

  const sciname = document.getElementById('resultScientificName');
  if (sciname) {
    sciname.innerHTML = isHealthy ? 'No pathogens detected · Optimal Vegetative Crop Health' : (scan.scientific ? 'Caused by <em>' + scan.scientific + '</em>' : 'Symptoms evaluated by MobileNet convolutional neural network.');
  }

  // Set initial symptoms language to English
  currentResultLang = 'english';

  renderResultLanguage();

  showScreen('results');
  loadHomeRecentScans();
  loadHistory();
}

let currentResultLang = 'english';

const RESULT_TRANSLATIONS = {
  diseases: {
    blast: {
      tagalog: {
        symptoms: {
          mild: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Leaf Blast. Maagang yugto (≤25%): may maliliit na kayumangging tuldok at hugis-diamanteng sugat sa dahon. Malusog at buo pa ang karamihan ng tanim. Mag-spray agad ng Tricyclazole o Isoprothiolane bilang proteksyon.',
          moderate: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Leaf Blast. Katamtamang yugto (26%-60%): aktibong hugis-bangkang sugat (spindle-shaped lesions) na may abong gitna at mapulang gilid na nagdurugtong. Mag-spray ng Azoxystrobin + Difenoconazole at itigil ang sobrang Urea.',
          severe: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Leaf Blast. Malalang yugto (>60%): malawak na pagkasunog ng mga dahon (burnt appearance), collar rot, at matinding panganib ng neck blast sa uhay. Mag-spray agad ng therapeutic systemic fungicide.'
        }
      },
      english: {
        symptoms: {
          mild: 'Rice Leaf Blast symptoms affect approximately {pct}% of the leaf area. Early blast stage (≤25%): small initial brown specks and pinhead-sized diamond spots on leaf blades with mostly green intact canopy. Apply preventive Tricyclazole or Isoprothiolane.',
          moderate: 'Rice Leaf Blast symptoms affect approximately {pct}% of the leaf area. Moderate blast stage (26%-60%): active spindle-shaped lesions with necrotic gray centers and reddish-brown margins coalescing across leaves. Spray Azoxystrobin + Difenoconazole and suspend nitrogen topdressing.',
          severe: 'Rice Leaf Blast symptoms affect approximately {pct}% of the leaf area. Severe blast stage (>60%): extensive coalesced lesions, scorched/burnt foliage appearance, collar rot, and acute danger of neck and panicle blast failure. Apply therapeutic systemic fungicide immediately.'
        }
      }
    },
    blb: {
      tagalog: {
        symptoms: {
          mild: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Bacterial Leaf Blight. Maagang yugto ng impeksyon: may bahagyang paninilaw at panunuyo sa dulo at gilid ng dahon. Agarang kontrolin gamit ang Copper Hydroxide bago kumalat sa buong taniman.',
          moderate: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Bacterial Leaf Blight. Katamtamang yugto: may kapansin-pansing kulot at mala-alon na paninilaw na bumababa sa ugat ng dahon. Mag-spray ng Streptomycin Sulfate / Zinc Thiazole at patuyuin ang bukid nang 2-3 araw.',
          severe: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Bacterial Leaf Blight. Malalang yugto: malawak na panunuyo, abo/puting patay na tisyu ng dahon (kresek stage) na humahadlang sa photosynthesis. Mag-apply ng emergency therapeutic bactericide at itigil ang abono ng Urea.'
        }
      },
      english: {
        symptoms: {
          mild: 'Bacterial blight lesions affect approximately {pct}% of the leaf blade. Early infection stage: minor yellowing and water-soaked margins at leaf tips. Apply preventive Copper Hydroxide immediately to halt further spread.',
          moderate: 'Bacterial blight lesions affect approximately {pct}% of the leaf blade. Intermediate stage: prominent wavy water-soaked margins extending down leaf veins. Spray Streptomycin Sulfate / Zinc Thiazole and drain paddy water for 2–3 days.',
          severe: 'Bacterial blight lesions affect approximately {pct}% of the leaf blade. Advanced severe stage: extensive blighted, grayish-white necrotic tissue with severe photosynthetic disruption. Apply therapeutic bactericide and immediately suspend nitrogen fertilization.'
        }
      }
    },
    brown_spot: {
      tagalog: {
        symptoms: {
          mild: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Brown Spot. Maagang yugto (≤25%): maliliit na bilog na kayumangging batik sa dahon dahil sa kakulangan sa sustansya (Potassium/Zinc). Mag-abono ng Muriate of Potash (0-0-60) at mag-spray ng Mancozeb.',
          moderate: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Brown Spot. Katamtamang yugto (26%-60%): malalaking bilog o hugis-itlog na mantsang kayumanggi na may manilaw-nilaw na paligid (chlorotic halo). Mag-spray ng Tebuconazole o Propiconazole at isagawa ang AWD patubig.',
          severe: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Brown Spot. Malalang yugto (>60%): malalaking patay na tisyu sa dahon at panunuyo ng uhay (pecky rice grain rot). Mag-spray ng Propiconazole + Difenoconazole tank mix at maglagay ng organikong pataba sa susunod na cropping.'
        }
      },
      english: {
        symptoms: {
          mild: 'Rice Brown Spot symptoms affect approximately {pct}% of the leaf area. Early brown spot stage (≤25%): scattered small circular brown specks indicating soil nutrient stress. Apply Muriate of Potash (0-0-60) and spray Mancozeb.',
          moderate: 'Rice Brown Spot symptoms affect approximately {pct}% of the leaf area. Moderate brown spot stage (26%-60%): prominent oval to circular dark brown lesions with yellow chlorotic halos. Spray Tebuconazole or Propiconazole and practice AWD water management.',
          severe: 'Rice Brown Spot symptoms affect approximately {pct}% of the leaf area. Severe brown spot stage (>60%): extensive dark brown necrotic patches, leaf withering, and grain infection leading to pecky rice. Spray Propiconazole + Difenoconazole tank mix and incorporate organic compost.'
        }
      }
    },
    tungro: {
      tagalog: {
        symptoms: {
          mild: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Tungro. Maagang yugto ng impeksyon: bahagyang paninilaw sa dulo ng itaas na dahon. Kontrolin agad ang berdeng ngusong damo (Green Leafhopper) gamit ang Imidacloprid o Thiamethoxam.',
          moderate: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Tungro. Katamtamang yugto: kapansin-pansing dilaw-kahel (yellow-orange) na kulay sa mga dahon at may senyales ng pagkabansot ng suwi. Mag-spray ng Dinotefuran o Clothianidin at magkabit ng yellow sticky traps.',
          severe: 'Umaabot sa humigit-kumulang {pct}% ng dahon ang apektado ng Tungro. Malalang yugto: matingkad na kahel na paninilaw, matinding pagkabansot, kakaunting suwi, at hindi paglabas ng uhay. Bunutin at sunugin ang mga grabeng apektadong tumpok (rogueing) upang hindi mahawa ang buong bukid.'
        }
      },
      english: {
        symptoms: {
          mild: 'Rice Tungro viral symptoms affect approximately {pct}% of the leaf blade. Early viral infection stage: light yellowing restricted to leaf tips. Control green leafhopper vectors immediately with Imidacloprid or Thiamethoxam.',
          moderate: 'Rice Tungro viral symptoms affect approximately {pct}% of the leaf blade. Moderate stage: pronounced yellow-orange discoloration extending across leaf blade with visible plant stunting. Spray Dinotefuran or Clothianidin and deploy yellow sticky traps.',
          severe: 'Rice Tungro viral symptoms affect approximately {pct}% of the leaf blade. Severe viral stage: intense orange-yellow discoloration, severe stunting, compact tillers, and failure of panicle emergence. Pull out and destroy severely infected hills (rogueing) to protect the crop.'
        }
      }
    },
    sheath_blight: {
      tagalog: {
        symptoms: {
          mild: 'Umaabot sa humigit-kumulang {pct}% ng saha at dahon ang apektado ng Sheath Blight. Maagang yugto (≤25%): may hugis-itlog na kulay berdeng-abo na basa-basang sugat (water-soaked lesions) sa ibaba ng puno malapit sa tubig. Mag-spray agad ng Validamycin 3% L o Hexaconazole bilang proteksyon.',
          moderate: 'Umaabot sa humigit-kumulang {pct}% ng saha at dahon ang apektado ng Sheath Blight. Katamtamang yugto (26%-60%): may mala-balat ng ahas (snake-skin pattern) na mga sugat na may maputing gitna at mamulang kayumangging gilid na umaakyat sa itaas na dahon. Mag-spray ng Azoxystrobin + Difenoconazole o Thifluzamide at isagawa ang AWD patubig.',
          severe: 'Umaabot sa humigit-kumulang {pct}% ng saha at dahon ang apektado ng Sheath Blight. Malalang yugto (>60%): umabot na ang mga sugat sa flag leaf sheath, nagdudulot ng paghiga ng palay (lodging), pagkabulok ng puno, at pagkasira ng uhay. Mag-spray agad ng therapeutic systemic fungicide at linisin ang bukid.'
        }
      },
      english: {
        symptoms: {
          mild: 'Rice Sheath Blight symptoms affect approximately {pct}% of the culm and sheath area. Early stage (≤25%): initial oval greenish-gray water-soaked spots on leaf sheaths near the water level. Apply preventive Validamycin or Hexaconazole.',
          moderate: 'Rice Sheath Blight symptoms affect approximately {pct}% of the culm and sheath area. Moderate stage (26%-60%): characteristic irregular ellipsoid snake-skin lesions ascending to upper sheaths and leaves. Spray Azoxystrobin + Difenoconazole or Thifluzamide and practice AWD irrigation.',
          severe: 'Rice Sheath Blight symptoms affect approximately {pct}% of the culm and sheath area. Severe stage (>60%): extensive blighting reaching flag leaf sheaths, causing widespread lodging, tiller death, and sclerotial formation. Apply emergency therapeutic systemic fungicide.'
        }
      }
    },
    healthy: {
      tagalog: {
        symptoms: 'Malusog at berde ang dahon ng palay. Walang anumang fungal lesions, bacterial streaks, o viral discoloration na natukoy. Ipagpatuloy ang Good Agricultural Practices (GAP) tulad ng balanseng pataba (NPK), tamang patubig (AWD), at regular na pagmamasid sa bukid.'
      },
      english: {
        symptoms: 'The rice leaf is healthy and vibrant green. No fungal lesions, bacterial streaks, or viral discoloration detected. Continue Good Agricultural Practices (GAP) such as balanced NPK fertilization, AWD irrigation, and regular field monitoring.'
      }
    }
  }
};

function toggleResultSymptomsTranslation() {
  currentResultLang = currentResultLang === 'english' ? 'tagalog' : 'english';
  if (typeof stopAiSpeech === 'function') {
    stopAiSpeech();
  }
  renderResultLanguage();
}

function setResultLanguage(lang) {
  currentResultLang = lang;
  if (typeof stopAiSpeech === 'function') {
    stopAiSpeech();
  }
  renderResultLanguage();
}

function renderResultLanguage() {
  if (!currentScanResult) return;
  const scan = currentScanResult;
  const lang = currentResultLang || 'english';
  const isHealthy = (scan.severity === 'healthy' || scan.disease_key === 'healthy' || (scan.disease || '').toLowerCase().includes('healthy'));
  const key = scan.disease_key || (isHealthy ? 'healthy' : 'blast');
  const diseaseData = RESULT_TRANSLATIONS.diseases[key] || (isHealthy ? RESULT_TRANSLATIONS.diseases.healthy : RESULT_TRANSLATIONS.diseases.blast);
  const localized = diseaseData[lang] || diseaseData.english;

  // Disease Name & Scientific Subtitle stay constant
  const dname = document.getElementById('resultDiseaseName');
  if (dname) dname.textContent = isHealthy ? 'Healthy Rice Leaf' : (scan.disease || 'Leaf Blast');

  const sciname = document.getElementById('resultScientificName');
  if (sciname) {
    sciname.innerHTML = isHealthy ? 'No pathogens detected · Optimal Vegetative Crop Health' : (scan.scientific ? 'Caused by <em>' + scan.scientific + '</em>' : 'Symptoms evaluated by MobileNet convolutional neural network.');
  }

  // Symptoms text (the only text that translates)
  const symptomsText = document.getElementById('resultSymptomsText');
  if (symptomsText) {
    let symp = '';
    const pct = scan.affected_percentage || (scan.severity === 'severe' ? '80' : (scan.severity === 'moderate' ? '45' : '15'));
    if (isHealthy) {
      symp = localized.symptoms;
    } else if (localized.symptoms) {
      const sevKey = scan.severity || 'moderate';
      symp = (localized.symptoms[sevKey] || localized.symptoms.moderate || '').replace('{pct}', pct);
    }
    symptomsText.textContent = symp || scan.symptoms || '';
  }

  // Translation button label in Symptoms card footer
  const transBtnText = document.getElementById('resultTranslateBtnText');
  if (transBtnText) {
    transBtnText.textContent = lang === 'english' ? 'Translate to Tagalog' : 'Translate to English';
  }

  // Read button label remains permanently 'Read' (or 'Stop' when speaking)
  const speakBtnText = document.getElementById('resultSpeakBtnText');
  if (speakBtnText) {
    const isSpeaking = window.speechSynthesis && window.speechSynthesis.speaking;
    speakBtnText.textContent = isSpeaking ? 'Stop' : 'Read';
  }

  // Render prominent treatment suggestions & staff advisory on the result card
  renderResultTreatments(scan, lang);
}

function renderResultTreatments(scan, lang) {
  const container = document.getElementById('resultTreatmentItemsList');
  const cardHeading = document.getElementById('resultTreatmentCardHeading');
  const guideLink = document.getElementById('resultTreatmentGuideLinkText');
  const advisoryBox = document.getElementById('resultStaffAdvisoryBox');
  const advisoryText = document.getElementById('resultStaffAdvisoryText');
  const advisoryHeader = document.getElementById('resultStaffAdvisoryHeader');

  if (cardHeading) {
    cardHeading.textContent = lang === 'tagalog' ? 'Mga Mungkahing Gamot at Hakbang na Ilalagay' : 'Recommended Treatments & What to Apply';
  }
  if (guideLink) {
    guideLink.textContent = lang === 'tagalog' ? 'Buong Gabay at Dosis' : 'Full Guide & Dosage';
  }
  if (advisoryHeader) {
    advisoryHeader.textContent = lang === 'tagalog' ? 'Opisyal na Payo mula sa Agriculturist / Staff' : 'Official Agronomist Advisory';
  }

  // Handle Staff Advisory
  const staffNotes = scan ? (scan.notes || scan.advisory || scan.staff_notes || '') : '';
  if (advisoryBox && advisoryText) {
    if (staffNotes && staffNotes.trim()) {
      advisoryBox.style.display = 'block';
      advisoryText.textContent = staffNotes.trim();
    } else {
      advisoryBox.style.display = 'none';
      advisoryText.textContent = '';
    }
  }

  if (!container) return;

  const isHealthy = (scan.severity === 'healthy' || scan.disease_key === 'healthy' || (scan.disease || '').toLowerCase().includes('healthy'));
  if (isHealthy) {
    container.innerHTML = `
      <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px; display:flex; align-items:flex-start; gap:12px;">
        <span style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:50%; background:#22c55e; color:#fff; flex-shrink:0;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        </span>
        <div>
          <h5 style="margin:0 0 4px; font-size:14px; font-weight:700; color:#15803d;">
            ${lang === 'tagalog' ? 'Malusog ang Tanim at Walang Sakit' : 'Optimal Vegetative Crop Health'}
          </h5>
          <p style="margin:0; font-size:13px; color:#166534; line-height:1.5;">
            ${lang === 'tagalog' ? 'Ipagpatuloy ang Good Agricultural Practices (GAP): balanseng pataba (NPK), tamang patubig (AWD), at regular na pagmamasid sa bukid.' : 'Continue standard Good Agricultural Practices (GAP): balanced NPK fertilization, Alternate Wetting and Drying (AWD) irrigation, and routine field scouting.'}
          </p>
        </div>
      </div>
    `;
    return;
  }

  const key = scan.disease_key || 'blast';
  const severity = scan.severity || 'moderate';
  const data = getLocalizedTreatmentsData(key, severity, lang);

  let html = '';

  // Chemical Treatments / Recommended Sprays
  if (data.chemical && data.chemical.length) {
    html += `
      <div style="margin-bottom:4px;">
        <div style="display:flex; align-items:center; gap:6px; margin-bottom:8px;">
          <span style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:3px 8px; border-radius:4px; background:#fee2e2; color:#b91c1c;">
            ${lang === 'tagalog' ? 'Mungkahing Gamot / Kemikal na I-spray' : 'Recommended Chemical Spray & Dosage'}
          </span>
        </div>
        <div style="display:flex; flex-direction:column; gap:8px;">
          ${data.chemical.map(item => `
            <div style="background:#fff; border:1px solid #fecaca; border-radius:10px; padding:12px 14px; box-shadow:0 1px 2px rgba(0,0,0,0.02);">
              <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:4px; flex-wrap:wrap;">
                <h5 style="margin:0; font-size:13.5px; font-weight:700; color:#991b1b;">${escapeHtml(item.name)}</h5>
                ${item.dosage ? `<span style="font-size:11.5px; font-weight:700; color:#1e3a8a; background:#dbeafe; padding:2px 8px; border-radius:12px;">${escapeHtml(item.dosage)}</span>` : ''}
              </div>
              <p style="margin:0; font-size:12.5px; color:#4b5563; line-height:1.5;">${escapeHtml(item.desc)}</p>
            </div>
          `).join('')}
        </div>
      </div>
    `;
  }

  // Organic & Cultural Management
  if (data.organic && data.organic.length) {
    html += `
      <div style="margin-top:6px;">
        <div style="display:flex; align-items:center; gap:6px; margin-bottom:8px;">
          <span style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:3px 8px; border-radius:4px; background:#dcfce7; color:#15803d;">
            ${lang === 'tagalog' ? 'Organiko at Wastong Pamamahala sa Bukid' : 'Organic & Cultural Farm Practices'}
          </span>
        </div>
        <div style="display:flex; flex-direction:column; gap:8px;">
          ${data.organic.map(item => `
            <div style="background:#fff; border:1px solid #bbf7d0; border-radius:10px; padding:12px 14px; box-shadow:0 1px 2px rgba(0,0,0,0.02);">
              <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:4px; flex-wrap:wrap;">
                <h5 style="margin:0; font-size:13.5px; font-weight:700; color:#166534;">${escapeHtml(item.name)}</h5>
                <span style="font-size:11px; font-weight:600; color:#15803d; background:#f0fdf4; border:1px solid #86efac; padding:2px 8px; border-radius:12px;">${escapeHtml(item.tag || 'Action')}</span>
              </div>
              <p style="margin:0; font-size:12.5px; color:#4b5563; line-height:1.5;">${escapeHtml(item.desc)}</p>
            </div>
          `).join('')}
        </div>
      </div>
    `;
  }

  container.innerHTML = html;
}

function getLocalizedTreatmentsData(key, severity = 'moderate', lang = 'english') {
  const isTagalog = lang === 'tagalog';

  if (key === 'blast') {
    if (severity === 'mild') {
      return {
        chemical: [
          {
            name: isTagalog ? 'Tricyclazole 75% WP (Beam / Blast-Off)' : 'Tricyclazole 75% WP (Beam / Blast-Off)',
            dosage: isTagalog ? 'Dosis: 0.6–1.0 g / Litro ng tubig' : 'Dosage: 0.6–1.0 g / L water',
            desc: isTagalog ? 'I-spray bilang maagang proteksyon sa dahon. Pinipigilan nito ang pagdami ng spore ng amag ayon sa DA-PhilRice standard.' : 'Apply 0.6–1.0 g/L (300–400 g/ha) as early preventive foliar spray. DA-PhilRice standard systemic protective fungicide.'
          },
          {
            name: isTagalog ? 'Kasugamycin 2% SL (Kasumin)' : 'Kasugamycin 2% SL (Kasumin)',
            dosage: isTagalog ? 'Dosis: 1.5–2.0 ml / Litro ng tubig' : 'Dosage: 1.5–2.0 ml / L water',
            desc: isTagalog ? 'Bio-fungicide na mabilis sumuot sa dahon upang mapigilan ang impeksyon bago kumalat.' : 'Apply 1.5–2.0 ml/L. Systemic protective agricultural bio-fungicide preventing fungal spore penetration.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'Bawasan ang Sobrang Urea (Sundin ang Leaf Color Chart)' : 'Balanced Nitrogen (Follow Leaf Color Chart - LCC)',
            tag: isTagalog ? 'Abono' : 'Fertilization',
            desc: isTagalog ? 'Hatiin sa 3–4 na yugto ang paglalagay ng abono upang hindi maging malambot ang dahon na pabor sa sakit.' : 'Avoid excess urea application during vegetative stage. Split nitrogen fertilizer into 3–4 applications based on LCC reading.'
          },
          {
            name: isTagalog ? 'Panatilihing may Tubig (3–5 cm)' : 'Maintain Continuous Shallow Water (3–5 cm)',
            tag: isTagalog ? 'Patubig' : 'Water Management',
            desc: isTagalog ? 'Huwag hayaang matuyo ang pinitak habang nagsusuwi dahil mas mabilis kumalat ang blast kapag tagtuyot ang lupa.' : 'Do not allow the paddy field to dry out during tillering. Water-stressed paddies significantly heighten blast vulnerability.'
          }
        ]
      };
    } else if (severity === 'severe') {
      return {
        chemical: [
          {
            name: isTagalog ? 'Therapeutic Tricyclazole + Propiconazole Tank Mix' : 'Therapeutic Tricyclazole + Propiconazole / Mancozeb Tank Mix',
            dosage: isTagalog ? 'Dosis: 1.5–2.0 g / Litro ng tubig' : 'Dosage: 1.5–2.0 g / L water',
            desc: isTagalog ? 'Agarang emergency tank spray sa itaas na dahon at punong-uhay upang iligtas ang natitirang suwi at maiwasan ang neck blast.' : 'Emergency therapeutic tank spray (1.5–2.0 g/L) directed at upper leaves and panicle boot to save productive tillers.'
          },
          {
            name: isTagalog ? 'Carbendazim 50% WP + Epoxiconazole' : 'Carbendazim 50% WP + Epoxiconazole',
            dosage: isTagalog ? 'Dosis: 1.5–2.0 g / Litro ng tubig' : 'Dosage: 1.5–2.0 g / L water',
            desc: isTagalog ? 'Pamatay sa kumakalat na amag sa katawan at watawat na dahon ng palay.' : 'Apply 1.5–2.0 g/L for rapid curative eradication of active sporulating mycelial masses.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'Ihiwalay at Sunugin ang Grabeng Apektadong Tanim' : 'Sanitation & Removal of Blasted Residues',
            tag: isTagalog ? 'Kalinisan' : 'Sanitation',
            desc: isTagalog ? 'Alisin at sunugin ang mga natuyong dahon at dayami malayo sa palayan upang mapatay ang mga naiwang spores.' : 'Carefully collect and burn heavily blasted crop stubbles away from paddies to eradicate spore reserves.'
          },
          {
            name: isTagalog ? 'Magtanim ng Resistant Varieties (NSIC Rc222, Rc160)' : 'Plant Blast-Resistant Varieties Next Season',
            tag: isTagalog ? 'Binhi' : 'Varietal Selection',
            desc: isTagalog ? 'Sa susunod na taniman, gumamit ng sertipikadong binhi na subok na panlaban sa blast tulad ng NSIC Rc222, Rc160, o Tubigan series.' : 'Shift strictly next cropping season to certified resistant varieties such as NSIC Rc222, NSIC Rc160, or Tubigan series.'
          }
        ]
      };
    } else {
      // Moderate
      return {
        chemical: [
          {
            name: isTagalog ? 'Isoprothiolane 40% EC (Fuji-One)' : 'Isoprothiolane 40% EC (Fuji-One)',
            dosage: isTagalog ? 'Dosis: 1.5–2.0 ml / Litro ng tubig' : 'Dosage: 1.5–2.0 ml / L water',
            desc: isTagalog ? 'Systemic fungicide na mabilis kumalat sa buong halaman para pigilan ang paglaki ng sugat sa dahon.' : 'Apply 1.5–2.0 ml/L (750–1000 ml/ha) foliar spray. Systemic fungicide that arrests active lesion expansion.'
          },
          {
            name: isTagalog ? 'Azoxystrobin + Difenoconazole (Amistar Top 325 SC)' : 'Azoxystrobin + Difenoconazole (Amistar Top 325 SC)',
            dosage: isTagalog ? 'Dosis: 1.0 ml / Litro ng tubig' : 'Dosage: 1.0 ml / L water',
            desc: isTagalog ? 'Mabisang panggamot at pampatigil sa pamumulaklak ng amag sa mga dahon.' : 'Apply 1.0 ml/L spray. Dual systemic active ingredients providing curative and anti-sporulant action.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'Itigil Muna ang Paglalagay ng Urea' : 'Complete Nitrogen (Urea) Suspension',
            tag: isTagalog ? 'Abono' : 'Fertilizer Control',
            desc: isTagalog ? 'Agad na ihinto ang paglalagay ng Urea hanggang sa ganap na matuyo ang mga sugat ng blast sa palay.' : 'Immediately halt all topdressing of nitrogenous fertilizers until blast spots dry up.'
          },
          {
            name: isTagalog ? 'Mag-abono ng Potash (0-0-60 @ 30–40 kg/ha)' : 'Potassium Boost (Muriate of Potash - 0-0-60)',
            tag: isTagalog ? 'Sustansya' : 'Nutrition',
            desc: isTagalog ? 'Maglagay ng Potassium upang mapakapal at mapatibay ang mga selula ng dahon laban sa fungus.' : 'Apply 30–40 kg/ha K₂O to strengthen cell walls and enhance physiological resistance against fungal enzymes.'
          }
        ]
      };
    }
  }

  if (key === 'blb') {
    if (severity === 'mild') {
      return {
        chemical: [
          {
            name: isTagalog ? 'Copper Hydroxide 77% WP' : 'Copper Hydroxide 77% WP',
            dosage: isTagalog ? 'Dosis: 2.0 g / Litro ng tubig' : 'Dosage: 2.0 g / L water',
            desc: isTagalog ? 'Pamatay-bakterya sa ibabaw ng dahon upang mapigilan ang pagpasok ng Xanthomonas bacteria sa mga ugat ng dahon.' : 'Apply 2.0 g/L as preventive contact foliar spray to sanitize leaf surfaces and inhibit bacterial entry.'
          },
          {
            name: isTagalog ? 'Copper Oxychloride 50% WP' : 'Copper Oxychloride 50% WP',
            dosage: isTagalog ? 'Dosis: 2.5–3.0 g / Litro ng tubig' : 'Dosage: 2.5–3.0 g / L water',
            desc: isTagalog ? 'Nagsisilbing panangga laban sa pagdami ng bacterial leaf blight.' : 'Use 2.5–3.0 g/L spray during early vegetative stage as protective barrier against Xanthomonas.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'Patuyuin ang Pinitak nang 2–3 Araw' : 'Field Drainage & Humidity Control',
            tag: isTagalog ? 'Patubig' : 'Water Management',
            desc: isTagalog ? 'Alisin ang tubig sa bukid para bumaba ang humidity na paboritong tirahan ng bakterya.' : 'Drain standing water from the paddy for 2–3 days to reduce canopy relative humidity.'
          },
          {
            name: isTagalog ? 'Maglagay ng Potash at Sunog na Ipa (CRH)' : 'Potassium & Silica Fertilization',
            tag: isTagalog ? 'Lupa' : 'Soil Amendment',
            desc: isTagalog ? 'Patibayin ang kutikula ng dahon gamit ang Muriate of Potash at silica mula sa sunog na ipa.' : 'Apply Muriate of Potash (30–40 kg/ha) and silica/rice hull ash to strengthen leaf epidermal walls.'
          }
        ]
      };
    } else if (severity === 'severe') {
      return {
        chemical: [
          {
            name: isTagalog ? 'Streptomycin-Tetracycline (200 ppm) Therapeutic Spray' : 'Therapeutic Streptomycin-Tetracycline (200 ppm)',
            dosage: isTagalog ? 'Dosis: 2.0–2.5 g / Litro ng tubig' : 'Dosage: 2.0–2.5 g / L water',
            desc: isTagalog ? 'Emergency pamatay-bakterya na nakatutok sa itaas na dahon upang maisalba ang mga suwi.' : 'Emergency therapeutic application (2.0–2.5 g/L) directed at upper foliage and flag leaves.'
          },
          {
            name: isTagalog ? 'Zinc Thiazole 20% SC + Copper Hydroxide' : 'Zinc Thiazole 20% SC + Copper Hydroxide Tank Mix',
            dosage: isTagalog ? 'Dosis: 1.5 ml + 2.0 g / Litro ng tubig' : 'Dosage: 1.5 ml + 2.0 g / L water',
            desc: isTagalog ? 'Pinagsamang systemic at contact bactericide para pigilan ang bacterial ooze.' : 'Dual-action systemic + contact application to rapidly arrest active bacterial streaming.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'Patuyuin ang Lupa Hanggang Magbitak' : 'Deep Field Aeration & Sun Drying',
            tag: isTagalog ? 'Aksyong Bukid' : 'Field Action',
            desc: isTagalog ? 'Ganap na alisin ang tubig upang maaarawan ang lupa at mamatay ang bakterya.' : 'Completely drain water from the field and allow the soil surface to crack and sun-dry.'
          },
          {
            name: isTagalog ? 'Magtanim ng BLB-Resistant Varieties (Xa4/Xa7 Genes)' : 'Switch to Resistant Varieties Next Season',
            tag: isTagalog ? 'Binhi' : 'Varietal Selection',
            desc: isTagalog ? 'Magtanim ng NSIC Rc152, PSB Rc82, o IRBB varieties sa susunod na cropping.' : 'Plant certified varieties with proven multi-gene resistance (NSIC Rc152, PSB Rc82, or IRBB varieties).'
          }
        ]
      };
    } else {
      // Moderate
      return {
        chemical: [
          {
            name: isTagalog ? 'Streptomycin Sulfate + Oxytetracycline (Plantomycin)' : 'Streptomycin Sulfate + Oxytetracycline (Plantomycin)',
            dosage: isTagalog ? 'Dosis: 1.5–2.0 g / Litro ng tubig' : 'Dosage: 1.5–2.0 g / L water',
            desc: isTagalog ? 'Systemic agricultural antibiotic na sumusuot sa ugat ng dahon upang mapatay ang bakterya. Ulitin makalipas ang 7–10 araw.' : 'Apply 1.5–2.0 g/L foliar spray. Systemic antibiotic that penetrates vascular bundles to arrest replication.'
          },
          {
            name: isTagalog ? 'Zinc Thiazole / Bismerthiazol 20% SC' : 'Zinc Thiazole / Bismerthiazol 20% SC',
            dosage: isTagalog ? 'Dosis: 1.5–2.0 ml / Litro ng tubig' : 'Dosage: 1.5–2.0 ml / L water',
            desc: isTagalog ? 'Bactericide na direktang pumupuksa sa Xanthomonas bacteria.' : 'Apply 1.5–2.0 ml/L. Highly effective systemic bactericide specifically targeting Xanthomonas.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'Iwasang Maglakad sa Bukid Habang may Hamog' : 'Avoid Field Operations During Morning Dew',
            tag: isTagalog ? 'Pag-iingat' : 'Precaution',
            desc: isTagalog ? 'Huwag hawakan o damuhan ang palay sa umaga dahil kumakapit ang bakterya sa tubig ng hamog at lumilipat sa ibang tanim.' : 'Do not walk through or weed the crop while morning dew is on the leaves to prevent bacterial transmission.'
          },
          {
            name: isTagalog ? 'Ihiwalay ang Daloy ng Tubig-Patubig' : 'Irrigation Water Isolation',
            tag: isTagalog ? 'Patubig' : 'Water Control',
            desc: isTagalog ? 'Huwag hayaang dumaloy ang tubig galing sa may sakit na pinitak papunta sa malulusog na katabing palayan.' : 'Ensure irrigation water does not flow from infected fields into healthy neighboring rice plots.'
          }
        ]
      };
    }
  }

  if (key === 'brown_spot') {
    if (severity === 'mild') {
      return {
        chemical: [
          {
            name: isTagalog ? 'Mancozeb 80% WP (Dithane M-45)' : 'Mancozeb 80% WP (Dithane M-45)',
            dosage: isTagalog ? 'Dosis: 2.0–2.5 g / Litro ng tubig' : 'Dosage: 2.0–2.5 g / L water',
            desc: isTagalog ? 'Proteksiyon sa dahon upang mapigilan ang pagtubo ng mga batik at fungal spores.' : 'Apply 2.0–2.5 g/L protective contact foliar spray to halt spore germination and shield young leaves.'
          },
          {
            name: isTagalog ? 'Propiconazole 25% EC (Tilt)' : 'Propiconazole 25% EC (Tilt)',
            dosage: isTagalog ? 'Dosis: 1.0 ml / Litro ng tubig' : 'Dosage: 1.0 ml / L water',
            desc: isTagalog ? 'Systemic protection laban sa Bipolaris oryzae fungus.' : 'Apply 1.0 ml/L spray at first emergence of pinpoint brown specks.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'Mag-abono ng Potash (0-0-60 @ 30–40 kg/ha) at Zinc' : 'Potassium & Zinc Soil Amendment',
            tag: isTagalog ? 'Abono' : 'Fertilization',
            desc: isTagalog ? 'Kulang sa sustansya ang lupa kaya madalas magka-brown spot; agad maglagay ng Muriate of Potash at Zinc Sulfate.' : 'Apply Muriate of Potash (30–40 kg/ha) and Zinc Sulfate (25 kg/ha) to rectify nutrient deficiencies.'
          },
          {
            name: isTagalog ? 'Maglagay ng Organikong Compost' : 'Well-Decomposed Organic Compost',
            tag: isTagalog ? 'Lupa' : 'Soil Health',
            desc: isTagalog ? 'Maghalo ng 2–3 toneladang compost sa bawat ektarya upang mapaganda ang biological health ng lupa.' : 'Incorporate 2–3 tons/ha compost or decomposed rice straw to enrich soil biological activity.'
          }
        ]
      };
    } else if (severity === 'severe') {
      return {
        chemical: [
          {
            name: isTagalog ? 'Propiconazole 25% EC + Mancozeb Tank Mix' : 'Propiconazole 25% EC + Mancozeb 80% WP Tank Mix',
            dosage: isTagalog ? 'Dosis: 1.0 ml + 2.0 g / Litro ng tubig' : 'Dosage: 1.0 ml + 2.0 g / L water',
            desc: isTagalog ? 'Emergency tank spray para pigilan ang pagkasunog ng dahon at impeksyon sa butil (pecky rice).' : 'Therapeutic emergency spray (1.0 ml + 2.0 g/L). Combines systemic curative eradication with protective contact.'
          },
          {
            name: isTagalog ? 'Carbendazim 50% WP + Tebuconazole 250 EC' : 'Carbendazim 50% WP + Tebuconazole 250 EC',
            dosage: isTagalog ? 'Dosis: 1.0–1.5 g / Litro ng tubig' : 'Dosage: 1.0–1.5 g / L water',
            desc: isTagalog ? 'Pang-spray sa watawat na dahon at lumalabas na uhay upang iligtas ang kalidad ng butil ng palay.' : 'Apply 1.0–1.5 g/L spray targeted at flag leaves and emerging panicles to protect grain quality.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'Ibabad ang Binhi sa Maligamgam na Tubig (52–54°C)' : 'Hot Water Seed Disinfection',
            tag: isTagalog ? 'Binhi' : 'Seed Care',
            desc: isTagalog ? 'Ibabad ang binhi nang 15 minuto sa 52–54°C bago ikulob para mapatay ang amag na nakakapit sa butil.' : 'Soak seeds in 52–54°C warm water for 15 minutes before pre-germination.'
          },
          {
            name: isTagalog ? 'Malalim na Pag-aararo at Pagbabaon ng Dayami' : 'Deep Plowing & Crop Residue Incorporation',
            tag: isTagalog ? 'Kalinisan' : 'Sanitation',
            desc: isTagalog ? 'Ibaon nang malalim ang mga dayami at pinag-anihan upang mabulok at mamatay ang natitirang amag.' : 'Plow down and decompose all infected stubbles immediately after harvest.'
          }
        ]
      };
    } else {
      // Moderate
      return {
        chemical: [
          {
            name: isTagalog ? 'Tebuconazole 250 EC (Folicur)' : 'Tebuconazole 250 EC (Folicur)',
            dosage: isTagalog ? 'Dosis: 1.0 ml / Litro ng tubig' : 'Dosage: 1.0 ml / L water',
            desc: isTagalog ? 'Systemic triazole fungicide na pumipigil sa paglaki ng mga bilog na kayumangging mantsa.' : 'Apply 1.0 ml/L foliar spray. Strong systemic triazole fungicide that halts mycelial elongation.'
          },
          {
            name: isTagalog ? 'Azoxystrobin + Difenoconazole (Amistar Top)' : 'Azoxystrobin + Difenoconazole (Amistar Top 325 SC)',
            dosage: isTagalog ? 'Dosis: 1.0 ml / Litro ng tubig' : 'Dosage: 1.0 ml / L water',
            desc: isTagalog ? 'Mabisang gamot na nagbibigay ng matagalang proteksyon sa gitna at itaas na dahon.' : 'Apply 1.0 ml/L spray. Dual systemic strobilurin + triazole with powerful curative action.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'Hatiin ang Paglalagay ng Potash (Basal + Panicle)' : 'Split Potassium Topdressing (MOP 0-0-60)',
            tag: isTagalog ? 'Abono' : 'Fertilization',
            desc: isTagalog ? 'Maglagay ng kalahating dosis ng Potash sa simula at kalahati bago magbuntis ang palay para lumakas ang resistensya.' : 'Apply 50% potash at basal and 50% at panicle initiation stage (15–20 kg/ha K₂O).'
          },
          {
            name: isTagalog ? 'AWD Patubig (Alternate Wetting & Drying)' : 'Alternate Wetting and Drying (AWD)',
            tag: isTagalog ? 'Patubig' : 'Water Control',
            desc: isTagalog ? 'Pasingawin ang lupa tuwing 3–5 araw para makahinga ang ugat at makasipsip ng micronutrients.' : 'Practice controlled AWD irrigation to improve soil aeration and enhance root vigor.'
          }
        ]
      };
    }
  }

  if (key === 'tungro') {
    if (severity === 'mild') {
      return {
        chemical: [
          {
            name: isTagalog ? 'Imidacloprid 17.8% SL' : 'Imidacloprid 17.8% SL',
            dosage: isTagalog ? 'Dosis: 0.5–0.75 ml / Litro ng tubig' : 'Dosage: 0.5–0.75 ml / L water',
            desc: isTagalog ? 'Mabilisang pamatay sa Green Leafhopper (ngusong damo) bago makapaglipat ng virus sa palay.' : 'Apply 0.5–0.75 ml/L foliar spray. Rapid knockdown of Green Leafhopper (GLH) vectors.'
          },
          {
            name: isTagalog ? 'Thiamethoxam 25% WG' : 'Thiamethoxam 25% WG',
            dosage: isTagalog ? 'Dosis: 0.2–0.3 g / Litro ng tubig' : 'Dosage: 0.2–0.3 g / L water',
            desc: isTagalog ? 'Systemic protective barrier sa paligid ng pilapil at taniman.' : 'Apply 0.2–0.3 g/L as systemic protective vector barrier across field borders.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'Sabayang Pagtatanim sa Komunidad (Synchronous Planting)' : 'Synchronous Community Planting',
            tag: isTagalog ? 'Pamamahala' : 'Cultural',
            desc: isTagalog ? 'Magtanim sa loob ng 2 linggong sabayang iskedyul ng mga katabing magsasaka upang maputol ang pamumugad ng ngusong damo.' : 'Coordinate community planting within a 2-week window to break continuous insect vector breeding.'
          },
          {
            name: isTagalog ? 'Magkabit ng Yellow Sticky Traps (20–25 bawat ektarya)' : 'Yellow Sticky Vector Traps',
            tag: isTagalog ? 'Bitag' : 'Trapping',
            desc: isTagalog ? 'Mabisang humuhuli at sumusubaybay sa dami ng berdeng ngusong damo.' : 'Install 20–25 yellow sticky insect traps per hectare to monitor and trap green leafhoppers.'
          }
        ]
      };
    } else if (severity === 'severe') {
      return {
        chemical: [
          {
            name: isTagalog ? 'Etofenprox 10% EC' : 'Etofenprox 10% EC',
            dosage: isTagalog ? 'Dosis: 1.5–2.0 ml / Litro ng tubig' : 'Dosage: 1.5–2.0 ml / L water',
            desc: isTagalog ? 'Emergency pamatay-insekto para sa makakapal na populasyon ng ngusong damo.' : 'Apply 1.5–2.0 ml/L pyrethroid ether for rapid emergency knockdown of high-density leafhopper populations.'
          },
          {
            name: isTagalog ? 'Fipronil 5% SC / Clothianidin 50% WDG' : 'Fipronil 5% SC / Clothianidin 50% WDG',
            dosage: isTagalog ? 'Dosis: 1.0 ml / Litro ng tubig' : 'Dosage: 1.0 ml / L water',
            desc: isTagalog ? 'I-spray sa puno at dahon ng palay upang maputol ang transmisyon ng virus.' : 'Emergency vector eradication directed at the base and foliage of the crop.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'Bunutin at Sunugin ang mga Bansot na Tanim (Rogueing)' : 'Systemic Rogueing & Field Sanitation',
            tag: isTagalog ? 'Aksyon' : 'Field Action',
            desc: isTagalog ? 'Agad bunutin at sunugin ang mga grabeng nanilaw at bansot na tumpok upang hindi mahawa ang buong bukid.' : 'Pull out completely stunted, unheading hills and burn away from the field.'
          },
          {
            name: isTagalog ? 'Magtanim ng Matatag/Tungro-Resistant Varieties (NSIC Rc160)' : 'Switch to Resistant Varieties Next Season',
            tag: isTagalog ? 'Binhi' : 'Varietal Selection',
            desc: isTagalog ? 'Magtanim ng NSIC Rc160, NSIC Rc120, o Matatag lines sa susunod na taniman.' : 'Plant certified Tungro-resistant rice varieties (NSIC Rc160, NSIC Rc120, or Matatag lines).'
          }
        ]
      };
    } else {
      // Moderate
      return {
        chemical: [
          {
            name: isTagalog ? 'Dinotefuran 20% SG' : 'Dinotefuran 20% SG',
            dosage: isTagalog ? 'Dosis: 0.5–1.0 g / Litro ng tubig' : 'Dosage: 0.5–1.0 g / L water',
            desc: isTagalog ? 'Mabilis na pamatay sa parehong bata at matandang ngusong damo.' : 'Apply 0.5–1.0 g/L. Fast-acting neonicotinoid with systemic activity against leafhoppers.'
          },
          {
            name: isTagalog ? 'Clothianidin + Pymetrozine' : 'Clothianidin + Pymetrozine',
            dosage: isTagalog ? 'Dosis: 1.0 g / Litro ng tubig' : 'Dosage: 1.0 g / L water',
            desc: isTagalog ? 'Pinaparalisa ang bibig ng insekto upang agad itong huminto sa pagsipsip ng katas ng dahon.' : 'Apply 1.0 g/L. Paralyzes insect feeding mouthparts and arrests vector transmission.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'Pumili at Magbunot ng mga Apektadong Tanim' : 'Selective Rogueing',
            tag: isTagalog ? 'Kalinisan' : 'Sanitation',
            desc: isTagalog ? 'Bunutin at ibaon sa lupa ang mga kapansin-pansing naninilaw na puno upang mabawasan ang source ng virus.' : 'Uproot and bury individual severely yellowed hills displaying distinct stunting.'
          },
          {
            name: isTagalog ? 'Spray ng Neem Extract (NSKE 5%)' : 'Neem Seed Kernel Extract (NSKE 5%)',
            tag: isTagalog ? 'Organiko' : 'Botanical',
            desc: isTagalog ? 'Likas na panaboy sa mga insektong sumisipsip ng dahon.' : 'Spray 5% neem extract to act as natural antifeedant and repellent against vectors.'
          }
        ]
      };
    }
  }

  if (key === 'sheath_blight') {
    if (severity === 'mild') {
      return {
        chemical: [
          {
            name: isTagalog ? 'Validamycin 3% L (Sheathmar / Validacin)' : 'Validamycin 3% L (Sheathmar / Validacin)',
            dosage: isTagalog ? 'Dosis: 2.0–2.5 ml / Litro ng tubig' : 'Dosage: 2.0–2.5 ml / L water',
            desc: isTagalog ? 'I-spray sa ibabang bahagi ng puno ng palay malapit sa tubig. Mabisang pampatigil sa paghaba ng amag.' : 'Apply 2.0–2.5 ml/L foliar spray targeted at the lower canopy/culm base. Halts Rhizoctonia hyphal elongation.'
          },
          {
            name: isTagalog ? 'Hexaconazole 5% SC (Contaf)' : 'Hexaconazole 5% SC (Contaf)',
            dosage: isTagalog ? 'Dosis: 1.5–2.0 ml / Litro ng tubig' : 'Dosage: 1.5–2.0 ml / L water',
            desc: isTagalog ? 'Panggamot sa mga basang sugat sa saha ng palay.' : 'Apply 1.5–2.0 ml/L spray to arrest mycelial growth on lower leaf sheaths.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'Panatilihin ang 20x20 cm na Distansya ng Tanim' : 'Canopy Aeration & Proper Spacing',
            tag: isTagalog ? 'Taniman' : 'Spacing',
            desc: isTagalog ? 'Wastong agwat upang makapasok ang sikat ng araw at sariwang hangin sa ibaba ng puno.' : 'Maintain 20x20 cm plant spacing to improve sunlight penetration and air circulation.'
          },
          {
            name: isTagalog ? 'Mag-spray ng Trichoderma harzianum' : 'Trichoderma viride / harzianum',
            tag: isTagalog ? 'Biolohikal' : 'Bio-Control',
            desc: isTagalog ? 'Mag-spray ng 5–10 g/L Trichoderma sa puno ng palay upang likas na labanan ang Rhizoctonia amag.' : 'Apply antagonistic bio-agent foliar/soil drench at 5–10 g/L to biologically suppress sclerotia.'
          }
        ]
      };
    } else if (severity === 'severe') {
      return {
        chemical: [
          {
            name: isTagalog ? 'Therapeutic Thifluzamide + Tebuconazole Tank Mix' : 'Therapeutic Thifluzamide + Tebuconazole Tank Mix',
            dosage: isTagalog ? 'Dosis: 1.5–2.0 g / Litro ng tubig' : 'Dosage: 1.5–2.0 g / L water',
            desc: isTagalog ? 'Emergency spray upang iligtas ang watawat na dahon at booting panicle mula sa paghiga (lodging) at pagkabulok.' : 'Emergency therapeutic spray (1.5–2.0 g/L) to salvage flag leaves and booting panicles.'
          },
          {
            name: isTagalog ? 'Carbendazim 50% WP + Difenoconazole' : 'Carbendazim 50% WP + Difenoconazole',
            dosage: isTagalog ? 'Dosis: 1.5–2.0 g / Litro ng tubig' : 'Dosage: 1.5–2.0 g / L water',
            desc: isTagalog ? 'Mabilisang pamatay sa amag na umaakyat sa itaas ng puno ng palay.' : 'Apply 1.5–2.0 g/L for rapid curative eradication of active ascending fungal colonies.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'Salain ang mga Lutang na Amag (Sclerotia) sa Tubig' : 'Destroy Stricken Stubbles & Floating Sclerotia',
            tag: isTagalog ? 'Kalinisan' : 'Sanitation',
            desc: isTagalog ? 'Salain ang mga lumulutang na itim/kayumangging butil ng amag kapag nagpapatag ng bukid at sunugin ang mga tuyong dayami.' : 'Skim floating sclerotia during final land leveling and burn severely infected residues.'
          },
          {
            name: isTagalog ? 'Magtanim ng Tolerant Varieties (NSIC Rc222, Rc216)' : 'Plant Tolerant Varieties Next Cropping',
            tag: isTagalog ? 'Binhi' : 'Varietal Selection',
            desc: isTagalog ? 'Gumamit ng binhi na may tuwid na dahon at matibay na puno laban sa paghiga sa susunod na taniman.' : 'Shift to certified erect-leaf, moderate-tillering tolerant varieties (NSIC Rc222, NSIC Rc216).'
          }
        ]
      };
    } else {
      // Moderate
      return {
        chemical: [
          {
            name: isTagalog ? 'Azoxystrobin + Difenoconazole (Amistar Top 325 SC)' : 'Azoxystrobin + Difenoconazole (Amistar Top 325 SC)',
            dosage: isTagalog ? 'Dosis: 1.0 ml / Litro ng tubig' : 'Dosage: 1.0 ml / L water',
            desc: isTagalog ? 'I-spray sa gitnang bahagi ng puno. Mabisang pumipigil sa pag-akyat ng sakit sa itaas na dahon.' : 'Apply 1.0 ml/L spray directed at mid-canopy. Dual systemic translaminar curative action.'
          },
          {
            name: isTagalog ? 'Thifluzamide 24% SC (Pulsor)' : 'Thifluzamide 24% SC (Pulsor)',
            dosage: isTagalog ? 'Dosis: 0.75–1.0 ml / Litro ng tubig' : 'Dosage: 0.75–1.0 ml / L water',
            desc: isTagalog ? 'Espesyal na gamot laban sa Rhizoctonia sheath blight sa palay.' : 'Apply 0.75–1.0 ml/L. Highly potent fungicide specifically active against Rhizoctonia sheath blight.'
          }
        ],
        organic: [
          {
            name: isTagalog ? 'AWD Patubig (Patuyuin ang Bukid nang 2–3 Araw)' : 'Alternate Wetting and Drying (AWD)',
            tag: isTagalog ? 'Patubig' : 'Water Control',
            desc: isTagalog ? 'Patuyuin ang tubig upang bumaba ang halumigmig at hindi umakyat ang amag sa saha.' : 'Drain field water intermittently for 2–3 days to reduce relative humidity inside crop canopy.'
          },
          {
            name: isTagalog ? 'Ganap na Itigil ang Pag-aabono ng Urea' : 'Complete Urea Suspension',
            tag: isTagalog ? 'Abono' : 'Fertilizer Control',
            desc: isTagalog ? 'Itigil muna ang nitrogenous fertilizer para hindi maging malambot ang saha ng palay.' : 'Halt all topdress nitrogen applications immediately to prevent succulent tissue expansion.'
          }
        ]
      };
    }
  }

  return {
    chemical: [
      {
        name: isTagalog ? 'Protektibong Spray' : 'Preventive Mild Fungicide',
        dosage: isTagalog ? 'Dosis: 1.0–2.0 g/L' : 'Dosage: 1.0–2.0 g/L',
        desc: isTagalog ? 'Mag-spray lamang kung tag-ulan o may senyales ng pagkalat ng sakit.' : 'Apply mild protective spray only during prolonged wet periods.'
      }
    ],
    organic: [
      {
        name: isTagalog ? 'Good Agricultural Practices (GAP)' : 'Good Agricultural Practices',
        tag: isTagalog ? 'Alaga' : 'Cultural',
        desc: isTagalog ? 'Panatilihin ang 20x20 cm na distansya, balanseng NPK, at malinis na patubig.' : 'Maintain 20x20 cm plant spacing, balanced NPK fertilization, and clean irrigation.'
      }
    ]
  };
}

function speakResultSymptoms(btn) {
  const symptomsText = document.getElementById('resultSymptomsText');
  const dname = document.getElementById('resultDiseaseName');
  if (!symptomsText || !dname) return;
  const textToRead = dname.textContent + '. ' + symptomsText.textContent;
  const lang = currentResultLang || 'english';
  speakText(textToRead, lang, btn);
}

/* ═══════════ TREATMENT GUIDE & DOSAGE CALCULATOR ═══════════ */
function switchTreatmentDisease(diseaseKey, severity = 'moderate') {
  const d = ALL_DISEASES[diseaseKey] || ALL_DISEASES.blast;
  const isMultiSev = (diseaseKey === 'blb' || diseaseKey === 'tungro' || diseaseKey === 'blast' || diseaseKey === 'brown_spot' || diseaseKey === 'sheath_blight');
  currentScanResult = {
    disease_key: diseaseKey,
    disease: d.name,
    scientific: d.scientific,
    severity: (isMultiSev ? severity : d.severity),
    treatments: getDefaultTreatments(diseaseKey, (isMultiSev ? severity : d.severity)),
  };

  // Update disease pills
  document.querySelectorAll('#treatmentDiseasePills .disease-pill').forEach(btn => {
    const fn = btn.getAttribute('onclick') || '';
    btn.classList.toggle('active', fn.includes("'" + diseaseKey + "'"));
  });

  loadCurrentTreatment();
}

function switchDiseaseSeverity(sevLevel) {
  document.querySelectorAll('.blb-sev-pill').forEach(b => b.classList.remove('active'));
  const activeBtn = document.getElementById('blbSev' + sevLevel.charAt(0).toUpperCase() + sevLevel.slice(1) + 'Btn');
  if (activeBtn) activeBtn.classList.add('active');

  const curKey = (currentScanResult && currentScanResult.disease_key) ? currentScanResult.disease_key : 'blast';
  const targetKey = (curKey === 'tungro' || curKey === 'blb' || curKey === 'blast' || curKey === 'brown_spot' || curKey === 'sheath_blight') ? curKey : 'blast';
  const d = ALL_DISEASES[targetKey] || ALL_DISEASES.blast;

  currentScanResult = {
    disease_key: targetKey,
    disease: d.name,
    scientific: d.scientific,
    severity: sevLevel,
    treatments: getDefaultTreatments(targetKey, sevLevel),
  };

  loadCurrentTreatment();
}

function switchBlbSeverity(sevLevel) {
  switchDiseaseSeverity(sevLevel);
}

function selectAndShowTreatment(diseaseKey) {
  switchTreatmentDisease(diseaseKey);
  navigateTo('treatment');
  setActiveSidebar('treatment');
}

function loadCurrentTreatment() {
  if (!currentScanResult) {
    applyScanResult(getFallbackResult());
  }
  const s = currentScanResult;
  const key = s.disease_key || 'blast';
  const curSeverity = s.severity || 'moderate';
  const isMultiSev = (key === 'blb' || key === 'tungro' || key === 'blast' || key === 'brown_spot' || key === 'sheath_blight');

  const name = document.getElementById('treatDiseaseName');
  if (name) name.textContent = s.disease;
  const sub = document.getElementById('treatDiseaseSub');
  if (sub) {
    const rangeText = isMultiSev ? (curSeverity === 'mild' ? ' (≤ 25%)' : (curSeverity === 'moderate' ? ' (26% – 60%)' : ' (> 60%)')) : '';
    sub.textContent = (s.scientific || s.disease) + ' · Severity: ' + curSeverity.toUpperCase() + rangeText;
  }

  // Show or hide severity selector pills for BLB, Tungro, Blast, and Brown Spot
  const blbPillsBar = document.getElementById('blbSeverityPillsBar');
  if (blbPillsBar) {
    if (isMultiSev) {
      blbPillsBar.style.display = 'flex';
      document.querySelectorAll('.blb-sev-pill').forEach(b => b.classList.remove('active'));
      const activeBtn = document.getElementById('blbSev' + curSeverity.charAt(0).toUpperCase() + curSeverity.slice(1) + 'Btn');
      if (activeBtn) activeBtn.classList.add('active');
    } else {
      blbPillsBar.style.display = 'none';
    }
  }

  const treatIcon = document.getElementById('treatDiseaseIcon');
  if (treatIcon) {
    treatIcon.classList.remove('blast-bg', 'blb-bg', 'healthy-bg');
    const lower = (s.disease || '').toLowerCase();
    let cls = 'blast-bg';
    if (lower.includes('healthy')) { cls = 'healthy-bg'; }
    else if (lower.includes('blight') || lower.includes('blb') || lower.includes('brown')) { cls = 'blb-bg'; }
    treatIcon.classList.add(cls);
    treatIcon.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22v-9"/><path d="M12 13C8 13 4 9 4 4c5 0 9 4 9 9z"/><path d="M12 8c2-3 5-4 8-4 0 5-4 9-8 9"/></svg>';
  }

  const treat = s.treatments || getDefaultTreatments(key, curSeverity);
  const chemList = document.getElementById('chemicalList');
  const orgList = document.getElementById('organicList');
  if (chemList) chemList.innerHTML = renderTreatmentItems(treat.chemical || []);
  if (orgList) orgList.innerHTML = renderTreatmentItems(treat.organic || []);

  calculateDosage();
}

function renderTreatmentItems(items) {
  if (!items || !items.length) {
    return '<div style="padding:16px; text-align:center; color:var(--neutral-500); font-size:13px;">No treatment items listed.</div>';
  }
  return items.map(it => {
    const tagClass = (it.tag_class || 'cultural').toLowerCase();
    return '<div class="treatment-item fade-in">' +
      '<h4>' + escapeHtml(it.name) + '</h4>' +
      '<p>' + escapeHtml(it.desc) + '</p>' +
      '<span class="treat-tag ' + tagClass + '">' + escapeHtml(it.tag || 'Recommendation') + '</span>' +
      '</div>';
  }).join('');
}

function switchTreatment(type) {
  const tabs = document.querySelectorAll('#treatmentTabs button');
  tabs.forEach(t => t.classList.remove('active'));

  const chemList = document.getElementById('chemicalList');
  const orgList = document.getElementById('organicList');
  if (type === 'chemical') {
    if (tabs[0]) tabs[0].classList.add('active');
    if (chemList) chemList.style.display = 'block';
    if (orgList) orgList.style.display = 'none';
  } else {
    if (tabs[1]) tabs[1].classList.add('active');
    if (chemList) chemList.style.display = 'none';
    if (orgList) orgList.style.display = 'block';
  }
}

function calculateDosage() {
  const areaInput = document.getElementById('calcFieldArea');
  const unitSelect = document.getElementById('calcAreaUnit');
  const resultBox = document.getElementById('dosageResultText');
  if (!areaInput || !unitSelect || !resultBox) return;

  const rawVal = parseFloat(areaInput.value) || 1;
  const isHectare = unitSelect.value === 'hectare';
  const hectares = isHectare ? rawVal : rawVal / 10000;

  const litersWater = Math.round(hectares * 200);
  const sprayers = (litersWater / 16).toFixed(1);
  const mlChemicalMin = Math.round(litersWater * 1.0);
  const mlChemicalMax = Math.round(litersWater * 2.0);

  resultBox.innerHTML =
    'Water volume needed: <strong>' + litersWater + ' Liters</strong> (~' + sprayers + ' knapsack loads at 16L each)<br>' +
    'Active fungicide / bactericide: <strong>' + mlChemicalMin + ' - ' + mlChemicalMax + ' ml (or grams)</strong> total spray mixture.';
}

function consultAboutCurrentDisease() {
  const d = currentScanResult ? currentScanResult.disease : 'Leaf Blast';
  navigateTo('consultation');
  setActiveSidebar('consultation');
  sendQuickQuestion('Ano ang mabisang gamot sa ' + d + '?');
}

/* ═══════════ SCAN HISTORY ═══════════ */
let historyCurrentPage = 1;
const historyPageSize = 10;
let currentFilteredHistory = [];

async function loadHistory() {
  await ensureCsrfCookie();
  fetch(apiUrl('/rice-detector/history'), {
    credentials: 'include',
    headers: authHeaders(),
  })
    .then(r => r.json())
    .then(data => {
      if (!data || !data.success) return;
      const stats = data.stats || { healthy: 0, mild: 0, moderate: 0, severe: 0 };
      const h = document.getElementById('statHealthy');
      const mild = document.getElementById('statMild');
      const m = document.getElementById('statModerate');
      const s = document.getElementById('statSevere');
      if (h) h.textContent = stats.healthy || 0;
      if (mild) mild.textContent = stats.mild || 0;
      if (m) m.textContent = stats.moderate || 0;
      if (s) s.textContent = stats.severe || 0;

      allHistoryScans = [];
      const groups = data.scans || {};
      Object.keys(groups).forEach(dateLabel => {
        (groups[dateLabel] || []).forEach(scan => {
          allHistoryScans.push({ ...scan, dateLabel: dateLabel });
        });
      });

      filterHistoryList();
    })
    .catch(() => {});
}

function renderHistoryList(scans) {
  currentFilteredHistory = scans || [];
  const tableWrapper = document.getElementById('historyTableWrapper');
  const emptyMsg = document.getElementById('historyEmptyMessage');
  const tbody = document.getElementById('historyTableBody');
  const paginationInfo = document.getElementById('historyPaginationInfo');
  const paginationControls = document.getElementById('historyPaginationControls');

  if (!tbody) return;

  if (!scans || !scans.length) {
    if (tableWrapper) tableWrapper.style.display = 'none';
    if (emptyMsg) emptyMsg.style.display = 'block';
    return;
  }

  if (tableWrapper) tableWrapper.style.display = 'block';
  if (emptyMsg) emptyMsg.style.display = 'none';

  const totalItems = scans.length;
  const totalPages = Math.ceil(totalItems / historyPageSize) || 1;
  if (historyCurrentPage > totalPages) historyCurrentPage = totalPages;
  if (historyCurrentPage < 1) historyCurrentPage = 1;

  const startIndex = (historyCurrentPage - 1) * historyPageSize;
  const endIndex = Math.min(startIndex + historyPageSize, totalItems);
  const pageItems = scans.slice(startIndex, endIndex);

  let html = '';
  pageItems.forEach(scan => {
    const sev = (scan.severity || 'severe').toLowerCase();
    let badgeCls = 'sev-badge severe';
    let badgeText = 'Severe (> 60%)';
    if (sev === 'healthy') {
      badgeCls = 'sev-badge healthy';
      badgeText = 'Healthy Leaf';
    } else if (sev === 'mild') {
      badgeCls = 'sev-badge mild';
      badgeText = 'Mild (≤ 25%)';
    } else if (sev === 'moderate') {
      badgeCls = 'sev-badge moderate';
      badgeText = 'Moderate (26% – 60%)';
    }

    const thumb = scan.image_url
      ? '<img src="' + escapeHtml(scan.image_url) + '" alt="' + escapeHtml(scan.disease) + '">'
      : '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><path d="M12 8v8M8 12h8"/></svg>';

    const sciname = scan.scientific ? '<em>' + escapeHtml(scan.scientific) + '</em>' : (sev === 'healthy' ? 'Optimal Plant Health' : 'Diagnosed via MobileNet');

    html += '<tr>' +
      '<td>' +
        '<div class="ht-thumb ' + escapeHtml(scan.severity_class || '') + '" onclick="viewHistoryScanDetail(' + (scan.id || 0) + ')">' +
          thumb +
        '</div>' +
      '</td>' +
      '<td>' +
        '<div class="ht-disease-name" style="cursor:pointer;" onclick="viewHistoryScanDetail(' + (scan.id || 0) + ')">' + escapeHtml(scan.disease) + '</div>' +
        '<div class="ht-scientific-name">' + sciname + '</div>' +
      '</td>' +
      '<td>' +
        '<span class="' + badgeCls + '">' + badgeText + '</span>' +
      '</td>' +
      '<td>' +
        '<div class="ht-date">' + escapeHtml(scan.date || scan.dateLabel || 'Today') + '</div>' +
        '<div class="ht-time">' + escapeHtml(scan.time || '') + '</div>' +
      '</td>' +
      '<td>' +
        '<div class="ht-actions">' +
          '<button type="button" class="ht-view-btn" onclick="viewHistoryScanDetail(' + (scan.id || 0) + ')" title="View Details">View</button>' +
          (scan.id ? '<button type="button" class="ht-delete-btn" onclick="deleteScan(' + scan.id + '); event.stopPropagation();" title="Delete">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
          '</button>' : '') +
        '</div>' +
      '</td>' +
    '</tr>';
  });

  tbody.innerHTML = html;

  if (paginationInfo) {
    paginationInfo.textContent = 'Showing ' + (startIndex + 1) + '–' + endIndex + ' of ' + totalItems + ' scan records';
  }

  if (paginationControls) {
    let phtml = '';
    phtml += '<button type="button" class="page-btn" onclick="goToHistoryPage(' + (historyCurrentPage - 1) + ')" ' + (historyCurrentPage <= 1 ? 'disabled' : '') + ' title="Previous Page">‹</button>';

    const maxVisible = 5;
    let startPage = Math.max(1, historyCurrentPage - 2);
    let endPage = Math.min(totalPages, startPage + maxVisible - 1);
    if (endPage - startPage < maxVisible - 1) {
      startPage = Math.max(1, endPage - maxVisible + 1);
    }

    if (startPage > 1) {
      phtml += '<button type="button" class="page-btn" onclick="goToHistoryPage(1)">1</button>';
      if (startPage > 2) phtml += '<span class="page-ellipsis">...</span>';
    }

    for (let p = startPage; p <= endPage; p++) {
      phtml += '<button type="button" class="page-btn ' + (p === historyCurrentPage ? 'active' : '') + '" onclick="goToHistoryPage(' + p + ')">' + p + '</button>';
    }

    if (endPage < totalPages) {
      if (endPage < totalPages - 1) phtml += '<span class="page-ellipsis">...</span>';
      phtml += '<button type="button" class="page-btn" onclick="goToHistoryPage(' + totalPages + ')">' + totalPages + '</button>';
    }

    phtml += '<button type="button" class="page-btn" onclick="goToHistoryPage(' + (historyCurrentPage + 1) + ')" ' + (historyCurrentPage >= totalPages ? 'disabled' : '') + ' title="Next Page">›</button>';

    paginationControls.innerHTML = phtml;
  }
}

function goToHistoryPage(page) {
  historyCurrentPage = page;
  renderHistoryList(currentFilteredHistory);
}

function setHistoryFilter(filter) {
  activeHistoryFilter = filter || 'all';
  historyCurrentPage = 1;
  const select = document.getElementById('historyFilterSelect');
  if (select && select.value !== activeHistoryFilter) {
    select.value = activeHistoryFilter;
  }
  filterHistoryList();
}

function toggleHistorySearch() {
  const input = document.getElementById('historySearchInput');
  if (input) {
    input.focus();
  }
}

function filterHistoryList() {
  const query = ((document.getElementById('historySearchInput') || {}).value || '').toLowerCase().trim();
  let filtered = allHistoryScans;

  if (activeHistoryFilter !== 'all') {
    filtered = filtered.filter(s => {
      const sev = (s.severity || '').toLowerCase();
      const dis = (s.disease || '').toLowerCase();
      if (activeHistoryFilter === 'severe') return sev === 'severe';
      if (activeHistoryFilter === 'moderate') return sev === 'moderate';
      if (activeHistoryFilter === 'mild') return sev === 'mild';
      if (activeHistoryFilter === 'healthy') return sev === 'healthy' || dis.includes('healthy');
      if (activeHistoryFilter === 'blast') return dis.includes('blast');
      if (activeHistoryFilter === 'blb') return dis.includes('blight') || dis.includes('blb');
      if (activeHistoryFilter === 'brown_spot') return dis.includes('brown') || dis.includes('spot');
      if (activeHistoryFilter === 'tungro') return dis.includes('tungro');
      return true;
    });
  }

  if (query) {
    filtered = filtered.filter(s => {
      const name = (s.disease || '').toLowerCase();
      const sci = (s.scientific || '').toLowerCase();
      const date = (s.dateLabel || s.date || '').toLowerCase();
      const sev = (s.severity || '').toLowerCase();
      return name.includes(query) || sci.includes(query) || date.includes(query) || sev.includes(query);
    });
  }

  renderHistoryList(filtered);
}

function viewHistoryScanDetail(scanId) {
  const scan = allHistoryScans.find(s => s.id === scanId);
  if (scan) {
    applyScanResult(scan);
    navigateTo('results');
  }
}

async function deleteScan(id) {
  if (!confirm('Are you sure you want to delete this scan record?')) return;
  await ensureCsrfCookie();
  fetch(apiUrl('/rice-detector/scan/' + id), {
    method: 'DELETE',
    credentials: 'include',
    headers: authHeaders(),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        loadHistory();
        loadHomeRecentScans();
      }
    })
    .catch(() => loadHistory());
}

async function loadHomeRecentScans() {
  await ensureCsrfCookie();
  fetch(apiUrl('/rice-detector/history'), {
    credentials: 'include',
    headers: authHeaders(),
  })
    .then(r => r.json())
    .then(data => {
      const container = document.getElementById('homeRecentScansContainer');
      if (!container) return;
      if (!data || !data.success) {
        renderHomeEmptyScans();
        return;
      }
      const all = [];
      Object.values(data.scans || {}).forEach(group => group.forEach(s => all.push(s)));
      const recent = all.slice(0, 3);
      if (!recent.length) {
        renderHomeEmptyScans();
        return;
      }
      container.innerHTML = recent.map(s => {
        const lower = (s.disease || '').toLowerCase();
        let thumbCls = 'blast';
        if (lower.includes('healthy')) thumbCls = 'healthy';
        else if (lower.includes('blight') || lower.includes('blb') || lower.includes('brown')) thumbCls = 'blb';
        let sevCls = 'severe';
        if (s.severity === 'healthy') sevCls = 'healthy';
        else if (s.severity === 'mild') sevCls = 'mild';
        else if (s.severity === 'moderate') sevCls = 'moderate';
        
        let sevLabel = 'Severe (>60%)';
        if (s.severity === 'healthy') sevLabel = 'Healthy';
        else if (s.severity === 'mild') sevLabel = 'Mild (≤25%)';
        else if (s.severity === 'moderate') sevLabel = 'Moderate (26-60%)';
        const thumbContent = s.image_url
          ? '<img src="' + escapeHtml(s.image_url) + '" alt="' + escapeHtml(s.disease) + '" style="width:100%; height:100%; object-fit:cover; border-radius:inherit;">'
          : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M7 12h10"/></svg>';

        return '<div class="recent-scan-card fade-in" onclick="viewHistoryScanDetail(' + (s.id || 0) + ')">' +
          '<div class="scan-thumb ' + thumbCls + '" style="overflow:hidden;">' +
          thumbContent +
          '</div>' +
          '<div class="scan-info">' +
          '<div class="disease-name">' + escapeHtml(s.disease) + '</div>' +
          '<div class="scan-meta">' + escapeHtml((s.time || '') + ' · ' + (s.date || '')) + '</div>' +
          '</div>' +
          '<span class="severity-badge ' + sevCls + '">' + escapeHtml(sevLabel) + '</span>' +
          '</div>';
      }).join('');
    })
    .catch(() => {
      renderHomeEmptyScans();
    });
}

function renderHomeEmptyScans() {
  const container = document.getElementById('homeRecentScansContainer');
  if (!container) return;
  container.innerHTML = `
    <div class="empty-scans-card">
      <div class="esc-icon">🌱</div>
      <div class="esc-text">
        <h4>No recent scans yet</h4>
        <p>Capture or upload a leaf photo to diagnose rice diseases.</p>
      </div>
      <button class="esc-btn" onclick="showScreen('scan')">Scan Leaf</button>
    </div>
  `;
}

/* ═══════════ AI CONSULTATION ═══════════ */
function sendQuickQuestion(text) {
  const input = document.getElementById('chatInput');
  if (input) {
    input.value = text;
    sendMessage();
  }
}

function confirmClearChat() {
  if (!confirm('Clear all chat conversation history?')) return;
  fetch(apiUrl('/consultation/clear'), {
    method: 'DELETE',
    credentials: 'include',
    headers: authHeaders(),
  }).finally(() => {
    const c = document.getElementById('chatMessages');
    if (c) c.innerHTML = '<div class="chat-time">Chat history cleared</div>';
  });
}

let isAiVoiceMuted = typeof window !== 'undefined' && window.localStorage ? window.localStorage.getItem('oryzatix_ai_voice_muted') === 'true' : false;
let currentSpeakingBtn = null;

function initAiVoiceMuteUI() {
  const btn = document.getElementById('aiMuteToggleBtn');
  const icon = document.getElementById('aiMuteIcon');
  const text = document.getElementById('aiMuteText');
  if (!btn) return;

  if (isAiVoiceMuted) {
    btn.classList.add('muted');
    if (text) text.textContent = 'Voice: Muted';
    if (icon) {
      icon.innerHTML = '<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/>';
    }
    btn.title = 'Unmute AI Voice';
  } else {
    btn.classList.remove('muted');
    if (text) text.textContent = 'Voice: On';
    if (icon) {
      icon.innerHTML = '<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/>';
    }
    btn.title = 'Mute AI Voice';
  }
}

function toggleAiVoiceMute() {
  isAiVoiceMuted = !isAiVoiceMuted;
  if (window.localStorage) {
    window.localStorage.setItem('oryzatix_ai_voice_muted', isAiVoiceMuted ? 'true' : 'false');
  }
  if (isAiVoiceMuted) {
    stopAiSpeech();
  }
  initAiVoiceMuteUI();
}

function stopAiSpeech() {
  if ('speechSynthesis' in window) {
    window.speechSynthesis.cancel();
  }
  if (currentSpeakingBtn) {
    currentSpeakingBtn.classList.remove('speaking');
    const span = currentSpeakingBtn.querySelector('span');
    if (span) span.textContent = 'Read';
    currentSpeakingBtn = null;
  }
}

function getCleanSpeechText(rawText) {
  if (!rawText) return '';
  const tmp = document.createElement('div');
  tmp.innerHTML = rawText;
  return (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
}

function speakText(rawText, lang, btnElement) {
  if (!('speechSynthesis' in window)) return;

  // Toggle stop if already speaking this bubble
  if (currentSpeakingBtn === btnElement && window.speechSynthesis.speaking) {
    stopAiSpeech();
    return;
  }

  stopAiSpeech();

  // If globally muted and triggered automatically, do not speak
  if (isAiVoiceMuted && !btnElement) {
    return;
  }

  const clean = getCleanSpeechText(rawText);
  if (!clean) return;

  const utter = new SpeechSynthesisUtterance(clean);
  utter.lang = lang === 'english' ? 'en-US' : 'fil-PH';
  utter.rate = 0.95;
  utter.pitch = 1.0;

  if (btnElement) {
    currentSpeakingBtn = btnElement;
    btnElement.classList.add('speaking');
    const span = btnElement.querySelector('span');
    if (span) span.textContent = 'Stop';
  }

  utter.onend = () => {
    if (btnElement) {
      btnElement.classList.remove('speaking');
      const span = btnElement.querySelector('span');
      if (span) span.textContent = 'Read';
    }
    if (currentSpeakingBtn === btnElement) currentSpeakingBtn = null;
  };

  utter.onerror = () => {
    if (btnElement) {
      btnElement.classList.remove('speaking');
      const span = btnElement.querySelector('span');
      if (span) span.textContent = 'Read';
    }
    if (currentSpeakingBtn === btnElement) currentSpeakingBtn = null;
  };

  window.speechSynthesis.speak(utter);
}

function toggleSpeechBubble(btn) {
  const bubble = btn.closest('.chat-bubble');
  const contentEl = bubble ? bubble.querySelector('.chat-text-content') : btn.previousElementSibling;
  const text = contentEl ? (contentEl.innerHTML || contentEl.textContent) : '';
  const lang = (bubble && bubble.getAttribute('data-lang')) || 'tagalog';
  speakText(text, lang, btn);
}

function formatAiMessage(rawText) {
  if (!rawText) return '';
  let text = String(rawText).trim();

  // Escape basic HTML safe characters
  text = text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

  // Bold formatting: **bold** or __bold__
  text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  text = text.replace(/__(.+?)__/g, '<strong>$1</strong>');

  // Italic formatting: *italic*
  text = text.replace(/(^|[^\*])\*([^\*]+)\*([^\*]|$)/g, '$1<em>$2</em>$3');

  // Headers
  text = text.replace(/^###\s+(.+)$/gm, '<h4>$1</h4>');
  text = text.replace(/^##\s+(.+)$/gm, '<h4>$1</h4>');
  text = text.replace(/^#\s+(.+)$/gm, '<h3>$1</h3>');

  // Break into lines to parse paragraphs, bullet lists, and numbered lists
  const lines = text.split(/\r?\n/);
  let html = '';
  let inUl = false;
  let inOl = false;
  let currentP = [];

  const flushP = () => {
    if (currentP.length > 0) {
      const pJoined = currentP.join('<br>');
      if (pJoined.trim()) {
        html += '<p>' + pJoined + '</p>';
      }
      currentP = [];
    }
  };

  const closeLists = () => {
    if (inUl) { html += '</ul>'; inUl = false; }
    if (inOl) { html += '</ol>'; inOl = false; }
  };

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i].trim();

    if (!line) {
      flushP();
      closeLists();
      continue;
    }

    if (line.startsWith('<h4>') || line.startsWith('<h3>')) {
      flushP();
      closeLists();
      html += line;
      continue;
    }

    // Bullet points: *, -, •
    const bulletMatch = line.match(/^[\*\-•]\s+(.*)$/);
    if (bulletMatch) {
      flushP();
      if (inOl) { html += '</ol>'; inOl = false; }
      if (!inUl) { html += '<ul>'; inUl = true; }
      html += '<li>' + bulletMatch[1] + '</li>';
      continue;
    }

    // Numbered lists: 1. or 1)
    const numberMatch = line.match(/^(\d+)[\.\)]\s+(.*)$/);
    if (numberMatch) {
      flushP();
      if (inUl) { html += '</ul>'; inUl = false; }
      if (!inOl) { html += '<ol>'; inOl = true; }
      html += '<li>' + numberMatch[2] + '</li>';
      continue;
    }

    // Normal paragraph line
    closeLists();
    currentP.push(line);
  }

  flushP();
  closeLists();

  return html || ('<p>' + text + '</p>');
}

async function sendMessage() {
  const input = document.getElementById('chatInput');
  if (!input) return;
  const msg = input.value.trim();
  if (!msg) return;

  const container = document.getElementById('chatMessages');
  if (!container) return;

  const uiLang = (document.getElementById('uiLanguage') || {}).value || 'tagalog';
  const language = detectLanguage(msg, uiLang);

  const userBubble = document.createElement('div');
  userBubble.className = 'chat-bubble user fade-in';
  userBubble.setAttribute('data-lang', language);
  userBubble.innerHTML = '<p>' + escapeHtml(msg).replace(/\n/g, '<br>') + '</p>';
  container.appendChild(userBubble);
  input.value = '';
  container.scrollTop = container.scrollHeight;

  await ensureCsrfCookie();
  fetch(apiUrl('/consultation/send'), {
    method: 'POST',
    credentials: 'include',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify({ message: msg, language: language }),
  })
    .then(r => r.json())
    .then(data => {
      let reply = 'Salamat sa iyong tanong! Para sa agarang lunas, sundin ang rekomendasyon sa Treatment Guide.';
      let replyLang = language;
      if (data && data.success) {
        reply = data.ai_response || reply;
        replyLang = data.language || language;
      }
      appendAIBubble(reply, replyLang, container);
      const voiceSetting = (document.getElementById('voiceSetting') || {}).value;
      if (voiceSetting !== 'manual' && !isAiVoiceMuted) {
        speakText(reply, replyLang);
      }
    })
    .catch(() => {
      const fallback = 'Inirerekomenda ko ang pagkonsulta sa inyong Municipal Agriculture Office para sa tamang paggamit ng fungicide at pestisidyo.';
      appendAIBubble(fallback, language, container);
    });
}

function appendAIBubble(content, language, container) {
  const currentLang = language || 'tagalog';
  const targetLang = (currentLang.toLowerCase() === 'tagalog') ? 'english' : 'tagalog';
  const targetLabel = (targetLang === 'english') ? 'Translate to English' : 'Translate to Tagalog';

  const aiBubble = document.createElement('div');
  aiBubble.className = 'chat-bubble ai fade-in';
  aiBubble.setAttribute('data-lang', currentLang);
  aiBubble.setAttribute('data-raw-content', content);
  aiBubble.innerHTML =
    '<div class="chat-text-content">' + formatAiMessage(content) + '</div>' +
    '<div class="chat-bubble-footer">' +
      '<button type="button" class="translate-bubble-btn" onclick="translateAiBubble(this)" title="Translate message">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M5 8l6 6"/><path d="M4 14l6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="M22 22l-5-10-5 10"/><path d="M14 18h6"/></svg>' +
        '<span>' + targetLabel + '</span>' +
      '</button>' +
      '<button type="button" class="audio-speaker-btn" onclick="toggleSpeechBubble(this)" title="Read aloud / Stop">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>' +
        '<span>Read</span>' +
      '</button>' +
    '</div>';
  container.appendChild(aiBubble);
  container.scrollTop = container.scrollHeight;
}

async function translateAiBubble(btn) {
  const bubble = btn.closest('.chat-bubble.ai');
  if (!bubble) return;
  const contentEl = bubble.querySelector('.chat-text-content');
  if (!contentEl) return;

  const currentLang = (bubble.getAttribute('data-lang') || 'tagalog').toLowerCase();
  const targetLang = (currentLang === 'tagalog') ? 'english' : 'tagalog';

  const rawContent = bubble.getAttribute('data-raw-content') || contentEl.innerText || contentEl.textContent;
  if (!rawContent || !rawContent.trim()) return;

  // Set loading state on button
  btn.disabled = true;
  btn.classList.add('translating');
  const labelSpan = btn.querySelector('span');
  const originalLabel = labelSpan ? labelSpan.textContent : '';
  if (labelSpan) labelSpan.textContent = 'Translating...';

  try {
    await ensureCsrfCookie();
    const res = await fetch(apiUrl('/consultation/translate'), {
      method: 'POST',
      credentials: 'include',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({
        text: rawContent,
        target_language: targetLang
      })
    });

    const data = await res.json();
    if (data && data.success && data.translated_text) {
      // Update bubble content and speech language
      contentEl.innerHTML = formatAiMessage(data.translated_text);
      bubble.setAttribute('data-lang', targetLang);
      bubble.setAttribute('data-raw-content', data.translated_text);

      // Flip button label to the other language
      const newTargetLang = (targetLang === 'english') ? 'tagalog' : 'english';
      const newLabel = (newTargetLang === 'english') ? 'Translate to English' : 'Translate to Tagalog';
      if (labelSpan) labelSpan.textContent = newLabel;
    } else {
      alert('Hindi ma-proseso ang translation sa ngayon. Pakisubukan muli.');
      if (labelSpan) labelSpan.textContent = originalLabel;
    }
  } catch (err) {
    console.error('Translation error:', err);
    alert('Translation error. Please check your internet connection.');
    if (labelSpan) labelSpan.textContent = originalLabel;
  } finally {
    btn.disabled = false;
    btn.classList.remove('translating');
  }
}

function detectLanguage(text, defaultLang) {
  const t = text.toLowerCase();
  if (/hello|hi|what|how|why|when|where|please|thank|yes|no|rice|disease|treatment|fungicide|dosage/.test(t)) return 'english';
  return defaultLang || 'tagalog';
}

function languageLabel(lang) {
  if (lang === 'english') return 'English';
  return 'Tagalog';
}

async function loadChatMessages() {
  await ensureCsrfCookie();
  fetch(apiUrl('/consultation/messages'), {
    credentials: 'include',
    headers: authHeaders(),
  })
    .then(r => r.json())
    .then(data => {
      if (!data || !data.success || !data.messages || !data.messages.length) return;
      const container = document.getElementById('chatMessages');
      if (!container) return;
      const keep = container.querySelector('.chat-time');
      container.innerHTML = '';
      if (keep) container.appendChild(keep.cloneNode(true));

      data.messages.forEach(m => {
        if (m.role === 'user') {
          const u = document.createElement('div');
          u.className = 'chat-bubble user fade-in';
          u.setAttribute('data-lang', m.language || 'tagalog');
          u.innerHTML = '<p>' + escapeHtml(m.content).replace(/\n/g, '<br>') + '</p>';
          container.appendChild(u);
        } else {
          appendAIBubble(m.content, m.language || 'tagalog', container);
        }
      });
      container.scrollTop = container.scrollHeight;
    })
    .catch(() => {});
}

function toggleVoiceRecognition() {
  const btn = document.getElementById('voiceBtn');
  if (!btn) return;
  const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SpeechRec) {
    alert('Voice recognition is not supported in this browser. Please use Chrome or Edge.');
    return;
  }

  if (!isRecording) {
    speechRecognition = new SpeechRec();
    const uiLang = (document.getElementById('uiLanguage') || {}).value || 'english';
    speechRecognition.lang = uiLang === 'tagalog' ? 'fil-PH' : 'en-US';
    speechRecognition.continuous = false;
    speechRecognition.interimResults = false;

    speechRecognition.onstart = () => {
      isRecording = true;
      btn.classList.add('recording');
      const ci = document.getElementById('chatInput');
      if (ci) ci.placeholder = 'Listening... Speak now';
    };

    speechRecognition.onresult = (event) => {
      const transcript = event.results[0][0].transcript;
      const ci = document.getElementById('chatInput');
      if (ci) ci.value = transcript;
      sendMessage();
    };

    speechRecognition.onerror = () => {
      isRecording = false;
      btn.classList.remove('recording');
    };

    speechRecognition.onend = () => {
      isRecording = false;
      btn.classList.remove('recording');
      const ci = document.getElementById('chatInput');
      if (ci) ci.placeholder = 'Ask about rice diseases...';
    };

    try { speechRecognition.start(); } catch (e) {}
  } else {
    if (speechRecognition) {
      try { speechRecognition.stop(); } catch(e) {}
    }
    isRecording = false;
    btn.classList.remove('recording');
  }
}

function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str).replace(/[&<>"']/g, function (c) {
    switch (c) {
      case '&': return '&amp;';
      case '<': return '&lt;';
      case '>': return '&gt;';
      case '"': return '&quot;';
      case "'": return '&#39;';
    }
    return c;
  });
}

/* ═══════════ ADMIN EXECUTIVE DASHBOARD ═══════════ */
async function loadAdminDashboard() {
  await ensureCsrfCookie();
  fetch(apiUrl('/admin/overview'), {
    credentials: 'include',
    headers: authHeaders(),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success && data.data) {
        const d = data.data;
        const stats = d.stats || {};
        const dist = d.disease_distribution || {};
        const telemetry = d.system_telemetry || {};

        // KPIs
        const uTot = document.getElementById('adminDashTotalUsers');
        if (uTot) uTot.textContent = stats.total_users || 0;
        const uBrk = document.getElementById('adminDashUserBreakdown');
        if (uBrk) uBrk.textContent = `${stats.total_farmers || 0} Farmers · ${stats.total_staff || 0} Staff · ${stats.total_admins || 0} Admin`;

        const sTot = document.getElementById('adminDashTotalScans');
        if (sTot) sTot.textContent = stats.total_scans || 0;
        const sToday = document.getElementById('adminDashTodayScans');
        if (sToday) sToday.textContent = stats.today_scans || 0;

        const outCount = document.getElementById('adminDashOutbreakCount');
        if (outCount) outCount.textContent = stats.active_outbreaks || 0;
        const outPct = document.getElementById('adminDashOutbreakPercent');
        if (outPct) {
          const pct = stats.total_scans > 0 ? Math.round((stats.active_outbreaks / stats.total_scans) * 100) : 0;
          outPct.textContent = `${pct}% of total scans`;
        }

        const advCount = document.getElementById('adminDashAdvisoriesCount');
        if (advCount) advCount.textContent = stats.advisories_count || 0;
        const advCov = document.getElementById('adminDashAdvisoryCoverage');
        if (advCov) advCov.textContent = `${stats.advisories_count || 0} official advisories recorded`;

        // Telemetry Time
        const sTime = document.getElementById('adminServerTime');
        if (sTime) sTime.textContent = telemetry.server_time || new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        // Disease Meters
        setMeterData('adminDistBlast', dist.blast);
        setMeterData('adminDistBlb', dist.blb);
        setMeterData('adminDistTungro', dist.tungro);
        setMeterData('adminDistBrownSpot', dist.brown_spot);
        setMeterData('adminDistHealthy', dist.healthy);

        // Locations Table
        renderAdminLocationsTable(d.locations || []);

        // Recent Scans
        renderAdminRecentScansTable(d.recent_scans || []);

        // Staff Roster
        renderAdminStaffRoster(d.staff_roster || []);
      } else {
        renderAdminEmpty('adminLocationsBody', 'Failed to load telemetry.');
        renderAdminEmpty('adminRecentScansTableBody', 'Failed to load telemetry.');
      }
    })
    .catch(() => {
      renderAdminEmpty('adminLocationsBody', 'Server unavailable.');
      renderAdminEmpty('adminRecentScansTableBody', 'Server unavailable.');
    });
}

function setMeterData(prefix, info) {
  if (!info) return;
  const countEl = document.getElementById(prefix + 'Count');
  if (countEl) countEl.textContent = `${info.count || 0} scans`;
  const pctEl = document.getElementById(prefix + 'Percent');
  if (pctEl) pctEl.textContent = `${info.percent || 0}%`;
  const barEl = document.getElementById(prefix + 'Bar');
  if (barEl) barEl.style.width = `${Math.min(100, info.percent || 0)}%`;
}

function renderAdminLocationsTable(locations) {
  const tbody = document.getElementById('adminLocationsBody');
  if (!tbody) return;

  if (!locations || locations.length === 0) {
    tbody.innerHTML = '<tr><td colspan="4" class="empty-table-msg">No location records yet.</td></tr>';
    return;
  }

  tbody.innerHTML = locations.map(loc => {
    const total = loc.total_scans || 0;
    const outbreaks = loc.outbreaks || 0;
    const healthy = loc.healthy || 0;
    const healthyPct = total > 0 ? Math.round((healthy / total) * 100) : 100;
    const isOutbreakRisk = outbreaks > 0;

    return `
      <tr>
        <td><strong>${escapeHtml(loc.location)}</strong></td>
        <td><span class="scan-count-badge">${total} scans</span></td>
        <td>
          <span class="badge-pill" style="${isOutbreakRisk ? 'background: var(--red-100); color: var(--red-700);' : 'background: var(--green-100); color: var(--brand-green);'}">
            ${outbreaks} ${outbreaks === 1 ? 'Alert' : 'Alerts'}
          </span>
        </td>
        <td>
          <strong style="color: ${healthyPct >= 70 ? 'var(--brand-green)' : (healthyPct >= 40 ? 'var(--amber-700)' : 'var(--red-600)')}">
            ${healthyPct}%
          </strong>
        </td>
      </tr>
    `;
  }).join('');
}

function renderAdminRecentScansTable(scans) {
  const tbody = document.getElementById('adminRecentScansTableBody');
  if (!tbody) return;

  if (!scans || scans.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty-table-msg">No disease scans recorded in system yet.</td></tr>';
    return;
  }

  tbody.innerHTML = scans.map(s => {
    const isHealthy = s.severity === 'healthy';
    const sevBadge = isHealthy
      ? '<span class="badge-pill" style="background:var(--green-100); color:var(--brand-green);">Healthy</span>'
      : (s.severity === 'severe'
        ? '<span class="badge-pill" style="background:var(--red-100); color:var(--red-700);">Severe (>60%)</span>'
        : (s.severity === 'mild'
          ? '<span class="badge-pill" style="background:var(--green-100); color:var(--brand-green);">Mild (≤25%)</span>'
          : '<span class="badge-pill" style="background:var(--amber-100); color:var(--amber-800);">Moderate (26-60%)</span>'));

    const thumbHtml = s.image_url
      ? `<img src="${s.image_url}" alt="Leaf Specimen" style="width: 38px; height: 38px; border-radius: 8px; object-fit: cover; border: 1px solid var(--neutral-200); cursor: pointer;" onclick="openAdminScanPreview(${s.id})">`
      : `<div style="width: 38px; height: 38px; border-radius: 8px; background: var(--neutral-100); display: flex; align-items: center; justify-content: center; color: var(--neutral-400); font-size: 16px;">🌿</div>`;

    return `
      <tr>
        <td style="width: 65px;">
          ${thumbHtml}
        </td>
        <td>
          <div style="font-weight: 700; color: var(--neutral-800);">${escapeHtml(s.farmer_name)}</div>
          <div style="font-size: 11.5px; color: var(--neutral-500);">${escapeHtml(s.location)}</div>
        </td>
        <td>
          <div style="font-weight: 700; color: var(--brand-green);">${escapeHtml(s.disease_name)}</div>
          <div style="font-size: 10.5px; font-style: italic; color: var(--neutral-500);">${escapeHtml(s.scientific_name)}</div>
        </td>
        <td>
          <strong>${s.confidence ? s.confidence.toFixed(1) + '%' : 'N/A'}</strong>
        </td>
        <td>${sevBadge}</td>
        <td><span style="font-size: 12px; color: var(--neutral-500);">${escapeHtml(s.created_at)}</span></td>
        <td style="text-align: center;">
          <button class="action-icon-btn" onclick="openAdminScanPreview(${s.id})" title="View Details" style="color:var(--brand-green); margin: 0 auto;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          </button>
        </td>
      </tr>
    `;
  }).join('');
}

function renderAdminStaffRoster(staff) {
  const tbody = document.getElementById('adminStaffRosterBody');
  if (!tbody) return;

  if (!staff || staff.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="empty-table-msg">No extension staff registered yet.</td></tr>';
    return;
  }

  tbody.innerHTML = staff.map(st => {
    const initials = (st.name || 'S').split(' ').map(n => n[0]).join('').slice(0,2).toUpperCase();
    return `
      <tr>
        <td>
          <div class="user-cell">
            <div class="u-avatar" style="background: var(--blue-100); color: var(--blue-700);">${initials}</div>
            <div class="u-info">
              <div class="u-name">${escapeHtml(st.name)}</div>
              <span class="badge-pill" style="background: var(--blue-50); color: var(--blue-700); font-size: 10.5px;">Agri Extension Officer</span>
            </div>
          </div>
        </td>
        <td><span style="font-size: 13px; color: var(--neutral-700);">${escapeHtml(st.email)}</span></td>
        <td><strong>${escapeHtml(st.location)}</strong></td>
        <td><span style="font-size: 12px; color: var(--neutral-500);">${escapeHtml(st.joined)}</span></td>
        <td style="text-align: right;">
          <button class="action-icon-btn edit" onclick="showScreen('admin-users'); switchAdminTab('staff');" title="Manage Staff Profile">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </button>
        </td>
      </tr>
    `;
  }).join('');
}

/* ═══════════ ADMIN USER MANAGEMENT ═══════════ */
/* ═══════════ ADMIN USER MANAGEMENT ═══════════ */
let adminAllUsersList = [];
let adminFarmersList = [];
let adminStaffList = [];
let activeAdminRoleFilter = 'all';

async function loadAdminUsers() {
  await ensureCsrfCookie();
  fetch(apiUrl('/admin/users'), {
    credentials: 'include',
    headers: authHeaders(),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success && data.data) {
        adminAllUsersList = data.data.users || [];
        adminFarmersList = data.data.farmers || [];
        adminStaffList = data.data.staff || [];
        const stats = data.data.stats || {};

        const sf = document.getElementById('adminStatFarmers');
        if (sf) sf.textContent = stats.total_farmers || adminFarmersList.length;
        const ss = document.getElementById('adminStatStaff');
        if (ss) ss.textContent = stats.total_staff || adminStaffList.length;
        const st = document.getElementById('adminStatTotal');
        if (st) st.textContent = stats.total_users || adminAllUsersList.length;
        const sc = document.getElementById('adminStatScans');
        if (sc) sc.textContent = stats.total_scans || 0;

        const tfc = document.getElementById('adminTabFarmersCount');
        if (tfc) tfc.textContent = stats.total_farmers || adminFarmersList.length;
        const tsc = document.getElementById('adminTabStaffCount');
        if (tsc) tsc.textContent = stats.total_staff || adminStaffList.length;
        const tac = document.getElementById('adminTabAdminsCount');
        if (tac) tac.textContent = stats.total_admins || 0;
        const tAll = document.getElementById('adminTabAllUsersCount');
        if (tAll) tAll.textContent = stats.total_users || adminAllUsersList.length;

        filterAdminUsersList();
      } else {
        renderAdminEmpty('adminUsersTableBody', data.message || 'Failed to load user accounts.');
      }
    })
    .catch(() => {
      renderAdminEmpty('adminUsersTableBody', 'Unable to connect to server.');
    });
}

function switchAdminUsersTab(role) {
  activeAdminRoleFilter = role;
  const tabs = ['all', 'farmer', 'agri_worker', 'admin'];
  tabs.forEach(t => {
    const idMap = {
      all: 'adminTabAllUsersBtn',
      farmer: 'adminTabFarmersBtn',
      agri_worker: 'adminTabStaffBtn',
      admin: 'adminTabAdminsBtn',
    };
    const btn = document.getElementById(idMap[t]);
    if (btn) btn.classList.toggle('active', t === role);
  });
  filterAdminUsersList();
}

function filterAdminUsersList() {
  const query = (document.getElementById('adminSearchInput')?.value || '').toLowerCase().trim();
  let list = adminAllUsersList;
  if (activeAdminRoleFilter !== 'all') {
    list = list.filter(u => u.role === activeAdminRoleFilter);
  }
  if (query) {
    list = list.filter(u =>
      (u.name || '').toLowerCase().includes(query) ||
      (u.email || '').toLowerCase().includes(query) ||
      (u.location || '').toLowerCase().includes(query) ||
      (u.role_label || '').toLowerCase().includes(query)
    );
  }
  renderAdminUsersTable(list);
}

function renderAdminUsersTable(users) {
  const tbody = document.getElementById('adminUsersTableBody');
  if (!tbody) return;

  if (!users || users.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty-table-msg">No user accounts found matching current filter.</td></tr>';
    return;
  }

  tbody.innerHTML = users.map(u => {
    const initials = (u.name || 'U').split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase();
    const roleBadgeClass = u.role === 'admin' ? 'admin' : (u.role === 'agri_worker' ? 'agri_worker' : 'farmer');
    const avatarContent = u.avatar_url
      ? `<img src="${u.avatar_url}" alt="${escapeHtml(u.name)}" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">`
      : initials;

    return `
      <tr>
        <td style="width: 55px;">
          <div class="u-avatar" style="background: ${u.role === 'admin' ? '#d97706' : (u.role === 'agri_worker' ? '#0284c7' : '#166534')};">${avatarContent}</div>
        </td>
        <td>
          <div class="user-cell">
            <div class="u-info">
              <div class="u-name">${escapeHtml(u.name)}</div>
              <div class="u-email">${escapeHtml(u.email)}</div>
            </div>
          </div>
        </td>
        <td><span class="role-badge ${roleBadgeClass}">${escapeHtml(u.role_label)}</span></td>
        <td><strong>${escapeHtml(u.location)}</strong></td>
        <td>
          <button class="scan-count-badge" onclick="openAdminUserScansModal(${u.id}, '${escapeHtml(u.name)}')" title="View All User Detections" style="cursor: pointer; border: none;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            ${u.scans_count || 0} scans
          </button>
        </td>
        <td><span style="color: var(--neutral-500); font-size: 12px;">${escapeHtml(u.created_at)}</span></td>
        <td>
          <div class="action-btns-group" style="justify-content: flex-end;">
            <button class="action-icon-btn edit" onclick="openAdminEditModal(${JSON.stringify(u).replace(/"/g, '&quot;')})" title="Edit Account">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>
            <button class="action-icon-btn delete" onclick="handleAdminDeleteUser(${u.id}, '${escapeHtml(u.name)}')" title="Delete Account">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function renderAdminEmpty(tbodyId, msg) {
  const tbody = document.getElementById(tbodyId);
  if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="empty-table-msg">${escapeHtml(msg)}</td></tr>`;
}

async function openAdminUserScansModal(userId, userName) {
  const titleEl = document.getElementById('adminUserScansModalTitle');
  const subEl = document.getElementById('adminUserScansModalSubtitle');
  const tbody = document.getElementById('adminUserScansTableBody');

  if (titleEl) titleEl.textContent = `${userName}'s Detection History`;
  if (subEl) subEl.textContent = `All rice leaf scans submitted by User #${userId}`;
  if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-table-msg">Loading user scan history...</td></tr>';

  openModal('modalAdminUserScans');

  await ensureCsrfCookie();
  fetch(apiUrl(`/admin/users/${userId}/scans`), {
    credentials: 'include',
    headers: authHeaders(),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success && data.data && data.data.scans) {
        const scans = data.data.scans;
        if (scans.length === 0) {
          tbody.innerHTML = '<tr><td colspan="5" class="empty-table-msg">This user has not submitted any leaf scans yet.</td></tr>';
          return;
        }
        tbody.innerHTML = scans.map(s => {
          const imgThumb = s.image_url
            ? `<img src="${s.image_url}" alt="Leaf" style="width:38px; height:38px; border-radius:6px; object-fit:cover; border:1px solid var(--neutral-200);">`
            : `<div style="width:38px; height:38px; border-radius:6px; background:var(--neutral-100); display:flex; align-items:center; justify-content:center; color:var(--neutral-400);">🌿</div>`;
          const isHealthy = s.severity === 'healthy';
          const sevBadge = isHealthy
            ? '<span class="badge-pill" style="background:var(--green-100); color:var(--brand-green);">Healthy</span>'
            : (s.severity === 'severe'
              ? '<span class="badge-pill" style="background:var(--red-100); color:var(--red-700);">Severe</span>'
              : (s.severity === 'mild'
                ? '<span class="badge-pill" style="background:var(--green-100); color:var(--brand-green);">Mild</span>'
                : '<span class="badge-pill" style="background:var(--amber-100); color:var(--amber-800);">Moderate</span>'));

          return `
            <tr>
              <td>${imgThumb}</td>
              <td><strong>${escapeHtml(s.disease_name)}</strong></td>
              <td><strong>${s.confidence ? s.confidence.toFixed(1) + '%' : 'N/A'}</strong></td>
              <td>${sevBadge}</td>
              <td><span style="font-size:12px; color:var(--neutral-500);">${escapeHtml(s.created_at)}</span></td>
            </tr>
          `;
        }).join('');
      } else {
        if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-table-msg">Unable to load scan records.</td></tr>';
      }
    })
    .catch(() => {
      if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-table-msg">Network error while fetching scans.</td></tr>';
    });
}

async function handleAdminAddUser(e) {
  e.preventDefault();
  const name = document.getElementById('adminNewName').value.trim();
  const email = document.getElementById('adminNewEmail').value.trim();
  const role = document.getElementById('adminNewRole').value;
  const location = document.getElementById('adminNewLocation').value.trim();
  const password = document.getElementById('adminNewPassword').value;
  const btn = document.getElementById('adminAddUserBtn');
  const errBanner = document.getElementById('adminAddUserError');
  const okBanner = document.getElementById('adminAddUserSuccess');

  errBanner.classList.remove('show');
  okBanner.classList.remove('show');
  if (btn) { btn.disabled = true; btn.textContent = 'Creating...'; }

  await ensureCsrfCookie();
  fetch(apiUrl('/admin/users'), {
    method: 'POST',
    credentials: 'include',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify({ name, email, role, location, password }),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        okBanner.textContent = data.message || 'Account created successfully!';
        okBanner.classList.add('show');
        document.getElementById('adminAddUserForm').reset();
        loadAdminUsers();
        setTimeout(() => {
          closeModal('modalAdminAddUser');
          okBanner.classList.remove('show');
        }, 800);
      } else {
        errBanner.textContent = data.message || 'Failed to create account.';
        errBanner.classList.add('show');
      }
    })
    .catch(() => {
      errBanner.textContent = 'Network error. Please try again.';
      errBanner.classList.add('show');
    })
    .finally(() => {
      if (btn) { btn.disabled = false; btn.textContent = 'Create Account'; }
    });
}

let pendingDeleteUserId = null;

function openAdminEditModal(user) {
  if (!user) return;
  document.getElementById('adminEditUserId').value = user.id;
  document.getElementById('adminEditName').value = user.name || '';
  document.getElementById('adminEditEmail').value = user.email || '';
  document.getElementById('adminEditRole').value = user.role || 'farmer';
  document.getElementById('adminEditLocation').value = user.location === 'Not specified' ? '' : (user.location || '');
  document.getElementById('adminEditPassword').value = '';

  const initials = (user.name || 'U').split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase();
  const avatarEl = document.getElementById('adminEditUserAvatar');
  if (avatarEl) {
    if (user.avatar_url) {
      avatarEl.innerHTML = `<img src="${user.avatar_url}" alt="${escapeHtml(user.name)}" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">`;
    } else {
      avatarEl.textContent = initials;
      avatarEl.style.background = user.role === 'admin' ? '#d97706' : (user.role === 'agri_worker' ? '#0284c7' : '#166534');
    }
  }
  const nameEl = document.getElementById('adminEditUserPreviewName');
  if (nameEl) nameEl.textContent = user.name || 'User';
  const roleEl = document.getElementById('adminEditUserPreviewRole');
  if (roleEl) roleEl.textContent = `${user.role_label || user.role} · ${user.location || 'Not specified'}`;

  document.getElementById('adminEditUserError').classList.remove('show');
  document.getElementById('adminEditUserSuccess').classList.remove('show');
  openModal('modalAdminEditUser');
}

async function handleAdminEditUser(e) {
  e.preventDefault();
  const id = document.getElementById('adminEditUserId').value;
  const name = document.getElementById('adminEditName').value.trim();
  const email = document.getElementById('adminEditEmail').value.trim();
  const role = document.getElementById('adminEditRole').value;
  const location = document.getElementById('adminEditLocation').value.trim();
  const password = document.getElementById('adminEditPassword').value;
  const btn = document.getElementById('adminEditUserBtn');
  const errBanner = document.getElementById('adminEditUserError');
  const okBanner = document.getElementById('adminEditUserSuccess');

  errBanner.classList.remove('show');
  okBanner.classList.remove('show');
  if (btn) { btn.disabled = true; btn.textContent = 'Updating...'; }

  const payload = { name, email, role, location };
  if (password) payload.password = password;

  await ensureCsrfCookie();
  fetch(apiUrl(`/admin/users/${id}`), {
    method: 'PUT',
    credentials: 'include',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify(payload),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        okBanner.textContent = data.message || 'Account updated successfully!';
        okBanner.classList.add('show');
        loadAdminUsers();
        setTimeout(() => {
          closeModal('modalAdminEditUser');
          okBanner.classList.remove('show');
        }, 800);
      } else {
        errBanner.textContent = data.message || 'Failed to update account.';
        errBanner.classList.add('show');
      }
    })
    .catch(() => {
      errBanner.textContent = 'Network error. Please try again.';
      errBanner.classList.add('show');
    })
    .finally(() => {
      if (btn) { btn.disabled = false; btn.textContent = 'Update Account'; }
    });
}

function handleAdminDeleteUser(id, name) {
  pendingDeleteUserId = id;
  const nameEl = document.getElementById('adminDeleteUserName');
  if (nameEl) nameEl.textContent = name || 'this user';
  const errBanner = document.getElementById('adminDeleteUserError');
  if (errBanner) errBanner.classList.remove('show');
  openModal('modalAdminDeleteUser');
}

async function confirmAdminDeleteUser() {
  if (!pendingDeleteUserId) return;
  const btn = document.getElementById('confirmDeleteUserBtn');
  const errBanner = document.getElementById('adminDeleteUserError');

  if (btn) { btn.disabled = true; btn.textContent = 'Deleting...'; }
  if (errBanner) errBanner.classList.remove('show');

  await ensureCsrfCookie();
  fetch(apiUrl(`/admin/users/${pendingDeleteUserId}`), {
    method: 'DELETE',
    credentials: 'include',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        closeModal('modalAdminDeleteUser');
        pendingDeleteUserId = null;
        loadAdminUsers();
      } else {
        if (errBanner) {
          errBanner.textContent = data.message || 'Failed to delete account.';
          errBanner.classList.add('show');
        }
      }
    })
    .catch(() => {
      if (errBanner) {
        errBanner.textContent = 'Network error. Please try again.';
        errBanner.classList.add('show');
      }
    })
    .finally(() => {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = `
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
          </svg>
          <span>Yes, Delete Account</span>
        `;
      }
    });
}

/* ═══════════ ADMIN DISEASE MANAGEMENT ═══════════ */
let adminDiseasesList = [];
let activeDiseaseFilter = 'all';
let pendingDeleteDiseaseId = null;

async function loadAdminDiseases() {
  const container = document.getElementById('adminDiseasesGrid');
  if (container) container.innerHTML = '<div class="empty-table-msg">Loading rice disease records...</div>';

  await ensureCsrfCookie();
  fetch(apiUrl('/admin/diseases'), {
    credentials: 'include',
    headers: authHeaders(),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success && data.data) {
        adminDiseasesList = data.data.diseases || [];
        
        const countAll = document.getElementById('adminDiseasesCountAll');
        if (countAll) countAll.textContent = adminDiseasesList.length;

        const activeCount = adminDiseasesList.filter(d => d.status === 'active' || d.is_active).length;
        const inactiveCount = adminDiseasesList.length - activeCount;

        const tabActive = document.getElementById('adminTabDiseaseActive');
        if (tabActive) tabActive.innerHTML = `<span>Active (${activeCount})</span>`;

        const tabInactive = document.getElementById('adminTabDiseaseInactive');
        if (tabInactive) tabInactive.innerHTML = `<span>Inactive (${inactiveCount})</span>`;

        renderAdminDiseasesGrid();
      } else {
        if (container) container.innerHTML = '<div class="empty-table-msg">Failed to load disease library.</div>';
      }
    })
    .catch(() => {
      if (container) container.innerHTML = '<div class="empty-table-msg">Network error while fetching diseases.</div>';
    });
}

function filterAdminDiseases(status, btnEl) {
  activeDiseaseFilter = status;
  document.querySelectorAll('#admin-diseases .admin-tab-btn').forEach(b => b.classList.remove('active'));
  if (btnEl) btnEl.classList.add('active');
  renderAdminDiseasesGrid();
}

function renderAdminDiseasesGrid() {
  const container = document.getElementById('adminDiseasesGrid');
  if (!container) return;

  let list = adminDiseasesList;
  if (activeDiseaseFilter === 'active') {
    list = list.filter(d => d.status === 'active' || d.is_active);
  } else if (activeDiseaseFilter === 'inactive') {
    list = list.filter(d => d.status !== 'active' && !d.is_active);
  }

  if (list.length === 0) {
    container.innerHTML = '<div class="empty-table-msg" style="grid-column: 1 / -1; padding: 40px 20px; text-align: center; color: var(--neutral-500); background: #fff; border-radius: 12px; border: 1px dashed var(--neutral-300);">No disease records match this filter. Click <strong>"Add Disease Info"</strong> to add a new disease.</div>';
    return;
  }

  container.innerHTML = list.map(d => {
    const isActive = d.status === 'active' || d.is_active;
    const imgHtml = d.image_url
      ? `<img src="${d.image_url}" alt="${escapeHtml(d.name)}" style="width:100%; height:160px; object-fit:cover; border-radius:10px 10px 0 0; cursor:pointer;" onclick="openAdminViewDiseaseModal(${d.id})">`
      : `<div style="width:100%; height:130px; background:var(--neutral-100); display:flex; align-items:center; justify-content:center; color:var(--neutral-400); font-size:28px; border-radius:10px 10px 0 0; cursor:pointer;" onclick="openAdminViewDiseaseModal(${d.id})">🌾</div>`;

    const treatmentPreview = d.recommended_treatment || d.treatment || 'N/A';

    return `
      <div class="admin-disease-card" style="background:#fff; border:1px solid var(--neutral-200); border-radius:12px; overflow:hidden; display:flex; flex-direction:column; box-shadow:var(--shadow-sm); transition: transform 0.2s, box-shadow 0.2s;">
        ${imgHtml}
        <div style="padding:16px; flex:1; display:flex; flex-direction:column;">
          <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px;">
            <div style="cursor:pointer;" onclick="openAdminViewDiseaseModal(${d.id})">
              <h3 style="margin:0; font-size:16px; font-weight:800; color:var(--neutral-900);">${escapeHtml(d.name)}</h3>
              <div style="font-size:12px; font-style:italic; color:var(--neutral-500); margin-top:2px;">${escapeHtml(d.scientific_name || '')}</div>
            </div>
            <span class="badge-pill" style="${isActive ? 'background:var(--green-100); color:var(--brand-green); font-weight:700;' : 'background:var(--neutral-100); color:var(--neutral-500);'}">
              ${isActive ? 'Active' : 'Inactive'}
            </span>
          </div>

          <p style="font-size:12.5px; color:var(--neutral-600); line-height:1.5; margin:8px 0; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;">
            ${escapeHtml(d.description || 'No description provided.')}
          </p>

          <div style="background:var(--neutral-50); border:1px solid var(--neutral-200); border-radius:8px; padding:10px; margin-top:auto; font-size:12px;">
            <div style="margin-bottom:4px;"><strong style="color:var(--brand-green);">🩺 Treatment:</strong> <span style="color:var(--neutral-700);">${escapeHtml(treatmentPreview.length > 90 ? treatmentPreview.slice(0, 90) + '...' : treatmentPreview)}</span></div>
          </div>

          <div style="display:flex; justify-content:space-between; align-items:center; margin-top:14px; padding-top:12px; border-top:1px solid var(--neutral-100);">
            <div style="display:flex; gap:6px;">
              <button class="panel-action-btn" onclick="openAdminViewDiseaseModal(${d.id})" style="font-size:11.5px; padding:4px 8px; background:var(--neutral-100); color:var(--neutral-700);">
                View Details
              </button>
              <button class="panel-action-btn" onclick="toggleAdminDiseaseStatus(${d.id})" style="font-size:11.5px; padding:4px 8px;">
                ${isActive ? 'Deactivate' : 'Activate'}
              </button>
            </div>
            <div style="display:flex; gap:6px;">
              <button class="action-icon-btn edit" onclick="openAdminEditDiseaseModalById(${d.id})" title="Edit Disease">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>
              <button class="action-icon-btn delete" onclick="handleAdminDeleteDisease(${d.id}, '${escapeHtml(d.name).replace(/'/g, "\\'")}')" title="Delete Disease">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

function openAdminViewDiseaseModal(id) {
  const d = adminDiseasesList.find(item => item.id == id);
  if (!d) return;

  const titleEl = document.getElementById('adminViewDiseaseTitle');
  const sciEl = document.getElementById('adminViewDiseaseSciTitle');
  const contentEl = document.getElementById('adminViewDiseaseContent');

  if (titleEl) titleEl.textContent = d.name;
  if (sciEl) sciEl.textContent = d.scientific_name ? `Scientific: ${d.scientific_name}` : 'Rice Pathogen';

  const isActive = d.status === 'active' || d.is_active;
  const imgHtml = d.image_url
    ? `<img src="${d.image_url}" alt="${escapeHtml(d.name)}" style="width:100%; max-height:220px; object-fit:cover; border-radius:10px; margin-bottom:14px; border:1px solid var(--neutral-200);">`
    : '';

  let chemHtml = '';
  if (Array.isArray(d.chemical_treatments) && d.chemical_treatments.length > 0) {
    chemHtml = `
      <div style="margin-top:10px;">
        <h5 style="margin:0 0 6px 0; font-size:13px; color:var(--blue-700); font-weight:700;">🧪 Chemical Fungicides / Bactericides:</h5>
        <ul style="margin:0; padding-left:18px; font-size:12.5px; color:var(--neutral-700); line-height:1.6;">
          ${d.chemical_treatments.map(c => `<li><strong>${escapeHtml(c.title || c.name || '')}</strong>: ${escapeHtml(c.rate || c.dosage || '')} <em>(${escapeHtml(c.timing || '')})</em></li>`).join('')}
        </ul>
      </div>
    `;
  }

  let orgHtml = '';
  if (Array.isArray(d.organic_treatments) && d.organic_treatments.length > 0) {
    orgHtml = `
      <div style="margin-top:10px;">
        <h5 style="margin:0 0 6px 0; font-size:13px; color:var(--green-700); font-weight:700;">🌿 Organic & Cultural Measures:</h5>
        <ul style="margin:0; padding-left:18px; font-size:12.5px; color:var(--neutral-700); line-height:1.6;">
          ${d.organic_treatments.map(o => `<li><strong>${escapeHtml(o.title || o.name || '')}</strong>: ${escapeHtml(o.rate || o.dosage || '')} <em>(${escapeHtml(o.timing || '')})</em></li>`).join('')}
        </ul>
      </div>
    `;
  }

  if (contentEl) {
    contentEl.innerHTML = `
      ${imgHtml}
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <span style="font-size:12px; color:var(--neutral-500);"><strong>Code:</strong> <code>${escapeHtml(d.code || 'N/A')}</code></span>
        <span class="badge-pill" style="${isActive ? 'background:var(--green-100); color:var(--brand-green);' : 'background:var(--neutral-100); color:var(--neutral-500);'} font-weight:700;">
          ${isActive ? 'Status: Active' : 'Status: Inactive'}
        </span>
      </div>

      <div style="display:flex; flex-direction:column; gap:12px;">
        <div>
          <h4 style="margin:0 0 4px 0; font-size:13.5px; color:var(--neutral-900); font-weight:700;">📖 Description</h4>
          <p style="margin:0; font-size:12.5px; color:var(--neutral-700); line-height:1.5;">${escapeHtml(d.description || 'No description provided.')}</p>
        </div>

        ${d.symptoms ? `
          <div>
            <h4 style="margin:0 0 4px 0; font-size:13.5px; color:var(--neutral-900); font-weight:700;">🔍 Symptoms & Diagnostic Visual Keys</h4>
            <p style="margin:0; font-size:12.5px; color:var(--neutral-700); line-height:1.5;">${escapeHtml(d.symptoms)}</p>
          </div>
        ` : ''}

        ${d.causes ? `
          <div>
            <h4 style="margin:0 0 4px 0; font-size:13.5px; color:var(--neutral-900); font-weight:700;">⚠️ Causes & Favorable Conditions</h4>
            <p style="margin:0; font-size:12.5px; color:var(--neutral-700); line-height:1.5;">${escapeHtml(d.causes)}</p>
          </div>
        ` : ''}

        ${d.prevention ? `
          <div>
            <h4 style="margin:0 0 4px 0; font-size:13.5px; color:var(--neutral-900); font-weight:700;">🛡️ Prevention Strategies</h4>
            <p style="margin:0; font-size:12.5px; color:var(--neutral-700); line-height:1.5;">${escapeHtml(d.prevention)}</p>
          </div>
        ` : ''}

        <div>
          <h4 style="margin:0 0 4px 0; font-size:13.5px; color:var(--brand-green); font-weight:700;">🩺 Recommended Treatment Protocol</h4>
          <p style="margin:0; font-size:12.5px; color:var(--neutral-700); line-height:1.5;">${escapeHtml(d.recommended_treatment || d.treatment || 'No treatment guidelines registered.')}</p>
          ${chemHtml}
          ${orgHtml}
        </div>
      </div>
    `;
  }

  const deleteBtn = document.getElementById('adminViewDiseaseDeleteBtn');
  if (deleteBtn) {
    deleteBtn.onclick = () => {
      closeModal('modalAdminViewDisease');
      handleAdminDeleteDisease(d.id, d.name);
    };
  }

  openModal('modalAdminViewDisease');
}

async function handleAdminAddDisease(e) {
  e.preventDefault();
  const name = document.getElementById('adminAddDiseaseName').value.trim();
  const sciName = document.getElementById('adminAddDiseaseSciName').value.trim();
  const desc = document.getElementById('adminAddDiseaseDesc').value.trim();
  const symptoms = document.getElementById('adminAddDiseaseSymptoms').value.trim();
  const causes = document.getElementById('adminAddDiseaseCauses').value.trim();
  const prevention = document.getElementById('adminAddDiseasePrevention').value.trim();
  const treatment = document.getElementById('adminAddDiseaseTreatment').value.trim();
  const status = document.getElementById('adminAddDiseaseStatus').value;
  const imgInput = document.getElementById('adminAddDiseaseImage');

  const btn = document.getElementById('adminAddDiseaseBtn');
  const err = document.getElementById('adminAddDiseaseError');
  const ok = document.getElementById('adminAddDiseaseSuccess');

  if (err) err.classList.remove('show');
  if (ok) ok.classList.remove('show');
  if (btn) { btn.disabled = true; btn.textContent = 'Saving Disease...'; }

  const formData = new FormData();
  formData.append('name', name);
  formData.append('scientific_name', sciName);
  formData.append('description', desc);
  formData.append('symptoms', symptoms);
  formData.append('causes', causes);
  formData.append('prevention', prevention);
  formData.append('recommended_treatment', treatment);
  formData.append('treatment', treatment);
  formData.append('status', status === '1' || status === 'active' ? 'active' : 'inactive');
  formData.append('is_active', status === '1' || status === 'active' ? '1' : '0');
  if (imgInput && imgInput.files[0]) {
    formData.append('image', imgInput.files[0]);
  }

  await ensureCsrfCookie();
  fetch(apiUrl('/admin/diseases'), {
    method: 'POST',
    credentials: 'include',
    headers: authHeaders(),
    body: formData,
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        ok.textContent = data.message || 'Disease record created successfully!';
        ok.classList.add('show');
        document.getElementById('adminAddDiseaseForm').reset();
        loadAdminDiseases();
        setTimeout(() => {
          closeModal('modalAdminAddDisease');
          ok.classList.remove('show');
        }, 800);
      } else {
        err.textContent = data.message || 'Failed to save disease.';
        err.classList.add('show');
      }
    })
    .catch(() => {
      err.textContent = 'Network error while saving disease.';
      err.classList.add('show');
    })
    .finally(() => {
      if (btn) { btn.disabled = false; btn.textContent = 'Save Disease Info'; }
    });
}

function openAdminEditDiseaseModalById(id) {
  const d = adminDiseasesList.find(item => item.id == id);
  if (!d) return;

  document.getElementById('adminEditDiseaseId').value = d.id;
  document.getElementById('adminEditDiseaseName').value = d.name || '';
  document.getElementById('adminEditDiseaseSciName').value = d.scientific_name || '';
  document.getElementById('adminEditDiseaseDesc').value = d.description || '';
  document.getElementById('adminEditDiseaseSymptoms').value = d.symptoms || '';
  document.getElementById('adminEditDiseaseCauses').value = d.causes || '';
  document.getElementById('adminEditDiseasePrevention').value = d.prevention || '';
  document.getElementById('adminEditDiseaseTreatment').value = d.recommended_treatment || d.treatment || '';
  
  const isActive = d.status === 'active' || d.is_active;
  document.getElementById('adminEditDiseaseStatus').value = isActive ? '1' : '0';

  const err = document.getElementById('adminEditDiseaseError');
  const ok = document.getElementById('adminEditDiseaseSuccess');
  if (err) err.classList.remove('show');
  if (ok) ok.classList.remove('show');

  openModal('modalAdminEditDisease');
}

async function handleAdminEditDisease(e) {
  e.preventDefault();
  const id = document.getElementById('adminEditDiseaseId').value;
  const name = document.getElementById('adminEditDiseaseName').value.trim();
  const sciName = document.getElementById('adminEditDiseaseSciName').value.trim();
  const desc = document.getElementById('adminEditDiseaseDesc').value.trim();
  const symptoms = document.getElementById('adminEditDiseaseSymptoms').value.trim();
  const causes = document.getElementById('adminEditDiseaseCauses').value.trim();
  const prevention = document.getElementById('adminEditDiseasePrevention').value.trim();
  const treatment = document.getElementById('adminEditDiseaseTreatment').value.trim();
  const status = document.getElementById('adminEditDiseaseStatus').value;
  const imgInput = document.getElementById('adminEditDiseaseImage');

  const btn = document.getElementById('adminEditDiseaseBtn');
  const err = document.getElementById('adminEditDiseaseError');
  const ok = document.getElementById('adminEditDiseaseSuccess');

  if (err) err.classList.remove('show');
  if (ok) ok.classList.remove('show');
  if (btn) { btn.disabled = true; btn.textContent = 'Updating Disease...'; }

  const formData = new FormData();
  formData.append('name', name);
  formData.append('scientific_name', sciName);
  formData.append('description', desc);
  formData.append('symptoms', symptoms);
  formData.append('causes', causes);
  formData.append('prevention', prevention);
  formData.append('recommended_treatment', treatment);
  formData.append('treatment', treatment);
  formData.append('status', status === '1' || status === 'active' ? 'active' : 'inactive');
  formData.append('is_active', status === '1' || status === 'active' ? '1' : '0');
  if (imgInput && imgInput.files[0]) {
    formData.append('image', imgInput.files[0]);
  }

  await ensureCsrfCookie();
  fetch(apiUrl(`/admin/diseases/${id}`), {
    method: 'POST',
    credentials: 'include',
    headers: authHeaders(),
    body: formData,
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        ok.textContent = data.message || 'Disease record updated successfully!';
        ok.classList.add('show');
        loadAdminDiseases();
        setTimeout(() => {
          closeModal('modalAdminEditDisease');
          ok.classList.remove('show');
        }, 800);
      } else {
        err.textContent = data.message || 'Failed to update disease.';
        err.classList.add('show');
      }
    })
    .catch(() => {
      err.textContent = 'Network error while updating disease.';
      err.classList.add('show');
    })
    .finally(() => {
      if (btn) { btn.disabled = false; btn.textContent = 'Update Disease'; }
    });
}

function handleAdminDeleteDisease(id, name) {
  pendingDeleteDiseaseId = id;
  const nameEl = document.getElementById('adminDeleteDiseaseName');
  if (nameEl) nameEl.textContent = name || 'this disease';
  const errBanner = document.getElementById('adminDeleteDiseaseError');
  if (errBanner) errBanner.classList.remove('show');
  openModal('modalAdminDeleteDisease');
}

async function confirmAdminDeleteDisease() {
  if (!pendingDeleteDiseaseId) return;
  const btn = document.getElementById('confirmDeleteDiseaseBtn');
  const errBanner = document.getElementById('adminDeleteDiseaseError');

  if (btn) { btn.disabled = true; btn.textContent = 'Deleting...'; }
  if (errBanner) errBanner.classList.remove('show');

  await ensureCsrfCookie();
  fetch(apiUrl(`/admin/diseases/${pendingDeleteDiseaseId}`), {
    method: 'DELETE',
    credentials: 'include',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        closeModal('modalAdminDeleteDisease');
        pendingDeleteDiseaseId = null;
        loadAdminDiseases();
      } else {
        if (errBanner) {
          errBanner.textContent = data.message || 'Failed to delete disease.';
          errBanner.classList.add('show');
        }
      }
    })
    .catch(() => {
      if (errBanner) {
        errBanner.textContent = 'Network error. Please try again.';
        errBanner.classList.add('show');
      }
    })
    .finally(() => {
      if (btn) { btn.disabled = false; btn.textContent = 'Yes, Delete Disease'; }
    });
}

async function toggleAdminDiseaseStatus(id) {
  await ensureCsrfCookie();
  fetch(apiUrl(`/admin/diseases/${id}/toggle-status`), {
    method: 'POST',
    credentials: 'include',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        loadAdminDiseases();
      }
    })
    .catch(() => {});
}

/* ═══════════ ADMIN DETECTION RECORDS / LOGS ═══════════ */
let adminScansLogsList = [];
let adminActiveScanChip = 'all';
let pendingDeleteScanId = null;

async function loadAdminScansLogs() {
  const tbody = document.getElementById('adminScansLogsTableBody');
  if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="empty-table-msg">Loading all detection records...</td></tr>';

  await ensureCsrfCookie();
  fetch(apiUrl('/admin/scans'), {
    credentials: 'include',
    headers: authHeaders(),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success && data.data) {
        adminScansLogsList = data.data.scans || [];
        filterAdminScansLogs();
      } else {
        if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="empty-table-msg">Failed to load detection logs.</td></tr>';
      }
    })
    .catch(() => {
      if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="empty-table-msg">Network error while fetching logs.</td></tr>';
    });
}

function filterAdminScansByChip(chipKey, chipEl) {
  adminActiveScanChip = chipKey;
  document.querySelectorAll('#admin-scans-logs .staff-chip').forEach(c => c.classList.remove('active'));
  if (chipEl) chipEl.classList.add('active');
  filterAdminScansLogs();
}

function filterAdminScansLogs() {
  const query = (document.getElementById('adminScansSearch')?.value || '').toLowerCase().trim();
  let list = adminScansLogsList;

  if (adminActiveScanChip === 'healthy') {
    list = list.filter(s => s.severity === 'healthy');
  } else if (adminActiveScanChip === 'outbreaks') {
    list = list.filter(s => s.severity === 'severe' || s.severity === 'moderate');
  } else if (adminActiveScanChip !== 'all') {
    const chipKey = adminActiveScanChip.toLowerCase();
    list = list.filter(s => (s.disease_name || '').toLowerCase().includes(chipKey) || (s.scientific_name || '').toLowerCase().includes(chipKey));
  }

  if (query) {
    list = list.filter(s =>
      (s.farmer_name || '').toLowerCase().includes(query) ||
      (s.farmer_email || '').toLowerCase().includes(query) ||
      (s.disease_name || '').toLowerCase().includes(query) ||
      (s.scientific_name || '').toLowerCase().includes(query) ||
      (s.location || '').toLowerCase().includes(query)
    );
  }

  renderAdminScansLogsTable(list);
}

function renderAdminScansLogsTable(scans) {
  const tbody = document.getElementById('adminScansLogsTableBody');
  if (!tbody) return;

  if (!scans || scans.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty-table-msg">No detection records match current filter.</td></tr>';
    return;
  }

  tbody.innerHTML = scans.map(s => {
    const isHealthy = s.severity === 'healthy';
    const sevBadge = isHealthy
      ? '<span class="badge-pill" style="background:var(--green-100); color:var(--brand-green);">Healthy</span>'
      : (s.severity === 'severe'
        ? '<span class="badge-pill" style="background:var(--red-100); color:var(--red-700);">Severe (>60%)</span>'
        : (s.severity === 'mild'
          ? '<span class="badge-pill" style="background:var(--green-100); color:var(--brand-green);">Mild (≤25%)</span>'
          : '<span class="badge-pill" style="background:var(--amber-100); color:var(--amber-800);">Moderate (26-60%)</span>'));

    const thumbHtml = s.image_url
      ? `<img src="${s.image_url}" alt="Leaf Specimen" style="width:42px; height:42px; border-radius:8px; object-fit:cover; border:1px solid var(--neutral-200); cursor:pointer;" onclick="openAdminScanPreview(${s.id})">`
      : `<div style="width:42px; height:42px; border-radius:8px; background:var(--neutral-100); display:flex; align-items:center; justify-content:center; color:var(--neutral-400);">🌿</div>`;

    return `
      <tr>
        <td style="width: 65px;">${thumbHtml}</td>
        <td>
          <div style="font-weight:700; color:var(--neutral-900); font-size:13.5px;">${escapeHtml(s.farmer_name)}</div>
          <div style="font-size:11.5px; color:var(--neutral-500); margin-top:1px;">📍 ${escapeHtml(s.location)}</div>
        </td>
        <td>
          <div style="font-weight:700; color:var(--brand-green); font-size:13.5px;">${escapeHtml(s.disease_name)}</div>
          <div style="font-size:11px; font-style:italic; color:var(--neutral-500);">${escapeHtml(s.scientific_name)}</div>
        </td>
        <td><strong>${s.confidence ? s.confidence.toFixed(1) + '%' : 'N/A'}</strong></td>
        <td>${sevBadge}</td>
        <td>
          <div style="font-size:12px; font-weight:600; color:var(--neutral-700);">${escapeHtml(s.created_at)}</div>
          <div style="font-size:11px; color:var(--neutral-400);">${escapeHtml(s.time_ago || '')}</div>
        </td>
        <td style="text-align: right;">
          <div class="action-btns-group" style="justify-content: flex-end;">
            <button class="action-icon-btn" onclick="openAdminScanPreview(${s.id})" title="View Details" style="color:var(--brand-green);">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </button>
            <button class="action-icon-btn delete" onclick="handleAdminDeleteScan(${s.id})" title="Delete Record">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function openAdminScanPreview(scanId) {
  const scan = adminScansLogsList.find(s => s.id === scanId);
  if (!scan) return;

  const content = document.getElementById('adminScanPreviewContent');
  if (content) {
    const isHealthy = scan.severity === 'healthy';
    const sev = (scan.severity || 'healthy').toLowerCase();
    let sevDisplay = 'HEALTHY';
    if (sev === 'mild') sevDisplay = 'MILD (≤ 25%)';
    else if (sev === 'moderate') sevDisplay = 'MODERATE (26% - 60%)';
    else if (sev === 'severe') sevDisplay = 'SEVERE (> 60%)';

    content.innerHTML = `
      <div style="text-align:center; margin-bottom:14px;">
        ${scan.image_url ? `<img src="${scan.image_url}" alt="Specimen" style="max-width:100%; max-height:260px; border-radius:10px; object-fit:contain; border:1px solid var(--neutral-200);">` : ''}
      </div>
      <div style="background:var(--neutral-50); border-radius:8px; padding:12px; font-size:13px; line-height:1.6;">
        <div><strong>User:</strong> ${escapeHtml(scan.farmer_name)} (${escapeHtml(scan.farmer_email || 'No email')})</div>
        <div><strong>Location:</strong> ${escapeHtml(scan.location)}</div>
        <div><strong>Predicted Result:</strong> <span style="color:var(--brand-green); font-weight:700;">${escapeHtml(scan.disease_name)}</span></div>
        <div><strong>Confidence / Accuracy:</strong> ${scan.confidence ? scan.confidence.toFixed(1) + '%' : 'N/A'}</div>
        <div><strong>Severity Assessment:</strong> <span style="font-weight:700;">${sevDisplay}</span></div>
        <div><strong>Scan Date:</strong> ${escapeHtml(scan.created_at)}</div>
        ${scan.notes ? `<div style="margin-top:8px; padding-top:8px; border-top:1px solid var(--neutral-200);"><strong>Staff Advisory:</strong> ${escapeHtml(scan.notes)}</div>` : ''}
      </div>
    `;
  }
  openModal('modalAdminScanPreview');
}

function handleAdminDeleteScan(scanId) {
  pendingDeleteScanId = scanId;
  const idEl = document.getElementById('adminDeleteScanId');
  if (idEl) idEl.textContent = `#${scanId}`;
  const errBanner = document.getElementById('adminDeleteScanError');
  if (errBanner) errBanner.classList.remove('show');
  openModal('modalAdminDeleteScan');
}

async function confirmAdminDeleteScan() {
  if (!pendingDeleteScanId) return;
  const btn = document.getElementById('confirmDeleteScanBtn');
  const errBanner = document.getElementById('adminDeleteScanError');

  if (btn) { btn.disabled = true; btn.textContent = 'Deleting...'; }
  if (errBanner) errBanner.classList.remove('show');

  await ensureCsrfCookie();
  fetch(apiUrl(`/admin/scans/${pendingDeleteScanId}`), {
    method: 'DELETE',
    credentials: 'include',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        closeModal('modalAdminDeleteScan');
        pendingDeleteScanId = null;
        loadAdminScansLogs();
      } else {
        if (errBanner) {
          errBanner.textContent = data.message || 'Failed to delete record.';
          errBanner.classList.add('show');
        }
      }
    })
    .catch(() => {
      if (errBanner) {
        errBanner.textContent = 'Network error. Please try again.';
        errBanner.classList.add('show');
      }
    })
    .finally(() => {
      if (btn) { btn.disabled = false; btn.textContent = 'Yes, Delete Record'; }
    });
}

function exportAdminScansCsv() {
  const token = window.localStorage ? window.localStorage.getItem('oryzatix_token') : null;
  const url = apiUrl('/admin/reports/export') + (token ? `?api_token=${encodeURIComponent(token)}` : '');
  window.open(url, '_blank');
}

/* ═══════════ ADMIN REPORTS & ANALYTICS ═══════════ */
let activeAdminReportPeriod = 'daily';

async function loadAdminReports(period = 'daily') {
  activeAdminReportPeriod = period;
  const meterContainer = document.getElementById('adminReportsDiseaseList');
  const chartContainer = document.getElementById('adminReportTrendChart');

  if (meterContainer) meterContainer.innerHTML = '<div class="empty-table-msg">Loading disease metrics...</div>';
  if (chartContainer) chartContainer.innerHTML = '<div class="empty-table-msg">Loading trends...</div>';

  await ensureCsrfCookie();
  fetch(apiUrl(`/admin/reports?period=${period}`), {
    credentials: 'include',
    headers: authHeaders(),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success && data.data) {
        const d = data.data;
        renderAdminReportsBreakdown(d.disease_breakdown || []);
        renderAdminReportTimelineChart(d.timeline || []);
      }
    })
    .catch(() => {});
}

function switchAdminReportPeriod(period) {
  const periods = ['daily', 'weekly', 'monthly'];
  periods.forEach(p => {
    const btn = document.getElementById(`adminReport${p.charAt(0).toUpperCase() + p.slice(1)}Btn`);
    if (btn) btn.classList.toggle('active', p === period);
  });
  loadAdminReports(period);
}

function renderAdminReportsBreakdown(breakdown) {
  const container = document.getElementById('adminReportsDiseaseList');
  if (!container) return;

  if (!breakdown || breakdown.length === 0) {
    container.innerHTML = '<div class="empty-table-msg">No detection data in this period.</div>';
    return;
  }

  container.innerHTML = breakdown.map(item => {
    const isHealthy = item.name.toLowerCase().includes('healthy');
    const color = isHealthy ? 'var(--brand-green)' : 'var(--amber-600)';
    return `
      <div class="disease-meter-item" style="margin-bottom: 14px;">
        <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:700; margin-bottom:4px;">
          <span>${escapeHtml(item.name)}</span>
          <span>${item.count} scans (${item.percent}%)</span>
        </div>
        <div style="height:8px; background:var(--neutral-100); border-radius:4px; overflow:hidden;">
          <div style="height:100%; width:${Math.min(100, item.percent)}%; background:${color}; border-radius:4px; transition:width 0.5s ease;"></div>
        </div>
      </div>
    `;
  }).join('');
}

function renderAdminReportTimelineChart(timeline) {
  const container = document.getElementById('adminReportTrendChart');
  if (!container) return;

  if (!timeline || timeline.length === 0) {
    container.innerHTML = '<div class="empty-table-msg">No timeline trend data logged yet.</div>';
    return;
  }

  const maxVal = Math.max(...timeline.map(t => t.total || 0), 1);
  container.innerHTML = `
    <div style="display:flex; align-items:flex-end; gap:12px; height:180px; padding:10px 0; border-bottom:1px solid var(--neutral-200);">
      ${timeline.map(t => {
        const heightPct = Math.max(8, Math.round(((t.total || 0) / maxVal) * 100));
        return `
          <div style="flex:1; display:flex; flex-direction:column; align-items:center; height:100%; justify-content:flex-end;">
            <div style="font-size:11px; font-weight:700; color:var(--neutral-700); margin-bottom:4px;">${t.total || 0}</div>
            <div style="width:100%; max-width:32px; height:${heightPct}%; background:var(--blue-600); border-radius:4px 4px 0 0; transition:height 0.4s ease;"></div>
            <div style="font-size:10.5px; color:var(--neutral-500); margin-top:6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:48px;">${escapeHtml(t.label || '')}</div>
          </div>
        `;
      }).join('')}
    </div>
  `;
}

/* ═══════════ ADMIN CHATBOT & FAQ MANAGEMENT ═══════════ */
let adminFaqList = [];
let adminChatLogsList = [];
let activeAdminChatbotTab = 'faq';
let pendingDeleteFaqId = null;

async function loadAdminChatbot() {
  await Promise.all([loadAdminFaqKnowledge(), loadAdminChatLogs()]);
}

async function loadAdminFaqKnowledge() {
  const tbody = document.getElementById('adminFaqTableBody');
  if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="empty-table-msg">Loading FAQ knowledge items...</td></tr>';

  await ensureCsrfCookie();
  fetch(apiUrl('/admin/chatbot/knowledge'), {
    credentials: 'include',
    headers: authHeaders(),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success && data.data) {
        adminFaqList = data.data.knowledge || [];
        const badge = document.getElementById('adminFaqCountBadge');
        if (badge) badge.textContent = adminFaqList.length;
        renderAdminFaqTable(adminFaqList);
      } else {
        if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="empty-table-msg">Failed to load FAQs.</td></tr>';
      }
    })
    .catch(() => {
      if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="empty-table-msg">Network error while fetching FAQs.</td></tr>';
    });
}

async function loadAdminChatLogs() {
  const tbody = document.getElementById('adminChatLogsTableBody');
  if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-table-msg">Loading conversation history...</td></tr>';

  await ensureCsrfCookie();
  fetch(apiUrl('/admin/chatbot/conversations'), {
    credentials: 'include',
    headers: authHeaders(),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success && data.data) {
        adminChatLogsList = data.data.conversations || [];
        const badge = document.getElementById('adminChatLogsCountBadge');
        if (badge) badge.textContent = adminChatLogsList.length;
        renderAdminChatLogsTable(adminChatLogsList);
      } else {
        if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-table-msg">Failed to load conversation logs.</td></tr>';
      }
    })
    .catch(() => {
      if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="empty-table-msg">Network error while fetching conversation logs.</td></tr>';
    });
}

function switchAdminChatbotTab(tab) {
  activeAdminChatbotTab = tab;
  const btnFaq = document.getElementById('adminChatbotTabFaq');
  const btnLogs = document.getElementById('adminChatbotTabLogs');
  const viewFaq = document.getElementById('adminChatbotFaqView');
  const viewLogs = document.getElementById('adminChatbotLogsView');

  if (tab === 'faq') {
    if (btnFaq) btnFaq.classList.add('active');
    if (btnLogs) btnLogs.classList.remove('active');
    if (viewFaq) viewFaq.style.display = 'block';
    if (viewLogs) viewLogs.style.display = 'none';
  } else {
    if (btnFaq) btnFaq.classList.remove('active');
    if (btnLogs) btnLogs.classList.add('active');
    if (viewFaq) viewFaq.style.display = 'none';
    if (viewLogs) viewLogs.style.display = 'block';
  }
}

function renderAdminFaqTable(faqs) {
  const tbody = document.getElementById('adminFaqTableBody');
  if (!tbody) return;

  if (!faqs || faqs.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" class="empty-table-msg">No FAQ knowledge rules registered yet.</td></tr>';
    return;
  }

  tbody.innerHTML = faqs.map(f => {
    const isActive = f.is_active;
    return `
      <tr>
        <td><span class="badge-pill" style="background:var(--blue-50); color:var(--blue-700);">${escapeHtml(f.category)}</span></td>
        <td><strong>${escapeHtml(f.question)}</strong></td>
        <td><div style="max-width:300px; font-size:12.5px; color:var(--neutral-700); line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">${escapeHtml(f.answer)}</div></td>
        <td><span class="badge-pill" style="background:var(--neutral-100); color:var(--neutral-700);">${(f.language || 'both').toUpperCase()}</span></td>
        <td>
          <button class="badge-pill" onclick="toggleAdminFaqStatus(${f.id})" style="cursor:pointer; border:none; ${isActive ? 'background:var(--green-100); color:var(--brand-green);' : 'background:var(--neutral-100); color:var(--neutral-500);'}">
            ${isActive ? 'Active' : 'Inactive'}
          </button>
        </td>
        <td style="text-align: right;">
          <div class="action-btns-group" style="justify-content: flex-end;">
            <button class="action-icon-btn edit" onclick="openAdminEditFaqModal(${JSON.stringify(f).replace(/"/g, '&quot;')})" title="Edit Rule">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>
            <button class="action-icon-btn delete" onclick="handleAdminDeleteFaq(${f.id}, '${escapeHtml(f.question)}')" title="Delete Rule">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function renderAdminChatLogsTable(logs) {
  const tbody = document.getElementById('adminChatLogsTableBody');
  if (!tbody) return;

  if (!logs || logs.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="empty-table-msg">No conversation records logged in system yet.</td></tr>';
    return;
  }

  tbody.innerHTML = logs.map(l => {
    const isBot = l.sender === 'bot';
    return `
      <tr>
        <td><strong>${escapeHtml(l.user_name || 'Anonymous User')}</strong></td>
        <td>
          <span class="badge-pill" style="${isBot ? 'background:var(--green-100); color:var(--brand-green);' : 'background:var(--blue-100); color:var(--blue-700);'}">
            ${isBot ? '🤖 AI Agronomist' : '👤 User'}
          </span>
        </td>
        <td><div style="font-size:12.5px; color:var(--neutral-800);">${escapeHtml(l.message)}</div></td>
        <td><span style="font-size:11.5px; color:var(--neutral-500);">${escapeHtml(l.language || 'tl')}</span></td>
        <td><span style="font-size:12px; color:var(--neutral-500);">${escapeHtml(l.created_at)}</span></td>
      </tr>
    `;
  }).join('');
}

async function handleAdminAddFaq(e) {
  e.preventDefault();
  const category = document.getElementById('adminAddFaqCategory').value;
  const question = document.getElementById('adminAddFaqQuestion').value.trim();
  const answer = document.getElementById('adminAddFaqAnswer').value.trim();
  const language = document.getElementById('adminAddFaqLang').value;
  const status = document.getElementById('adminAddFaqStatus').value;

  const btn = document.getElementById('adminAddFaqBtn');
  const err = document.getElementById('adminAddFaqError');
  const ok = document.getElementById('adminAddFaqSuccess');

  if (err) err.classList.remove('show');
  if (ok) ok.classList.remove('show');
  if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }

  await ensureCsrfCookie();
  fetch(apiUrl('/admin/chatbot/knowledge'), {
    method: 'POST',
    credentials: 'include',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify({ category, question, answer, language, is_active: status }),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        ok.textContent = data.message || 'Q&A knowledge rule saved!';
        ok.classList.add('show');
        document.getElementById('adminAddFaqForm').reset();
        loadAdminFaqKnowledge();
        setTimeout(() => {
          closeModal('modalAdminAddFaq');
          ok.classList.remove('show');
        }, 800);
      } else {
        err.textContent = data.message || 'Failed to save rule.';
        err.classList.add('show');
      }
    })
    .catch(() => {
      err.textContent = 'Network error while saving rule.';
      err.classList.add('show');
    })
    .finally(() => {
      if (btn) { btn.disabled = false; btn.textContent = 'Save Q&A Rule'; }
    });
}

function openAdminEditFaqModal(f) {
  if (!f) return;
  document.getElementById('adminEditFaqId').value = f.id;
  document.getElementById('adminEditFaqCategory').value = f.category || 'general';
  document.getElementById('adminEditFaqQuestion').value = f.question || '';
  document.getElementById('adminEditFaqAnswer').value = f.answer || '';
  document.getElementById('adminEditFaqLang').value = f.language || 'both';
  document.getElementById('adminEditFaqStatus').value = f.is_active ? '1' : '0';

  const err = document.getElementById('adminEditFaqError');
  const ok = document.getElementById('adminEditFaqSuccess');
  if (err) err.classList.remove('show');
  if (ok) ok.classList.remove('show');

  openModal('modalAdminEditFaq');
}

async function handleAdminEditFaq(e) {
  e.preventDefault();
  const id = document.getElementById('adminEditFaqId').value;
  const category = document.getElementById('adminEditFaqCategory').value;
  const question = document.getElementById('adminEditFaqQuestion').value.trim();
  const answer = document.getElementById('adminEditFaqAnswer').value.trim();
  const language = document.getElementById('adminEditFaqLang').value;
  const status = document.getElementById('adminEditFaqStatus').value;

  const btn = document.getElementById('adminEditFaqBtn');
  const err = document.getElementById('adminEditFaqError');
  const ok = document.getElementById('adminEditFaqSuccess');

  if (err) err.classList.remove('show');
  if (ok) ok.classList.remove('show');
  if (btn) { btn.disabled = true; btn.textContent = 'Updating...'; }

  await ensureCsrfCookie();
  fetch(apiUrl(`/admin/chatbot/knowledge/${id}`), {
    method: 'PUT',
    credentials: 'include',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify({ category, question, answer, language, is_active: status }),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        ok.textContent = data.message || 'Rule updated successfully!';
        ok.classList.add('show');
        loadAdminFaqKnowledge();
        setTimeout(() => {
          closeModal('modalAdminEditFaq');
          ok.classList.remove('show');
        }, 800);
      } else {
        err.textContent = data.message || 'Failed to update rule.';
        err.classList.add('show');
      }
    })
    .catch(() => {
      err.textContent = 'Network error while updating rule.';
      err.classList.add('show');
    })
    .finally(() => {
      if (btn) { btn.disabled = false; btn.textContent = 'Update Rule'; }
    });
}

function handleAdminDeleteFaq(id, question) {
  pendingDeleteFaqId = id;
  const qEl = document.getElementById('adminDeleteFaqQuestion');
  if (qEl) qEl.textContent = `"${question}"` || 'this entry';
  const errBanner = document.getElementById('adminDeleteFaqError');
  if (errBanner) errBanner.classList.remove('show');
  openModal('modalAdminDeleteFaq');
}

async function confirmAdminDeleteFaq() {
  if (!pendingDeleteFaqId) return;
  const btn = document.getElementById('confirmDeleteFaqBtn');
  const errBanner = document.getElementById('adminDeleteFaqError');

  if (btn) { btn.disabled = true; btn.textContent = 'Deleting...'; }
  if (errBanner) errBanner.classList.remove('show');

  await ensureCsrfCookie();
  fetch(apiUrl(`/admin/chatbot/knowledge/${pendingDeleteFaqId}`), {
    method: 'DELETE',
    credentials: 'include',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        closeModal('modalAdminDeleteFaq');
        pendingDeleteFaqId = null;
        loadAdminFaqKnowledge();
      } else {
        if (errBanner) {
          errBanner.textContent = data.message || 'Failed to delete entry.';
          errBanner.classList.add('show');
        }
      }
    })
    .catch(() => {
      if (errBanner) {
        errBanner.textContent = 'Network error. Please try again.';
        errBanner.classList.add('show');
      }
    })
    .finally(() => {
      if (btn) { btn.disabled = false; btn.textContent = 'Yes, Delete Entry'; }
    });
}

async function toggleAdminFaqStatus(id) {
  await ensureCsrfCookie();
  fetch(apiUrl(`/admin/chatbot/knowledge/${id}/toggle-status`), {
    method: 'POST',
    credentials: 'include',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        loadAdminFaqKnowledge();
      }
    })
    .catch(() => {});
}

/* ═══════════ STAFF EXTENSION WORKER DASHBOARD ═══════════ */
let staffScansList = [];
let staffBarangayList = [];
let staffActiveDiseaseFilter = 'all';

async function loadStaffDashboard() {
  const tableBody = document.getElementById('staffScansTableBody');
  const cardsContainer = document.getElementById('staffBarangayCardsContainer');
  if (tableBody) {
    tableBody.innerHTML = '<tr><td colspan="6" class="empty-table-msg">Loading field surveillance records...</td></tr>';
  }
  if (cardsContainer) {
    cardsContainer.innerHTML = '<div class="empty-table-msg">Loading barangay surveillance data...</div>';
  }

  fetch(apiUrl('/staff/overview'), {
    credentials: 'include',
    headers: authHeaders(),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success && data.data) {
        const d = data.data;
        const stats = d.stats || {};

        // Populate Stat Cards
        const elScans = document.getElementById('staffStatTotalScans');
        if (elScans) elScans.textContent = stats.total_field_scans || 0;

        const elOutbreaks = document.getElementById('staffStatActiveOutbreaks');
        if (elOutbreaks) elOutbreaks.textContent = stats.active_outbreaks || 0;

        const elFarmers = document.getElementById('staffStatTotalFarmers');
        if (elFarmers) elFarmers.textContent = stats.total_farmers || 0;

        const elHealthy = document.getElementById('staffStatHealthyRatio');
        if (elHealthy) elHealthy.textContent = (stats.healthy_ratio !== undefined ? stats.healthy_ratio : 100) + '%';

        staffScansList = d.recent_scans || [];
        staffBarangayList = d.location_surveillance || [];

        const badgeScans = document.getElementById('staffScansCountBadge');
        if (badgeScans) badgeScans.textContent = staffScansList.length;

        const badgeLoc = document.getElementById('staffLocationCountBadge');
        if (badgeLoc) badgeLoc.textContent = staffBarangayList.length;

        renderStaffScansTable(staffScansList);
        renderBarangayCards(staffBarangayList);
      } else {
        if (tableBody) tableBody.innerHTML = '<tr><td colspan="6" class="empty-table-msg">Unable to load staff data. Extension access required.</td></tr>';
      }
    })
    .catch(() => {
      if (tableBody) tableBody.innerHTML = '<tr><td colspan="6" class="empty-table-msg">Network error while fetching extension data.</td></tr>';
    });
}

function switchStaffTab(tab) {
  const tabs = ['scans', 'outbreak', 'protocols'];
  tabs.forEach(t => {
    const btn = document.getElementById(`staffTab${t.charAt(0).toUpperCase() + t.slice(1)}Btn`);
    const view = document.getElementById(`staff${t.charAt(0).toUpperCase() + t.slice(1)}View`);
    if (btn) btn.classList.toggle('active', t === tab);
    if (view) view.style.display = (t === tab) ? 'block' : 'none';
  });
}

function renderStaffScansTable(scans) {
  const tbody = document.getElementById('staffScansTableBody');
  if (!tbody) return;

  if (!scans || scans.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" class="empty-table-msg">No farmer scans match the current filter.</td></tr>';
    return;
  }

  tbody.innerHTML = scans.map(s => {
    const sev = s.severity || 'healthy';
    const sevLabels = {
      healthy: 'Healthy',
      mild: 'Mild (≤ 25%)',
      moderate: 'Moderate (26%–60%)',
      severe: 'Severe (> 60%)',
    };
    const sevLabel = sevLabels[sev] || sev.toUpperCase();
    const hasNote = Boolean(s.notes && s.notes.trim());

    const imgThumb = s.image_url
      ? `<img src="${s.image_url}" alt="Leaf" style="width:42px; height:42px; border-radius:6px; object-fit:cover; border:1px solid var(--neutral-200);">`
      : `<div style="width:42px; height:42px; border-radius:6px; background:var(--neutral-100); display:flex; align-items:center; justify-content:center; color:var(--neutral-400);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg></div>`;

    return `
      <tr>
        <td>
          <div style="font-weight:700; color:var(--neutral-900); font-size:13.5px;">${escapeHtml(s.farmer_name)}</div>
          <div style="font-size:11.5px; color:var(--neutral-500); margin-top:2px;">📍 ${escapeHtml(s.location)}</div>
        </td>
        <td>
          <div style="display:flex; align-items:center; gap:8px;">
            ${imgThumb}
          </div>
        </td>
        <td>
          <div style="font-weight:700; color:var(--brand-green); font-size:13.5px;">${escapeHtml(s.disease_name)}</div>
          <div style="font-size:11.5px; font-style:italic; color:var(--neutral-500);">${escapeHtml(s.scientific_name)}</div>
          ${hasNote ? `<div style="margin-top:4px; font-size:11px; padding:2px 6px; background:#eff6ff; color:#1d4ed8; border-radius:4px; border:1px solid #bfdbfe; display:inline-block;">💬 Staff Note Added</div>` : ''}
        </td>
        <td>
          <span class="sev-badge ${sev}">${sevLabel}</span>
        </td>
        <td>
          <div style="font-size:12px; font-weight:600; color:var(--neutral-700);">${escapeHtml(s.created_at)}</div>
          <div style="font-size:11px; color:var(--neutral-400);">${escapeHtml(s.time_ago || '')}</div>
        </td>
        <td>
          <button class="ht-view-btn" onclick="openStaffAdvisoryModal(${s.id})" title="Add Agronomist Advice" style="display:inline-flex; align-items:center; gap:5px; font-size:12px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            <span>${hasNote ? 'Edit Advice' : 'Add Advice'}</span>
          </button>
        </td>
      </tr>
    `;
  }).join('');
}

function filterStaffScans() {
  const query = (document.getElementById('staffScansSearch') || {}).value || '';
  const q = query.toLowerCase().trim();

  let filtered = staffScansList.filter(s => {
    const matchQuery = !q ||
      (s.farmer_name && s.farmer_name.toLowerCase().includes(q)) ||
      (s.location && s.location.toLowerCase().includes(q)) ||
      (s.disease_name && s.disease_name.toLowerCase().includes(q));

    if (!matchQuery) return false;

    if (staffActiveDiseaseFilter === 'all') return true;
    if (staffActiveDiseaseFilter === 'urgent') return s.severity === 'severe' || s.severity === 'moderate';
    if (staffActiveDiseaseFilter === 'healthy') return s.severity === 'healthy';

    const dName = (s.disease_name || '').toLowerCase();
    if (staffActiveDiseaseFilter === 'blb') return dName.includes('bacterial') || dName.includes('blb') || dName.includes('blight');
    if (staffActiveDiseaseFilter === 'blast') return dName.includes('blast');
    if (staffActiveDiseaseFilter === 'brown_spot') return dName.includes('brown');
    if (staffActiveDiseaseFilter === 'tungro') return dName.includes('tungro');

    return true;
  });

  renderStaffScansTable(filtered);
}

function filterStaffScansByDisease(diseaseKey, chipEl) {
  staffActiveDiseaseFilter = diseaseKey;
  document.querySelectorAll('.staff-filters-row .staff-chip').forEach(c => c.classList.remove('active'));
  if (chipEl) chipEl.classList.add('active');
  filterStaffScans();
}

function renderBarangayCards(barangays) {
  const container = document.getElementById('staffBarangayCardsContainer');
  if (!container) return;

  if (!barangays || barangays.length === 0) {
    container.innerHTML = '<div class="empty-table-msg">No location surveillance data currently logged.</div>';
    return;
  }

  container.innerHTML = barangays.map(b => {
    const statusMap = {
      outbreak: { label: 'OUTBREAK ALERT', class: 'outbreak' },
      watch: { label: 'UNDER SURVEILLANCE', class: 'watch' },
      normal: { label: 'NORMAL / STABLE', class: 'normal' },
    };
    const st = statusMap[b.status] || statusMap.normal;

    return `
      <div class="barangay-card ${b.status}">
        <div class="b-card-header">
          <div>
            <h4 class="b-name">📍 ${escapeHtml(b.location)}</h4>
            <div class="b-scans-count">${b.total_scans} Total Farm Reports</div>
          </div>
          <span class="b-status-badge ${st.class}">${st.label}</span>
        </div>

        <div class="b-stats-breakdown">
          <div class="b-stat-pill"><strong>${b.blb}</strong> BLB</div>
          <div class="b-stat-pill"><strong>${b.blast}</strong> Blast</div>
          <div class="b-stat-pill"><strong>${b.brown_spot}</strong> Brown Spot</div>
          <div class="b-stat-pill"><strong>${b.tungro}</strong> Tungro</div>
          <div class="b-stat-pill green"><strong>${b.healthy}</strong> Healthy</div>
        </div>

        <div class="b-severity-bar">
          <div style="font-size:11.5px; font-weight:700; color:var(--neutral-600); margin-bottom:4px; display:flex; justify-content:space-between;">
            <span>Severe: ${b.severe}</span>
            <span>Moderate: ${b.moderate}</span>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

function openStaffAdvisoryModal(scanId) {
  const scan = staffScansList.find(s => s.id === scanId);
  if (!scan) return;

  const idInput = document.getElementById('staffAdvisoryScanId');
  if (idInput) idInput.value = scan.id;

  const preview = document.getElementById('staffAdvisoryPreview');
  if (preview) {
    const sev = (scan.severity || 'healthy').toLowerCase();
    let sevDisplay = 'HEALTHY';
    if (sev === 'mild') sevDisplay = 'MILD (≤ 25%)';
    else if (sev === 'moderate') sevDisplay = 'MODERATE (26% - 60%)';
    else if (sev === 'severe') sevDisplay = 'SEVERE (> 60%)';

    preview.innerHTML = `
      <div style="display:flex; align-items:center; gap:12px;">
        ${scan.image_url ? `<img src="${scan.image_url}" style="width:50px; height:50px; border-radius:6px; object-fit:cover;">` : ''}
        <div>
          <div style="font-weight:700; font-size:14px; color:var(--neutral-900);">${escapeHtml(scan.farmer_name)} · <span style="font-weight:500; font-size:12px; color:var(--neutral-500);">${escapeHtml(scan.location)}</span></div>
          <div style="font-size:13px; font-weight:700; color:var(--brand-green); margin-top:2px;">${escapeHtml(scan.disease_name)} (${sevDisplay})</div>
        </div>
      </div>
    `;
  }

  const txt = document.getElementById('staffAdvisoryText');
  if (txt) {
    txt.value = scan.notes ? scan.notes.replace(/^\[DA Extension Note by [^\]]+\]:\s*/, '') : '';
  }

  const err = document.getElementById('staffAdvisoryError');
  const ok = document.getElementById('staffAdvisorySuccess');
  if (err) err.classList.remove('show');
  if (ok) ok.classList.remove('show');

  openModal('modalStaffAdvisory');
}

async function handleStaffAdvisorySubmit(e) {
  if (e) e.preventDefault();

  const idInput = document.getElementById('staffAdvisoryScanId');
  const scanId = idInput ? idInput.value : null;
  const txtInput = document.getElementById('staffAdvisoryText');
  const advisory = txtInput ? txtInput.value.trim() : '';

  const errBanner = document.getElementById('staffAdvisoryError');
  const okBanner = document.getElementById('staffAdvisorySuccess');
  const submitBtn = document.getElementById('staffAdvisorySubmitBtn');

  if (!scanId || !advisory) return;

  if (errBanner) errBanner.classList.remove('show');
  if (okBanner) okBanner.classList.remove('show');
  if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Saving Advisory...'; }

  await ensureCsrfCookie();
  fetch(apiUrl(`/staff/scan/${scanId}/advisory`), {
    method: 'POST',
    credentials: 'include',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify({ advisory: advisory }),
  })
    .then(r => r.json())
    .then(data => {
      if (data && data.success) {
        if (okBanner) {
          okBanner.textContent = data.message || 'Advisory note successfully saved!';
          okBanner.classList.add('show');
        }
        loadStaffDashboard();
        setTimeout(() => {
          closeModal('modalStaffAdvisory');
          if (okBanner) okBanner.classList.remove('show');
        }, 800);
      } else {
        if (errBanner) {
          errBanner.textContent = data.message || 'Failed to save advisory.';
          errBanner.classList.add('show');
        }
      }
    })
    .catch(() => {
      if (errBanner) {
        errBanner.textContent = 'Network error. Please try again.';
        errBanner.classList.add('show');
      }
    })
    .finally(() => {
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Submit Official Advisory'; }
    });
}

/* ═══════════ LIVE REAL-TIME CLOCK WIDGET ═══════════ */
function initLiveClock() {
  const dateEl = document.getElementById('topbarLiveDate');
  const timeEl = document.getElementById('topbarLiveTime');
  if (!dateEl && !timeEl) return;

  function updateClock() {
    const now = new Date();
    
    // Format Date: e.g. "Thu, Oct 1, 2026"
    const dateOptions = { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' };
    const dateStr = now.toLocaleDateString('en-US', dateOptions);

    // Format Time: e.g. "08:38:52 AM"
    const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
    const timeStr = now.toLocaleTimeString('en-US', timeOptions);

    if (dateEl) dateEl.textContent = dateStr;
    if (timeEl) timeEl.textContent = timeStr;
  }

  updateClock();
  setInterval(updateClock, 1000);
}

/* ═══════════ 11F: ADMIN LOGIN SECURITY & LOCKOUT SETTINGS ═══════════ */
let adminCurrentSecurity = { max_login_attempts: 3, lockout_duration_seconds: 30 };

async function loadAdminSecuritySettings() {
  const successBanner = document.getElementById('adminSecuritySuccessBanner');
  const errBanner = document.getElementById('adminSecurityErrorBanner');
  if (successBanner) { successBanner.classList.remove('show'); successBanner.textContent = ''; }
  if (errBanner) { errBanner.classList.remove('show'); errBanner.textContent = ''; }

  try {
    const res = await fetch(apiUrl('/admin/security-settings'), {
      headers: authHeaders(),
    });
    const data = await res.json();
    if (res.ok && data && data.success && data.settings) {
      adminCurrentSecurity = data.settings;
      renderAdminSecurityUI(adminCurrentSecurity);
    }
  } catch (e) {
    console.error('Failed to load admin security settings:', e);
  }
}

function renderAdminSecurityUI(settings) {
  const maxAtt = settings.max_login_attempts || 3;
  const lockSec = settings.lockout_duration_seconds || 30;

  const dispMax = document.getElementById('dispActiveMaxAttempts');
  const dispLock = document.getElementById('dispActiveLockoutSeconds');
  const inputMax = document.getElementById('adminMaxAttemptsInput');
  const inputLock = document.getElementById('adminLockoutDurationInput');

  if (dispMax) dispMax.textContent = maxAtt + ' Attempts';
  if (dispLock) {
    let durStr = lockSec + ' Seconds';
    if (lockSec >= 60) {
      const mins = Math.round((lockSec / 60) * 10) / 10;
      durStr = mins + (mins === 1 ? ' Minute' : ' Minutes');
    }
    dispLock.textContent = durStr;
  }

  if (inputMax) inputMax.value = maxAtt;
  if (inputLock) inputLock.value = lockSec;

  syncAttemptPills(maxAtt);
  syncDurationPills(lockSec);
}

function selectAttemptPreset(num, btnEl) {
  const input = document.getElementById('adminMaxAttemptsInput');
  if (input) input.value = num;
  syncAttemptPills(num);
}

function selectDurationPreset(seconds, btnEl) {
  const input = document.getElementById('adminLockoutDurationInput');
  if (input) input.value = seconds;
  syncDurationPills(seconds);
}

function onCustomAttemptChange() {
  const input = document.getElementById('adminMaxAttemptsInput');
  const val = parseInt(input ? input.value : '0', 10);
  syncAttemptPills(val);
}

function onCustomDurationChange() {
  const input = document.getElementById('adminLockoutDurationInput');
  const val = parseInt(input ? input.value : '0', 10);
  syncDurationPills(val);
}

function syncAttemptPills(val) {
  document.querySelectorAll('#attemptPillsContainer .sec-preset-pill').forEach(btn => {
    const txt = (btn.textContent || '').trim();
    const match = txt.startsWith(val + ' ');
    btn.classList.toggle('active', match);
  });
}

function syncDurationPills(val) {
  document.querySelectorAll('#durationPillsContainer .sec-preset-pill').forEach(btn => {
    const txt = (btn.textContent || '').trim();
    let match = false;
    if (val === 30 && txt.startsWith('30')) match = true;
    else if (val === 60 && txt.startsWith('1 Minute')) match = true;
    else if (val === 120 && txt.startsWith('2 Minutes')) match = true;
    else if (val === 300 && txt.startsWith('5 Minutes')) match = true;
    btn.classList.toggle('active', match);
  });
}

let adminSecuritySuccessTimeout = null;
let adminSecurityErrorTimeout = null;

function showAdminSecuritySuccess(msg, autoHideMs = 3000) {
  const successBanner = document.getElementById('adminSecuritySuccessBanner');
  if (adminSecuritySuccessTimeout) {
    clearTimeout(adminSecuritySuccessTimeout);
    adminSecuritySuccessTimeout = null;
  }
  if (successBanner) {
    successBanner.textContent = msg;
    successBanner.classList.add('show');
  }
  if (autoHideMs && autoHideMs > 0) {
    adminSecuritySuccessTimeout = setTimeout(() => {
      if (successBanner) {
        successBanner.classList.remove('show');
        successBanner.textContent = '';
      }
    }, autoHideMs);
  }
}

function showAdminSecurityError(msg, autoHideMs = 3000) {
  const errBanner = document.getElementById('adminSecurityErrorBanner');
  if (adminSecurityErrorTimeout) {
    clearTimeout(adminSecurityErrorTimeout);
    adminSecurityErrorTimeout = null;
  }
  if (errBanner) {
    errBanner.textContent = msg;
    errBanner.classList.add('show');
  }
  if (autoHideMs && autoHideMs > 0) {
    adminSecurityErrorTimeout = setTimeout(() => {
      if (errBanner) {
        errBanner.classList.remove('show');
        errBanner.textContent = '';
      }
    }, autoHideMs);
  }
}

async function saveAdminSecuritySettings() {
  const inputMax = document.getElementById('adminMaxAttemptsInput');
  const inputLock = document.getElementById('adminLockoutDurationInput');
  const btn = document.getElementById('btnSaveSecuritySettings');
  const successBanner = document.getElementById('adminSecuritySuccessBanner');
  const errBanner = document.getElementById('adminSecurityErrorBanner');

  if (adminSecuritySuccessTimeout) {
    clearTimeout(adminSecuritySuccessTimeout);
    adminSecuritySuccessTimeout = null;
  }
  if (adminSecurityErrorTimeout) {
    clearTimeout(adminSecurityErrorTimeout);
    adminSecurityErrorTimeout = null;
  }
  if (successBanner) { successBanner.classList.remove('show'); successBanner.textContent = ''; }
  if (errBanner) { errBanner.classList.remove('show'); errBanner.textContent = ''; }

  const maxAttempts = parseInt(inputMax ? inputMax.value : '3', 10);
  const lockoutSeconds = parseInt(inputLock ? inputLock.value : '30', 10);

  if (isNaN(maxAttempts) || maxAttempts < 1 || maxAttempts > 20) {
    showAdminSecurityError('Maximum attempts must be between 1 and 20.', 3000);
    return;
  }

  if (isNaN(lockoutSeconds) || lockoutSeconds < 5 || lockoutSeconds > 3600) {
    showAdminSecurityError('Lockout penalty must be between 5 seconds and 3600 seconds.', 3000);
    return;
  }

  if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }

  try {
    const res = await fetch(apiUrl('/admin/security-settings'), {
      method: 'POST',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({
        max_login_attempts: maxAttempts,
        lockout_duration_seconds: lockoutSeconds,
      }),
    });

    const data = await res.json();
    if (res.ok && data && data.success) {
      showAdminSecuritySuccess(data.message || 'Login Security Settings updated successfully!', 3000);
      if (data.settings) {
        adminCurrentSecurity = data.settings;
        renderAdminSecurityUI(adminCurrentSecurity);
      }
    } else {
      showAdminSecurityError((data && data.message) ? data.message : 'Failed to update security settings.', 3000);
    }
  } catch (e) {
    console.error('Failed to save security settings:', e);
    showAdminSecurityError('Could not connect to the server. Please check your connection.', 3000);
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg><span>Save Security Settings</span>';
    }
  }
}

/* ═══════════ INITIALIZATION ═══════════ */
document.addEventListener('DOMContentLoaded', function () {
  initLiveClock();

  const savedLang = window.localStorage ? window.localStorage.getItem('oryzatix_ui_lang') : null;
  if (savedLang) setUILanguage(savedLang);

  const fileInput = document.getElementById('fileInput');
  if (fileInput) {
    fileInput.addEventListener('change', handleFileUpload);
  }

  if (typeof initAiVoiceMuteUI === 'function') {
    initAiVoiceMuteUI();
  }

  checkLoginLockoutState();
  fetchSecurityConfig();

  checkAuthAndProceed();
});