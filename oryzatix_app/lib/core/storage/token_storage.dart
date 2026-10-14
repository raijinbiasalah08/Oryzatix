import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

class TokenStorage {
  static const String _keyToken = 'oryzatix_sanctum_token';
  static const String _keyUser = 'oryzatix_user_cache';
  static const String _keyChatLanguage = 'oryzatix_chat_language';

  static const _secureStorage = FlutterSecureStorage();

  static Future<void> saveToken(String token) async {
    try {
      await _secureStorage.write(key: _keyToken, value: token);
    } catch (_) {}
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_keyToken, token);
    } catch (_) {}
  }

  static Future<String?> getToken() async {
    try {
      final secureVal = await _secureStorage.read(key: _keyToken);
      if (secureVal != null && secureVal.isNotEmpty) {
        return secureVal;
      }
    } catch (_) {}

    try {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString(_keyToken);
    } catch (_) {}
    return null;
  }

  static Future<void> saveUser(Map<String, dynamic> userMap) async {
    final str = jsonEncode(userMap);
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_keyUser, str);
    } catch (_) {}
  }

  static Future<Map<String, dynamic>?> getUser() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final str = prefs.getString(_keyUser);
      if (str != null && str.isNotEmpty) {
        return jsonDecode(str) as Map<String, dynamic>;
      }
    } catch (_) {}
    return null;
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
