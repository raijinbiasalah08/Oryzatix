class ApiEndpoints {
  static const String health = '/health';

  // Auth
  static const String login = '/auth/login';
  static const String register = '/auth/register';
  static const String logout = '/auth/logout';
  static const String user = '/auth/user';
  static const String profile = '/auth/profile';
  static const String securityConfig = '/auth/security-config';

  // Rice Disease Scanner & History
  static const String uploadScan = '/rice-detector/upload';
  static const String scanHistory = '/rice-detector/history';
  static String deleteScan(dynamic id) => '/rice-detector/scan/$id';

  // Dashboard
  static const String dashboardStats = '/dashboard/stats';
  static const String dashboardPublicStats = '/dashboard/public-stats';

  // Treatment Guides
  static const String treatmentGuides = '/treatment-guides';

  // AI Consultation
  static const String consultationMessages = '/consultation/messages';
  static const String consultationSend = '/consultation/send';
  static const String consultationTranslate = '/consultation/translate';
  static const String consultationClear = '/consultation/clear';
}
