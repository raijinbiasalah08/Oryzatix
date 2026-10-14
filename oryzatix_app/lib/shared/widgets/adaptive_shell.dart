import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../core/theme/app_colors.dart';
import '../../features/ai_consultation/screens/consultation_screen.dart';
import '../../features/auth/providers/auth_provider.dart';
import '../../features/dashboard/screens/dashboard_screen.dart';
import '../../features/disease_scan/screens/scan_screen.dart';
import '../../features/scan_history/screens/history_screen.dart';
import '../../features/treatment_guide/screens/treatment_guide_screen.dart';
import 'server_config_dialog.dart';

class AdaptiveShell extends StatefulWidget {
  const AdaptiveShell({super.key});

  @override
  State<AdaptiveShell> createState() => _AdaptiveShellState();
}

class _AdaptiveShellState extends State<AdaptiveShell> {
  int _currentIndex = 0;

  void _navigateTo(int index) {
    setState(() => _currentIndex = index);
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final user = auth.currentUser;
    final width = MediaQuery.of(context).size.width;
    final isDesktop = width >= 900;

    final pages = [
      DashboardScreen(
        onStartScan: () => _navigateTo(1),
        onOpenConsultation: () => _navigateTo(3),
        onOpenTreatment: () => _navigateTo(4),
      ),
      ScanScreen(
        onOpenConsultation: () => _navigateTo(3),
        onOpenTreatment: () => _navigateTo(4),
      ),
      HistoryScreen(
        onOpenScan: () => _navigateTo(1),
      ),
      const ConsultationScreen(),
      const TreatmentGuideScreen(),
    ];

    if (isDesktop) {
      return Scaffold(
        body: Row(
          children: [
            // Desktop Dark Green Sidebar
            Container(
              width: 260,
              decoration: const BoxDecoration(
                color: AppColors.primary,
                boxShadow: [
                  BoxShadow(
                    color: Colors.black12,
                    blurRadius: 10,
                    offset: Offset(2, 0),
                  ),
                ],
              ),
              child: Column(
                children: [
                  // Brand Header
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
                    child: Row(
                      children: [
                        Container(
                          width: 44,
                          height: 44,
                          decoration: const BoxDecoration(
                            shape: BoxShape.circle,
                            color: Colors.white,
                          ),
                          child: ClipOval(
                            child: Image.asset(
                              'assets/images/logo.png',
                              fit: BoxFit.cover,
                              errorBuilder: (ctx, err, stack) => const Icon(
                                Icons.eco,
                                color: AppColors.primary,
                              ),
                            ),
                          ),
                        ),
                        const SizedBox(width: 12),
                        const Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'ORYZATIX',
                              style: TextStyle(
                                fontSize: 18,
                                fontWeight: FontWeight.w900,
                                color: Colors.white,
                                letterSpacing: 1.0,
                              ),
                            ),
                            Text(
                              'Rice Disease System',
                              style: TextStyle(
                                fontSize: 11,
                                color: Colors.white70,
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const Divider(color: Colors.white24, height: 1),
                  const SizedBox(height: 12),

                  // Menu Items
                  Expanded(
                    child: ListView(
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      children: [
                        _buildSidebarBtn(0, 'Dashboard', Icons.dashboard_outlined, Icons.dashboard),
                        _buildSidebarBtn(1, 'Disease Scan', Icons.camera_alt_outlined, Icons.camera_alt),
                        _buildSidebarBtn(2, 'Scan History', Icons.history_outlined, Icons.history),
                        _buildSidebarBtn(3, 'AI Consultation', Icons.forum_outlined, Icons.forum),
                        _buildSidebarBtn(4, 'Treatment Guide', Icons.menu_book_outlined, Icons.menu_book),
                      ],
                    ),
                  ),

                  // User Info & Footer
                  const Divider(color: Colors.white24, height: 1),
                  Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      children: [
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: CircleAvatar(
                            backgroundColor: AppColors.accentLime,
                            child: Text(
                              (user?.name.isNotEmpty == true ? user!.name[0] : 'U').toUpperCase(),
                              style: const TextStyle(color: AppColors.primaryDark, fontWeight: FontWeight.bold),
                            ),
                          ),
                          title: Text(
                            user?.name ?? 'Farmer User',
                            style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.bold),
                            overflow: TextOverflow.ellipsis,
                          ),
                          subtitle: Text(
                            user?.roleLabel ?? 'Rice Farmer',
                            style: const TextStyle(color: Colors.white70, fontSize: 11),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        const SizedBox(height: 8),
                        Row(
                          children: [
                            Expanded(
                              child: OutlinedButton.icon(
                                style: OutlinedButton.styleFrom(
                                  foregroundColor: Colors.white,
                                  side: const BorderSide(color: Colors.white38),
                                  padding: const EdgeInsets.symmetric(vertical: 8),
                                ),
                                onPressed: () => ServerConfigDialog.show(context),
                                icon: const Icon(Icons.settings, size: 16),
                                label: const Text('Host', style: TextStyle(fontSize: 12)),
                              ),
                            ),
                            const SizedBox(width: 8),
                            IconButton(
                              tooltip: 'Sign Out',
                              icon: const Icon(Icons.logout, color: Colors.white70),
                              onPressed: () => auth.logout(),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),

            // Content Canvas
            Expanded(child: pages[_currentIndex]),
          ],
        ),
      );
    }

    // Mobile / Tablet Navigation Layout
    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            Image.asset(
              'assets/images/logo.png',
              width: 28,
              height: 28,
              errorBuilder: (ctx, err, stack) => const Icon(Icons.eco),
            ),
            const SizedBox(width: 10),
            const Text('ORYZATIX'),
          ],
        ),
        actions: [
          IconButton(
            tooltip: 'Server Settings',
            icon: const Icon(Icons.settings_ethernet),
            onPressed: () => ServerConfigDialog.show(context),
          ),
        ],
      ),
      drawer: Drawer(
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            UserAccountsDrawerHeader(
              decoration: const BoxDecoration(color: AppColors.primary),
              currentAccountPicture: CircleAvatar(
                backgroundColor: AppColors.accentLime,
                child: Text(
                  (user?.name.isNotEmpty == true ? user!.name[0] : 'U').toUpperCase(),
                  style: const TextStyle(fontSize: 24, fontWeight: FontWeight.bold, color: AppColors.primaryDark),
                ),
              ),
              accountName: Text(user?.name ?? 'Oryzatix Farmer', style: const TextStyle(fontWeight: FontWeight.bold)),
              accountEmail: Text(user?.email ?? 'farmer@oryzatix.local'),
            ),
            ListTile(
              leading: const Icon(Icons.dashboard_outlined),
              title: const Text('Dashboard'),
              selected: _currentIndex == 0,
              onTap: () {
                Navigator.pop(context);
                _navigateTo(0);
              },
            ),
            ListTile(
              leading: const Icon(Icons.camera_alt_outlined),
              title: const Text('Disease Scan'),
              selected: _currentIndex == 1,
              onTap: () {
                Navigator.pop(context);
                _navigateTo(1);
              },
            ),
            ListTile(
              leading: const Icon(Icons.history_outlined),
              title: const Text('Scan History'),
              selected: _currentIndex == 2,
              onTap: () {
                Navigator.pop(context);
                _navigateTo(2);
              },
            ),
            ListTile(
              leading: const Icon(Icons.forum_outlined),
              title: const Text('AI Consultation'),
              selected: _currentIndex == 3,
              onTap: () {
                Navigator.pop(context);
                _navigateTo(3);
              },
            ),
            ListTile(
              leading: const Icon(Icons.menu_book_outlined),
              title: const Text('Treatment Guide'),
              selected: _currentIndex == 4,
              onTap: () {
                Navigator.pop(context);
                _navigateTo(4);
              },
            ),
            const Divider(),
            ListTile(
              leading: const Icon(Icons.settings_ethernet),
              title: const Text('API Server Config'),
              onTap: () {
                Navigator.pop(context);
                ServerConfigDialog.show(context);
              },
            ),
            ListTile(
              leading: const Icon(Icons.logout, color: AppColors.severe),
              title: const Text('Sign Out', style: TextStyle(color: AppColors.severe)),
              onTap: () {
                Navigator.pop(context);
                auth.logout();
              },
            ),
          ],
        ),
      ),
      body: pages[_currentIndex],
      bottomNavigationBar: NavigationBar(
        selectedIndex: _currentIndex,
        onDestinationSelected: _navigateTo,
        indicatorColor: AppColors.primaryContainer,
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.dashboard_outlined),
            selectedIcon: Icon(Icons.dashboard, color: AppColors.primary),
            label: 'Home',
          ),
          NavigationDestination(
            icon: Icon(Icons.camera_alt_outlined),
            selectedIcon: Icon(Icons.camera_alt, color: AppColors.primary),
            label: 'Scan',
          ),
          NavigationDestination(
            icon: Icon(Icons.history_outlined),
            selectedIcon: Icon(Icons.history, color: AppColors.primary),
            label: 'History',
          ),
          NavigationDestination(
            icon: Icon(Icons.forum_outlined),
            selectedIcon: Icon(Icons.forum, color: AppColors.primary),
            label: 'AI Consult',
          ),
          NavigationDestination(
            icon: Icon(Icons.menu_book_outlined),
            selectedIcon: Icon(Icons.menu_book, color: AppColors.primary),
            label: 'Treatments',
          ),
        ],
      ),
    );
  }

  Widget _buildSidebarBtn(int index, String title, IconData iconOutline, IconData iconFilled) {
    final isSelected = _currentIndex == index;
    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      child: Material(
        color: isSelected ? Colors.white.withValues(alpha: 0.18) : Colors.transparent,
        borderRadius: BorderRadius.circular(12),
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: () => _navigateTo(index),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            child: Row(
              children: [
                Icon(
                  isSelected ? iconFilled : iconOutline,
                  color: isSelected ? AppColors.accentLime : Colors.white70,
                  size: 20,
                ),
                const SizedBox(width: 14),
                Text(
                  title,
                  style: TextStyle(
                    color: isSelected ? Colors.white : Colors.white70,
                    fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
                    fontSize: 14,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
