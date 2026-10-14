import 'package:flutter/material.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../models/treatment_guide_model.dart';

class TreatmentProvider extends ChangeNotifier {
  List<DiseaseGuideModel> _guides = [];
  int _selectedIndex = 0;
  String _selectedSeverity = 'moderate';
  bool _isLoading = false;
  String? _errorMessage;

  List<DiseaseGuideModel> get guides => _guides;
  int get selectedIndex => _selectedIndex;
  DiseaseGuideModel? get selectedGuide => _guides.isNotEmpty && _selectedIndex < _guides.length ? _guides[_selectedIndex] : null;
  String get selectedSeverity => _selectedSeverity;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  TreatmentProvider() {
    loadGuides();
  }

  void selectDisease(int index) {
    if (index >= 0 && index < _guides.length) {
      _selectedIndex = index;
      notifyListeners();
    }
  }

  void setSeverity(String sev) {
    _selectedSeverity = sev;
    notifyListeners();
  }

  Future<void> loadGuides({bool isSilent = false}) async {
    if (!isSilent) {
      _isLoading = true;
      _errorMessage = null;
      notifyListeners();
    }

    try {
      final res = await ApiClient.get(ApiEndpoints.treatmentGuides);
      if (res is Map<String, dynamic> && res['success'] == true) {
        final list = res['data'] as List? ?? [];
        _guides = list.map((g) => DiseaseGuideModel.fromJson(g as Map<String, dynamic>)).toList();
        _errorMessage = null;
      }
    } catch (e) {
      _errorMessage = 'Could not load treatment guides: ${e.toString()}';
    }

    _isLoading = false;
    notifyListeners();
  }
}
