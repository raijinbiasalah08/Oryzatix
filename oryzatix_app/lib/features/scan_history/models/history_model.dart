class HistoryItem {
  final int id;
  final String disease;
  final String? scientific;
  final String confidence;
  final String severity;
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
    this.affectedPercentage,
    required this.time,
    required this.date,
    this.imageUrl,
    this.treatments,
  });

  factory HistoryItem.fromJson(Map<String, dynamic> json) {
    return HistoryItem(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      disease: json['disease']?.toString() ?? 'Rice Leaf',
      scientific: json['scientific']?.toString(),
      confidence: json['confidence']?.toString() ?? '0.0',
      severity: json['severity']?.toString() ?? 'healthy',
      affectedPercentage: json['affected_percentage']?.toString(),
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

  HistoryStats({
    required this.total,
    required this.healthy,
    required this.mild,
    required this.moderate,
    required this.severe,
  });

  factory HistoryStats.fromJson(Map<String, dynamic> json) {
    return HistoryStats(
      total: int.tryParse(json['total']?.toString() ?? '0') ?? 0,
      healthy: int.tryParse(json['healthy']?.toString() ?? '0') ?? 0,
      mild: int.tryParse(json['mild']?.toString() ?? '0') ?? 0,
      moderate: int.tryParse(json['moderate']?.toString() ?? '0') ?? 0,
      severe: int.tryParse(json['severe']?.toString() ?? '0') ?? 0,
    );
  }
}
