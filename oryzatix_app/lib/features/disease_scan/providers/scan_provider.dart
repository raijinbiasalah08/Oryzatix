import 'dart:typed_data';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/network/api_exception.dart';
import '../models/scan_result_model.dart';

class ScanProvider extends ChangeNotifier {
  Uint8List? _selectedImageBytes;
  String? _selectedImageName;
  bool _isAnalyzing = false;
  String? _errorMessage;
  ScanResultModel? _result;

  Uint8List? get selectedImageBytes => _selectedImageBytes;
  String? get selectedImageName => _selectedImageName;
  bool get isAnalyzing => _isAnalyzing;
  String? get errorMessage => _errorMessage;
  ScanResultModel? get result => _result;

  final ImagePicker _picker = ImagePicker();
  bool get usesServerValidation => true;

  Future<void> pickFromCamera() async {
    try {
      final XFile? photo = await _picker.pickImage(
        source: ImageSource.camera,
        maxWidth: 1600,
        maxHeight: 1600,
        imageQuality: 85,
      );
      if (photo != null) {
        final bytes = await photo.readAsBytes();
        _selectedImageBytes = bytes;
        _selectedImageName = photo.name.isNotEmpty
            ? photo.name
            : 'camera_leaf_${DateTime.now().millisecondsSinceEpoch}.jpg';
        _result = null;
        _errorMessage = null;
        notifyListeners();
      }
    } catch (e) {
      _errorMessage = 'Could not access camera: ${e.toString()}';
      notifyListeners();
    }
  }

  Future<void> pickFromGallery() async {
    try {
      final XFile? image = await _picker.pickImage(
        source: ImageSource.gallery,
        maxWidth: 1600,
        maxHeight: 1600,
        imageQuality: 85,
      );
      if (image != null) {
        final bytes = await image.readAsBytes();
        _selectedImageBytes = bytes;
        _selectedImageName = image.name.isNotEmpty
            ? image.name
            : 'gallery_leaf_${DateTime.now().millisecondsSinceEpoch}.jpg';
        _result = null;
        _errorMessage = null;
        notifyListeners();
      }
    } catch (_) {
      // Fallback to FilePicker if ImagePicker fails on Desktop
      await pickFromFilePicker();
    }
  }

  Future<void> pickFromFilePicker() async {
    try {
      final files = await FilePicker.pickFiles(
        type: FileType.custom,
        allowedExtensions: ['jpg', 'jpeg', 'png', 'webp'],
      );
      if (files.isNotEmpty) {
        final file = files.first;
        final bytes = await file.xFile.readAsBytes();
        _selectedImageBytes = bytes;
        _selectedImageName = file.name;
        _result = null;
        _errorMessage = null;
        notifyListeners();
      }
    } catch (e) {
      _errorMessage = 'File selection failed: ${e.toString()}';
      notifyListeners();
    }
  }

  Future<bool> analyzeCurrentLeaf() async {
    if (_selectedImageBytes == null) {
      _errorMessage = 'Please select or capture a rice leaf image first.';
      notifyListeners();
      return false;
    }

    _isAnalyzing = true;
    _errorMessage = null;
    notifyListeners();

    try {
      final res = await ApiClient.uploadFile(
        path: ApiEndpoints.uploadScan,
        fileBytes: _selectedImageBytes!,
        filename: _selectedImageName ?? 'leaf_scan.jpg',
      );

      if (res is Map<String, dynamic>) {
        final scan = res['scan'] is Map<String, dynamic>
            ? res['scan'] as Map<String, dynamic>
            : <String, dynamic>{};
        const supportedDiseaseKeys = {
          'blast',
          'blb',
          'brown_spot',
          'healthy',
          'sheath_blight',
          'tungro',
        };
        final confidence = double.tryParse(scan['confidence']?.toString() ?? '');
        final confidenceIsValid = scan['confidence'] == null ||
            (confidence != null && confidence.isFinite && confidence >= 0 && confidence <= 100);
        final diagnosisIsValid = res['success'] == true &&
            res['recognized'] == true &&
            res['rice_leaf_validated'] == true &&
            scan['recognized'] == true &&
            supportedDiseaseKeys.contains(scan['disease_key']) &&
            (scan['disease']?.toString().trim().isNotEmpty ?? false) &&
            confidenceIsValid;

        if (diagnosisIsValid) {
          _result = ScanResultModel.fromJson(res);
          _isAnalyzing = false;
          notifyListeners();
          return true;
        }

        _errorMessage = res['message']?.toString() ??
            'The scanner did not confirm a clear rice-leaf image and a supported diagnosis.';
      }
    } on ApiException catch (e) {
      _errorMessage = e.message;
    } catch (e) {
      _errorMessage = 'Analysis error: ${e.toString()}';
    }

    _isAnalyzing = false;
    notifyListeners();
    return false;
  }

  void clear() {
    _selectedImageBytes = null;
    _selectedImageName = null;
    _result = null;
    _errorMessage = null;
    _isAnalyzing = false;
    notifyListeners();
  }
}
