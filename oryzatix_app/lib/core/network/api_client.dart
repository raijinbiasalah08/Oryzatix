import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:http_parser/http_parser.dart';
import '../config/app_config.dart';
import '../storage/token_storage.dart';
import 'api_exception.dart';

class ApiClient {
  static Future<Map<String, String>> _buildHeaders({bool isMultipart = false}) async {
    final headers = <String, String>{
      'Accept': 'application/json',
    };
    if (!isMultipart) {
      headers['Content-Type'] = 'application/json';
    }

    final token = await TokenStorage.getToken();
    if (token != null && token.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
    }

    return headers;
  }

  static Uri _buildUri(String path, [Map<String, dynamic>? queryParams]) {
    final base = AppConfig.baseUrl;
    final normalizedPath = path.startsWith('/') ? path : '/$path';
    final fullUrl = '$base$normalizedPath';

    if (queryParams != null && queryParams.isNotEmpty) {
      final strParams = queryParams.map((k, v) => MapEntry(k, v.toString()));
      return Uri.parse(fullUrl).replace(queryParameters: strParams);
    }
    return Uri.parse(fullUrl);
  }

  static dynamic _handleResponse(http.Response response) {
    dynamic decoded;
    try {
      decoded = jsonDecode(response.body);
    } catch (_) {
      decoded = {'message': response.body};
    }

    final statusCode = response.statusCode;

    if (statusCode >= 200 && statusCode < 300) {
      return decoded;
    }

    String message = 'Request failed with status $statusCode';
    bool isLocked = false;
    int remainingSeconds = 0;

    if (decoded is Map<String, dynamic>) {
      if (decoded['message'] != null) {
        message = decoded['message'].toString();
      } else if (decoded['error'] != null) {
        message = decoded['error'].toString();
      }

      if (decoded['errors'] != null && decoded['errors'] is Map) {
        final errs = decoded['errors'] as Map;
        if (errs.isNotEmpty) {
          final firstKey = errs.keys.first;
          final firstVal = errs[firstKey];
          if (firstVal is List && firstVal.isNotEmpty) {
            message = firstVal.first.toString();
          } else {
            message = firstVal.toString();
          }
        }
      }

      if (decoded['locked'] == true) {
        isLocked = true;
        remainingSeconds = int.tryParse(decoded['remaining_seconds']?.toString() ?? '0') ?? 0;
      }
    }

    throw ApiException(
      message: message,
      statusCode: statusCode,
      data: decoded is Map<String, dynamic> ? decoded : null,
      isLocked: isLocked,
      remainingSeconds: remainingSeconds,
    );
  }

  static Future<dynamic> get(String path, {Map<String, dynamic>? queryParams}) async {
    try {
      final uri = _buildUri(path, queryParams);
      final headers = await _buildHeaders();
      final response = await http.get(uri, headers: headers).timeout(
            const Duration(seconds: 25),
          );
      return _handleResponse(response);
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(message: 'Connection error: ${e.toString()}');
    }
  }

  static Future<dynamic> post(String path, {dynamic body}) async {
    try {
      final uri = _buildUri(path);
      final headers = await _buildHeaders();
      final response = await http
          .post(
            uri,
            headers: headers,
            body: body != null ? jsonEncode(body) : null,
          )
          .timeout(const Duration(seconds: 25));
      return _handleResponse(response);
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(message: 'Connection error: ${e.toString()}');
    }
  }

  static Future<dynamic> put(String path, {dynamic body}) async {
    try {
      final uri = _buildUri(path);
      final headers = await _buildHeaders();
      final response = await http
          .put(
            uri,
            headers: headers,
            body: body != null ? jsonEncode(body) : null,
          )
          .timeout(const Duration(seconds: 25));
      return _handleResponse(response);
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(message: 'Connection error: ${e.toString()}');
    }
  }

  static Future<dynamic> delete(String path) async {
    try {
      final uri = _buildUri(path);
      final headers = await _buildHeaders();
      final response = await http.delete(uri, headers: headers).timeout(
            const Duration(seconds: 25),
          );
      return _handleResponse(response);
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(message: 'Connection error: ${e.toString()}');
    }
  }

  /// Uploads an image file as multipart/form-data with field 'image'.
  static Future<dynamic> uploadFile({
    required String path,
    required List<int> fileBytes,
    required String filename,
    Map<String, String>? extraFields,
  }) async {
    try {
      final uri = _buildUri(path);
      final headers = await _buildHeaders(isMultipart: true);

      final request = http.MultipartRequest('POST', uri);
      request.headers.addAll(headers);

      if (extraFields != null) {
        request.fields.addAll(extraFields);
      }

      String mimeType = 'image/jpeg';
      final lower = filename.toLowerCase();
      if (lower.endsWith('.png')) {
        mimeType = 'image/png';
      } else if (lower.endsWith('.webp')) {
        mimeType = 'image/webp';
      }

      final parts = mimeType.split('/');
      final mediaType = MediaType(parts[0], parts[1]);

      final multipartFile = http.MultipartFile.fromBytes(
        'image',
        fileBytes,
        filename: filename,
        contentType: mediaType,
      );

      request.files.add(multipartFile);

      final streamedResponse = await request.send().timeout(const Duration(seconds: 40));
      final response = await http.Response.fromStream(streamedResponse);

      return _handleResponse(response);
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(message: 'Upload error: ${e.toString()}');
    }
  }
}
