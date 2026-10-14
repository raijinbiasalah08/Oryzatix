class RecentScanItem {
  final int id;
  final String disease;
  final String? scientific;
  final String confidence;
  final String severity;
  final String time;
  final String date;
  final String? imageUrl;

  RecentScanItem({
    required this.id,
    required this.disease,
    this.scientific,
    required this.confidence,
    required this.severity,
    required this.time,
    required this.date,
    this.imageUrl,
  });

  factory RecentScanItem.fromJson(Map<String, dynamic> json) {
    return RecentScanItem(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      disease: json['disease']?.toString() ?? 'Rice Leaf',
      scientific: json['scientific']?.toString(),
      confidence: json['confidence']?.toString() ?? '0.0',
      severity: json['severity']?.toString() ?? 'healthy',
      time: json['time']?.toString() ?? '',
      date: json['date']?.toString() ?? '',
      imageUrl: json['image_url']?.toString(),
    );
  }
}

class DashboardStatsModel {
  final int totalScans;
  final int healthyCount;
  final int mildCount;
  final int moderateCount;
  final int severeCount;
  final double healthIndex;
  final List<RecentScanItem> recentScans;

  DashboardStatsModel({
    required this.totalScans,
    required this.healthyCount,
    required this.mildCount,
    required this.moderateCount,
    required this.severeCount,
    required this.healthIndex,
    required this.recentScans,
  });

  factory DashboardStatsModel.fromJson(Map<String, dynamic> json) {
    final stats = json['stats'] as Map<String, dynamic>? ?? {};
    final recentList = json['recent_scans'] as List? ?? [];

    return DashboardStatsModel(
      totalScans: int.tryParse(stats['total_scans']?.toString() ?? '0') ?? 0,
      healthyCount: int.tryParse(stats['healthy_count']?.toString() ?? '0') ?? 0,
      mildCount: int.tryParse(stats['mild_count']?.toString() ?? '0') ?? 0,
      moderateCount: int.tryParse(stats['moderate_count']?.toString() ?? '0') ?? 0,
      severeCount: int.tryParse(stats['severe_count']?.toString() ?? '0') ?? 0,
      healthIndex: double.tryParse(stats['health_index']?.toString() ?? '100.0') ?? 100.0,
      recentScans: recentList.map((e) => RecentScanItem.fromJson(e as Map<String, dynamic>)).toList(),
    );
  }

  factory DashboardStatsModel.empty() {
    return DashboardStatsModel(
      totalScans: 0,
      healthyCount: 0,
      mildCount: 0,
      moderateCount: 0,
      severeCount: 0,
      healthIndex: 100.0,
      recentScans: [],
    );
  }
}
