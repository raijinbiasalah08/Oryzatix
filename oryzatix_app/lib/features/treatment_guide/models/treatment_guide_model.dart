class SeverityLevelInfo {
  final String severity;
  final String range;
  final String description;
  final List<dynamic> chemical;
  final List<dynamic> organic;

  SeverityLevelInfo({
    required this.severity,
    required this.range,
    required this.description,
    required this.chemical,
    required this.organic,
  });

  factory SeverityLevelInfo.fromJson(Map<String, dynamic> json) {
    return SeverityLevelInfo(
      severity: json['severity']?.toString() ?? 'mild',
      range: json['range']?.toString() ?? '',
      description: json['description']?.toString() ?? '',
      chemical: json['chemical'] is List ? json['chemical'] : [],
      organic: json['organic'] is List ? json['organic'] : [],
    );
  }
}

class DiseaseGuideModel {
  final String key;
  final String name;
  final String? scientificName;
  final String? description;
  final String? symptoms;
  final String? causes;
  final String? prevention;
  final String? recommendedTreatment;
  final String? imageUrl;
  final String severityClass;
  final Map<String, SeverityLevelInfo>? severityLevels;
  final List<dynamic> chemicalTreatments;
  final List<dynamic> organicTreatments;

  DiseaseGuideModel({
    required this.key,
    required this.name,
    this.scientificName,
    this.description,
    this.symptoms,
    this.causes,
    this.prevention,
    this.recommendedTreatment,
    this.imageUrl,
    required this.severityClass,
    this.severityLevels,
    required this.chemicalTreatments,
    required this.organicTreatments,
  });

  factory DiseaseGuideModel.fromJson(Map<String, dynamic> json) {
    Map<String, SeverityLevelInfo>? sevLevels;
    if (json['severity_levels'] is Map) {
      sevLevels = {};
      (json['severity_levels'] as Map).forEach((k, v) {
        if (v is Map<String, dynamic>) {
          sevLevels![k.toString()] = SeverityLevelInfo.fromJson(v);
        }
      });
    }

    final treatments = json['treatments'] as Map<String, dynamic>? ?? {};
    final chem = treatments['chemical'] is List ? treatments['chemical'] : [];
    final org = treatments['organic'] is List ? treatments['organic'] : [];

    return DiseaseGuideModel(
      key: json['key']?.toString() ?? 'healthy',
      name: json['name']?.toString() ?? '',
      scientificName: json['scientific_name']?.toString(),
      description: json['description']?.toString(),
      symptoms: json['symptoms']?.toString(),
      causes: json['causes']?.toString(),
      prevention: json['prevention']?.toString(),
      recommendedTreatment: json['recommended_treatment']?.toString(),
      imageUrl: json['image_url']?.toString(),
      severityClass: json['severity_class']?.toString() ?? 'healthy-bg',
      severityLevels: sevLevels,
      chemicalTreatments: chem,
      organicTreatments: org,
    );
  }
}
