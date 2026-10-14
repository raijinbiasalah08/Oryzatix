class ChatMessageModel {
  final String role; // 'user' or 'ai'
  final String content;
  final String language;
  final String time;

  ChatMessageModel({
    required this.role,
    required this.content,
    required this.language,
    required this.time,
  });

  bool get isUser => role == 'user';

  factory ChatMessageModel.fromJson(Map<String, dynamic> json) {
    return ChatMessageModel(
      role: json['role']?.toString() ?? 'ai',
      content: json['content']?.toString() ?? '',
      language: json['language']?.toString() ?? 'tagalog',
      time: json['time']?.toString() ?? '',
    );
  }
}
