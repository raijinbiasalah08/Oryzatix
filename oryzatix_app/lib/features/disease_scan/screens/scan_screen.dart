import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../../core/theme/app_colors.dart';
import '../models/scan_result_model.dart';
import '../providers/scan_provider.dart';

class ScanScreen extends StatefulWidget {
  final VoidCallback onOpenConsultation;
  final VoidCallback onOpenTreatment;

  const ScanScreen({
    super.key,
    required this.onOpenConsultation,
    required this.onOpenTreatment,
  });

  @override
  State<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends State<ScanScreen> {
  int _activeTreatmentTab = 0; // 0 = Chemical, 1 = Organic

  @override
  Widget build(BuildContext context) {
    final scanner = context.watch<ScanProvider>();
    final result = scanner.result;
    final width = MediaQuery.of(context).size.width;
    final isDesktop = width >= 900;

    return Scaffold(
      body: SingleChildScrollView(
        padding: EdgeInsets.symmetric(
          horizontal: isDesktop ? 32 : 16,
          vertical: 24,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Screen Title
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Rice Disease Scan Studio',
                      style: TextStyle(
                        fontSize: 24,
                        fontWeight: FontWeight.w900,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    SizedBox(height: 4),
                    Text(
                      scanner.usesOnDeviceModel
                          ? 'On-device MobileNetV2 diagnosis; severity is estimated separately.'
                          : 'Image reference matching and color-based severity estimate',
                      style: const TextStyle(fontSize: 13, color: AppColors.textSecondary),
                    ),
                  ],
                ),
                if (scanner.selectedImageBytes != null || result != null)
                  TextButton.icon(
                    onPressed: () => scanner.clear(),
                    icon: const Icon(Icons.refresh, color: AppColors.primary),
                    label: const Text('Reset Scanner'),
                  ),
              ],
            ),
            const SizedBox(height: 20),

            // Main Scanner Layout
            if (isDesktop)
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(flex: 5, child: _buildImagePickerCard(scanner)),
                  const SizedBox(width: 24),
                  Expanded(
                    flex: 6,
                    child: result != null
                        ? _buildDiagnosisResultView(result)
                        : _buildDiagnosticGuidelinesCard(),
                  ),
                ],
              )
            else
              Column(
                children: [
                  _buildImagePickerCard(scanner),
                  const SizedBox(height: 20),
                  if (result != null)
                    _buildDiagnosisResultView(result)
                  else
                    _buildDiagnosticGuidelinesCard(),
                ],
              ),
          ],
        ),
      ),
    );
  }

  Widget _buildImagePickerCard(ScanProvider scanner) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Rice Leaf Image Input',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
            ),
            const SizedBox(height: 6),
            const Text(
              'Capture a leaf lesion close-up in good lighting for high diagnostic accuracy.',
              style: TextStyle(fontSize: 12, color: AppColors.textSecondary),
            ),
            const SizedBox(height: 16),

            // Image Viewport
            Container(
              height: 260,
              decoration: BoxDecoration(
                color: AppColors.surfaceSubtle,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(
                  color: scanner.selectedImageBytes != null ? AppColors.primary : AppColors.border,
                  width: scanner.selectedImageBytes != null ? 2 : 1,
                ),
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(15),
                child: scanner.selectedImageBytes != null
                    ? Stack(
                        fit: StackFit.expand,
                        children: [
                          Image.memory(
                            scanner.selectedImageBytes!,
                            fit: BoxFit.contain,
                          ),
                          Positioned(
                            top: 8,
                            right: 8,
                            child: IconButton(
                              icon: const Icon(Icons.cancel, color: AppColors.severe),
                              onPressed: () => scanner.clear(),
                            ),
                          ),
                        ],
                      )
                    : Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(Icons.add_photo_alternate_outlined, size: 54, color: AppColors.textMuted.withValues(alpha: 0.6)),
                            const SizedBox(height: 12),
                            const Text(
                              'No Rice Leaf Selected',
                              style: TextStyle(fontWeight: FontWeight.w600, color: AppColors.textSecondary),
                            ),
                            const SizedBox(height: 4),
                            const Text(
                              'Choose a real rice leaf image from your camera or gallery',
                              style: TextStyle(fontSize: 12, color: AppColors.textMuted),
                            ),
                          ],
                        ),
                      ),
              ),
            ),
            const SizedBox(height: 16),

            // Selection Buttons
            Row(
              children: [
                Expanded(
                  child: ElevatedButton.icon(
                    onPressed: scanner.isAnalyzing ? null : () => scanner.pickFromCamera(),
                    icon: const Icon(Icons.camera_alt),
                    label: const Text('Camera'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: scanner.isAnalyzing ? null : () => scanner.pickFromGallery(),
                    icon: const Icon(Icons.photo_library),
                    label: const Text('Gallery / Files'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),

            if (scanner.errorMessage != null) ...[
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AppColors.severeBg,
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: AppColors.severe),
                ),
                child: Text(
                  scanner.errorMessage!,
                  style: const TextStyle(color: AppColors.severe, fontSize: 13),
                ),
              ),
              const SizedBox(height: 16),
            ],

            // Analyze Button
            ElevatedButton(
              onPressed: (scanner.selectedImageBytes == null || scanner.isAnalyzing)
                  ? null
                  : () => scanner.analyzeCurrentLeaf(),
              style: ElevatedButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 16),
                backgroundColor: AppColors.primaryLight,
              ),
              child: scanner.isAnalyzing
                  ? const Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                        ),
                        SizedBox(width: 12),
                        Text('Analyzing Rice Leaf...'),
                      ],
                    )
                  : Text(
                    scanner.usesOnDeviceModel
                        ? 'Analyze with MobileNetV2'
                        : 'Analyze Rice Leaf',
                      style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildDiagnosticGuidelinesCard() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(
              children: [
                Icon(Icons.tips_and_updates, color: AppColors.primary),
                SizedBox(width: 10),
                Text(
                  'PhilRice Scanning Best Practices',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                ),
              ],
            ),
            const SizedBox(height: 16),
            _buildGuidelineItem(
              icon: Icons.filter_center_focus,
              title: 'Hold Camera 10–15 cm from Leaf',
              desc: 'Focus directly on lesion discoloration rather than the surrounding water or mud.',
            ),
            const SizedBox(height: 12),
            _buildGuidelineItem(
              icon: Icons.wb_sunny_outlined,
              title: 'Avoid Harsh Backlight Shadows',
              desc: 'Natural daylight without direct sun reflection produces the highest color space accuracy.',
            ),
            const SizedBox(height: 12),
            _buildGuidelineItem(
              icon: Icons.verified_user_outlined,
              title: 'MobileNetV2 classes (Android/iOS)',
              desc: 'Recognizes Bacterial Leaf Blight (BLB), Brown Spot, Sheath Blight, Tungro, and Healthy leaves. Leaf Blast is not included in this model.',
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildGuidelineItem({required IconData icon, required String title, required String desc}) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(
            color: AppColors.primaryContainer,
            borderRadius: BorderRadius.circular(10),
          ),
          child: Icon(icon, color: AppColors.primary, size: 20),
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
              const SizedBox(height: 2),
              Text(desc, style: const TextStyle(fontSize: 12, color: AppColors.textSecondary, height: 1.4)),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildDiagnosisResultView(ScanResultModel result) {
    final sevColor = AppColors.getSeverityColor(result.severity);
    final sevBg = AppColors.getSeverityBg(result.severity);

    return Card(
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: BorderSide(color: sevColor.withValues(alpha: 0.5), width: 1.5),
      ),
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Top Status Badge
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: sevBg,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Row(
                    children: [
                      Icon(Icons.check_circle, size: 14, color: sevColor),
                      const SizedBox(width: 6),
                      Text(
                        'DIAGNOSIS COMPLETE',
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w900,
                          color: sevColor,
                        ),
                      ),
                    ],
                  ),
                ),
                Text(
                  '${result.date} ${result.time}',
                  style: const TextStyle(fontSize: 12, color: AppColors.textMuted),
                ),
              ],
            ),
            const SizedBox(height: 16),

            // Disease Name & Scientific Name
            Text(
              result.disease,
              style: TextStyle(
                fontSize: 24,
                fontWeight: FontWeight.w900,
                color: sevColor,
              ),
            ),
            if (result.scientific != null) ...[
              const SizedBox(height: 2),
              Text(
                result.scientific!,
                style: const TextStyle(
                  fontStyle: FontStyle.italic,
                  fontSize: 14,
                  color: AppColors.textSecondary,
                ),
              ),
            ],
            const SizedBox(height: 16),

            // Diagnostic Metrics Row (Color Analysis Score & Severity)
            Row(
              children: [
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: AppColors.surfaceSubtle,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          result.analysisSource == 'mobilenet_v2'
                              ? 'MobileNetV2 confidence'
                              : 'Color analysis',
                          style: const TextStyle(fontSize: 11, color: AppColors.textMuted),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          '${result.confidence}%',
                          style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: sevBg,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Severity Level', style: TextStyle(fontSize: 11, color: AppColors.textSecondary)),
                        const SizedBox(height: 2),
                        Text(
                          result.severity.toUpperCase(),
                          style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: sevColor),
                        ),
                      ],
                    ),
                  ),
                ),
                if (result.affectedPercentage != null) ...[
                  const SizedBox(width: 12),
                  Expanded(
                    child: Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: AppColors.surfaceSubtle,
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text('Affected Area', style: TextStyle(fontSize: 11, color: AppColors.textMuted)),
                          const SizedBox(height: 2),
                          Text(
                            '${result.affectedPercentage}%',
                            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ],
            ),
            const SizedBox(height: 20),

            // Treatment Tabs (Chemical vs Organic)
            Container(
              decoration: BoxDecoration(
                color: AppColors.surfaceSubtle,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: InkWell(
                      onTap: () => setState(() => _activeTreatmentTab = 0),
                      child: Container(
                        padding: const EdgeInsets.symmetric(vertical: 10),
                        decoration: BoxDecoration(
                          color: _activeTreatmentTab == 0 ? AppColors.primary : Colors.transparent,
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Text(
                          'Chemical Control (${result.chemicalTreatments.length})',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.bold,
                            color: _activeTreatmentTab == 0 ? Colors.white : AppColors.textSecondary,
                          ),
                        ),
                      ),
                    ),
                  ),
                  Expanded(
                    child: InkWell(
                      onTap: () => setState(() => _activeTreatmentTab = 1),
                      child: Container(
                        padding: const EdgeInsets.symmetric(vertical: 10),
                        decoration: BoxDecoration(
                          color: _activeTreatmentTab == 1 ? AppColors.primary : Colors.transparent,
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Text(
                          'Organic & Agronomic (${result.organicTreatments.length})',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.bold,
                            color: _activeTreatmentTab == 1 ? Colors.white : AppColors.textSecondary,
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // Treatments List
            Builder(
              builder: (ctx) {
                final treatments = _activeTreatmentTab == 0 ? result.chemicalTreatments : result.organicTreatments;
                if (treatments.isEmpty) {
                  return const Padding(
                    padding: EdgeInsets.all(16),
                    child: Text('No treatments recorded for this category.', textAlign: TextAlign.center),
                  );
                }

                return Column(
                  children: treatments.map((item) {
                    return Container(
                      margin: const EdgeInsets.only(bottom: 10),
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: AppColors.surface,
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: AppColors.border),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Expanded(
                                child: Text(
                                  item.name,
                                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                ),
                              ),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                                decoration: BoxDecoration(
                                  color: AppColors.primaryContainer,
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: Text(
                                  item.tag,
                                  style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: AppColors.primary),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 6),
                          Text(
                            item.desc,
                            style: const TextStyle(fontSize: 12, color: AppColors.textSecondary, height: 1.4),
                          ),
                        ],
                      ),
                    );
                  }).toList(),
                );
              },
            ),
            const SizedBox(height: 16),

            // Follow-up Actions
            Row(
              children: [
                Expanded(
                  child: ElevatedButton.icon(
                    onPressed: widget.onOpenConsultation,
                    icon: const Icon(Icons.forum, size: 18),
                    label: const Text('Consult AI'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: widget.onOpenTreatment,
                    icon: const Icon(Icons.menu_book, size: 18),
                    label: const Text('Full Guide'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
