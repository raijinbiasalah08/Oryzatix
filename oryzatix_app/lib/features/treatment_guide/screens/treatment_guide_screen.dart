import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../../core/theme/app_colors.dart';
import '../models/treatment_guide_model.dart';
import '../providers/treatment_provider.dart';

class TreatmentGuideScreen extends StatefulWidget {
  const TreatmentGuideScreen({super.key});

  @override
  State<TreatmentGuideScreen> createState() => _TreatmentGuideScreenState();
}

class _TreatmentGuideScreenState extends State<TreatmentGuideScreen> {
  final _areaController = TextEditingController(text: '1.0');
  String _areaUnit = 'hectare'; // 'hectare' or 'sqm'
  int _activeTreatmentTab = 0; // 0 = Chemical, 1 = Organic

  @override
  void dispose() {
    _areaController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final treatment = context.watch<TreatmentProvider>();
    final guide = treatment.selectedGuide;
    final width = MediaQuery.of(context).size.width;
    final isDesktop = width >= 900;

    return Scaffold(
      body: treatment.isLoading && treatment.guides.isEmpty
          ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
          : RefreshIndicator(
              onRefresh: () => treatment.loadGuides(),
              color: AppColors.primary,
              child: SingleChildScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: EdgeInsets.symmetric(
                  horizontal: isDesktop ? 32 : 16,
                  vertical: 24,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Header
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Treatment & Management Guide',
                              style: TextStyle(
                                fontSize: 24,
                                fontWeight: FontWeight.w900,
                                color: AppColors.textPrimary,
                              ),
                            ),
                            SizedBox(height: 4),
                            Text(
                              'DA-PhilRice Certified Chemical and Organic Protocols',
                              style: TextStyle(fontSize: 13, color: AppColors.textSecondary),
                            ),
                          ],
                        ),
                        IconButton(
                          icon: const Icon(Icons.refresh, color: AppColors.primary),
                          tooltip: 'Refresh Guides',
                          onPressed: () => treatment.loadGuides(),
                        ),
                      ],
                    ),
                    const SizedBox(height: 20),

                    // Disease Selection Horizontal Bar
                    SizedBox(
                      height: 42,
                      child: ListView.separated(
                        scrollDirection: Axis.horizontal,
                        itemCount: treatment.guides.length,
                        separatorBuilder: (ctx, i) => const SizedBox(width: 8),
                        itemBuilder: (ctx, i) {
                          final g = treatment.guides[i];
                          final isSelected = i == treatment.selectedIndex;
                          return ChoiceChip(
                            label: Text(g.name),
                            selected: isSelected,
                            onSelected: (sel) {
                              if (sel) treatment.selectDisease(i);
                            },
                            selectedColor: AppColors.primary,
                            labelStyle: TextStyle(
                              color: isSelected ? Colors.white : AppColors.textPrimary,
                              fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
                              fontSize: 13,
                            ),
                          );
                        },
                      ),
                    ),
                    const SizedBox(height: 24),

                    if (guide == null)
                      const Center(child: Text('No disease guides available.'))
                    else ...[
                      // Disease Card Banner
                      _buildDiseaseBanner(guide),
                      const SizedBox(height: 20),

                      // Severity Selector (for diseases with severity levels)
                      if (guide.severityLevels != null && guide.severityLevels!.isNotEmpty) ...[
                        _buildSeverityPills(treatment, guide),
                        const SizedBox(height: 20),
                      ],

                      // Treatments Grid / Tabs
                      _buildTreatmentsSection(treatment, guide),
                      const SizedBox(height: 28),

                      // Agronomic Symptoms & Prevention Details
                      _buildPathologyCard(guide),
                      const SizedBox(height: 28),

                      // Knapsack Field Dosage Calculator
                      _buildDosageCalculator(),
                    ],
                  ],
                ),
              ),
            ),
    );
  }

  Widget _buildDiseaseBanner(DiseaseGuideModel guide) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.border),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.03),
            blurRadius: 10,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(14),
            decoration: const BoxDecoration(
              color: AppColors.primaryContainer,
              shape: BoxShape.circle,
            ),
            child: const Icon(Icons.eco, color: AppColors.primary, size: 28),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  guide.name,
                  style: const TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.w900,
                    color: AppColors.primary,
                  ),
                ),
                if (guide.scientificName != null) ...[
                  const SizedBox(height: 2),
                  Text(
                    guide.scientificName!,
                    style: const TextStyle(
                      fontStyle: FontStyle.italic,
                      fontSize: 13,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ],
                if (guide.description != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    guide.description!,
                    style: const TextStyle(fontSize: 13, color: AppColors.textSecondary, height: 1.4),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSeverityPills(TreatmentProvider provider, DiseaseGuideModel guide) {
    final levels = guide.severityLevels!;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Select Severity Stage:',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
        ),
        const SizedBox(height: 8),
        Row(
          children: ['mild', 'moderate', 'severe'].map((sev) {
            final hasIt = levels.containsKey(sev);
            if (!hasIt) return const SizedBox.shrink();

            final isSelected = provider.selectedSeverity == sev;
            final color = AppColors.getSeverityColor(sev);

            return Expanded(
              child: GestureDetector(
                onTap: () => provider.setSeverity(sev),
                child: Container(
                  margin: const EdgeInsets.symmetric(horizontal: 4),
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  decoration: BoxDecoration(
                    color: isSelected ? color : AppColors.surface,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(
                      color: isSelected ? color : AppColors.border,
                      width: isSelected ? 2 : 1,
                    ),
                  ),
                  child: Column(
                    children: [
                      Text(
                        sev.toUpperCase(),
                        style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.bold,
                          color: isSelected ? Colors.white : AppColors.textPrimary,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        levels[sev]?.range ?? '',
                        style: TextStyle(
                          fontSize: 11,
                          color: isSelected ? Colors.white70 : AppColors.textMuted,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            );
          }).toList(),
        ),
      ],
    );
  }

  Widget _buildTreatmentsSection(TreatmentProvider provider, DiseaseGuideModel guide) {
    List<dynamic> chem = [];
    List<dynamic> org = [];

    if (guide.severityLevels != null && guide.severityLevels!.containsKey(provider.selectedSeverity)) {
      final info = guide.severityLevels![provider.selectedSeverity]!;
      chem = info.chemical;
      org = info.organic;
    } else {
      chem = guide.chemicalTreatments;
      org = guide.organicTreatments;
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // Tabs
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
                      'Chemical Controls (${chem.length})',
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
                      'Organic & Cultural (${org.length})',
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

        // Treatment Cards
        Builder(
          builder: (ctx) {
            final activeList = _activeTreatmentTab == 0 ? chem : org;
            if (activeList.isEmpty) {
              return const Card(
                child: Padding(
                  padding: EdgeInsets.all(24),
                  child: Center(
                    child: Text(
                      'No specific treatments recorded for this category.',
                      style: TextStyle(color: AppColors.textMuted),
                    ),
                  ),
                ),
              );
            }

            return Column(
              children: activeList.map((item) {
                final name = item['name'] ?? item['title'] ?? 'Treatment Protocol';
                final desc = item['desc'] ?? item['rate'] ?? '';
                final tag = item['tag'] ?? item['timing'] ?? 'DA-PhilRice';

                return Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Expanded(
                              child: Text(
                                name,
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                              ),
                            ),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                              decoration: BoxDecoration(
                                color: AppColors.primaryContainer,
                                borderRadius: BorderRadius.circular(8),
                              ),
                              child: Text(
                                tag,
                                style: const TextStyle(
                                  fontSize: 10,
                                  fontWeight: FontWeight.bold,
                                  color: AppColors.primary,
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 6),
                        Text(
                          desc,
                          style: const TextStyle(fontSize: 13, color: AppColors.textSecondary, height: 1.4),
                        ),
                      ],
                    ),
                  ),
                );
              }).toList(),
            );
          },
        ),
      ],
    );
  }

  Widget _buildPathologyCard(DiseaseGuideModel guide) {
    if (guide.symptoms == null && guide.causes == null && guide.prevention == null) {
      return const SizedBox.shrink();
    }

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Field Diagnostics & Preventive Measures',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
            ),
            const SizedBox(height: 14),
            if (guide.symptoms != null) ...[
              _buildSectionItem('Diagnostic Symptoms', guide.symptoms!, Icons.visibility_outlined),
              const SizedBox(height: 12),
            ],
            if (guide.causes != null) ...[
              _buildSectionItem('Environmental Triggers', guide.causes!, Icons.cloud_outlined),
              const SizedBox(height: 12),
            ],
            if (guide.prevention != null) ...[
              _buildSectionItem('Agronomic Prevention', guide.prevention!, Icons.shield_outlined),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildSectionItem(String title, String content, IconData icon) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 18, color: AppColors.primary),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
              const SizedBox(height: 2),
              Text(content, style: const TextStyle(fontSize: 12, color: AppColors.textSecondary, height: 1.4)),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildDosageCalculator() {
    final rawVal = double.tryParse(_areaController.text) ?? 1.0;
    final isHectare = _areaUnit == 'hectare';
    final hectares = isHectare ? rawVal : rawVal / 10000;

    final litersWater = (hectares * 200).round();
    final sprayers = (litersWater / 16).toStringAsFixed(1);
    final mlMin = (litersWater * 1.0).round();
    final mlMax = (litersWater * 2.0).round();

    return Card(
      color: AppColors.primaryContainer.withOpacity(0.35),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: const BorderSide(color: AppColors.borderGreen),
      ),
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(
              children: [
                Icon(Icons.calculate, color: AppColors.primary),
                SizedBox(width: 8),
                Text(
                  'PhilRice Field Spray Dosage Calculator',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: AppColors.primaryDark),
                ),
              ],
            ),
            const SizedBox(height: 6),
            const Text(
              'Calculate water volume and fungicide/bactericide mixture needed for your exact paddy area.',
              style: TextStyle(fontSize: 12, color: AppColors.textSecondary),
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  flex: 2,
                  child: TextField(
                    controller: _areaController,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: const InputDecoration(
                      labelText: 'Field Area',
                    ),
                    onChanged: (val) => setState(() {}),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  flex: 2,
                  child: DropdownButtonFormField<String>(
                    value: _areaUnit,
                    decoration: const InputDecoration(
                      labelText: 'Unit',
                    ),
                    items: const [
                      DropdownMenuItem(value: 'hectare', child: Text('Hectares (ha)')),
                      DropdownMenuItem(value: 'sqm', child: Text('Square Meters (m²)')),
                    ],
                    onChanged: (val) {
                      if (val != null) setState(() => _areaUnit = val);
                    },
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),

            // Calculation Results
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppColors.borderGreen),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const Icon(Icons.water_drop, color: Colors.blue, size: 20),
                      const SizedBox(width: 8),
                      Text(
                        'Total Water Volume: $litersWater Liters',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '≈ $sprayers knapsack loads (standard 16-liter backpack tank)',
                    style: const TextStyle(fontSize: 12, color: AppColors.textSecondary),
                  ),
                  const Divider(height: 16),
                  Row(
                    children: [
                      const Icon(Icons.sanitizer, color: AppColors.primary, size: 20),
                      const SizedBox(width: 8),
                      Text(
                        'Active Chemical Mixture: $mlMin – $mlMax ml (or grams)',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: AppColors.primary),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  const Text(
                    'Standard concentration rate: 1.0 – 2.0 ml (or grams) per liter of water.',
                    style: TextStyle(fontSize: 11, color: AppColors.textMuted),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
