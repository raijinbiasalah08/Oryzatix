import 'package:flutter/material.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/network/api_exception.dart';
import '../models/history_model.dart';

class HistoryProvider extends ChangeNotifier {
  Map<String, List<HistoryItem>> _scansByDate = {};
  HistoryStats _stats = HistoryStats(total: 0, healthy: 0, mild: 0, moderate: 0, severe: 0);
  bool _isLoading = false;
  String? _errorMessage;

  Map<String, List<HistoryItem>> get scansByDate => _scansByDate;
  HistoryStats get stats => _stats;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  HistoryProvider() {
    loadHistory();
  }

  Future<void> loadHistory({bool isSilent = false}) async {
    if (!isSilent) {
      _isLoading = true;
      _errorMessage = null;
      notifyListeners();
    }

    try {
      final res = await ApiClient.get(ApiEndpoints.scanHistory);
      if (res is Map<String, dynamic> && res['success'] == true) {
        if (res['stats'] != null) {
          _stats = HistoryStats.fromJson(res['stats']);
        }

        final rawScans = res['scans'];
        final Map<String, List<HistoryItem>> grouped = {};

        if (rawScans is Map) {
          rawScans.forEach((dateKey, items) {
            if (items is List) {
              grouped[dateKey.toString()] = items
                  .map((it) => HistoryItem.fromJson(it as Map<String, dynamic>))
                  .toList();
            }
          });
        }

        _scansByDate = grouped;
        _errorMessage = null;
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
        // Remove locally from map
        _scansByDate.forEach((key, list) {
          list.removeWhere((item) => item.id == id);
        });
        _scansByDate.removeWhere((key, list) => list.isEmpty);
        notifyListeners();
        return true;
      }
    } catch (_) {}
    return false;
  }
}
