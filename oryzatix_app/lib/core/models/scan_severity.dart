class ScanSeverity {
  final String level;
  final String status;
  final String? affectedPercentage;

  const ScanSeverity(this.level, this.status, [this.affectedPercentage]);

  bool get allowsRecordedTreatments =>
      level == 'healthy' ||
      status == 'user_assessed' ||
      status == 'expert_validated';

  factory ScanSeverity.fromJson(Map<String, dynamic> scan) {
    final raw = scan['severity']?.toString().trim().toLowerCase();
    final key = scan['disease_key']?.toString().trim().toLowerCase();
    final name = scan['disease']?.toString().trim().toLowerCase() ?? '';
    const healthyNames = {
      'healthy',
      'healthy rice',
      'healthy leaf',
      'healthy rice leaf',
      'healthy leaves',
      'healthy rice leaves',
    };
    final hasKey = key != null && key.isNotEmpty && key != 'unknown';
    final healthy = hasKey
        ? key == 'healthy'
        : healthyNames.contains(name) || (name.isEmpty && raw == 'healthy');
    if (healthy) return const ScanSeverity('healthy', 'not_applicable');

    final source = scan['severity_status'] ?? scan['severity_source'];
    if (source == 'expert_validated') {
      final area = double.tryParse(
        scan['affected_percentage']?.toString() ?? '',
      );
      if (area != null && area.isFinite && area >= 0 && area <= 100) {
        final level = area <= 25
            ? 'mild'
            : area <= 60
            ? 'moderate'
            : 'severe';
        return ScanSeverity(level, 'expert_validated', area.toString());
      }
    }
    if (raw != null &&
        const {'mild', 'moderate', 'severe'}.contains(raw) &&
        const {
          'user_assessed',
          'expert_validated',
          'model_estimated',
        }.contains(source)) {
      return ScanSeverity(raw, source as String);
    }
    return const ScanSeverity('unassessed', 'unassessed');
  }
}
