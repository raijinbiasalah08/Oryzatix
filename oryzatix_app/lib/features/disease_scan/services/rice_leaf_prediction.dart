class RiceLeafPrediction {
  const RiceLeafPrediction({
    required this.diseaseKey,
    required this.diseaseName,
    required this.confidence,
    this.status = 'diagnosed',
    this.reason = 'validated_prediction',
    this.riceLeafProbability,
    this.focusScore,
    this.minimumConfidence,
    this.orientationAgreement,
  });

  static const double reviewThreshold = 0.55;

  final String diseaseKey;
  final String diseaseName;
  final double confidence;
  final String status;
  final String reason;
  final double? riceLeafProbability;
  final double? focusScore;
  final double? minimumConfidence;
  final double? orientationAgreement;

  bool get isDiagnosed => status == 'diagnosed';
  bool get needsReview => !isDiagnosed || confidence < reviewThreshold;
}
