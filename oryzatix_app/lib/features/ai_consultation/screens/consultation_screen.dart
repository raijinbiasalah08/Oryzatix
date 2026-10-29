import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../../core/theme/app_colors.dart';
import '../providers/consultation_provider.dart';

class ConsultationScreen extends StatefulWidget {
  const ConsultationScreen({super.key});

  @override
  State<ConsultationScreen> createState() => _ConsultationScreenState();
}

class _ConsultationScreenState extends State<ConsultationScreen> {
  final _textController = TextEditingController();
  final _scrollController = ScrollController();

  final List<String> _quickTagalogQuestions = [
    'Paano gamutin ang Bacterial Leaf Blight (BLB)?',
    'Paano makikilala ang Rice Blast sa ibang sintomas?',
    'Ano ang gagawin kapag naninilaw ang dahon (Tungro)?',
    'Tamang iskedyul ng patubig (AWD) sa palayan',
    'Anong impormasyon ang kailangan bago magrekomenda ng abono?',
  ];

  final List<String> _quickEnglishQuestions = [
    'How to control Bacterial Leaf Blight (BLB)?',
    'How can I tell rice blast from similar leaf symptoms?',
    'How to manage Green Leafhopper vector in Tungro?',
    'How does Alternate Wetting and Drying work?',
    'What information is needed before recommending fertilizer?',
  ];

  @override
  void dispose() {
    _textController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _scrollToBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scrollController.hasClients) {
        _scrollController.animateTo(
          _scrollController.position.maxScrollExtent,
          duration: const Duration(milliseconds: 300),
          curve: Curves.easeOut,
        );
      }
    });
  }

  Future<void> _handleSend([String? textToSend]) async {
    final text = textToSend ?? _textController.text;
    if (text.trim().isEmpty) return;

    final provider = context.read<ConsultationProvider>();
    if (provider.isSending || provider.isLoading) return;
    if (textToSend == null) _textController.clear();
    final sent = await provider.sendMessage(text);
    if (!mounted) return;
    if (!sent) {
      if (textToSend == null && _textController.text.isEmpty) {
        _textController.text = text;
      }
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(provider.errorMessage ?? 'Message could not be sent.'),
      ));
    }
    _scrollToBottom();
  }

  @override
  Widget build(BuildContext context) {
    final consultation = context.watch<ConsultationProvider>();
    final isTagalog = consultation.activeLanguage == 'tagalog';
    final quickQuestions = isTagalog ? _quickTagalogQuestions : _quickEnglishQuestions;
    final width = MediaQuery.of(context).size.width;
    final isDesktop = width >= 900;
    final isCompact = width < 520;

    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            const Icon(Icons.psychology, color: Colors.white),
            const SizedBox(width: 8),
            const Flexible(
              child: Text(
                'AI Consultation',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            if (width >= 600) ...[
              const SizedBox(width: 10),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(
                  color: Colors.white.withOpacity(0.2),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: const Text(
                  'Rice farming guidance',
                  style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Colors.white),
                ),
              ),
            ],
          ],
        ),
        actions: [
          // Language Switcher
          if (!isCompact)
            Container(
              margin: const EdgeInsets.symmetric(vertical: 10, horizontal: 8),
              decoration: BoxDecoration(
                color: Colors.white.withOpacity(0.15),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Row(
                children: [
                  _buildLangBtn('tagalog', 'Tagalog', isTagalog),
                  _buildLangBtn('english', 'English', !isTagalog),
                ],
              ),
            )
          else
            PopupMenuButton<String>(
              tooltip: 'Choose language',
              initialValue: consultation.activeLanguage,
              onSelected: (language) => consultation.setLanguage(language),
              icon: const Icon(Icons.translate),
              itemBuilder: (context) => [
                PopupMenuItem<String>(
                  value: 'tagalog',
                  child: Row(
                    children: [
                      if (isTagalog) const Icon(Icons.check, size: 18),
                      if (isTagalog) const SizedBox(width: 8),
                      const Text('Tagalog'),
                    ],
                  ),
                ),
                PopupMenuItem<String>(
                  value: 'english',
                  child: Row(
                    children: [
                      if (!isTagalog) const Icon(Icons.check, size: 18),
                      if (!isTagalog) const SizedBox(width: 8),
                      const Text('English'),
                    ],
                  ),
                ),
              ],
            ),
          IconButton(
            icon: const Icon(Icons.delete_sweep_outlined),
            tooltip: 'Clear Chat History',
            onPressed: () async {
              final confirm = await showDialog<bool>(
                context: context,
                builder: (ctx) => AlertDialog(
                  title: const Text('Clear Chat?'),
                  content: const Text('This will delete all past consultation messages.'),
                  actions: [
                    TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
                    ElevatedButton(
                      style: ElevatedButton.styleFrom(backgroundColor: AppColors.severe),
                      onPressed: () => Navigator.pop(ctx, true),
                      child: const Text('Clear'),
                    ),
                  ],
                ),
              );
              if (confirm == true) {
                await consultation.clearMessages();
                if (!context.mounted) return;
                if (consultation.errorMessage != null) {
                  ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                    content: Text(consultation.errorMessage!),
                  ));
                }
              }
            },
          ),
        ],
      ),
      body: Center(
        child: Container(
          constraints: BoxConstraints(maxWidth: isDesktop ? 960 : double.infinity),
          child: Column(
            children: [
              // Chat Message List
              Expanded(
                child: consultation.isLoading && consultation.messages.isEmpty
                    ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
                    : consultation.messages.isEmpty
                        ? _buildEmptyWelcomeState(isTagalog)
                        : ListView.builder(
                            controller: _scrollController,
                            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 20),
                            itemCount: consultation.messages.length + (consultation.isSending ? 1 : 0),
                            itemBuilder: (ctx, i) {
                              if (i == consultation.messages.length && consultation.isSending) {
                                return _buildTypingIndicator();
                              }
                              final msg = consultation.messages[i];
                              return _buildChatBubble(msg);
                            },
                          ),
              ),

              // Quick agronomic chips bar
              Container(
                height: 44,
                padding: const EdgeInsets.symmetric(horizontal: 12),
                child: ListView.separated(
                  scrollDirection: Axis.horizontal,
                  itemCount: quickQuestions.length,
                  separatorBuilder: (ctx, i) => const SizedBox(width: 8),
                  itemBuilder: (ctx, i) {
                    final q = quickQuestions[i];
                    return ActionChip(
                      label: Text(q, style: const TextStyle(fontSize: 11)),
                      backgroundColor: AppColors.surface,
                      side: const BorderSide(color: AppColors.border),
                      onPressed: consultation.isSending ? null : () => _handleSend(q),
                    );
                  },
                ),
              ),
              const SizedBox(height: 8),

              // Bottom Input Box
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withOpacity(0.04),
                      blurRadius: 10,
                      offset: const Offset(0, -2),
                    ),
                  ],
                ),
                child: SafeArea(
                  child: Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: _textController,
                          decoration: InputDecoration(
                            hintText: isTagalog
                                ? 'Magtanong tungkol sa sakit, gamot, o abono...'
                                : 'Ask about rice symptoms, crop care, or irrigation...',
                            border: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(24),
                              borderSide: const BorderSide(color: AppColors.border),
                            ),
                            contentPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
                          ),
                          onSubmitted: (val) => _handleSend(),
                        ),
                      ),
                      const SizedBox(width: 10),
                      IconButton(
                        onPressed: consultation.isSending ? null : () => _handleSend(),
                        icon: Container(
                          padding: const EdgeInsets.all(10),
                          decoration: const BoxDecoration(
                            color: AppColors.primary,
                            shape: BoxShape.circle,
                          ),
                          child: const Icon(Icons.send, color: Colors.white, size: 18),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildLangBtn(String lang, String label, bool active) {
    return GestureDetector(
      onTap: () => context.read<ConsultationProvider>().setLanguage(lang),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(
          color: active ? Colors.white : Colors.transparent,
          borderRadius: BorderRadius.circular(16),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.bold,
            color: active ? AppColors.primary : Colors.white,
          ),
        ),
      ),
    );
  }

  Widget _buildEmptyWelcomeState(bool isTagalog) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(32),
      child: Center(
        child: Column(
          children: [
            Container(
              padding: const EdgeInsets.all(20),
              decoration: const BoxDecoration(
                color: AppColors.primaryContainer,
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.support_agent, size: 48, color: AppColors.primary),
            ),
            const SizedBox(height: 16),
            Text(
              isTagalog ? 'Kumusta! Ako si Oryzatix AI Agronomist' : 'Welcome to Oryzatix AI Agronomist',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
            ),
            const SizedBox(height: 8),
            Text(
              isTagalog
                  ? 'Digital na katuwang para sa mga tanong tungkol sa palay. Pumili ng mungkahi sa ibaba o mag-type ng inyong tanong.'
                  : 'Your digital assistant for rice farming questions. Choose a prompt below or type your question.',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 13, color: AppColors.textSecondary, height: 1.4),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildChatBubble(dynamic msg) {
    final isUser = msg.isUser;
    return Align(
      alignment: isUser ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        constraints: const BoxConstraints(maxWidth: 620),
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
        decoration: BoxDecoration(
          color: isUser ? AppColors.primary : AppColors.surface,
          borderRadius: BorderRadius.only(
            topLeft: const Radius.circular(16),
            topRight: const Radius.circular(16),
            bottomLeft: Radius.circular(isUser ? 16 : 4),
            bottomRight: Radius.circular(isUser ? 4 : 16),
          ),
          border: isUser ? null : Border.all(color: AppColors.border),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.03),
              blurRadius: 6,
              offset: const Offset(0, 2),
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: isUser ? CrossAxisAlignment.end : CrossAxisAlignment.start,
          children: [
            Text(
              msg.content,
              style: TextStyle(
                color: isUser ? Colors.white : AppColors.textPrimary,
                fontSize: 14,
                height: 1.4,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              msg.time,
              style: TextStyle(
                color: isUser ? Colors.white70 : AppColors.textMuted,
                fontSize: 10,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildTypingIndicator() {
    return Align(
      alignment: Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AppColors.border),
        ),
        child: const Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox(
              width: 14,
              height: 14,
              child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.primary),
            ),
            SizedBox(width: 10),
            Text('AI Agronomist is typing...', style: TextStyle(fontSize: 12, color: AppColors.textMuted)),
          ],
        ),
      ),
    );
  }
}
