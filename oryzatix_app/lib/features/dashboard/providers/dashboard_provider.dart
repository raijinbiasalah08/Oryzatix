import 'package:flutter/material.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/network/api_exception.dart';
import '../models/dashboard_stats_model.dart';

class DashboardProvider extends ChangeNotifier {
  DashboardStatsModel _stats = DashboardStatsModel.empty();
  bool _isLoading = false;
  String? _errorMessage;

  DashboardStatsModel get stats => _stats;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  DashboardProvider() {
    loadStats();
  }

  Future<void> loadStats({bool isSilent = false}) async {
    if (!isSilent) {
      _isLoading = true;
      _errorMessage = null;
      notifyListeners();
    }

    try {
      dynamic res;
      try {
        res = await ApiClient.get(ApiEndpoints.dashboardStats);
      } catch (_) {
        // Fallback to public stats if unauthenticated
        res = await ApiClient.get(ApiEndpoints.dashboardPublicStats);
      }

      if (res is Map<String, dynamic> && res['success'] == true) {
        _stats = DashboardStatsModel.fromJson(res);
        _errorMessage = null;
      }
    } on ApiException catch (e) {
      _errorMessage = e.message;
    } catch (e) {
      _errorMessage = 'Failed to load dashboard data: ${e.toString()}';
    }

    _isLoading = false;
    notifyListeners();
  }
}
