import 'dart:convert';
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:image/image.dart' as img;
import 'package:tflite_flutter/tflite_flutter.dart';

import 'rice_leaf_prediction.dart';

class MobileNetClassifier {
  MobileNetClassifier._();

  static final MobileNetClassifier instance = MobileNetClassifier._();

  static const int _imageSize = 224;
  static const String _modelAsset =
      'assets/models/mobilenetv2_rice_leaf.tflite';
  static const String _labelsAsset = 'assets/models/class_labels.json';

  Interpreter? _interpreter;
  IsolateInterpreter? _isolateInterpreter;
  List<String> _classKeys = const [];
  List<String> _classNames = const [];
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
      throw const FormatException(
        'The selected file is not a supported image.',
      );
    }

    final orientedImage = img.bakeOrientation(decodedImage);
    final resizedImage = img.copyResize(
      orientedImage,
      width: _imageSize,
      height: _imageSize,
      interpolation: img.Interpolation.linear,
    );
    final input = <List<List<List<double>>>>[
      List.generate(
        _imageSize,
        (y) => List.generate(_imageSize, (x) {
          final pixel = resizedImage.getPixel(x, y);
          return <double>[
            pixel.r.toDouble(),
            pixel.g.toDouble(),
            pixel.b.toDouble(),
          ];
        }),
      ),
    ];
    final output = <List<double>>[List<double>.filled(_classKeys.length, 0)];

    await _isolateInterpreter!.run(input, output);
    final probabilities = output.single;
    if (probabilities.length != _classKeys.length ||
        probabilities.any((score) => !score.isFinite)) {
      throw StateError(
        'The MobileNet model returned invalid class probabilities.',
      );
    }

    var bestIndex = 0;
    for (var index = 1; index < probabilities.length; index++) {
      if (probabilities[index] > probabilities[bestIndex]) {
        bestIndex = index;
      }
    }

    return RiceLeafPrediction(
      diseaseKey: _classKeys[bestIndex],
      diseaseName: _classNames[bestIndex],
      confidence: probabilities[bestIndex].clamp(0.0, 1.0).toDouble(),
    );
  }

  Future<void> _ensureLoaded() async {
    if (_interpreter != null && _isolateInterpreter != null) return;

    _loading ??= _loadModel();
    try {
      await _loading;
    } catch (_) {
      _loading = null;
      rethrow;
    }
  }

  Future<void> _loadModel() async {
    final labelsJson =
        jsonDecode(await rootBundle.loadString(_labelsAsset)) as List<dynamic>;
    final labels =
        labelsJson
            .map((entry) => Map<String, dynamic>.from(entry as Map))
            .toList()
          ..sort((a, b) => (a['index'] as int).compareTo(b['index'] as int));
    if (labels.isEmpty ||
        labels.any((label) => label['key'] == null || label['name'] == null)) {
      throw const FormatException(
        'The MobileNet class label asset is invalid.',
      );
    }

    final interpreter = await Interpreter.fromAsset(
      _modelAsset,
      options: InterpreterOptions()..threads = 2,
    );
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
        outputShape[1] != labels.length) {
      interpreter.close();
      throw StateError(
        'The bundled MobileNet model has an unexpected tensor shape.',
      );
    }

    _classKeys = labels
        .map((label) => label['key'].toString())
        .toList(growable: false);
    _classNames = labels
        .map((label) => label['name'].toString())
        .toList(growable: false);
    _interpreter = interpreter;
    _isolateInterpreter = await IsolateInterpreter.create(
      address: interpreter.address,
    );
  }
}
