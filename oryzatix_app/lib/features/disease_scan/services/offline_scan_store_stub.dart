class OfflineScanStore {
  OfflineScanStore._();

  static final OfflineScanStore instance = OfflineScanStore._();

  Future<List<Map<String, dynamic>>> loadRecords() async => [];

  Future<String> saveScan({
    required int id,
    required List<int> imageBytes,
    required String filename,
    required Map<String, dynamic> scan,
  }) async {
    throw UnsupportedError('Local scan files are unavailable on this platform.');
  }

  Future<void> deleteScan(int id) async {}
}
