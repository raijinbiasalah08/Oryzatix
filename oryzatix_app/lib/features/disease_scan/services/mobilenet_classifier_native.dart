import 'dart:convert';
import 'dart:io';
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:flutter/services.dart';
import 'package:image/image.dart' as img;
import 'package:tflite_flutter/tflite_flutter.dart';

import 'rice_leaf_prediction.dart';

class MobileNetClassifier {
  MobileNetClassifier._();

  static final MobileNetClassifier instance = MobileNetClassifier._();

  static const int _imageSize = 224;
  static const String _validatorAsset = 'assets/models/rice_leaf_validator.tflite';
  static const String _diseaseAsset = 'assets/models/mobilenetv2_rice_leaf.tflite';
  static const String _labelsAsset = 'assets/models/class_labels.json';
  static const String _thresholdsAsset = 'assets/models/scan_validation_thresholds.json';

  Interpreter? _validator;
  Interpreter? _diseaseModel;
  IsolateInterpreter? _validatorIsolate;
  IsolateInterpreter? _diseaseIsolate;
  List<String> _classKeys = const [];
  List<String> _classNames = const [];
  Map<String, dynamic> _thresholds = const {};
  Future<void>? _loading;

  bool get supportsOnDeviceInference => Platform.isAndroid || Platform.isIOS;

  Future<RiceLeafPrediction> classify(Uint8List imageBytes) async {
    if (!supportsOnDeviceInference) {
      throw UnsupportedError(
        'On-device MobileNet inference is only enabled on Android and iOS.',
      );
    }

    await _ensureLoaded();
    final decodedImage = img.decodeImage(imageBytes);
    if (decodedImage == null) {
      throw const FormatException('The selected file is not a supported image.');
    }

    final orientedImage = img.bakeOrientation(decodedImage);
    final resizedImage = img.copyResize(
      orientedImage,
      width: _imageSize,
      height: _imageSize,
      interpolation: img.Interpolation.linear,
    );
    final focusScore = _laplacianVariance(resizedImage);
    final blurThreshold = _numberAt(_thresholds, ['blur', 'threshold']);

    if (focusScore < blurThreshold) {
      return RiceLeafPrediction(
        diseaseKey: '',
        diseaseName: '',
        confidence: 0,
        status: 'blurry_image',
        reason: 'image_quality_below_validation_threshold',
        focusScore: focusScore,
      );
    }

    final pixels = _pixelsFor(resizedImage);
    final validatorOutput = <List<double>>[<double>[0]];
    await _validatorIsolate!.run(pixels, validatorOutput);
    final riceProbability = validatorOutput.single.single;
    if (!riceProbability.isFinite || riceProbability < 0 || riceProbability > 1) {
      throw StateError('The rice-leaf validator returned an invalid probability.');
    }

    final riceThreshold = _numberAt(_thresholds, ['rice_leaf', 'threshold']);
    if (riceProbability < riceThreshold) {
      return RiceLeafPrediction(
        diseaseKey: 'not_rice_leaf',
        diseaseName: 'Not a Rice Leaf',
        confidence: riceProbability,
        status: 'not_rice_leaf',
        reason: 'rice_leaf_probability_below_validation_threshold',
        riceLeafProbability: riceProbability,
        focusScore: focusScore,
      );
    }

    final orientationViews = _numberAt(
      _thresholds,
      ['disease', 'orientation_views'],
      fallback: 1,
    ).round();
    if (orientationViews != 1 && orientationViews != 4) {
      throw StateError('The bundled model has an unsupported orientation policy.');
    }

    final averageScores = List<double>.filled(_classKeys.length, 0);
    final predictedClasses = <int>[];
    for (var turn = 0; turn < orientationViews; turn++) {
      final view = turn == 0
          ? resizedImage
          : img.copyRotate(
              resizedImage,
              angle: 90 * turn,
              interpolation: img.Interpolation.linear,
            );
      final output = <List<double>>[
        List<double>.filled(_classKeys.length, 0),
      ];
      await _diseaseIsolate!.run(_pixelsFor(view), output);
      final scores = output.single;
      if (scores.length != _classKeys.length ||
          scores.any((score) => !score.isFinite || score < 0 || score > 1)) {
        throw StateError('The disease model returned invalid class probabilities.');
      }
      final sum = scores.fold<double>(0, (total, score) => total + score);
      if ((sum - 1).abs() > 0.02) {
        throw StateError('The disease model returned probabilities that do not sum to one.');
      }
      var viewBestIndex = 0;
      for (var i = 0; i < scores.length; i++) {
        averageScores[i] += scores[i] / orientationViews;
        if (scores[i] > scores[viewBestIndex]) viewBestIndex = i;
      }
      predictedClasses.add(viewBestIndex);
    }

    var bestIndex = 0;
    for (var i = 1; i < averageScores.length; i++) {
      if (averageScores[i] > averageScores[bestIndex]) bestIndex = i;
    }

    final diseaseKey = _classKeys[bestIndex];
    final confidence = averageScores[bestIndex];
    final thresholds = _thresholds['disease']?['thresholds'];
    final minimumConfidenceValue = thresholds is Map ? thresholds[diseaseKey] : null;
    final minimumConfidence = minimumConfidenceValue is num
        ? minimumConfidenceValue.toDouble()
        : null;
    final agreement = predictedClasses
            .where((index) => index == bestIndex)
            .length /
        predictedClasses.length;

    String reason = 'validated_prediction';
    var status = 'diagnosed';
    if (diseaseKey == 'not_rice_leaf') {
      status = 'uncertain_prediction';
      reason = 'classifiers_disagree';
    } else if (minimumConfidence == null) {
      status = 'uncertain_prediction';
      reason = 'no_validated_threshold_for_predicted_class';
    } else if (confidence < minimumConfidence) {
      status = 'uncertain_prediction';
      reason = 'disease_confidence_below_validation_threshold';
    }

    return RiceLeafPrediction(
      diseaseKey: diseaseKey,
      diseaseName: _classNames[bestIndex],
      confidence: confidence,
      status: status,
      reason: reason,
      riceLeafProbability: riceProbability,
      focusScore: focusScore,
      minimumConfidence: minimumConfidence,
      orientationAgreement: agreement,
    );
  }

  Future<void> _ensureLoaded() async {
    if (_validatorIsolate != null && _diseaseIsolate != null) return;

    _loading ??= _loadModels();
    try {
      await _loading;
    } catch (_) {
      _loading = null;
      rethrow;
    }
  }

  Future<void> _loadModels() async {
    final labels = (jsonDecode(await rootBundle.loadString(_labelsAsset)) as List)
        .map((entry) => Map<String, dynamic>.from(entry as Map))
        .toList()
      ..sort((a, b) => (a['index'] as int).compareTo(b['index'] as int));
    if (labels.isEmpty ||
        labels.any((label) => label['key'] == null || label['name'] == null)) {
      throw const FormatException('The MobileNet class label asset is invalid.');
    }

    _thresholds = jsonDecode(await rootBundle.loadString(_thresholdsAsset))
        as Map<String, dynamic>;
    final riceLeafCalibration = _thresholds['rice_leaf'];
    final blurCalibration = _thresholds['blur'];
    final diseaseCalibration = _thresholds['disease'];
    final diseaseThresholds = diseaseCalibration is Map
        ? diseaseCalibration['thresholds']
        : null;
    if (riceLeafCalibration is! Map ||
        riceLeafCalibration['operating_point_available'] != true ||
        diseaseThresholds is! Map ||
        blurCalibration is! Map ||
        blurCalibration['threshold'] is! num) {
      throw const FormatException('The bundled scan calibration file is invalid.');
    }

    final diseaseData = await rootBundle.load(_diseaseAsset);
    final diseaseBytes = diseaseData.buffer.asUint8List(
      diseaseData.offsetInBytes,
      diseaseData.lengthInBytes,
    );
    final expectedHash = _thresholds['disease']?['model_sha256']?.toString();
    if (expectedHash != null && expectedHash.isNotEmpty &&
        sha256.convert(diseaseBytes).toString() != expectedHash.toLowerCase()) {
      throw StateError('The scan thresholds do not match the bundled disease model.');
    }

    final validator = await Interpreter.fromAsset(
      _validatorAsset,
      options: InterpreterOptions()..threads = 2,
    );
    final disease = await Interpreter.fromAsset(
      _diseaseAsset,
      options: InterpreterOptions()..threads = 2,
    );
    _validateModelShape(validator, 1);
    _validateModelShape(disease, labels.length);

    _classKeys = labels.map((label) => label['key'].toString()).toList(growable: false);
    _classNames = labels.map((label) => label['name'].toString()).toList(growable: false);
    _validator = validator;
    _diseaseModel = disease;
    _validatorIsolate = await IsolateInterpreter.create(address: validator.address);
    _diseaseIsolate = await IsolateInterpreter.create(address: disease.address);
  }

  void _validateModelShape(Interpreter interpreter, int outputCount) {
    interpreter.allocateTensors();
    final inputShape = interpreter.getInputTensor(0).shape;
    final outputShape = interpreter.getOutputTensor(0).shape;
    if (inputShape.length != 4 ||
        inputShape[0] != 1 ||
        inputShape[1] != _imageSize ||
        inputShape[2] != _imageSize ||
        inputShape[3] != 3 ||
        outputShape.length != 2 ||
        outputShape[0] != 1 ||
        outputShape[1] != outputCount ||
        interpreter.getInputTensor(0).type != TensorType.float32 ||
        interpreter.getOutputTensor(0).type != TensorType.float32) {
      interpreter.close();
      throw StateError('A bundled scan model has an unsupported tensor shape or type.');
    }
  }

  double _numberAt(
    Map<String, dynamic> source,
    List<String> path, {
    double? fallback,
  }) {
    dynamic value = source;
    for (final key in path) {
      if (value is! Map) return fallback ?? (throw FormatException('Missing calibration value: ${path.join('.')}'));
      value = value[key];
    }
    if (value is num && value.isFinite) return value.toDouble();
    if (fallback != null) return fallback;
    throw FormatException('Invalid calibration value: ${path.join('.')}');
  }

  List<List<List<List<double>>>> _pixelsFor(img.Image image) => [
        List.generate(
          _imageSize,
          (y) => List.generate(_imageSize, (x) {
            final pixel = image.getPixel(x, y);
            return <double>[
              pixel.r.toDouble(),
              pixel.g.toDouble(),
              pixel.b.toDouble(),
            ];
          }),
        ),
      ];

  double _laplacianVariance(img.Image image) {
    final values = List<double>.filled(_imageSize * _imageSize, 0);
    for (var y = 0; y < _imageSize; y++) {
      for (var x = 0; x < _imageSize; x++) {
        final pixel = image.getPixel(x, y);
        values[y * _imageSize + x] =
            (299 * pixel.r + 587 * pixel.g + 114 * pixel.b) / 1000;
      }
    }

    var sum = 0.0;
    var sumSquares = 0.0;
    var count = 0;
    for (var y = 1; y < _imageSize - 1; y++) {
      for (var x = 1; x < _imageSize - 1; x++) {
        final center = values[y * _imageSize + x];
        final laplacian = values[(y - 1) * _imageSize + x] +
            values[(y + 1) * _imageSize + x] +
            values[y * _imageSize + x - 1] +
            values[y * _imageSize + x + 1] -
            4 * center;
        sum += laplacian;
        sumSquares += laplacian * laplacian;
        count++;
      }
    }
    final mean = sum / count;
    return math.max(0.0, sumSquares / count - mean * mean);
  }
}
