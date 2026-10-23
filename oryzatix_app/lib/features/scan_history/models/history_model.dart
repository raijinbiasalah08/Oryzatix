class HistoryItem {
  final int id;
  final String disease;
  final String? scientific;
  final String confidence;
  final String severity;
  final String severityStatus;
  final String? affectedPercentage;
  final String time;
  final String date;
  final String? imageUrl;
  final Map<String, dynamic>? treatments;

  HistoryItem({
    required this.id,
    required this.disease,
    this.scientific,
    required this.confidence,
    required this.severity,
    required this.severityStatus,
    this.affectedPercentage,
    required this.time,
    required this.date,
    this.imageUrl,
    this.treatments,
  });

  factory HistoryItem.fromJson(Map<String, dynamic> json) {
    final rawSeverity = json['severity']?.toString().toLowerCase();
    final disease = json['disease']?.toString() ?? 'Rice Leaf';
    final isHealthy = rawSeverity == 'healthy' || disease.toLowerCase().contains('healthy');
    final area = double.tryParse(json['affected_percentage']?.toString() ?? '');
    final hasValidatedSeverity = json['severity_status'] == 'expert_validated' &&
        ['mild', 'moderate', 'severe'].contains(rawSeverity) &&
        area != null && area.isFinite && area >= 0 && area <= 100;

    return HistoryItem(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      disease: disease,
      scientific: json['scientific']?.toString(),
      confidence: json['confidence']?.toString() ?? '0.0',
      severity: isHealthy ? 'healthy' : (hasValidatedSeverity ? rawSeverity! : 'unassessed'),
      severityStatus: isHealthy ? 'not_applicable' : (hasValidatedSeverity ? 'expert_validated' : 'unassessed'),
      affectedPercentage: hasValidatedSeverity ? area.toStringAsFixed(1) : null,
      time: json['time']?.toString() ?? '',
      date: json['date']?.toString() ?? '',
      imageUrl: json['image_url']?.toString(),
      treatments: json['treatments'] is Map<String, dynamic> ? json['treatments'] : null,
    );
  }
}

class HistoryStats {
  final int total;
  final int healthy;
  final int mild;
  final int moderate;
  final int severe;
  final int unassessed;

  HistoryStats({
    required this.total,
    required this.healthy,
    required this.mild,
    required this.moderate,
    required this.severe,
    required this.unassessed,
  });

  factory HistoryStats.fromJson(Map<String, dynamic> json) {
    return HistoryStats(
      total: int.tryParse(json['total']?.toString() ?? '0') ?? 0,
      healthy: int.tryParse(json['healthy']?.toString() ?? '0') ?? 0,
      mild: int.tryParse(json['mild']?.toString() ?? '0') ?? 0,
      moderate: int.tryParse(json['moderate']?.toString() ?? '0') ?? 0,
      severe: int.tryParse(json['severe']?.toString() ?? '0') ?? 0,
      unassessed: int.tryParse(json['unassessed']?.toString() ?? '0') ?? 0,
    );
  }
}
