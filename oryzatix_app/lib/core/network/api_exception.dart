class ApiException implements Exception {
  final String message;
  final int statusCode;
  final Map<String, dynamic>? data;
  final bool isLocked;
  final int remainingSeconds;

  ApiException({
    required this.message,
    this.statusCode = 500,
    this.data,
    this.isLocked = false,
    this.remainingSeconds = 0,
  });

  @override
  String toString() => message;
}
