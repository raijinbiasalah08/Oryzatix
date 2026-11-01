import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/network/api_exception.dart';
import '../models/scan_result_model.dart';
import '../services/mobilenet_classifier.dart';
import '../services/offline_scan_store.dart';
import '../services/rice_leaf_prediction.dart';

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
  bool get supportsOfflineScanning =>
      MobileNetClassifier.instance.supportsOnDeviceInference;
  bool get usesServerValidation => true;

  final ImagePicker _picker = ImagePicker();

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

  Future<bool> analyzeCurrentLeaf({bool guestMode = false}) async {
    if (_selectedImageBytes == null) {
      _errorMessage = 'Please select or capture a rice leaf image first.';
      notifyListeners();
      return false;
    }
    if (_selectedImageBytes!.length > 10 * 1024 * 1024) {
      _errorMessage = 'This image is larger than the 10 MB limit. Choose a smaller photo.';
      notifyListeners();
      return false;
    }

    _isAnalyzing = true;
    _errorMessage = null;
    notifyListeners();

    try {
      if (guestMode && supportsOfflineScanning) {
        return await _analyzeOnDeviceAndSave();
      }
      if (guestMode) {
        _errorMessage = 'Guest scanning is available offline in the Android or iPhone app. This platform has no offline scanner.';
        return false;
      }

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
            res['saved'] == true &&
            scan['recognized'] == true &&
            int.tryParse(scan['id']?.toString() ?? '') != null &&
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
            (res['success'] == true && res['saved'] != true
                ? 'The scan could not be saved to history. Please try again.'
                : 'The scanner did not confirm a clear rice-leaf image and a supported diagnosis.');
      }
    } on ApiException catch (e) {
      final message = e.message.toLowerCase();
      final looksLikeNetworkFailure = message.contains('connection error') ||
          message.contains('socketexception') ||
          message.contains('failed host lookup') ||
          message.contains('network is unreachable');
      if (!guestMode && supportsOfflineScanning && looksLikeNetworkFailure) {
        try {
          return await _analyzeOnDeviceAndSave();
        } catch (offlineError) {
          _errorMessage = 'Offline scan failed: ${offlineError.toString()}';
        }
      } else {
        _errorMessage = e.message;
      }
    } catch (e) {
      _errorMessage = 'Analysis error: ${e.toString()}';
    }

    _isAnalyzing = false;
    notifyListeners();
    return false;
  }

  Future<bool> _analyzeOnDeviceAndSave() async {
    final prediction = await MobileNetClassifier.instance.classify(_selectedImageBytes!);
    if (!prediction.isDiagnosed) {
      _errorMessage = _messageForPrediction(prediction);
      _isAnalyzing = false;
      notifyListeners();
      return false;
    }

    final now = DateTime.now();
    final id = -now.microsecondsSinceEpoch;
    final isHealthy = prediction.diseaseKey == 'healthy';
    final severity = isHealthy ? 'healthy' : 'unassessed';
    final imagePath = await OfflineScanStore.instance.saveScan(
      id: id,
      imageBytes: _selectedImageBytes!,
      filename: _selectedImageName ?? 'guest_scan.jpg',
      scan: {
        'disease_key': prediction.diseaseKey,
        'disease': prediction.diseaseName,
        'scientific': _scientificName(prediction.diseaseKey),
        'confidence': (prediction.confidence * 100).toStringAsFixed(1),
        'severity': severity,
        'severity_status': isHealthy ? 'not_applicable' : 'unassessed',
        'severity_class': isHealthy ? 'healthy-bg' : 'unassessed-bg',
        'affected_percentage': null,
        'date': DateFormat('MMM d, yyyy').format(now),
        'time': DateFormat('h:mm a').format(now),
        'analysis_source': 'on_device_mobilenet_v2',
      },
    );

    _result = ScanResultModel(
      recognized: true,
      saved: true,
      id: id,
      diseaseKey: prediction.diseaseKey,
      disease: prediction.diseaseName,
      scientific: _scientificName(prediction.diseaseKey),
      confidence: (prediction.confidence * 100).toStringAsFixed(1),
      severity: severity,
      severityStatus: isHealthy ? 'not_applicable' : 'unassessed',
      analysisSource: 'on_device_mobilenet_v2',
      date: DateFormat('MMM d, yyyy').format(now),
      time: DateFormat('h:mm a').format(now),
      chemicalTreatments: const [],
      organicTreatments: const [],
      localOnly: true,
      localImagePath: imagePath,
    );
    _isAnalyzing = false;
    notifyListeners();
    return true;
  }

  String _messageForPrediction(RiceLeafPrediction prediction) {
    switch (prediction.status) {
      case 'blurry_image':
        return 'This photo is too blurry to scan reliably. Retake it with the leaf in focus and good light.';
      case 'not_rice_leaf':
        return 'This image does not appear to show a rice leaf. Choose a clear rice-leaf photo.';
      default:
        return 'The rice leaf was checked, but the disease result is uncertain. Try a clear close-up photo.';
    }
  }

  String? _scientificName(String diseaseKey) {
    const names = {
      'blb': 'Xanthomonas oryzae pv. oryzae',
      'brown_spot': 'Bipolaris oryzae',
      'sheath_blight': 'Rhizoctonia solani',
      'tungro': 'RTBV + RTSV viruses',
      'blast': 'Magnaporthe oryzae',
    };
    return names[diseaseKey];
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
