class RiceLeafPrediction {
  const RiceLeafPrediction({
    required this.diseaseKey,
    required this.diseaseName,
    required this.confidence,
  });

  static const double reviewThreshold = 0.55;

  final String diseaseKey;
  final String diseaseName;
  final double confidence;

  bool get needsReview => confidence < reviewThreshold;
}
