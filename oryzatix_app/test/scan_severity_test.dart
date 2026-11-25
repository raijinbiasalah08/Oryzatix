import 'package:flutter_test/flutter_test.dart';
import 'package:oryzatix_app/core/theme/app_colors.dart';
import 'package:oryzatix_app/features/dashboard/models/dashboard_stats_model.dart';
import 'package:oryzatix_app/features/disease_scan/models/scan_result_model.dart';
import 'package:oryzatix_app/features/scan_history/models/history_model.dart';

void main() {
  void check(
    Map<String, dynamic> scan,
    String level,
    String status, {
    String? area,
  }) {
    final result = ScanResultModel.fromJson({'scan': scan});
    final history = HistoryItem.fromJson(scan);
    final recent = RecentScanItem.fromJson(scan);
    expect([
      result.severity,
      history.severity,
      recent.severity,
    ], everyElement(level));
    expect([
      result.severityStatus,
      history.severityStatus,
      recent.severityStatus,
    ], everyElement(status));
    expect(result.affectedPercentage, area);
    expect(history.affectedPercentage, area);
  }

  test(
    'manual and experimental categories retain their source without legacy percentages',
    () {
      for (final source in ['user_assessed', 'model_estimated']) {
        check(
          {
            'disease': 'Leaf Blast',
            'severity': 'moderate',
            'severity_status': source,
            'affected_percentage': 99,
          },
          'moderate',
          source,
        );
      }
      expect(
        AppColors.getSeverityLabel('moderate', status: 'model_estimated'),
        contains('Experimental estimate'),
      );
      expect(
        AppColors.getSeverityLabel('moderate', status: 'user_assessed'),
        contains('User assessed'),
      );
    },
  );

  test('expert categories and valid recorded measurements are independent', () {
    final assessed = {
      'disease_key': 'blast',
      'severity': 'mild',
      'severity_status': 'expert_validated',
    };
    check(assessed, 'mild', 'expert_validated');
    check(
      {...assessed, 'affected_percentage': '14.3'},
      'mild',
      'expert_validated',
      area: '14.3',
    );
    check({...assessed, 'affected_percentage': -1}, 'mild', 'expert_validated');
  });

  test('legacy, missing and negated healthy records remain unassessed', () {
    check(
      {'severity': 'severe', 'affected_percentage': 70},
      'unassessed',
      'unassessed',
    );
    check({'disease': 'Not Healthy Leaf'}, 'unassessed', 'unassessed');
    check(
      {'disease_key': 'blast', 'severity': 'healthy'},
      'unassessed',
      'unassessed',
    );
    check({}, 'unassessed', 'unassessed');
    check({'disease': 'Healthy Rice Leaf'}, 'healthy', 'not_applicable');
  });

  test(
    'expert areas override missing or conflicting categories at the band edges',
    () {
      for (final entry in {
        0.0: 'mild',
        25.0: 'mild',
        25.01: 'moderate',
        60.0: 'moderate',
        60.01: 'severe',
        100.0: 'severe',
      }.entries) {
        for (final raw in [null, 'mild', 'severe']) {
          check(
            {
              'severity_status': 'expert_validated',
              'severity': raw,
              'affected_percentage': entry.key,
            },
            entry.value,
            'expert_validated',
            area: entry.key.toString(),
          );
        }
      }
    },
  );

  test(
    'cached treatment advice requires healthy, manual or expert provenance',
    () {
      for (final source in [
        null,
        'model_estimated',
        'unassessed',
        'expert_validated',
        'user_assessed',
      ]) {
        final scan = <String, dynamic>{
          'severity': 'moderate',
          'severity_status': source,
          'treatments': {
            'chemical': [
              {'name': 'Recorded advice'},
            ],
            'organic': [
              {'name': 'Recorded advice'},
            ],
          },
          'all_severity_treatments': {'moderate': {}},
        };
        final allowed =
            source == 'expert_validated' || source == 'user_assessed';
        final result = ScanResultModel.fromJson({'scan': scan});
        expect(result.chemicalTreatments.isNotEmpty, allowed);
        expect(result.organicTreatments.isNotEmpty, allowed);
        expect(result.allSeverityTreatments != null, allowed);
        expect(HistoryItem.fromJson(scan).treatments != null, allowed);
      }
    },
  );
}
