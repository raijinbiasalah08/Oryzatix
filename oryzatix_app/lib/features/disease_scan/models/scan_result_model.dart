class TreatmentItem {
  final String name;
  final String desc;
  final String tag;
  final String tagClass;

  TreatmentItem({
    required this.name,
    required this.desc,
    required this.tag,
    required this.tagClass,
  });

  factory TreatmentItem.fromJson(Map<String, dynamic> json) {
    return TreatmentItem(
      name: json['name']?.toString() ?? json['title']?.toString() ?? '',
      desc: json['desc']?.toString() ?? json['rate']?.toString() ?? '',
      tag: json['tag']?.toString() ?? json['timing']?.toString() ?? 'Recommendation',
      tagClass: json['tag_class']?.toString() ?? 'cultural',
    );
  }
}

class ScanResultModel {
  final bool recognized;
  final bool saved;
  final String? message;
  final int? id;
  final String diseaseKey;
  final String disease;
  final String? scientific;
  final String confidence;
  final String severity;
  final String? affectedPercentage;
  final String? imageUrl;
  final String analysisSource;
  final String date;
  final String time;
  final List<TreatmentItem> chemicalTreatments;
  final List<TreatmentItem> organicTreatments;
  final Map<String, dynamic>? allSeverityTreatments;

  ScanResultModel({
    required this.recognized,
    required this.saved,
    this.message,
    this.id,
    required this.diseaseKey,
    required this.disease,
    this.scientific,
    required this.confidence,
    required this.severity,
    this.affectedPercentage,
    this.imageUrl,
    required this.analysisSource,
    required this.date,
    required this.time,
    required this.chemicalTreatments,
    required this.organicTreatments,
    this.allSeverityTreatments,
  });

  factory ScanResultModel.fromJson(Map<String, dynamic> json) {
    final scan = json['scan'] as Map<String, dynamic>? ?? {};
    final isRec = (json['recognized'] == true) || (scan['recognized'] == true);

    final treatObj = scan['treatments'] as Map<String, dynamic>? ?? {};
    final chemList = (treatObj['chemical'] as List? ?? [])
        .map((e) => TreatmentItem.fromJson(e as Map<String, dynamic>))
        .toList();
    final orgList = (treatObj['organic'] as List? ?? [])
        .map((e) => TreatmentItem.fromJson(e as Map<String, dynamic>))
        .toList();

    return ScanResultModel(
      recognized: isRec,
      saved: json['saved'] == true,
      message: json['message']?.toString(),
      id: scan['id'] != null ? (scan['id'] is int ? scan['id'] : int.tryParse(scan['id'].toString())) : null,
      diseaseKey: scan['disease_key']?.toString() ?? 'healthy',
      disease: scan['disease']?.toString() ?? 'Rice Leaf',
      scientific: scan['scientific']?.toString(),
      confidence: scan['confidence']?.toString() ?? '0.0',
      severity: scan['severity']?.toString() ?? 'healthy',
      affectedPercentage: scan['affected_percentage']?.toString(),
      imageUrl: scan['image_url']?.toString(),
      analysisSource: scan['analysis_source']?.toString() ?? 'reference_match',
      date: scan['date']?.toString() ?? '',
      time: scan['time']?.toString() ?? '',
      chemicalTreatments: chemList,
      organicTreatments: orgList,
      allSeverityTreatments: scan['all_severity_treatments'] as Map<String, dynamic>?,
    );
  }
}
