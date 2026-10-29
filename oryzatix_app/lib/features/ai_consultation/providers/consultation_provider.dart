import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/network/api_exception.dart';
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
    if (trimmed.isEmpty || _isSending || _isLoading) return false;
    final language = _activeLanguage;

    final nowStr = DateFormat('h:mm a').format(DateTime.now());

    // Optimistically add user message
    final pendingMessage = ChatMessageModel(
      role: 'user',
      content: trimmed,
      language: language,
      time: nowStr,
    );
    _messages.add(pendingMessage);
    _isSending = true;
    _errorMessage = null;
    notifyListeners();

    try {
      final res = await ApiClient.post(ApiEndpoints.consultationSend, body: {
        'message': trimmed,
        'language': language,
      });

      if (res is Map<String, dynamic> && res['success'] == true &&
          res['ai_response'] is String && (res['ai_response'] as String).trim().isNotEmpty) {
        final aiContent = (res['ai_response'] as String).trim();
        final aiTime = res['time']?.toString() ?? nowStr;

        _messages.add(ChatMessageModel(
          role: 'ai',
          content: aiContent,
          language: res['language']?.toString() ?? language,
          time: aiTime,
        ));
        return true;
      }
      throw ApiException(message: 'The consultation service returned no answer. Please try again.');
    } catch (e) {
      _messages.remove(pendingMessage);
      _errorMessage = 'Failed to get answer: ${e.toString()}';
      return false;
    } finally {
      _isSending = false;
      notifyListeners();
    }
  }

  Future<void> clearMessages() async {
    if (_isSending || _isLoading) return;
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();
    try {
      final res = await ApiClient.delete(ApiEndpoints.consultationClear);
      if (res is! Map || res['success'] != true) {
        throw ApiException(message: 'Could not clear the conversation.');
      }
      _messages.clear();
      _errorMessage = null;
    } catch (e) {
      _errorMessage = 'Could not clear messages: ${e.toString()}';
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }
}
