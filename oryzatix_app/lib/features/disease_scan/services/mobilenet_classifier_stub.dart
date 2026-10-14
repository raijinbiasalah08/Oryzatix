import 'dart:typed_data';

import 'rice_leaf_prediction.dart';

class MobileNetClassifier {
  MobileNetClassifier._();

  static final MobileNetClassifier instance = MobileNetClassifier._();

  bool get supportsOnDeviceInference => false;

  Future<RiceLeafPrediction> classify(Uint8List imageBytes) {
    throw UnsupportedError(
      'On-device MobileNet inference is unavailable on this platform.',
    );
  }
}
