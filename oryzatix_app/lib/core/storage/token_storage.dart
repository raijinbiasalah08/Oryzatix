import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

class TokenStorage {
  static const String _keyToken = 'oryzatix_sanctum_token';
  static const String _keyUser = 'oryzatix_user_cache';
  static const String _keyChatLanguage = 'oryzatix_chat_language';

  static const _secureStorage = FlutterSecureStorage();
  static String? _sessionToken;
  static Map<String, dynamic>? _sessionUser;
  static bool _legacyAuthPurged = false;

  // Authentication is session-only. Remove credentials left by older app
  // versions once, then keep the current login in process memory only.
  static Future<void> _purgeLegacyPersistentAuth() async {
    if (_legacyAuthPurged) return;
    _legacyAuthPurged = true;

    try {
      await _secureStorage.delete(key: _keyToken);
    } catch (_) {}
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_keyToken);
      await prefs.remove(_keyUser);
    } catch (_) {}
  }

  static Future<void> saveToken(String token) async {
    await _purgeLegacyPersistentAuth();
    _sessionToken = token;
  }

  static Future<String?> getToken() async {
    await _purgeLegacyPersistentAuth();
    return _sessionToken;
  }

  static Future<void> saveUser(Map<String, dynamic> userMap) async {
    await _purgeLegacyPersistentAuth();
    _sessionUser = Map<String, dynamic>.from(userMap);
  }

  static Future<Map<String, dynamic>?> getUser() async {
    await _purgeLegacyPersistentAuth();
    final user = _sessionUser;
    return user == null ? null : Map<String, dynamic>.from(user);
  }

  static Future<void> saveChatLanguage(String lang) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_keyChatLanguage, lang);
    } catch (_) {}
  }

  static Future<String> getChatLanguage() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString(_keyChatLanguage) ?? 'tagalog';
    } catch (_) {}
    return 'tagalog';
  }

  static Future<void> clearAuth() async {
    _sessionToken = null;
    _sessionUser = null;
    try {
      await _secureStorage.delete(key: _keyToken);
    } catch (_) {}
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_keyToken);
      await prefs.remove(_keyUser);
    } catch (_) {}
  }
}
