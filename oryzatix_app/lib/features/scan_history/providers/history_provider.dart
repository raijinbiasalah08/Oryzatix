import 'package:flutter/material.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/network/api_exception.dart';
import '../models/history_model.dart';

class HistoryProvider extends ChangeNotifier {
  Map<String, List<HistoryItem>> _scansByDate = {};
  HistoryStats _stats = HistoryStats(
    total: 0,
    healthy: 0,
    mild: 0,
    moderate: 0,
    severe: 0,
    unassessed: 0,
  );
  bool _isLoading = false;
  String? _errorMessage;

  Map<String, List<HistoryItem>> get scansByDate => _scansByDate;
  HistoryStats get stats => _stats;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  Future<void> loadHistory() async {
    if (_isLoading) return;

    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    try {
      final res = await ApiClient.get(ApiEndpoints.scanHistory);
      if (res is! Map || res['success'] != true) {
        throw ApiException(
          message: res is Map
              ? (res['message']?.toString() ?? 'The server could not load scan history.')
              : 'The server returned an invalid scan-history response.',
        );
      }

      final grouped = <String, List<HistoryItem>>{};
      final rawScans = res['scans'];

      if (rawScans is Map) {
        for (final entry in rawScans.entries) {
          if (entry.value is List) {
            grouped[entry.key.toString()] = (entry.value as List)
                .whereType<Map>()
                .map((item) => HistoryItem.fromJson(_stringKeyedMap(item)))
                .toList();
          }
        }
      } else if (rawScans is List) {
        for (final value in rawScans.whereType<Map>()) {
          final item = HistoryItem.fromJson(_stringKeyedMap(value));
          final dateKey = item.date.isEmpty ? 'Other dates' : item.date;
          (grouped[dateKey] ??= <HistoryItem>[]).add(item);
        }
      }

      _scansByDate = grouped;
      if (res['stats'] is Map) {
        _stats = HistoryStats.fromJson(_stringKeyedMap(res['stats'] as Map));
      } else {
        _stats = HistoryStats.fromScans(grouped.values.expand((items) => items));
      }
    } on ApiException catch (e) {
      _errorMessage = e.message;
    } catch (e) {
      _errorMessage = 'Failed to load history: ${e.toString()}';
    }

    _isLoading = false;
    notifyListeners();
  }

  Future<bool> deleteScan(int id) async {
    try {
      final res = await ApiClient.delete(ApiEndpoints.deleteScan(id));
      if (res is Map && res['success'] == true) {
        _scansByDate.forEach((key, list) {
          list.removeWhere((item) => item.id == id);
        });
        _scansByDate.removeWhere((key, list) => list.isEmpty);
        _stats = HistoryStats.fromScans(_scansByDate.values.expand((items) => items));
        notifyListeners();
        await loadHistory();
        return true;
      }
    } catch (_) {}
    return false;
  }

  static Map<String, dynamic> _stringKeyedMap(Map value) =>
      value.map((key, item) => MapEntry(key.toString(), item));
}
