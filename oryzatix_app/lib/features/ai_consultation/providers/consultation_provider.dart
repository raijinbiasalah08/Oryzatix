import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/storage/token_storage.dart';
import '../models/chat_message_model.dart';

class ConsultationProvider extends ChangeNotifier {
  List<ChatMessageModel> _messages = [];
  bool _isLoading = false;
  bool _isSending = false;
  String _activeLanguage = 'tagalog';
  String? _errorMessage;

  List<ChatMessageModel> get messages => _messages;
  bool get isLoading => _isLoading;
  bool get isSending => _isSending;
  String get activeLanguage => _activeLanguage;
  String? get errorMessage => _errorMessage;

  ConsultationProvider() {
    _init();
  }

  Future<void> _init() async {
    _activeLanguage = await TokenStorage.getChatLanguage();
    await loadMessages();
  }

  Future<void> setLanguage(String lang) async {
    _activeLanguage = lang;
    await TokenStorage.saveChatLanguage(lang);
    notifyListeners();
  }

  Future<void> loadMessages({bool isSilent = false}) async {
    if (!isSilent) {
      _isLoading = true;
      _errorMessage = null;
      notifyListeners();
    }

    try {
      final res = await ApiClient.get(ApiEndpoints.consultationMessages);
      if (res is Map<String, dynamic> && res['success'] == true) {
        final list = res['messages'] as List? ?? [];
        _messages = list
            .map((m) => ChatMessageModel.fromJson(m as Map<String, dynamic>))
            .toList();
        _errorMessage = null;
      }
    } catch (e) {
      _errorMessage = 'Could not load messages: ${e.toString()}';
    }

    _isLoading = false;
    notifyListeners();
  }

  Future<bool> sendMessage(String text) async {
    final trimmed = text.trim();
    if (trimmed.isEmpty) return false;

    final nowStr = DateFormat('h:mm a').format(DateTime.now());

    // Optimistically add user message
    _messages.add(ChatMessageModel(
      role: 'user',
      content: trimmed,
      language: _activeLanguage,
      time: nowStr,
    ));
    _isSending = true;
    _errorMessage = null;
    notifyListeners();

    try {
      final res = await ApiClient.post(ApiEndpoints.consultationSend, body: {
        'message': trimmed,
        'language': _activeLanguage,
      });

      if (res is Map<String, dynamic> && res['success'] == true) {
        final aiContent = res['ai_response']?.toString() ?? 'Received advisory.';
        final aiTime = res['time']?.toString() ?? nowStr;

        _messages.add(ChatMessageModel(
          role: 'ai',
          content: aiContent,
          language: _activeLanguage,
          time: aiTime,
        ));
      }
    } catch (e) {
      _errorMessage = 'Failed to get answer: ${e.toString()}';
    }

    _isSending = false;
    notifyListeners();
    return true;
  }

  Future<void> clearMessages() async {
    try {
      await ApiClient.delete(ApiEndpoints.consultationClear);
      _messages.clear();
      notifyListeners();
    } catch (_) {}
  }
}
