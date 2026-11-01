import 'dart:convert';
import 'dart:io';

import 'package:path_provider/path_provider.dart';

class OfflineScanStore {
  OfflineScanStore._();

  static final OfflineScanStore instance = OfflineScanStore._();
  static const int _maxRecords = 200;
  static const String _folderName = 'oryzatix_guest_scans';
  static const String _indexName = 'scan_records.json';

  Future<Directory> _directory() async {
    final documents = await getApplicationDocumentsDirectory();
    final directory = Directory('${documents.path}${Platform.pathSeparator}$_folderName');
    if (!await directory.exists()) await directory.create(recursive: true);
    return directory;
  }

  Future<File> _indexFile() async {
    final directory = await _directory();
    return File('${directory.path}${Platform.pathSeparator}$_indexName');
  }

  Future<List<Map<String, dynamic>>> loadRecords() async {
    final file = await _indexFile();
    if (!await file.exists()) return [];

    try {
      final decoded = jsonDecode(await file.readAsString());
      if (decoded is! List) return [];
      return decoded
          .whereType<Map>()
          .map((record) => record.map((key, value) => MapEntry(key.toString(), value)))
          .toList()
        ..sort((a, b) => (b['created_at']?.toString() ?? '')
            .compareTo(a['created_at']?.toString() ?? ''));
    } catch (_) {
      return [];
    }
  }

  Future<String> saveScan({
    required int id,
    required List<int> imageBytes,
    required String filename,
    required Map<String, dynamic> scan,
  }) async {
    final directory = await _directory();
    final extension = _safeExtension(filename);
    final imagePath = '${directory.path}${Platform.pathSeparator}guest_${id.abs()}$extension';
    await File(imagePath).writeAsBytes(imageBytes, flush: true);

    final record = <String, dynamic>{
      ...scan,
      'id': id,
      'image_path': imagePath,
      'local_only': true,
      'created_at': DateTime.now().toUtc().toIso8601String(),
    };
    final records = await loadRecords();
    records.removeWhere((existing) => existing['id'] == id);
    records.insert(0, record);

    final expired = records.skip(_maxRecords).toList();
    for (final old in expired) {
      final oldPath = old['image_path']?.toString();
      if (oldPath != null) {
        try {
          final oldFile = File(oldPath);
          if (await oldFile.exists()) await oldFile.delete();
        } catch (_) {}
      }
    }

    final index = await _indexFile();
    await index.writeAsString(jsonEncode(records.take(_maxRecords).toList()), flush: true);
    return imagePath;
  }

  Future<void> deleteScan(int id) async {
    final records = await loadRecords();
    final removed = records.where((record) => record['id'] == id).toList();
    records.removeWhere((record) => record['id'] == id);
    for (final record in removed) {
      final imagePath = record['image_path']?.toString();
      if (imagePath != null) {
        try {
          final file = File(imagePath);
          if (await file.exists()) await file.delete();
        } catch (_) {}
      }
    }
    final index = await _indexFile();
    await index.writeAsString(jsonEncode(records), flush: true);
  }

  String _safeExtension(String filename) {
    final match = RegExp(r'\.(jpe?g|png|webp)$', caseSensitive: false).firstMatch(filename);
    if (match == null) return '.jpg';
    final extension = match.group(0)!.toLowerCase();
    return extension == '.jpeg' ? '.jpg' : extension;
  }
}
