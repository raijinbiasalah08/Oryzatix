import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

class AppConfig {
  static const String envApiBase = String.fromEnvironment('API_BASE_URL', defaultValue: '');
  static const String defaultProductionRenderBase = 'https://oryzatix-backend.onrender.com/api/v1';

  static const String defaultWebWindowsBase = 'http://localhost/Oryzatix/public/api/v1';
  static const String defaultAndroidEmulatorBase = 'http://10.0.2.2/Oryzatix/public/api/v1';
  static const String defaultLanBase = 'http://192.168.1.34/Oryzatix/public/api/v1';

  static const String _prefKeyBaseUrl = 'oryzatix_api_base_url';

  static String _currentBaseUrl = '';

  static String get defaultBaseUrl {
    if (envApiBase.isNotEmpty) {
      return envApiBase;
    }
    if (kIsWeb) {
      // In web production on Vercel or external domain, default to Render backend
      final uri = Uri.base;
      if (uri.host != 'localhost' && uri.host != '127.0.0.1' && uri.host.isNotEmpty) {
        return defaultProductionRenderBase;
      }
      return defaultWebWindowsBase;
    }
    switch (defaultTargetPlatform) {
      case TargetPlatform.android:
        return defaultAndroidEmulatorBase;
      case TargetPlatform.windows:
      case TargetPlatform.linux:
      case TargetPlatform.macOS:
        return defaultWebWindowsBase;
      case TargetPlatform.iOS:
      case TargetPlatform.fuchsia:
        return defaultLanBase;
    }
  }

  static String get baseUrl {
    if (_currentBaseUrl.isNotEmpty) {
      return _currentBaseUrl;
    }
    return defaultBaseUrl;
  }

  static Future<void> init() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final savedUrl = prefs.getString(_prefKeyBaseUrl);
      if (savedUrl != null && savedUrl.trim().isNotEmpty) {
        _currentBaseUrl = savedUrl.trim();
      } else {
        _currentBaseUrl = defaultBaseUrl;
      }
    } catch (_) {
      _currentBaseUrl = defaultBaseUrl;
    }
  }

  static Future<void> setBaseUrl(String newUrl) async {
    final cleaned = newUrl.trim().replaceAll(RegExp(r'/+$'), '');
    _currentBaseUrl = cleaned;
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_prefKeyBaseUrl, cleaned);
    } catch (_) {}
  }

  static Future<void> resetToDefault() async {
    _currentBaseUrl = defaultBaseUrl;
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_prefKeyBaseUrl);
    } catch (_) {}
  }
}
