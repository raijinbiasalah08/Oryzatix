import 'dart:async';
import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:oryzatix_app/core/storage/token_storage.dart';
import 'package:oryzatix_app/features/auth/providers/auth_provider.dart';
import 'package:oryzatix_app/features/ai_consultation/providers/consultation_provider.dart';
import 'package:oryzatix_app/features/scan_history/providers/history_provider.dart';

Future<void> waitUntil(bool Function() done) async {
  for (var i = 0; i < 100; i++) {
    await Future<void>.delayed(const Duration(milliseconds: 1));
    if (done()) return;
  }
  fail('Provider did not finish its request');
}

http.Response jsonResponse(Object value, [int status = 200]) =>
    http.Response(jsonEncode(value), status, headers: {'content-type': 'application/json'});

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
  });

  for (final status in [401, 403, 503]) {
    test('auth handles server status $status without restoring a revoked login', () async {
      await TokenStorage.saveToken('saved-token');
      await TokenStorage.saveUser({'id': 1, 'name': 'Farmer'});
      await http.runWithClient(() async {
        final auth = AuthProvider();
        await waitUntil(() => !auth.isCheckingAuth);
        expect(auth.isAuthenticated, status == 503);
        expect(await TokenStorage.getToken(), status == 503 ? 'saved-token' : null);
        auth.dispose();
      }, () => MockClient((_) async => jsonResponse({'message': 'Unavailable'}, status)));
    });
  }

  for (final status in [500, 200]) {
    test('chat rejects failed or empty responses ($status) and permits retry', () async {
      var loaded = false;
      await http.runWithClient(() async {
        final chat = ConsultationProvider();
        await waitUntil(() => loaded && !chat.isLoading);
        expect(await chat.sendMessage('Rice question'), isFalse);
        expect(chat.messages, isEmpty);
        expect(chat.isSending, isFalse);
        expect(chat.errorMessage, isNotNull);
        chat.dispose();
      }, () => MockClient((request) async {
        if (request.method == 'GET') {
          loaded = true;
          return jsonResponse({'success': true, 'messages': []});
        }
        return jsonResponse({'success': true, 'ai_response': ''}, status);
      }));
    });
  }

  test('chat prevents duplicate sends and preserves the language of the request', () async {
    var loaded = false;
    var sends = 0;
    final reply = Completer<http.Response>();
    await http.runWithClient(() async {
      final chat = ConsultationProvider();
      await waitUntil(() => loaded && !chat.isLoading);
      final pending = chat.sendMessage('Rice question');
      expect(await chat.sendMessage('Duplicate'), isFalse);
      await chat.setLanguage('english');
      reply.complete(jsonResponse({'success': true, 'ai_response': 'Payo sa palay'}));
      expect(await pending, isTrue);
      expect(sends, 1);
      expect(chat.messages.length, 2);
      expect(chat.messages.last.language, 'tagalog');
      chat.dispose();
    }, () => MockClient((request) async {
      if (request.method == 'GET') {
        loaded = true;
        return jsonResponse({'success': true, 'messages': []});
      }
      sends++;
      return reply.future;
    }));
  });

  test('failed chat clearing keeps the conversation and exposes the error', () async {
    var loaded = false;
    await http.runWithClient(() async {
      final chat = ConsultationProvider();
      await waitUntil(() => loaded && !chat.isLoading);
      await chat.clearMessages();
      expect(chat.messages.length, 1);
      expect(chat.errorMessage, isNotNull);
      chat.dispose();
    }, () => MockClient((request) async {
      if (request.method == 'GET') {
        loaded = true;
        return jsonResponse({'success': true, 'messages': [{'role': 'user', 'content': 'Rice question'}]});
      }
      return jsonResponse({'message': 'Unavailable'}, 500);
    }));
  });

  test('deleting a scan refreshes the history counters', () async {
    var loads = 0;
    var deleted = false;
    await http.runWithClient(() async {
      final history = HistoryProvider();
      await waitUntil(() => loads > 0 && !history.isLoading);
      expect(history.stats.total, 1);
      expect(await history.deleteScan(7), isTrue);
      expect(history.stats.total, 0);
      expect(history.scansByDate, isEmpty);
      history.dispose();
    }, () => MockClient((request) async {
      if (request.method == 'DELETE') {
        deleted = true;
        return jsonResponse({'success': true});
      }
      loads++;
      return jsonResponse({
        'success': true,
        'stats': {'total': deleted ? 0 : 1, 'healthy': deleted ? 0 : 1},
        'scans': deleted ? {} : {'Today': [{'id': 7, 'disease': 'Healthy Rice Leaf', 'severity': 'healthy'}]},
      });
    }));
  });
}
