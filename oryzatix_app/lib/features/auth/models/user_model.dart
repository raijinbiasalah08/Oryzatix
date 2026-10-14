class UserModel {
  final int id;
  final String name;
  final String email;
  final String role;
  final String roleLabel;
  final String location;
  final String? avatar;
  final String? avatarUrl;
  final String? createdAt;

  UserModel({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    required this.roleLabel,
    required this.location,
    this.avatar,
    this.avatarUrl,
    this.createdAt,
  });

  factory UserModel.fromJson(Map<String, dynamic> json) {
    return UserModel(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      name: json['name']?.toString() ?? '',
      email: json['email']?.toString() ?? '',
      role: json['role']?.toString() ?? 'farmer',
      roleLabel: json['role_label']?.toString() ?? (json['role'] == 'farmer' ? 'Rice Farmer' : (json['role'] == 'agri_worker' ? 'Extension Worker' : 'Administrator')),
      location: json['location']?.toString() ?? 'Roxas, Oriental Mindoro',
      avatar: json['avatar']?.toString(),
      avatarUrl: json['avatar_url']?.toString(),
      createdAt: json['created_at']?.toString(),
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'email': email,
      'role': role,
      'role_label': roleLabel,
      'location': location,
      'avatar': avatar,
      'avatar_url': avatarUrl,
      'created_at': createdAt,
    };
  }
}
