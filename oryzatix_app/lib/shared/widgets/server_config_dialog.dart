import 'package:flutter/material.dart';
import '../../core/config/app_config.dart';
import '../../core/network/api_client.dart';
import '../../core/network/api_endpoints.dart';
import '../../core/theme/app_colors.dart';

class ServerConfigDialog extends StatefulWidget {
  const ServerConfigDialog({super.key});

  static Future<void> show(BuildContext context) {
    return showDialog(
      context: context,
      builder: (ctx) => const ServerConfigDialog(),
    );
  }

  @override
  State<ServerConfigDialog> createState() => _ServerConfigDialogState();
}

class _ServerConfigDialogState extends State<ServerConfigDialog> {
  late final TextEditingController _controller;
  bool _isTesting = false;
  String? _testResult;
  bool? _testSuccess;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: AppConfig.baseUrl);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _testConnection() async {
    setState(() {
      _isTesting = true;
      _testResult = null;
      _testSuccess = null;
    });

    final testUrl = _controller.text.trim();
    // Temporarily set base URL for test
    await AppConfig.setBaseUrl(testUrl);

    try {
      final res = await ApiClient.get(ApiEndpoints.health);
      if (res is Map && res['success'] == true) {
        setState(() {
          _isTesting = false;
          _testSuccess = true;
          _testResult = 'Connected to Oryzatix API v1 successfully!';
        });
      } else {
        setState(() {
          _isTesting = false;
          _testSuccess = false;
          _testResult = 'Server responded with unexpected format.';
        });
      }
    } catch (e) {
      setState(() {
        _isTesting = false;
        _testSuccess = false;
        _testResult = 'Connection failed: ${e.toString()}';
      });
    }
  }

  Future<void> _saveAndClose() async {
    await AppConfig.setBaseUrl(_controller.text.trim());
    if (mounted) {
      Navigator.of(context).pop();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Server API URL updated successfully'),
          backgroundColor: AppColors.primary,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Row(
        children: [
          const Icon(Icons.settings_ethernet, color: AppColors.primary),
          const SizedBox(width: 8),
          const Text('API Server Host', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
        ],
      ),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Configure the target Laravel backend endpoint. This allows seamless switching between Windows Desktop, Android Emulator, physical devices, and web deployments.',
              style: TextStyle(fontSize: 13, color: AppColors.textSecondary),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _controller,
              decoration: const InputDecoration(
                labelText: 'API Base URL',
                hintText: 'http://localhost/Oryzatix/public/api/v1',
                prefixIcon: Icon(Icons.link, color: AppColors.primary),
              ),
            ),
            const SizedBox(height: 12),
            const Text('Quick Presets:', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 12)),
            const SizedBox(height: 6),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                ActionChip(
                  label: const Text('Localhost (Desktop/Web)', style: TextStyle(fontSize: 11)),
                  onPressed: () => _controller.text = AppConfig.defaultWebWindowsBase,
                ),
                ActionChip(
                  label: const Text('10.0.2.2 (Android Emu)', style: TextStyle(fontSize: 11)),
                  onPressed: () => _controller.text = AppConfig.defaultAndroidEmulatorBase,
                ),
                ActionChip(
                  label: const Text('192.168.1.34 (Physical LAN)', style: TextStyle(fontSize: 11)),
                  onPressed: () => _controller.text = AppConfig.defaultLanBase,
                ),
              ],
            ),
            if (_isTesting) ...[
              const SizedBox(height: 14),
              const Center(
                child: SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.primary),
                ),
              ),
            ],
            if (_testResult != null) ...[
              const SizedBox(height: 14),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: _testSuccess == true ? AppColors.healthyBg : AppColors.severeBg,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(
                    color: _testSuccess == true ? AppColors.healthy : AppColors.severe,
                  ),
                ),
                child: Row(
                  children: [
                    Icon(
                      _testSuccess == true ? Icons.check_circle : Icons.error,
                      color: _testSuccess == true ? AppColors.healthy : AppColors.severe,
                      size: 18,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        _testResult!,
                        style: TextStyle(
                          fontSize: 12,
                          color: _testSuccess == true ? AppColors.healthy : AppColors.severe,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: _testConnection,
          child: const Text('Test Connection'),
        ),
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        ElevatedButton(
          onPressed: _saveAndClose,
          child: const Text('Save Host'),
        ),
      ],
    );
  }
}
