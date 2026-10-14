import 'package:flutter/material.dart';

class AppColors {
  // Brand Greens
  static const Color primary = Color(0xFF16501A);
  static const Color primaryDark = Color(0xFF0E3811);
  static const Color primaryLight = Color(0xFF1B5E20);
  static const Color primaryContainer = Color(0xFFDCFCE7);

  static const Color accentLime = Color(0xFF84CC16);
  static const Color accentYellow = Color(0xFFEAB308);

  // Background & Surfaces
  static const Color background = Color(0xFFF8FAFC);
  static const Color surface = Color(0xFFFFFFFF);
  static const Color surfaceSubtle = Color(0xFFF1F5F9);
  static const Color border = Color(0xFFE2E8F0);
  static const Color borderGreen = Color(0xFFBBF7D0);

  // Text Colors
  static const Color textPrimary = Color(0xFF0F172A);
  static const Color textSecondary = Color(0xFF475569);
  static const Color textMuted = Color(0xFF94A3B8);
  static const Color textOnPrimary = Color(0xFFFFFFFF);

  // Severity Colors
  static const Color healthy = Color(0xFF16A34A);
  static const Color healthyBg = Color(0xFFDCFCE7);

  static const Color mild = Color(0xFFD97706);
  static const Color mildBg = Color(0xFFFEF3C7);

  static const Color moderate = Color(0xFFEA580C);
  static const Color moderateBg = Color(0xFFFFEDD5);

  static const Color severe = Color(0xFFDC2626);
  static const Color severeBg = Color(0xFFFEE2E2);

  static Color getSeverityColor(String? severity) {
    switch (severity?.toLowerCase()) {
      case 'healthy':
        return healthy;
      case 'mild':
        return mild;
      case 'moderate':
        return moderate;
      case 'severe':
        return severe;
      default:
        return primary;
    }
  }

  static Color getSeverityBg(String? severity) {
    switch (severity?.toLowerCase()) {
      case 'healthy':
        return healthyBg;
      case 'mild':
        return mildBg;
      case 'moderate':
        return moderateBg;
      case 'severe':
        return severeBg;
      default:
        return primaryContainer;
    }
  }
}
