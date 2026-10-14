import 'dart:typed_data';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/network/api_exception.dart';
import '../models/scan_result_model.dart';
import '../services/mobilenet_classifier.dart';

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
  final MobileNetClassifier _classifier = MobileNetClassifier.instance;

  bool get usesOnDeviceModel => _classifier.supportsOnDeviceInference;

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
      final prediction = usesOnDeviceModel
          ? await _classifier.classify(_selectedImageBytes!)
          : null;

      if (prediction != null &&
          prediction.diseaseKey == 'not_rice_leaf' &&
          !prediction.needsReview) {
        _errorMessage =
            'This image does not appear to show a rice leaf. Choose a clear, close-up photo of one rice leaf.';
        _isAnalyzing = false;
        notifyListeners();
        return false;
      }

      if (prediction != null && prediction.needsReview) {
        _errorMessage =
            'The MobileNetV2 model is unsure (${(prediction.confidence * 100).toStringAsFixed(1)}%). Try a clearer, closer rice-leaf photo.';
        _isAnalyzing = false;
        notifyListeners();
        return false;
      }

      final res = await ApiClient.uploadFile(
        path: ApiEndpoints.uploadScan,
        fileBytes: _selectedImageBytes!,
        filename: _selectedImageName ?? 'leaf_scan.jpg',
        extraFields: prediction == null
            ? null
            : {
                'model_disease_key': prediction.diseaseKey,
                'model_confidence': prediction.confidence.toString(),
              },
      );

      if (res is Map<String, dynamic> && res['success'] == true) {
        if (res['recognized'] != true) {
          _errorMessage =
              res['message']?.toString() ??
              'The image could not be recognized. Try another rice-leaf photo.';
          _isAnalyzing = false;
          notifyListeners();
          return false;
        }

        _result = ScanResultModel.fromJson(res);
        _isAnalyzing = false;
        notifyListeners();
        return true;
      } else {
        _errorMessage = res is Map<String, dynamic>
            ? res['message']?.toString() ??
                  'Analysis could not recognize this image.'
            : 'Analysis could not recognize this image.';
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
