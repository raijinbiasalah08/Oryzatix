import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../../core/theme/app_colors.dart';
import '../models/history_model.dart';
import '../providers/history_provider.dart';

class HistoryScreen extends StatefulWidget {
  final VoidCallback onOpenScan;

  const HistoryScreen({super.key, required this.onOpenScan});

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  String _selectedSeverityFilter = 'all';

  void _showDetailDialog(HistoryItem item) {
    final sevColor = AppColors.getSeverityColor(item.severity);
    final sevBg = AppColors.getSeverityBg(item.severity);

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(color: sevBg, shape: BoxShape.circle),
              child: Icon(Icons.coronavirus, color: sevColor, size: 24),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(item.disease, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
                  if (item.scientific != null)
                    Text(
                      item.scientific!,
                      style: const TextStyle(fontSize: 12, fontStyle: FontStyle.italic, color: AppColors.textMuted),
                    ),
                ],
              ),
            ),
          ],
        ),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text('Date: ${item.date} ${item.time}', style: const TextStyle(fontSize: 12, color: AppColors.textSecondary)),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(color: sevBg, borderRadius: BorderRadius.circular(8)),
                    child: Text(
                      item.severity.toUpperCase(),
                      style: TextStyle(color: sevColor, fontWeight: FontWeight.bold, fontSize: 11),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Text('Color analysis score (heuristic): ${item.confidence}%', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
              if (item.affectedPercentage != null) ...[
                const SizedBox(height: 4),
                Text('Affected Canopy: ${item.affectedPercentage}', style: const TextStyle(fontSize: 13, color: AppColors.textSecondary)),
              ],
              const SizedBox(height: 16),
              const Text('Management Advice Recorded:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
              const SizedBox(height: 6),
              if (item.treatments != null && item.treatments!['chemical'] is List)
                ...((item.treatments!['chemical'] as List).take(2).map((t) => Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Text('• ${t['name'] ?? ''}: ${t['desc'] ?? ''}', style: const TextStyle(fontSize: 12, color: AppColors.textSecondary)),
                    )))
              else
                const Text('Standard PhilRice cultural practices advised.', style: TextStyle(fontSize: 12, color: AppColors.textMuted)),
            ],
          ),
        ),
        actions: [
          TextButton.icon(
            onPressed: () async {
              final confirm = await showDialog<bool>(
                context: context,
                builder: (dCtx) => AlertDialog(
                  title: const Text('Delete Scan Record?'),
                  content: const Text('Are you sure you want to delete this scan from history?'),
                  actions: [
                    TextButton(onPressed: () => Navigator.pop(dCtx, false), child: const Text('Cancel')),
                    ElevatedButton(
                      style: ElevatedButton.styleFrom(backgroundColor: AppColors.severe),
                      onPressed: () => Navigator.pop(dCtx, true),
                      child: const Text('Delete'),
                    ),
                  ],
                ),
              );
              if (confirm == true && mounted) {
                final historyProvider = context.read<HistoryProvider>();
                await historyProvider.deleteScan(item.id);
                if (ctx.mounted) Navigator.pop(ctx);
              }
            },
            icon: const Icon(Icons.delete_outline, color: AppColors.severe, size: 18),
            label: const Text('Delete Record', style: TextStyle(color: AppColors.severe)),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Close'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final history = context.watch<HistoryProvider>();
    final stats = history.stats;
    final width = MediaQuery.of(context).size.width;
    final isDesktop = width >= 900;

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: () => history.loadHistory(),
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
                        'Scan History & Surveillance Log',
                        style: TextStyle(
                          fontSize: 24,
                          fontWeight: FontWeight.w900,
                          color: AppColors.textPrimary,
                        ),
                      ),
                      SizedBox(height: 4),
                      Text(
                        'Historical rice disease diagnosis records and treatment logs',
                        style: TextStyle(fontSize: 13, color: AppColors.textSecondary),
                      ),
                    ],
                  ),
                  IconButton(
                    icon: const Icon(Icons.refresh, color: AppColors.primary),
                    tooltip: 'Refresh Records',
                    onPressed: () => history.loadHistory(),
                  ),
                ],
              ),
              const SizedBox(height: 20),

              // Filter Chips
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  _buildFilterChip('all', 'All (${stats.total})'),
                  _buildFilterChip('healthy', 'Healthy (${stats.healthy})'),
                  _buildFilterChip('mild', 'Mild (${stats.mild})'),
                  _buildFilterChip('moderate', 'Moderate (${stats.moderate})'),
                  _buildFilterChip('severe', 'Severe (${stats.severe})'),
                ],
              ),
              const SizedBox(height: 20),

              // Content
              if (history.isLoading && history.scansByDate.isEmpty)
                const Center(
                  child: Padding(
                    padding: EdgeInsets.all(40),
                    child: CircularProgressIndicator(color: AppColors.primary),
                  ),
                )
              else if (history.scansByDate.isEmpty)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(40),
                    child: Center(
                      child: Column(
                        children: [
                          Icon(Icons.history, size: 54, color: AppColors.textMuted.withOpacity(0.5)),
                          const SizedBox(height: 14),
                          const Text(
                            'No scan records found.',
                            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                          ),
                          const SizedBox(height: 6),
                          const Text(
                            'Your past disease diagnoses and field logs will appear here.',
                            style: TextStyle(fontSize: 13, color: AppColors.textMuted),
                          ),
                          const SizedBox(height: 18),
                          ElevatedButton.icon(
                            onPressed: widget.onOpenScan,
                            icon: const Icon(Icons.camera_alt),
                            label: const Text('Scan Rice Leaf Now'),
                          ),
                        ],
                      ),
                    ),
                  ),
                )
              else ...[
                ...history.scansByDate.entries.map((entry) {
                  final dateHeader = entry.key;
                  final rawItems = entry.value;
                  final filteredItems = _selectedSeverityFilter == 'all'
                      ? rawItems
                      : rawItems.where((it) => it.severity.toLowerCase() == _selectedSeverityFilter).toList();

                  if (filteredItems.isEmpty) return const SizedBox.shrink();

                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 8),
                        child: Row(
                          children: [
                            const Icon(Icons.calendar_today, size: 14, color: AppColors.primary),
                            const SizedBox(width: 6),
                            Text(
                              dateHeader,
                              style: const TextStyle(
                                fontSize: 14,
                                fontWeight: FontWeight.bold,
                                color: AppColors.primary,
                              ),
                            ),
                            const SizedBox(width: 8),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                              decoration: BoxDecoration(
                                color: AppColors.primaryContainer,
                                borderRadius: BorderRadius.circular(10),
                              ),
                              child: Text(
                                '${filteredItems.length}',
                                style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: AppColors.primary),
                              ),
                            ),
                          ],
                        ),
                      ),
                      ListView.separated(
                        shrinkWrap: true,
                        physics: const NeverScrollableScrollPhysics(),
                        itemCount: filteredItems.length,
                        separatorBuilder: (ctx, i) => const SizedBox(height: 8),
                        itemBuilder: (ctx, i) {
                          final item = filteredItems[i];
                          final sevColor = AppColors.getSeverityColor(item.severity);
                          final sevBg = AppColors.getSeverityBg(item.severity);

                          return Card(
                            child: InkWell(
                              borderRadius: BorderRadius.circular(16),
                              onTap: () => _showDetailDialog(item),
                              child: Padding(
                                padding: const EdgeInsets.all(16),
                                child: Row(
                                  children: [
                                    Container(
                                      width: 48,
                                      height: 48,
                                      decoration: BoxDecoration(
                                        color: sevBg,
                                        borderRadius: BorderRadius.circular(12),
                                        border: Border.all(color: sevColor.withOpacity(0.3)),
                                      ),
                                      child: Center(
                                        child: Icon(
                                          item.severity == 'healthy' ? Icons.check_circle_outline : Icons.coronavirus_outlined,
                                          color: sevColor,
                                          size: 26,
                                        ),
                                      ),
                                    ),
                                    const SizedBox(width: 14),
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          Row(
                                            children: [
                                              Text(
                                                item.disease,
                                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                                              ),
                                              const SizedBox(width: 8),
                                              Container(
                                                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                                decoration: BoxDecoration(
                                                  color: sevBg,
                                                  borderRadius: BorderRadius.circular(6),
                                                ),
                                                child: Text(
                                                  item.severity.toUpperCase(),
                                                  style: TextStyle(
                                                    fontSize: 10,
                                                    fontWeight: FontWeight.w800,
                                                    color: sevColor,
                                                  ),
                                                ),
                                              ),
                                            ],
                                          ),
                                          if (item.scientific != null) ...[
                                            const SizedBox(height: 2),
                                            Text(
                                              item.scientific!,
                                              style: const TextStyle(
                                                fontStyle: FontStyle.italic,
                                                fontSize: 12,
                                                color: AppColors.textMuted,
                                              ),
                                            ),
                                          ],
                                          const SizedBox(height: 4),
                                          Text(
                                            'Color analysis: ${item.confidence}% · Time: ${item.time}',
                                            style: const TextStyle(fontSize: 12, color: AppColors.textSecondary),
                                          ),
                                        ],
                                      ),
                                    ),
                                    const Icon(Icons.chevron_right, color: AppColors.textMuted),
                                  ],
                                ),
                              ),
                            ),
                          );
                        },
                      ),
                      const SizedBox(height: 16),
                    ],
                  );
                }),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildFilterChip(String key, String label) {
    final isSelected = _selectedSeverityFilter == key;
    return ChoiceChip(
      label: Text(label),
      selected: isSelected,
      onSelected: (selected) {
        if (selected) setState(() => _selectedSeverityFilter = key);
      },
      selectedColor: AppColors.primary,
      labelStyle: TextStyle(
        color: isSelected ? Colors.white : AppColors.textSecondary,
        fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
        fontSize: 12,
      ),
    );
  }
}
